<?php
require_once __DIR__ . '/config.php';

$pdo = db();

/* ---- 1) Στηλη cod στο 4a_clients (idempotent) ----
   Η στηλη υπαρχει ηδη στον live server -- προστεθηκε χειροκινητα, ποτε ως
   migration. Αυτο το αρχειο υπαρχει ωστε ενα καθαρο deploy (ή staging) να
   φτιαχνει το ιδιο schema. Σε server που την εχει ηδη, ειναι no-op.

   Περιεχομενο: object-or-null, οχι list. Παραδειγμα:
     {"cod_enabled":true,"cod_amount":250,"cod_fee":4,
      "snapshot":{"flat_fee":4,"tier_limit":500,"min_fee":1.30,
                  "tier_pct":0.50,"asof":"2026-07-10"}}

   Το snapshot κλειδωνει τα ορια τη στιγμη της προσφορας, ωστε μια μεταγενεστερη
   αλλαγη στο 4a_charge_limits να μην αναδρομικα αλλαζει παλιες προσφορες.

   JSON: χωρις DEFAULT -- η MySQL δεν επιτρεπει default value σε JSON στηλη.
   Σε MariaDB το JSON ειναι alias του LONGTEXT· και τα δυο δεχονται NULL. */
$col = $pdo->query("SHOW COLUMNS FROM `4a_clients` LIKE 'cod'")->fetch();
$added = false;
if (!$col) {
    $pdo->exec("ALTER TABLE `4a_clients` ADD COLUMN `cod` JSON NULL AFTER `managers`");
    $added = true;
}

/* ---- proof: τυπος στηλης + ποσοι πελατες εχουν COD ---- */
$def = $pdo->query("SHOW COLUMNS FROM `4a_clients` LIKE 'cod'")->fetch(PDO::FETCH_ASSOC);
$withCod = (int)$pdo->query("SELECT COUNT(*) FROM `4a_clients` WHERE cod IS NOT NULL")->fetchColumn();
$total   = (int)$pdo->query("SELECT COUNT(*) FROM `4a_clients`")->fetchColumn();

echo json_encode(
    [
        'ok'          => true,
        'migration'   => '007_clients_cod',
        'added'       => $added,          // false = υπηρχε ηδη, no-op
        'column'      => $def,
        'clients'     => ['total' => $total, 'with_cod' => $withCod],
    ],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
