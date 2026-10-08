<?php
// cron/fuel_weekly_create.php | v1.0 | 08-10-2026
//
// Δημιουργεί την εβδομαδιαία εργασία γενικού επίναυλου (fuel_weekly +
// fuel_weekly_verify) για κάθε μελλοντική εβδομάδα του cache που δεν έχει
// ήδη εργασία. Η λογική ζει στο api/fuel_weekly.php.
//
//   php fuel_weekly_create.php --api=<φάκελος api> [--dry-run]
//
// ΤΡΕΧΕΙ ΜΟΝΟ από γραμμή εντολών και μένει ΕΚΤΟΣ webroot. Η διαδρομή του
// api δίνεται ως παράμετρος, ώστε κανένα στοιχείο του server να μη γράφεται
// στο repo.
//
// Τυπώνει ΜΙΑ γραμμή: ώρα, εβδομάδες που βρήκε, εργασίες που δημιούργησε
// ή «καμία νέα». Κανένα στοιχείο σύνδεσης, κανένα μήνυμα βάσης: σε σφάλμα
// γράφεται μόνο το είδος του.
//
// Exit: 0 εντάξει (και «καμία νέα»), 1 σφάλμα.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$api = null; $dry = false;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--api=') === 0) $api = rtrim(substr($a, 6), '/');
    elseif ($a === '--dry-run')     $dry = true;
}
$now = gmdate('Y-m-d H:i:s');
if ($api === null || !is_file($api . '/config.php') || !is_file($api . '/fuel_weekly.php')) {
    echo gmdate('Y-m-d\TH:i:s\Z') . " · ΣΦΑΛΜΑ: λείπει ή είναι άκυρο το --api\n";
    exit(1);
}

try {
    require $api . '/config.php';
    require_once $api . '/fuel_weekly.php';

    $db = db();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $types = array();
    foreach ($db->query('SELECT `code`, `label_el`, `label_en`, `source`, `multiplier`
                           FROM `4a_fuel_types` WHERE `active` = 1')->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $types[$t['code']] = $t;
    }

    // Ίδια πηγή, μνήμη και έλεγχος περιεχομένου με το api/fuel_catalog.php.
    list($cache, $meta) = fuel_cache_load(
        function () use ($db) {
            return $db->query('SELECT `body`, `read_at`, `read_at` > NOW() - INTERVAL 10 MINUTE AS `fresh`
                                 FROM `4a_fuel_cache_mem` WHERE `id` = 1')->fetch(PDO::FETCH_ASSOC);
        },
        function () {
            $ch = curl_init(FUEL_WEEKLY_CACHE_URL);
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                                         CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true));
            $body = curl_exec($ch);
            $res  = array(curl_getinfo($ch, CURLINFO_HTTP_CODE), $body, '');
            curl_close($ch);
            return $res;
        },
        function ($body) use ($db, $dry) {
            if ($dry) return null;   // η δοκιμή δεν γράφει ΤΙΠΟΤΑ, ούτε τη μνήμη του cache
            $db->prepare('REPLACE INTO `4a_fuel_cache_mem` (`id`, `body`, `read_at`) VALUES (1, ?, NOW())')
               ->execute(array($body));
            return $db->query('SELECT `read_at` FROM `4a_fuel_cache_mem` WHERE `id` = 1')->fetchColumn();
        },
        function ($m) { /* τα μηνύματα του cache δεν πάνε στο log του cron */ }
    );

    if ($cache === null) {
        echo gmdate('Y-m-d\TH:i:s\Z') . ($dry ? ' ΔΟΚΙΜΗ' : '') . " · καμία νέα · ΠΡΟΕΙΔΟΠΟΙΗΣΗ: το cache δεν διαβάστηκε\n";
        exit(0);
    }

    $r = fuel_weekly_ensure($db, $cache, $types, fuel_weekly_today_athens($now), $dry);
    echo fuel_weekly_log_line($r, $now, $dry) . "\n";

    // Στη δοκιμή, και τα στοιχεία που θα έμπαιναν, για έλεγχο πριν την πραγματική εκτέλεση.
    if ($dry) {
        foreach ($r['actions'] as $a) {
            echo '  ' . $a['code'] . ' ' . $a['week'] . ' · ' . $a['status']
               . ($a['task_id'] ? ' #' . $a['task_id'] : '')
               . ' · προθεσμία ' . $a['due_at'] . ' (Αθήνα)'
               . ' · γραμμές ' . $a['rows']
               . ' · τιμές ' . json_encode($a['prices']) . "\n";
        }
        echo '  cache: fetched_at ' . $meta['fetched_at'] . ($meta['stale'] ? ' (παλιό αντίγραφο)' : '') . "\n";
    }
    foreach ($r['actions'] as $a) if ($a['status'] === 'error') exit(1);
    exit(0);

} catch (Throwable $e) {
    echo gmdate('Y-m-d\TH:i:s\Z') . ($dry ? ' ΔΟΚΙΜΗ' : '') . ' · ΣΦΑΛΜΑ: ' . get_class($e) . "\n";
    exit(1);
}
