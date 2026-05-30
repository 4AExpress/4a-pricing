<?php
// cod/services.php | v1.0 | 30-05-2026
// GET /api/cod/services — all services with cod_capable flag, no auth required
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['error' => 'Method not allowed'], 405);
}

const COD_CAPABLE = ['S1003_GR', 'S1012_GR', 'S1039', 'S1059'];

$rows = db()->query(
    "SELECT `code`, `name` FROM `4a_services` ORDER BY `sort_order` ASC, `code` ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$result = array_map(function ($row) {
    return [
        'code'        => $row['code'],
        'name'        => $row['name'],
        'cod_capable' => in_array($row['code'], COD_CAPABLE, true),
    ];
}, $rows);

respond($result);
