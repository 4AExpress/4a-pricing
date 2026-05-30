<?php
// test_setup.php | v1.0 | 30-05-2026
// Integration-test fixture helper.
// Disabled unless TEST_SETUP_SECRET is set in the server environment.
require_once 'config.php';

$env_secret = getenv('TEST_SETUP_SECRET');
if (!$env_secret) {
    respond(['error' => 'Not found'], 404);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

$b = body();
if (($b['secret'] ?? '') !== $env_secret) {
    respond(['error' => 'Forbidden'], 403);
}

$action = $b['action'] ?? '';

// ── create_shipment ──────────────────────────────────────────────────────────
if ($action === 'create_shipment') {
    $services = $b['services'] ?? [];

    db()->exec("INSERT INTO `4a_shipments` (cod_enabled) VALUES (0)");
    $id = (int)db()->lastInsertId();

    if ($services) {
        $stmt = db()->prepare(
            "INSERT IGNORE INTO `4a_shipment_services` (shipment_id, service_code) VALUES (?, ?)"
        );
        foreach ($services as $code) {
            $stmt->execute([$id, $code]);
        }
    }

    respond(['ok' => true, 'id' => $id]);
}

// ── delete_shipment ──────────────────────────────────────────────────────────
if ($action === 'delete_shipment') {
    $id = (int)($b['id'] ?? 0);
    if (!$id) respond(['error' => 'Missing id'], 400);

    db()->prepare("DELETE FROM `4a_shipment_services` WHERE shipment_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM `4a_cod_audit_log`     WHERE shipment_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM `4a_shipments`         WHERE id = ?")         ->execute([$id]);

    respond(['ok' => true]);
}

respond(['error' => 'Unknown action'], 400);
