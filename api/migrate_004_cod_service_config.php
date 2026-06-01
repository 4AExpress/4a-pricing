<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cod_service_config (
        id            INT PRIMARY KEY AUTO_INCREMENT,
        service_code  VARCHAR(20)   NOT NULL UNIQUE,
        cod_enabled   TINYINT(1)    NOT NULL DEFAULT 0,
        flat_limit    DECIMAL(10,2) NOT NULL DEFAULT 500.00,
        flat_fee      DECIMAL(10,2) NOT NULL DEFAULT 3.00,
        min_fee       DECIMAL(10,2) NOT NULL DEFAULT 1.30,
        percentage    DECIMAL(5,2)  NOT NULL DEFAULT 1.00,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB CHARSET=utf8mb4
");

echo json_encode(['ok' => true, 'migration' => '004_cod_service_config']);
