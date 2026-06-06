<?php
// services.php | v1.0 | 22-05-2026
error_reporting(E_ALL); ini_set("log_errors",1); ini_set("error_log","/tmp/services_errors.log");
require_once 'config.php';

// CREATE TABLE IF NOT EXISTS — τρέχει κάθε φορά, ασφαλές
db()->exec("CREATE TABLE IF NOT EXISTS `4a_services` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `code`        VARCHAR(32) NOT NULL UNIQUE,
    `name`        VARCHAR(128) NOT NULL,
    `description` TEXT,
    `type`        VARCHAR(16) DEFAULT 'AIR',
    `color`       VARCHAR(16) DEFAULT 'blue',
    `emoji`       VARCHAR(8)  DEFAULT '',
    `active`      TINYINT(1)  DEFAULT 1,
    `sort_order`  INT         DEFAULT 0,
    `created_at`  DATETIME    DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Seed — αν ο πίνακας είναι άδειος, βάλε τα default services
$count = db()->query("SELECT COUNT(*) FROM `4a_services`")->fetchColumn();
if ($count == 0) {
    $defaults = [
        ['S1003',    '✈️↗️ Express Export',              'Αεροπορική μεταφορά εγγράφων και δεμάτων από Κύπρο προς όλο τον κόσμο.',          'AIR',   'blue',   '✈️', 1, 1],
        ['S1012',    '✈️↙️ Express Import',              'Αεροπορική μεταφορά εγγράφων και δεμάτων από όλο τον κόσμο προς Κύπρο.',          'AIR',   'blue',   '✈️', 1, 2],
        ['S1010',    '🚛↗️ Economy Export',              'Οδική μεταφορά εγγράφων και δεμάτων από Κύπρο προς Ευρώπη.',                      'ROAD',  'blue',   '🚛', 1, 3],
        ['S1041',    '🚛↙️ Economy Import',              'Οδική μεταφορά εγγράφων και δεμάτων από Ευρώπη προς Κύπρο.',                      'ROAD',  'blue',   '🚛', 1, 4],
        ['S1003_GR', '🇬🇷✈️↗️ Export Ελλάδα→Κύπρος',   'Αεροπορική μεταφορά εγγράφων και δεμάτων από Ελλάδα προς Κύπρο.',                 'AIR',   'blue',   '🇬🇷', 1, 5],
        ['S1012_GR', '🇬🇷✈️↙️ Import Κύπρος→Ελλάδα',   'Αεροπορική μεταφορά εγγράφων και δεμάτων από Κύπρο προς Ελλάδα.',                 'AIR',   'blue',   '🇬🇷', 1, 6],
        ['S1003_CY', '🇨🇾✈️↗️ Express Export Κύπρος',   'Αεροπορική μεταφορά εγγράφων και δεμάτων από Κύπρο προς όλο τον κόσμο.',          'AIR',   'yellow', '🇨🇾', 1, 7],
        ['S1012_CY', '🇨🇾✈️↙️ Express Import Κύπρος',   'Αεροπορική μεταφορά εγγράφων και δεμάτων από όλο τον κόσμο προς Κύπρο.',          'AIR',   'yellow', '🇨🇾', 1, 8],
        ['S1050',    '🇨🇾✈️🇬🇷🚛 Export CY→GR→World',   'Συνδυασμένη αεροπορική και οδική μεταφορά από Κύπρο προς Ελλάδα και εξωτερικό.',  'COMBI', 'yellow', '🔀', 1, 9],
        ['S1051',    '🌍🚛🇬🇷✈️🇨🇾 Import World→GR→CY', 'Συνδυασμένη οδική και αεροπορική μεταφορά από εξωτερικό προς Ελλάδα και Κύπρο.',  'COMBI', 'yellow', '🔀', 1, 10],
    ];
    $stmt = db()->prepare("INSERT INTO `4a_services` (`code`,`name`,`description`,`type`,`color`,`emoji`,`active`,`sort_order`) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($defaults as $d) $stmt->execute($d);
}

$method = $_SERVER['REQUEST_METHOD'];

// GET — φόρτωση όλων των services
if ($method === 'GET') {
    $rows = db()->query("SELECT * FROM `4a_services` ORDER BY `sort_order` ASC, `code` ASC")->fetchAll(PDO::FETCH_ASSOC);
    respond($rows);
}

// POST
if ($method === 'POST') {
    $b = body();
    $action = $b['action'] ?? 'save';

    // SAVE (insert or update)
    if ($action === 'save') {
        $code = strtoupper(trim($b['code'] ?? ''));
        if (!$code) respond(['error' => 'Ο κωδικός είναι υποχρεωτικός'], 400);

        $stmt = db()->prepare("INSERT INTO `4a_services` (`code`,`name`,`description`,`type`,`color`,`emoji`,`active`,`sort_order`,`country`)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            `name`=VALUES(`name`), `description`=VALUES(`description`),
            `type`=VALUES(`type`), `color`=VALUES(`color`),
            `emoji`=VALUES(`emoji`), `active`=VALUES(`active`),
            `sort_order`=VALUES(`sort_order`), `country`=VALUES(`country`), `updated_at`=NOW()");
        $stmt->execute([
            $code,
            trim($b['name'] ?? ''),
            trim($b['description'] ?? ''),
            $b['type'] ?? 'AIR',
            $b['color'] ?? 'blue',
            $b['emoji'] ?? '',
            isset($b['active']) ? (int)$b['active'] : 1,
            (int)($b['sort_order'] ?? 0),
            strtoupper(trim($b['country'] ?? 'GR'))
        ]);
        respond(['ok' => true]);
    }

    // DELETE
    if ($action === 'delete') {
        $code = strtoupper(trim($b['code'] ?? ''));
        if (!$code) respond(['error' => 'Ο κωδικός είναι υποχρεωτικός'], 400);
        $stmt = db()->prepare("DELETE FROM `4a_services` WHERE `code`=?");
        $stmt->execute([$code]);
        respond(['ok' => true]);
    }
}

respond(['error' => 'Bad request'], 400);
