<?php
/* api/fuel_lib.php | v1.1 | 07-10-2026
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

/**
 * ΚΑΡΤΕΣ ΤΟΥ DASHBOARD: ο κατάλογος CMS ανά σταθμό και υπηρεσία, όχι ανά
 * γραμμή. Μία κάρτα ενώνει export και import της ίδιας υπηρεσίας στον ίδιο
 * σταθμό. Οι εξαιρέσεις (4a_fuel_cms_extra_rows) δεν φτιάχνουν κάρτα: είναι
 * γραμμές αρχείου, όχι υπηρεσίες.
 *
 * Καθαρή συνάρτηση, χωρίς βάση, ώστε να δοκιμάζεται.
 *
 * @param array $catRows  γραμμές του 4a_fuel_cms_services
 * @param array $types    4a_fuel_types με κλειδί το code
 * @return array          κάρτες κατά σταθμό και sort_order
 * @throws RuntimeException αν μία υπηρεσία έχει δύο fuel_type ή άγνωστο τύπο
 */
function fuel_catalog_cards(array $catRows, array $types)
{
    $cards = array();
    foreach ($catRows as $c) {
        $k = $c['station_country'] . '|' . $c['cms_service'];
        if (!isset($types[$c['fuel_type']])) {
            throw new RuntimeException("άγνωστος fuel_type '{$c['fuel_type']}' στην {$k}");
        }
        if (!isset($cards[$k])) {
            $cards[$k] = array(
                'station'       => $c['station_country'],
                'cms_service'   => $c['cms_service'],
                'service_name'  => $c['service_name'],
                'fuel_type'     => $c['fuel_type'],
                'fuel_label'    => $types[$c['fuel_type']]['label_el'],
                'directions'    => array(),
                'zones'         => array(),
                'is_combi'      => 0,
                'visible_scope' => $c['visible_scope'],
                'sort_order'    => (int)$c['sort_order'],
            );
        }
        $card =& $cards[$k];
        // Καμία σιωπηλή επιλογή: δύο τύποι για την ίδια κάρτα είναι λάθος δεδομένων.
        if ($card['fuel_type'] !== $c['fuel_type']) {
            throw new RuntimeException("δύο fuel_type στην {$k}");
        }
        if ($card['visible_scope'] !== $c['visible_scope']) {
            throw new RuntimeException("δύο visible_scope στην {$k}");
        }
        $card['directions'][] = $c['direction'];
        foreach (explode(',', $c['zones']) as $z) {
            $z = trim($z);
            if ($z !== '' && !in_array($z, $card['zones'], true)) $card['zones'][] = $z;
        }
        if ((int)$c['is_combi']) $card['is_combi'] = 1;
        $card['sort_order'] = min($card['sort_order'], (int)$c['sort_order']);
        unset($card);
    }

    $out = array_values($cards);
    usort($out, function ($a, $b) {
        if ($a['station'] !== $b['station']) return strcmp($b['station'], $a['station']); // GR πριν CY
        return $a['sort_order'] - $b['sort_order'];
    });
    return $out;
}

/**
 * ΤΙΜΕΣ ΑΝΑ ΤΥΠΟ ΓΙΑ ΚΑΘΕ ΕΒΔΟΜΑΔΑ ΤΟΥ CACHE, με τη fuel_pct() — το ίδιο
 * σημείο υπολογισμού με το αρχείο CMS. Το frontend μόνο εμφανίζει.
 *
 * Το `src` κρατά την τιμή πηγής όπως ήρθε, ώστε η σελίδα να ελέγξει ότι
 * μιλά για την ίδια εβδομάδα με την ίδια τιμή. Αν ο browser και ο server
 * έχουν διαφορετική έκδοση του cache, η σελίδα δείχνει «—», δεν υπολογίζει.
 *
 * @param array $cache  το fuel_surcharge_cache.json αποκωδικοποιημένο
 * @param array $types  4a_fuel_types με κλειδί το code
 * @return array        εβδομάδες με την ίδια σειρά που εμφανίζονται στο cache
 */
