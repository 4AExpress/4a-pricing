<?php
// fuel_catalog.php | v1.0 | 07-10-2026
// GET → οι κάρτες επίναυλου του dashboard, ανά σταθμό και υπηρεσία, από τον
//       κατάλογο CMS (4a_fuel_cms_services), με τις τιμές ανά τύπο για κάθε
//       εβδομάδα του cache. Κάθε συνδεδεμένος χρήστης, όχι μόνο admin.
//
// ΤΟ FRONTEND ΔΕΝ ΥΠΟΛΟΓΙΖΕΙ. Οι τιμές βγαίνουν από τη fuel_pct() του
// fuel_lib.php, το ίδιο σημείο που παράγει το αρχείο CMS (half-up, bcmath).
//
// Πηγή καρτών ο κατάλογος CMS και ΟΧΙ το services.php: η S1027 δεν υπάρχει
// στο 4a_services (R15).
//
// Το cache του GitHub κρατιέται 10′ στον πίνακα 4a_fuel_cache_mem (migration
// 2026-10-07d), ώστε να μην καλείται το GitHub σε κάθε φόρτωση. Το endpoint
// ΔΕΝ εκτελεί DDL: αν ο πίνακας λείπει, απαντά με τον κατάλογο και
// weeks: [], και γράφει error_log. Η λογική ζει στη fuel_cache_load().
require_once 'config.php';
require_once 'fuel_lib.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

const FUEL_CACHE_URL     = 'https://raw.githubusercontent.com/4AExpress/4a-pricing/main/data/fuel_surcharge_cache.json';
const FUEL_CACHE_TTL_MIN = 10;

try {
    require_once 'auth.php';
    require_user();

    $types = array();
    foreach (db()->query('SELECT `code`, `label_el`, `label_en`, `source`, `multiplier`
                            FROM `4a_fuel_types` WHERE `active` = 1
                           ORDER BY `sort_order`, `code`')->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $types[$t['code']] = $t;
    }

    $cat = db()->query('SELECT `cms_service`, `service_name`, `station_country`, `direction`,
                               `zones`, `fuel_type`, `is_combi`, `visible_scope`, `sort_order`
                          FROM `4a_fuel_cms_services`
                         WHERE `active` = 1
                         ORDER BY `sort_order`, `id`')->fetchAll(PDO::FETCH_ASSOC);

    $cards = fuel_catalog_cards($cat, $types);

    list($cache, $meta) = fuel_cache_load(
        function () {
            return db()->query('SELECT `body`, `read_at`,
                                       `read_at` > NOW() - INTERVAL ' . (int)FUEL_CACHE_TTL_MIN . ' MINUTE AS `fresh`
                                  FROM `4a_fuel_cache_mem` WHERE `id` = 1')->fetch(PDO::FETCH_ASSOC);
        },
        function () {
            $ch = curl_init(FUEL_CACHE_URL);
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                                         CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => true));
            $body = curl_exec($ch);
            $res  = array(curl_getinfo($ch, CURLINFO_HTTP_CODE), $body, curl_error($ch));
            curl_close($ch);
            return $res;
        },
        function ($body) {
            db()->prepare('REPLACE INTO `4a_fuel_cache_mem` (`id`, `body`, `read_at`) VALUES (1, ?, NOW())')
                ->execute(array($body));
            // Η ώρα που γράφτηκε, ώστε η απάντηση να έχει το ΙΔΙΟ read_at με
            // τις επόμενες κλήσεις που θα απαντηθούν από τον πίνακα.
            return db()->query('SELECT `read_at` FROM `4a_fuel_cache_mem` WHERE `id` = 1')->fetchColumn();
        },
        'error_log'
    );
    $weeks = $cache ? fuel_week_prices($cache, $types) : array();

    respond(array(
        'ok'    => true,
        'types' => array_values($types),
        'cards' => $cards,
        'weeks' => $weeks,
        'cache' => $meta,
    ));
} catch (Throwable $e) {
    // Το μήνυμα PDO μένει στο log του server, ποτέ στον browser.
    error_log('fuel_catalog: ' . $e->getMessage());
    respond(array('ok' => false, 'error' => 'Σφάλμα διακομιστή'), 500);
}
