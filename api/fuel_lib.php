<?php
/* api/fuel_lib.php | v1.0 | 07-10-2026
 *
 * ΕΝΑ ΣΗΜΕΙΟ ΥΠΟΛΟΓΙΣΜΟΥ ΕΠΙΝΑΥΛΟΥ, ΣΤΟΝ SERVER.
 *
 * Το frontend ΔΕΝ ξαναϋπολογίζει τίποτα. Παίρνει έτοιμες τιμές και τις
 * εμφανίζει. Όποιος προσθέσει δεύτερο υπολογισμό αλλού, θα δημιουργήσει
 * δεύτερη αλήθεια που θα αποκλίνει στην πρώτη αλλαγή πολλαπλασιαστή.
 *
 * ΓΙΑΤΙ bcmath ΚΑΙ ΟΧΙ float: ο κανόνας είναι half-up στα 2 δεκαδικά, και
 * τα γινόμενα πέφτουν συχνά σε .xx5. Το 46.25 x 1.06 δίνει 49.025, που ΔΕΝ
 * είναι ακριβώς αναπαραστάσιμο σε δυαδικό. Σε float η στρογγυλοποίηση
 * εξαρτάται από το αν το σφάλμα αναπαράστασης έπεσε πάνω ή κάτω από το
 * μισό, δηλαδή από την τύχη. Μετρήθηκε στην παραγωγή: το float έδωσε
 * 49.025000000000006, οπότε τύχαινε να βγει σωστό. Δεν βασιζόμαστε σε αυτό.
 *
 * Καμία εξάρτηση από config.php ή από βάση για τις συναρτήσεις
 * υπολογισμού, ώστε να δοκιμάζονται χωρίς σύνδεση.
 */