function fuel_week_prices(array $cache, array $types)
{
    $weeks = array();
    foreach (array('air', 'road') as $src) {
        $rows = (isset($cache[$src]) && is_array($cache[$src])) ? $cache[$src] : array();
        foreach ($rows as $r) {
            if (empty($r['week_start']) || !isset($r['pct']) || !is_numeric($r['pct'])) continue;
            $ws = $r['week_start'];
            if (!isset($weeks[$ws])) {
                $weeks[$ws] = array(
                    'week_start' => $ws,
                    'week_end'   => isset($r['week_end']) ? $r['week_end'] : '',
                    'week'       => isset($r['week']) ? $r['week'] : '',
                    'src'        => array('air' => null, 'road' => null),
                    'pct'        => array(),
                );
            }
            $weeks[$ws]['src'][$src] = $r['pct'];
        }
    }
    foreach ($weeks as $ws => $w) {
        foreach ($types as $code => $t) {
            $s = $t['source'];
            $weeks[$ws]['pct'][$code] = ($s !== null && $w['src'][$s] !== null)
                ? fuel_pct($w['src'][$s], $t['multiplier'])
                : null;
        }
    }
    return array_values($weeks);
}

/**
 * ΕΛΕΓΧΟΣ ΠΕΡΙΕΧΟΜΕΝΟΥ του cache επίναυλου, όχι μόνο έγκυρου JSON.
 * Ένα αρχείο που πέρασε το json_decode αλλά ήρθε άδειο ή μισό δεν πρέπει
 * να αντικαταστήσει το προηγούμενο καλό αντίγραφο.
 *
 * @param  mixed $j  το αποκωδικοποιημένο JSON
 * @return string|null  ο λόγος απόρριψης, ή null αν είναι εντάξει
 */
function fuel_cache_invalid($j)
{
    if (!is_array($j))                                      return 'όχι αντικείμενο JSON';
    if (!isset($j['fetched_at']) || !is_string($j['fetched_at']) || trim($j['fetched_at']) === '')
                                                            return 'λείπει fetched_at';
    foreach (array('air', 'road') as $src) {
        if (!isset($j[$src]) || !is_array($j[$src]) || !count($j[$src]))
                                                            return "κενός ή άκυρος πίνακας $src";
        foreach ($j[$src] as $i => $r) {
            if (!is_array($r))                              return "{$src}[{$i}] όχι αντικείμενο";
            foreach (array('week_start', 'week_end') as $k) {
                if (!isset($r[$k]) || !is_string($r[$k]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $r[$k]))
                                                            return "{$src}[{$i}] χωρίς έγκυρο $k";
            }
            if (!isset($r['pct']) || !is_numeric($r['pct'])) return "{$src}[{$i}] χωρίς αριθμητικό pct";
        }
    }
    return null;
}

/**
 * ΤΟ CACHE ΕΠΙΝΑΥΛΟΥ ΓΙΑ ΤΟ DASHBOARD, με μνήμη στον πίνακα 4a_fuel_cache_mem.
 *
 * Οι εξαρτήσεις περνούν ως callables, ώστε η λογική να δοκιμάζεται χωρίς
 * βάση και χωρίς δίκτυο:
 *   $readRow()      → ['body','read_at','fresh'] | false. Πετά αν λείπει ο πίνακας.
 *   $fetch()        → [int $httpCode, string|false $body, string $err]
 *   $write($body)   αντικαθιστά τη γραμμή id=1, επιστρέφει το read_at που γράφτηκε
 *   $log($msg)      error_log
 *
 * Κανόνες:
 *   - πίνακας που λείπει ή σφάλμα ανάγνωσης → null, καμία κλήση GitHub
 *   - φρέσκια γραμμή με έγκυρο περιεχόμενο → αυτή
 *   - αλλιώς GitHub. Γράφεται ΜΟΝΟ αν περάσει τη fuel_cache_invalid()
 *   - αποτυχία GitHub ή κακό περιεχόμενο → το προηγούμενο καλό αντίγραφο
 *     με stale=true, αλλιώς null
 *
 * @return array [ array|null $cache, array $meta ]
 */
function fuel_cache_load($readRow, $fetch, $write, $log)
{
    $meta = function ($j, $stale, $readAt) {
        return array('ok' => $j !== null, 'stale' => $stale,
                     'fetched_at' => $j !== null ? $j['fetched_at'] : null, 'read_at' => $readAt);
    };

    try {
        $row = $readRow();
    } catch (Throwable $e) {
        $log('fuel_catalog: ανάγνωση 4a_fuel_cache_mem απέτυχε (λείπει το migration 2026-10-07d;): ' . $e->getMessage());
        return array(null, $meta(null, false, null));
    }

    $old = $row ? json_decode($row['body'], true) : null;
    if ($old !== null && ($why = fuel_cache_invalid($old)) !== null) {
        $log("fuel_catalog: το αποθηκευμένο αντίγραφο είναι άκυρο: $why");
        $old = null;
    }
    if ($old !== null && (int)$row['fresh']) return array($old, $meta($old, false, $row['read_at']));

    list($code, $body, $err) = $fetch();
    $new = ($code === 200 && is_string($body)) ? json_decode($body, true) : null;
    $why = ($code === 200 && is_string($body)) ? fuel_cache_invalid($new) : "HTTP $code $err";

    if ($why === null) {
        $readAt = null;
        try {
            $readAt = $write($body);
        } catch (Throwable $e) {
            $log('fuel_catalog: εγγραφή 4a_fuel_cache_mem απέτυχε: ' . $e->getMessage());
        }
        return array($new, $meta($new, false, is_string($readAt) ? $readAt : null));
    }

    $log("fuel_catalog: το cache του GitHub απορρίφθηκε, δεν αντικαθίσταται: $why");
    if ($old !== null) return array($old, $meta($old, true, $row['read_at']));
    return array(null, $meta(null, false, null));
}

