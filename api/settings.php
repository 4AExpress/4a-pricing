<?php
// settings.php | v1.0 | 13-05-2026
require_once 'config.php';
require_once 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method === 'GET')  { require_permission('pricelist-editor', 'view'); }
if ($method === 'POST') { require_permission('pricelist-editor', 'edit'); }

if ($method === 'GET') {
    $rows = db()->query('SELECT `key`, `value` FROM 4a_settings')->fetchAll();
    $settings = [];
    foreach ($rows as $r) {
        $settings[$r['key']] = $r['value'];
    }
    respond($settings);
}

if ($method === 'POST') {
    $b = body();
    $user = $b['user'] ?? 'unknown';
    $stmt = db()->prepare('INSERT INTO 4a_settings (`key`, `value`, `updated_by`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_by`=VALUES(`updated_by`)');
    foreach ($b['settings'] as $key => $value) {
        $stmt->execute([$key, $value, $user]);
    }
    respond(['ok' => true]);
}

respond(['error' => 'Bad request'], 400);