<?php
require_once __DIR__ . '/config.php';

$pdo = db();

/* ---- 1) Schema: append-only, effective-dated, audit-aware ---- */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `4a_charge_limits` (
        id             INT PRIMARY KEY AUTO_INCREMENT,
        charge_code    VARCHAR(20)   NOT NULL,
        scope          VARCHAR(20)   NOT NULL DEFAULT '*',
        param_key      VARCHAR(30)   NOT NULL,
        label          VARCHAR(120)  NOT NULL,
        value          DECIMAL(12,4) NOT NULL,
        unit           VARCHAR(4)    NOT NULL DEFAULT 'EUR',
        mode           VARCHAR(10)   NOT NULL DEFAULT 'floor',
        effective_date DATE          NOT NULL,
        note           VARCHAR(255)  DEFAULT NULL,
        created_by     INT           DEFAULT NULL,
        created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        active         TINYINT(1)    NOT NULL DEFAULT 1,
        KEY idx_lookup (charge_code, scope, param_key, effective_date),
        UNIQUE KEY uq_ver (charge_code, scope, param_key, effective_date)
    ) ENGINE=InnoDB CHARSET=utf8mb4
");

/* ---- 2) Seed COD (idempotent: μονο αν αδειο) ---- */
$has = $pdo->query("SELECT COUNT(*) FROM `4a_charge_limits` WHERE charge_code='COD'")->fetchColumn();
$seeded = false;
if ((int)$has === 0) {
    $stmt = $pdo->prepare("
        INSERT INTO `4a_charge_limits`
            (charge_code, scope, param_key, label, value, unit, mode, effective_date, note)
        VALUES (:cc, '*', :pk, :lbl, :val, :unit, :mode, :eff, :note)
    ");
    $rows = [
        /* --- ΙΣΤΟΡΙΚΟ: τρεχον μοντελο, ισχυ απο 01/01/2026 --- */
        ['COD','flat_fee',  'Χρέωση αντικαταβολής έως το όριο', 2.00, 'EUR','floor','2026-01-01','Αρχικό μοντέλο'],
        ['COD','tier_limit','Όριο κλιμάκωσης ποσού',            500.00,'EUR','fixed','2026-01-01','Αρχικό μοντέλο'],
        ['COD','min_fee',   'Απόλυτη ελάχιστη χρέωση',          1.30, 'EUR','floor','2026-01-01','Αρχικό μοντέλο'],
        ['COD','tier_pct',  'Ποσοστό άνω του ορίου',            1.00, '%',  'floor','2026-01-01','Αρχικό μοντέλο'],
        /* --- ΝΕΟ: ισχυ απο 10/07/2026 (μονο ο,τι αλλαζει) --- */
        ['COD','flat_fee',  'Χρέωση αντικαταβολής έως το όριο', 1.30, 'EUR','floor','2026-07-10','Νέο μοντέλο 10/7'],
        ['COD','tier_pct',  'Ποσοστό άνω του ορίου',            0.50, '%',  'floor','2026-07-10','Νέο μοντέλο 10/7'],
    ];
    foreach ($rows as $r) {
        $stmt->execute([
            ':cc'=>$r[0], ':pk'=>$r[1], ':lbl'=>$r[2], ':val'=>$r[3],
            ':unit'=>$r[4], ':mode'=>$r[5], ':eff'=>$r[6], ':note'=>$r[7],
        ]);
    }
    $seeded = true;
}

$check = $pdo->query("
    SELECT scope, param_key, value, unit, mode, effective_date
    FROM `4a_charge_limits` WHERE charge_code='COD'
    ORDER BY effective_date, param_key
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(
    ['ok'=>true, 'migration'=>'005_charge_limits', 'seeded'=>$seeded, 'cod_rows'=>$check],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