// ─────────────────────────────────────────────────────────────────────
// ΕΒΔΟΜΑΔΙΑΙΑ ΕΡΓΑΣΙΑ ΓΕΝΙΚΟΥ ΕΠΙΝΑΥΛΟΥ
// ─────────────────────────────────────────────────────────────────────

/**
 * EffectiveDate του αρχείου CMS: dd-Mon-yyyy με αγγλικό μήνα, όπως το
 * δέχτηκε το CMS. 2026-10-12 -> «12-Oct-2026». null αν δεν είναι ημερομηνία.
 */
function fuel_effective_date($weekStart)
{
    $d = DateTime::createFromFormat('!Y-m-d', (string)$weekStart, new DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d') !== $weekStart) return null;
    return $d->format('d-M-Y');
}

/**
 * Προθεσμία: η Παρασκευή πριν το week_start, 17:00 ώρα Αθήνας.
 * Επιστρέφεται ως ώρα Αθήνας «Y-m-d H:i:s», έτοιμη για DATETIME.
 * 2026-10-12 (Δευτέρα) -> «2026-10-09 17:00:00».
 */
function fuel_weekly_due_at($weekStart)
{
    $tz = new DateTimeZone('Europe/Athens');
    $d  = DateTime::createFromFormat('!Y-m-d', (string)$weekStart, $tz);
    if (!$d || $d->format('Y-m-d') !== $weekStart) return null;
    $d->modify('-1 day');                                   // ποτέ η ίδια μέρα
    while ($d->format('N') !== '5') $d->modify('-1 day');   // 5 = Παρασκευή
    $d->setTime(17, 0, 0);
    return $d->format('Y-m-d H:i:s');
}

/**
 * ΤΟ ΣΤΙΓΜΙΟΤΥΠΟ μιας εβδομάδας για την εργασία fuel_weekly. Ό,τι
 * χρειάζεται η οθόνη και το αρχείο CMS, υπολογισμένο ΕΔΩ, μία φορά. Το
 * frontend φτιάχνει το xlsx ΜΟΝΟ από αυτό, χωρίς να ξαναϋπολογίζει.
 *
 *   week          ετικέτα, έναρξη, λήξη, από το cache
 *   source        οι τιμές πηγής air/road όπως ήρθαν
 *   prices        τιμή ανά fuel_type, από τη fuel_week_prices()
 *   effective_date dd-Mon-yyyy
 *   file.rows     οι γραμμές του αρχείου: κεφαλίδα + μία ανά γραμμή CMS,
 *                 9 στήλες, ΟΛΑ string, αριθμοί από τη fuel_num()
 *
 * @return array|null  null αν το cache δεν έχει την εβδομάδα
 */
function fuel_weekly_payload(PDO $db, array $cache, array $types, $weekStart)
{
    $week = null;
    foreach (fuel_week_prices($cache, $types) as $w) {
        if ($w['week_start'] === $weekStart) { $week = $w; break; }
    }
    if ($week === null || $week['src']['air'] === null || $week['src']['road'] === null) return null;

    $eff  = fuel_effective_date($weekStart);
    $rows = fuel_cms_rows($db, array('air' => $week['src']['air'], 'road' => $week['src']['road']), $eff);
    $file = fuel_cms_file($rows);
    foreach ($file as $i => $r) $file[$i] = array_map('strval', $r);

    return array(
        'kind'           => 'fuel_weekly',
        'week_start'     => $week['week_start'],
        'week_end'       => $week['week_end'],
        'week'           => $week['week'],
        'source'         => $week['src'],
        'prices'         => $week['pct'],
        'effective_date' => $eff,
        'fetched_at'     => isset($cache['fetched_at']) ? $cache['fetched_at'] : null,
        'rows_count'     => count($file) - 1,
        'file'           => array('sheet' => 'Table', 'rows' => $file),
    );
}

} // function_exists
