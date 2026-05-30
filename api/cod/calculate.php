<?php
// cod/calculate.php | v1.0 | 28-05-2026
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

$b          = body();
$cod_amount = isset($b['cod_amount']) ? (float)$b['cod_amount'] : null;

if ($cod_amount === null) {
    respond(['error' => 'cod_amount is required'], 400);
}

$cfg = db()->query(
    "SELECT min_fee, default_fee, threshold, percentage FROM `4a_cod_config` WHERE id = 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$cfg) {
    respond(['error' => 'COD config not found'], 500);
}

$min_fee     = (float)$cfg['min_fee'];
$default_fee = (float)$cfg['default_fee'];
$threshold   = (float)$cfg['threshold'];
$percentage  = (float)$cfg['percentage'];

if ($cod_amount <= 0) {
    respond(['error' => 'Το ποσό αντικαταβολής πρέπει να είναι θετικό'], 422);
}
if ($cod_amount < $min_fee) {
    respond(['error' => "Το ελάχιστο ποσό COD είναι €{$min_fee}"], 422);
}

if ($cod_amount <= $threshold) {
    $cod_fee = $min_fee;
    $method  = 'flat';
} else {
    $cod_fee = max($min_fee, $cod_amount * $percentage / 100);
    $method  = 'percentage';
}

respond([
    'cod_fee'     => round($cod_fee, 2),
    'method'      => $method,
    'min_fee'     => $min_fee,
    'default_fee' => $default_fee,
    'threshold'   => $threshold,
    'percentage'  => $percentage,
]);
