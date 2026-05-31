<?php
// admin/cod/config.php | v1.2 | 30-05-2026
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

db()->exec("CREATE TABLE IF NOT EXISTS `4a_cod_config` (
    `id`          INT           NOT NULL DEFAULT 1,
    `min_fee`     DECIMAL(10,2) NOT NULL DEFAULT 1.30,
    `default_fee` DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    `threshold`   DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
    `percentage`  DECIMAL(5,2)  NOT NULL DEFAULT 1.00,
    `updated_by`  INT           DEFAULT NULL,
    `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add default_fee to existing deployments that predate v1.1
db()->exec("ALTER TABLE `4a_cod_config`
    ADD COLUMN IF NOT EXISTS `default_fee` DECIMAL(10,2) NOT NULL DEFAULT 5.00
    AFTER `min_fee`");

db()->exec("CREATE TABLE IF NOT EXISTS `4a_cod_config_log` (
    `id`          INT           AUTO_INCREMENT PRIMARY KEY,
    `min_fee`     DECIMAL(10,2) NOT NULL,
    `default_fee` DECIMAL(10,2) NOT NULL,
    `threshold`   DECIMAL(10,2) NOT NULL,
    `percentage`  DECIMAL(5,2)  NOT NULL,
    `updated_by`  INT           NOT NULL,
    `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

db()->exec("ALTER TABLE `4a_cod_config_log`
    ADD COLUMN IF NOT EXISTS `default_fee` DECIMAL(10,2) NOT NULL DEFAULT 5.00
    AFTER `min_fee`");

// Seed singleton row if absent
$exists = db()->query("SELECT COUNT(*) FROM `4a_cod_config`")->fetchColumn();
if (!$exists) {
    db()->exec("INSERT INTO `4a_cod_config` (id, min_fee, default_fee, threshold, percentage)
                VALUES (1, 1.30, 5.00, 1000.00, 1.00)");
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    require_admin();
    $row = db()->query(
        "SELECT id, min_fee, default_fee, threshold, percentage, updated_by, updated_at
         FROM `4a_cod_config` WHERE id = 1"
    )->fetch(PDO::FETCH_ASSOC);
    respond($row);
}

// ── PUT ──────────────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $actor = require_admin();
    $b = body();

    $min_fee     = isset($b['min_fee'])     ? (float)$b['min_fee']     : null;
    $default_fee = isset($b['default_fee']) ? (float)$b['default_fee'] : null;
    $threshold   = isset($b['threshold'])   ? (float)$b['threshold']   : null;
    $percentage  = isset($b['percentage'])  ? (float)$b['percentage']  : null;

    if ($min_fee === null || $default_fee === null || $threshold === null || $percentage === null) {
        respond(['error' => 'Τα πεδία min_fee, default_fee, threshold, percentage είναι υποχρεωτικά'], 400);
    }
    if ($min_fee <= 0 || $default_fee <= 0 || $threshold <= 0 || $percentage <= 0) {
        respond(['error' => 'Όλες οι τιμές πρέπει να είναι θετικές'], 400);
    }
    if ($percentage > 100) {
        respond(['error' => 'Το ποσοστό (percentage) πρέπει να είναι ≤ 100'], 400);
    }
    if ($min_fee > $threshold) {
        respond(['error' => 'Το min_fee πρέπει να είναι ≤ threshold'], 400);
    }
    if ($default_fee < $min_fee) {
        respond(['error' => 'Το default_fee πρέπει να είναι ≥ min_fee'], 400);
    }

    $pdo = db();

    $pdo->prepare(
        "UPDATE `4a_cod_config`
         SET min_fee = ?, default_fee = ?, threshold = ?, percentage = ?,
             updated_by = ?, updated_at = NOW()
         WHERE id = 1"
    )->execute([$min_fee, $default_fee, $threshold, $percentage, $actor['id']]);

    $pdo->prepare(
        "INSERT INTO `4a_cod_config_log` (min_fee, default_fee, threshold, percentage, updated_by)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$min_fee, $default_fee, $threshold, $percentage, $actor['id']]);

    $updated = $pdo->query(
        "SELECT id, min_fee, default_fee, threshold, percentage, updated_by, updated_at
         FROM `4a_cod_config` WHERE id = 1"
    )->fetch(PDO::FETCH_ASSOC);

    respond($updated);
}

respond(['error' => 'Method not allowed'], 405);
