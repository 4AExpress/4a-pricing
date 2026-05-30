<?php
// shipments/cod.php | v1.0 | 28-05-2026
// POST /api/shipments/{id}/cod  — set or clear COD on a shipment
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// ── Table definitions ─────────────────────────────────────────────────────────

db()->exec("CREATE TABLE IF NOT EXISTS `4a_shipments` (
    `id`              INT           AUTO_INCREMENT PRIMARY KEY,
    `cod_enabled`     TINYINT(1)    NOT NULL DEFAULT 0,
    `cod_amount`      DECIMAL(10,2) DEFAULT NULL,
    `cod_fee`         DECIMAL(10,2) DEFAULT NULL,
    `cod_declared_val` DECIMAL(10,2) DEFAULT NULL,
    `cod_override`    TINYINT(1)    NOT NULL DEFAULT 0,
    `cod_confirmed_by` INT          DEFAULT NULL,
    `cod_confirmed_at` DATETIME     DEFAULT NULL,
    `updated_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Migration: add COD columns to deployments that predate this endpoint
$cod_cols = [
    "ADD COLUMN IF NOT EXISTS `cod_enabled`      TINYINT(1)    NOT NULL DEFAULT 0",
    "ADD COLUMN IF NOT EXISTS `cod_amount`        DECIMAL(10,2) DEFAULT NULL",
    "ADD COLUMN IF NOT EXISTS `cod_fee`           DECIMAL(10,2) DEFAULT NULL",
    "ADD COLUMN IF NOT EXISTS `cod_declared_val`  DECIMAL(10,2) DEFAULT NULL",
    "ADD COLUMN IF NOT EXISTS `cod_override`      TINYINT(1)    NOT NULL DEFAULT 0",
    "ADD COLUMN IF NOT EXISTS `cod_confirmed_by`  INT           DEFAULT NULL",
    "ADD COLUMN IF NOT EXISTS `cod_confirmed_at`  DATETIME      DEFAULT NULL",
];
foreach ($cod_cols as $col) {
    db()->exec("ALTER TABLE `4a_shipments` $col");
}

db()->exec("CREATE TABLE IF NOT EXISTS `4a_shipment_services` (
    `shipment_id`  INT         NOT NULL,
    `service_code` VARCHAR(32) NOT NULL,
    PRIMARY KEY (`shipment_id`, `service_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

db()->exec("CREATE TABLE IF NOT EXISTS `4a_cod_audit_log` (
    `id`          INT           AUTO_INCREMENT PRIMARY KEY,
    `shipment_id` INT           NOT NULL,
    `action`      VARCHAR(16)   NOT NULL,
    `old_amount`  DECIMAL(10,2) DEFAULT NULL,
    `new_amount`  DECIMAL(10,2) DEFAULT NULL,
    `old_fee`     DECIMAL(10,2) DEFAULT NULL,
    `new_fee`     DECIMAL(10,2) DEFAULT NULL,
    `performed_by` INT          NOT NULL,
    `notes`       TEXT          DEFAULT NULL,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Route ─────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

$actor       = require_user();
$shipment_id = (int)($_GET['id'] ?? 0);
if (!$shipment_id) respond(['error' => 'Invalid shipment ID'], 400);

$b           = body();
$cod_enabled = isset($b['cod_enabled']) ? (bool)$b['cod_enabled'] : null;
if ($cod_enabled === null) respond(['error' => 'cod_enabled is required'], 400);

// ── 1. Verify shipment exists ─────────────────────────────────────────────────

$shipment = db()->prepare(
    "SELECT id, cod_enabled, cod_amount, cod_fee FROM `4a_shipments` WHERE id = ?"
);
$shipment->execute([$shipment_id]);
$shipment = $shipment->fetch(PDO::FETCH_ASSOC);
if (!$shipment) respond(['error' => 'Shipment not found'], 404);

// ── 2. Verify at least one COD-capable service ────────────────────────────────

const COD_CAPABLE_SERVICES = ['S1003_GR', 'S1012_GR', 'S1039', 'S1059'];
$placeholders = implode(',', array_fill(0, count(COD_CAPABLE_SERVICES), '?'));

$capable = db()->prepare(
    "SELECT COUNT(*) FROM `4a_shipment_services`
     WHERE shipment_id = ? AND service_code IN ($placeholders)"
);
$capable->execute(array_merge([$shipment_id], COD_CAPABLE_SERVICES));
if (!(int)$capable->fetchColumn()) {
    respond(['error' => 'Η αποστολή δεν περιέχει υπηρεσία που υποστηρίζει αντικαταβολή'], 422);
}

// ── 3. Disable COD ────────────────────────────────────────────────────────────

if (!$cod_enabled) {
    db()->prepare(
        "UPDATE `4a_shipments`
         SET cod_enabled=0, cod_amount=NULL, cod_fee=NULL,
             cod_declared_val=NULL, cod_override=0,
             cod_confirmed_by=NULL, cod_confirmed_at=NULL
         WHERE id=?"
    )->execute([$shipment_id]);

    db()->prepare(
        "INSERT INTO `4a_cod_audit_log`
             (shipment_id, action, old_amount, new_amount, old_fee, new_fee, performed_by, notes)
         VALUES (?, 'disabled', ?, NULL, ?, NULL, ?, ?)"
    )->execute([
        $shipment_id,
        $shipment['cod_amount'],
        $shipment['cod_fee'],
        $actor['id'],
        $b['override_reason'] ?? null,
    ]);

    respond(['ok' => true, 'cod_enabled' => false]);
}

// ── 4. Enable / modify COD ────────────────────────────────────────────────────

$cod_amount      = isset($b['cod_amount'])      ? (float)$b['cod_amount']      : null;
$declared_value  = isset($b['declared_value'])  ? (float)$b['declared_value']  : null;

if ($cod_amount === null)     respond(['error' => 'cod_amount is required'],     400);
if ($declared_value === null) respond(['error' => 'declared_value is required'], 400);

// Load config
$cfg = db()->query(
    "SELECT min_fee, threshold, percentage FROM `4a_cod_config` WHERE id = 1"
)->fetch(PDO::FETCH_ASSOC);
if (!$cfg) respond(['error' => 'COD config not found'], 500);

$min_fee    = (float)$cfg['min_fee'];
$threshold  = (float)$cfg['threshold'];
$percentage = (float)$cfg['percentage'];

// 4a. Validate amount
if ($cod_amount <= 0) {
    respond(['error' => 'Το ποσό αντικαταβολής πρέπει να είναι θετικό'], 422);
}
if ($cod_amount < $min_fee) {
    respond(['error' => "Το ελάχιστο ποσό COD είναι €{$min_fee}"], 422);
}

// 4b. Calculate fee (once, regardless of how many COD-capable services)
if ($cod_amount <= $threshold) {
    $cod_fee        = $min_fee;
    $cod_fee_method = 'flat';
} else {
    $cod_fee        = max($min_fee, $cod_amount * $percentage / 100);
    $cod_fee_method = 'percentage';
}
$cod_fee = round($cod_fee, 2);

// 4c. Override flag
$cod_override = ($cod_amount != $declared_value) ? 1 : 0;

// 4d. Save
db()->prepare(
    "UPDATE `4a_shipments`
     SET cod_enabled=1, cod_amount=?, cod_fee=?, cod_declared_val=?,
         cod_override=?, cod_confirmed_by=?, cod_confirmed_at=NOW()
     WHERE id=?"
)->execute([
    $cod_amount, $cod_fee, $declared_value,
    $cod_override, $actor['id'],
    $shipment_id,
]);

// 4e. Audit log
$was_enabled = (bool)$shipment['cod_enabled'];
$action      = $was_enabled ? 'modified' : 'enabled';

db()->prepare(
    "INSERT INTO `4a_cod_audit_log`
         (shipment_id, action, old_amount, new_amount, old_fee, new_fee, performed_by, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
)->execute([
    $shipment_id,
    $action,
    $shipment['cod_amount'],
    $cod_amount,
    $shipment['cod_fee'],
    $cod_fee,
    $actor['id'],
    $b['override_reason'] ?? null,
]);

respond([
    'ok'             => true,
    'cod_enabled'    => true,
    'cod_amount'     => $cod_amount,
    'cod_fee'        => $cod_fee,
    'cod_fee_method' => $cod_fee_method,
    'cod_override'   => (bool)$cod_override,
    'cod_confirmed_at' => date('Y-m-d H:i:s'),
    'action'         => $action,
]);