if (!function_exists('fuel_round')) {

/**
 * Στρογγυλοποίηση half-up σε σταθερά δεκαδικά, με ακριβή δεκαδική
 * αριθμητική. Επιστρέφει string, ποτέ float, ώστε να μη χαθεί η ακρίβεια
 * στο επόμενο βήμα.
 *
 * @return string|null  null όταν η είσοδος δεν είναι αριθμός
 */
function fuel_round($value, $dp = 2)
{
    if ($value === null || $value === '') return null;
    // Οι float περνούν από sprintf με περίσσεια δεκαδικών: έτσι η τιμή
    // μπαίνει στη bcmath ως δεκαδικό string και όχι ως δυαδικό κλάσμα.
    $s = is_string($value) ? trim($value) : sprintf('%.12F', (float)$value);
    if (!is_numeric($s)) return null;

    $half = '0.' . str_repeat('0', (int)$dp) . '5';
    $neg  = (bccomp($s, '0', 12) < 0);
    // Το bcadd με scale $dp ΑΠΟΚΟΠΤΕΙ. Πρόσθεση του μισού πριν την
    // αποκοπή ισοδυναμεί με half-up, μακριά από το μηδέν.
    return bcadd($s, $neg ? '-' . $half : $half, (int)$dp);
}

/**
 * Η τιμή ενός τύπου επίναυλου: ποσοστό πηγής επί πολλαπλασιαστή.
 * Το ΜΟΝΟ σημείο όπου εφαρμόζεται ο πολλαπλασιαστής.
 *
 * @param  mixed $sourcePct  το air ή road του cache
 * @param  mixed $multiplier το 4a_fuel_types.multiplier
 * @return string|null       null όταν ο τύπος δεν παίρνει επίναυλο
 */
function fuel_pct($sourcePct, $multiplier)
{
    if ($sourcePct === null || $multiplier === null) return null;
    $src = is_string($sourcePct) ? trim($sourcePct) : sprintf('%.12F', (float)$sourcePct);
    $mul = is_string($multiplier) ? trim($multiplier) : sprintf('%.12F', (float)$multiplier);
    if (!is_numeric($src) || !is_numeric($mul)) return null;
    return fuel_round(bcmul($src, $mul, 12), 2);
}

/**
 * Μορφή αριθμού για το αρχείο CMS: έως δύο δεκαδικά, χωρίς μηδενικά στο
 * τέλος, τελεία ως υποδιαστολή.
 *
 * 48 -> "48"   ·   40.5 -> "40.5"   ·   40.75 -> "40.75"
 *
 * @return string|null
 */
function fuel_num($value)
{
    $r = fuel_round($value, 2);
    if ($r === null) return null;
    if (strpos($r, '.') !== false) {
        $r = rtrim($r, '0');
        $r = rtrim($r, '.');
    }
    return $r === '' || $r === '-' ? '0' : $r;
}

/**
 * ΟΙ ΓΡΑΜΜΕΣ ΤΟΥ ΑΡΧΕΙΟΥ CMS ΓΙΑ ΤΟΝ ΓΕΝΙΚΟ ΕΠΙΝΑΥΛΟ — Η ΜΟΝΗ ΥΛΟΠΟΙΗΣΗ.
 *
 * Ενώνει δύο πηγές:
 *   4a_fuel_cms_services    κατάλογος, μία εγγραφή ανά υπηρεσία, σταθμό και
 *                           κατεύθυνση. Η λίστα zones ανοίγει σε μία γραμμή
 *                           ανά ζώνη, με τον κανόνα export/import.
 *   4a_fuel_cms_extra_rows  γραμμές που δεν ακολουθούν τον κανόνα, αυτούσιες.
 *
 * Ο κανόνας:
 *   export  Station=station, OriginZone κενό, OriginCountry=station,
 *           Zone=Zx, Delivery κενό
 *   import  Station=station, OriginZone=Zx, OriginCountry κενό,
 *           Zone κενό, Delivery=station
 *
 * Η σειρά είναι κατά sort_order, ώστε οι εξαιρέσεις να μπαίνουν στη θέση
 * τους μέσα στην ομάδα τους και όχι στο τέλος.
 *
 * ΤΟ FRONTEND ΔΕΝ ΞΑΝΑΫΠΟΛΟΓΙΖΕΙ. Η χρέωση υπολογίζεται εδώ, μία φορά, με
 * fuel_pct() και μορφοποιείται με fuel_num().
 *
 * @param PDO    $db
 * @param array  $sourcePct  ['air' => 48, 'road' => 40] από το cache.
 *                           Κενό σημαίνει χωρίς χρέωση, μόνο οι στήλες
 *                           ταυτότητας, για έλεγχο και απόδειξη.
 * @param string $weFrom     EffectiveDate σε μορφή DD-MON-YYYY.
 * @return array             λίστα associative γραμμών
 */
function fuel_cms_rows(PDO $db, array $sourcePct = array(), $weFrom = '')
{
    // Το λεξιλόγιο: ποια πηγή και ποιος πολλαπλασιαστής ανά τύπο.
    $types = array();
    foreach ($db->query('SELECT `code`, `source`, `multiplier` FROM `4a_fuel_types`
                          WHERE `active` = 1')->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $types[$t['code']] = $t;
    }

    $charge = function ($fuelType) use ($types, $sourcePct) {
        if (!isset($types[$fuelType])) return null;
        $src = $types[$fuelType]['source'];
        if ($src === null) return null;                  // τύπος NONE
        if (!array_key_exists($src, $sourcePct)) return null;
        return fuel_pct($sourcePct[$src], $types[$fuelType]['multiplier']);
    };

    $out = array();

    // 1. Κατάλογος, με τον κανόνα.
    $cat = $db->query('SELECT `cms_service`, `service_name`, `station_country`,
                              `direction`, `zones`, `fuel_type`, `is_combi`,
                              `visible_scope`, `sort_order`
                         FROM `4a_fuel_cms_services`
                        WHERE `active` = 1')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cat as $c) {
        $zones = array_values(array_filter(array_map('trim', explode(',', $c['zones'])), 'strlen'));
        $i = 0;
        foreach ($zones as $z) {
            $exp = ($c['direction'] === 'export');
            $out[] = array(
                'sort_order'      => (int)$c['sort_order'] + $i,
                'station'         => $c['station_country'],
                'origin_zone'     => $exp ? ''                     : $z,
                'origin_country'  => $exp ? $c['station_country']  : '',
                'zone'            => $exp ? $z                     : '',
                'delivery'        => $exp ? ''                     : $c['station_country'],
                'service'         => $c['cms_service'],
                'service_name'    => $c['service_name'],
                'fuel_type'       => $c['fuel_type'],
                'is_combi'        => (int)$c['is_combi'],
                'visible_scope'   => $c['visible_scope'],
                'charge'          => $charge($c['fuel_type']),
                'we_from'         => $weFrom,
                'source'          => 'rule',
            );
            $i++;
        }
    }

    // 2. Εξαιρέσεις, αυτούσιες.
    $ex = $db->query('SELECT `station_country`, `origin_zone`, `origin_country`,
                             `zone`, `delivery_country`, `cms_service`,
                             `service_name`, `fuel_type`, `sort_order`
                        FROM `4a_fuel_cms_extra_rows`
                       WHERE `active` = 1')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ex as $e) {
        $out[] = array(
            'sort_order'     => (int)$e['sort_order'],
            'station'        => $e['station_country'],
            'origin_zone'    => $e['origin_zone'],
            'origin_country' => $e['origin_country'],
            'zone'           => $e['zone'],
            'delivery'       => $e['delivery_country'],
            'service'        => $e['cms_service'],
            'service_name'   => $e['service_name'],
            'fuel_type'      => $e['fuel_type'],
            'is_combi'       => 0,
            'visible_scope'  => $e['station_country'],
            'charge'         => $charge($e['fuel_type']),
            'we_from'        => $weFrom,
            'source'         => 'extra',
        );
    }

    usort($out, function ($a, $b) {
        if ($a['sort_order'] !== $b['sort_order']) return $a['sort_order'] - $b['sort_order'];
        return strcmp($a['service'] . $a['zone'] . $a['origin_zone'],
                      $b['service'] . $b['zone'] . $b['origin_zone']);
    });

    return $out;
}

/**
 * Οι ίδιες γραμμές στις εννέα στήλες του αρχείου CMS, με τη σειρά που
 * χρησιμοποιεί το FuelChargeImport. Το ClientCode μένει κενό: το αρχείο
 * γενικού επίναυλου δεν αφορά συγκεκριμένο πελάτη.
 */
function fuel_cms_file($rows)
{
    $out = array(array('ClientCode', 'StationCountryCode', 'OriginZoneCode',
                       'OriginCountryCode', 'ZoneCode', 'DeliveryCountryCode',
                       'ServiceTypeCode', 'FuelCharge', 'EffectiveDate'));
    foreach ($rows as $r) {
        if ($r['charge'] === null) continue;        // τύπος NONE, καμία γραμμή
        $out[] = array('', $r['station'], $r['origin_zone'], $r['origin_country'],
                       $r['zone'], $r['delivery'], $r['service'],
                       fuel_num($r['charge']), $r['we_from']);
    }
    return $out;
}

} // function_exists
