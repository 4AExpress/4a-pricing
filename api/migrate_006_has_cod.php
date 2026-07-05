<?php
require_once __DIR__ . '/config.php';

$pdo = db();

/* ---- 1) Στηλη has_cod (idempotent) ---- */
$col = $pdo->query("SHOW COLUMNS FROM `4a_services` LIKE 'has_cod'")->fetch();
if (!$col) {
    $pdo->exec("ALTER TABLE `4a_services` ADD COLUMN `has_cod` TINYINT(1) NOT NULL DEFAULT 0 AFTER `has_fuel`");
}

/* ---- 2) Seed: μεταφορα enablement απο cod_service_config (η τρεχουσα αληθεια) ---- */
$pdo->exec("
    UPDATE `4a_services` s
    JOIN `cod_service_config` c ON c.service_code = s.code
    SET s.has_cod = 1
    WHERE c.cod_enabled = 1
");

/* ---- proof: ποια services εχουν has_cod=1 ---- */
$rows = $pdo->query("SELECT code, has_cod FROM `4a_services` WHERE has_cod = 1 ORDER BY code")
            ->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(
    ['ok' => true, 'migration' => '006_has_cod', 'cod_services' => $rows],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
