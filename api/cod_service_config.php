<?php
// cod_service_config.php | v1.0 | 31-05-2026
error_reporting(E_ALL); ini_set("log_errors", 1); ini_set("error_log", "/tmp/cod_svc_cfg_errors.log");
require_once 'config.php';
require_once 'auth.php';

db()->exec("CREATE TABLE IF NOT EXISTS cod_service_config (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    service_code  VARCHAR(20)   NOT NULL UNIQUE,
    cod_enabled   TINYINT(1)    NOT NULL DEFAULT 0,
    flat_limit    DECIMAL(10,2) NOT NULL DEFAULT 500.00,
    flat_fee      DECIMAL(10,2) NOT NULL DEFAULT 3.00,
    min_fee       DECIMAL(10,2) NOT NULL DEFAULT 1.30,
    percentage    DECIMAL(5,2)  NOT NULL DEFAULT 1.00,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB CHARSET=utf8mb4");

$method   = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
require_user();
$DEFAULTS = ['cod_enabled' => 0, 'flat_limit' => 500.00, 'flat_fee' => 3.00, 'min_fee' => 1.30, 'percentage' => 1.00];

if ($method === 'GET') {
    $code = strtoupper(trim($_GET['service_code'] ?? ''));
    if (!$code) respond(['error' => 'service_code required'], 400);

    $stmt = db()->prepare("SELECT * FROM cod_service_config WHERE service_code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        respond(array_merge($DEFAULTS, ['service_code' => $code, 'id' => null, 'updated_at' => null]));
    }
    respond($row);
}

if ($method === 'POST') {
    $b    = body();
    $code = strtoupper(trim($b['service_code'] ?? ''));
    if (!$code) respond(['error' => 'service_code required'], 400);

    $cod_enabled = (int)(bool)($b['cod_enabled'] ?? 0);
    $flat_limit  = isset($b['flat_limit'])  ? (float)$b['flat_limit']  : $DEFAULTS['flat_limit'];
    $flat_fee    = isset($b['flat_fee'])    ? (float)$b['flat_fee']    : $DEFAULTS['flat_fee'];
    $min_fee     = isset($b['min_fee'])     ? (float)$b['min_fee']     : $DEFAULTS['min_fee'];
    $percentage  = isset($b['percentage'])  ? (float)$b['percentage']  : $DEFAULTS['percentage'];

    db()->prepare("
        INSERT INTO cod_service_config (service_code, cod_enabled, flat_limit, flat_fee, min_fee, percentage)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            cod_enabled = VALUES(cod_enabled),
            flat_limit  = VALUES(flat_limit),
            flat_fee    = VALUES(flat_fee),
            min_fee     = VALUES(min_fee),
            percentage  = VALUES(percentage)
    ")->execute([$code, $cod_enabled, $flat_limit, $flat_fee, $min_fee, $percentage]);

    respond(['ok' => true]);
}

respond(['error' => 'Method not allowed'], 405);
