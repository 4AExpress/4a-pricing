<?php
/* tests/php/fuel_lib.test.php
 *
 *   php tests/php/fuel_lib.test.php        (από τη ρίζα του repo)
 *
 * Καμία βάση, κανένα δίκτυο, κανένα config. Δοκιμάζει τις ΠΡΑΓΜΑΤΙΚΕΣ
 * συναρτήσεις του api/fuel_lib.php. Έξοδος 1 αν έστω ένας έλεγχος πέσει.
 */

require_once __DIR__ . '/../../api/fuel_lib.php';

$pass = 0; $fail = 0;
function ck($label, $got, $want) {
    global $pass, $fail;
    $ok = ($got === $want);
    $ok ? $pass++ : $fail++;
    printf("   %s %-46s got=%-10s want=%s\n",
        $ok ? 'OK   ' : 'ΛΑΘΟΣ',
        $label,
        $got === null ? 'NULL' : "'$got'",
        $want === null ? 'NULL' : "'$want'");
}

echo "\nbcmath: " . (function_exists('bcmul') ? 'ΥΠΑΡΧΕΙ' : '*** ΛΕΙΠΕΙ ***') . "\n";

echo "\n═══ fuel_pct: AIR_CY = AIR x 1.06, half-up στα 2 δεκαδικά ═══\n";
ck('46.25 x 1.06  (49.025 -> half-up)', fuel_pct(46.25, '1.0600'), '49.03');
ck('43.75 x 1.06  (46.375 -> half-up)', fuel_pct(43.75, '1.0600'), '46.38');
ck('48    x 1.06',                      fuel_pct(48,    '1.0600'), '50.88');
ck('49    x 1.06',                      fuel_pct(49,    '1.0600'), '51.94');

echo "\n═══ fuel_pct: πολλαπλασιαστής 1.0000 δεν αλλάζει τιμή ═══\n";
ck('48    x 1.00', fuel_pct(48,    '1.0000'), '48.00');
ck('40.75 x 1.00', fuel_pct(40.75, '1.0000'), '40.75');
ck('46.25 x 1.00', fuel_pct(46.25, '1.0000'), '46.25');

echo "\n═══ fuel_pct: ο τύπος NONE δεν παίρνει επίναυλο ═══\n";
ck('source NULL',     fuel_pct(null, '1.0000'), null);
ck('multiplier NULL', fuel_pct(48,   null),     null);
ck('και τα δύο NULL', fuel_pct(null, null),     null);

echo "\n═══ fuel_round: half-up, όχι στρογγυλοποίηση τραπεζίτη ═══\n";
ck('49.025 -> 49.03', fuel_round('49.025'), '49.03');
ck('49.015 -> 49.02', fuel_round('49.015'), '49.02');
ck('49.024 -> 49.02', fuel_round('49.024'), '49.02');
ck('49.026 -> 49.03', fuel_round('49.026'), '49.03');
ck('0.001  -> 0.00',  fuel_round('0.001'),  '0.00');
ck('μη αριθμός',      fuel_round('abc'),    null);
ck('κενό',            fuel_round(''),       null);

echo "\n═══ fuel_num: μορφή για το αρχείο CMS ═══\n";
ck('48    -> "48"',    fuel_num(48),      '48');
ck('40.75 -> "40.75"', fuel_num(40.75),   '40.75');
ck('50.88 -> "50.88"', fuel_num('50.88'), '50.88');
ck('49.03 -> "49.03"', fuel_num('49.03'), '49.03');
ck('40.5  -> "40.5"',  fuel_num(40.5),    '40.5');
ck('40.00 -> "40"',    fuel_num('40.00'), '40');
ck('0     -> "0"',     fuel_num(0),       '0');
ck('NULL  -> NULL',    fuel_num(null),    null);

echo "\n═══ ΑΛΥΣΙΔΑ: υπολογισμός και μετά μορφοποίηση ═══\n";
ck('AIR 48 -> AIR_CY -> αρχείο', fuel_num(fuel_pct(48, '1.0600')), '50.88');
ck('ROAD 40 -> ROAD -> αρχείο',  fuel_num(fuel_pct(40, '1.0000')), '40');
ck('AIR 46.25 -> AIR_CY',        fuel_num(fuel_pct(46.25, '1.0600')), '49.03');

// ── Dashboard: fuel_week_prices / fuel_catalog_cards ──────────────────────
// Το fixture του frontend (tests/frontend/fuel_catalog.fixture.json) έχει
// ΓΡΑΜΜΕΝΕΣ τιμές. Εδώ ελέγχεται ότι οι ΠΡΑΓΜΑΤΙΚΕΣ συναρτήσεις βγάζουν
// ακριβώς αυτό, ώστε τα harness του frontend να μη δοκιμάζουν φανταστική
// απάντηση του server.
$FX    = __DIR__ . '/../frontend/';
$cache = json_decode(file_get_contents($FX . 'fuel.fixture.099ce2e.json'), true);
$want  = json_decode(file_get_contents($FX . 'fuel_catalog.fixture.json'), true);

$types = array();
foreach ($want['types'] as $t) $types[$t['code']] = $t;

echo "\n═══ fuel_week_prices: τιμές ανά τύπο για κάθε εβδομάδα (fixture 099ce2e) ═══\n";
$weeks = fuel_week_prices($cache, $types);
ck('πλήθος εβδομάδων', (string)count($weeks), (string)count($want['weeks']));
foreach ($want['weeks'] as $i => $w) {
    $g = isset($weeks[$i]) ? $weeks[$i] : array('week_start' => null, 'pct' => array(), 'src' => array());
    ck("εβδ. $i week_start", $g['week_start'], $w['week_start']);
    foreach ($w['pct'] as $code => $v) {
        ck("{$w['week_start']} $code", isset($g['pct'][$code]) ? $g['pct'][$code] : null, $v);
    }
    // Η τιμή πηγής περνά αυτούσια: η σελίδα τη συγκρίνει με το δικό της cache.
    ck("{$w['week_start']} src air==",  ($g['src']['air']  == $w['src']['air'])  ? 'ίσο' : 'άνισο', 'ίσο');
    ck("{$w['week_start']} src road==", ($g['src']['road'] == $w['src']['road']) ? 'ίσο' : 'άνισο', 'ίσο');
}
ck('AIR 46.25 -> Air Cyprus 49.03', $weeks[2]['pct']['AIR_CY'], '49.03');

echo "\n═══ fuel_catalog_cards: μία κάρτα ανά υπηρεσία ανά σταθμό ═══\n";
// Οι 12 εγγραφές του seed του 2026-10-07c_fuel_cms_catalog.sql.
$seed = array();
foreach (array(
    array('S1003','Export Express','GR','export','Z1,Z2,Z3,Z4,Z5,Z6,Z7,Z9,Z10','AIR',0,'GR',10),
    array('S1012','IMPORT EXPRESS','GR','import','Z1,Z2,Z3,Z4,Z5,Z6,Z7','AIR',0,'GR',100),
    array('S1026','CARGO EXPORT','GR','export','Z1,Z2,Z3,Z4,Z5,Z6,Z7','AIR',0,'GR',170),
    array('S1029','Bio Express','GR','export','Z1,Z2,Z3,Z4,Z5,Z6,Z7,Z9,Z10','AIR',0,'GR',250),
    array('S1032','Airletter 500gr','GR','export','Z1,Z2,Z3,Z4,Z5,Z6,Z7,Z9,Z10','AIR',0,'GR',340),
    array('S1027','Air Cyprus','GR','export','Z9','AIR_CY',0,'GR',430),
    array('S1010','Road 4-8 export','GR','export','Z1,Z2,Z3','ROAD',0,'GR',440),
    array('S1041','IMPORT ROAD','GR','import','Z1,Z2,Z3','ROAD',0,'GR',470),
    array('S1003','Export Express','CY','export','Z1,Z2,Z3,Z4,Z5,Z6,Z7','AIR',0,'CY',500),
    array('S1012','IMPORT EXPRESS','CY','import','Z1,Z2,Z3,Z4,Z5,Z6,Z7','AIR',0,'CY',570),
    array('S1050','EXPORTAIRANDROAD','CY','export','Z1,Z2,Z3','ROAD',1,'CY',640),
    array('S1051','IMPORT ROAD AND AIR','CY','import','Z1,Z2,Z3','ROAD',1,'CY',670),
) as $r) {
    $seed[] = array_combine(array('cms_service','service_name','station_country','direction','zones',
                                  'fuel_type','is_combi','visible_scope','sort_order'), $r);
}
$cards = fuel_catalog_cards($seed, $types);
ck('ίδιες κάρτες με το fixture (12)', json_encode($cards), json_encode($want['cards']));

// Export και import της ίδιας υπηρεσίας στον ίδιο σταθμό → μία κάρτα.
$two = fuel_catalog_cards(array(
    array_merge($seed[0], array('direction' => 'export', 'zones' => 'Z1,Z2', 'sort_order' => 20)),
    array_merge($seed[0], array('direction' => 'import', 'zones' => 'Z2,Z3', 'sort_order' => 10)),
), $types);
ck('export+import -> μία κάρτα',       (string)count($two), '1');
ck('ζώνες ενωμένες, χωρίς διπλές',     implode(',', $two[0]['zones']), 'Z1,Z2,Z3');
ck('sort_order = το μικρότερο',        (string)$two[0]['sort_order'], '10');

$threw = 'όχι';
try { fuel_catalog_cards(array(array_merge($seed[0], array('fuel_type' => 'XYZ'))), $types); }
catch (RuntimeException $e) { $threw = 'ναι'; }
ck('άγνωστος fuel_type -> σφάλμα, όχι σιωπή', $threw, 'ναι');

$threw = 'όχι';
try { fuel_catalog_cards(array($seed[0], array_merge($seed[0], array('direction' => 'import', 'fuel_type' => 'ROAD'))), $types); }
catch (RuntimeException $e) { $threw = 'ναι'; }
ck('δύο fuel_type στην ίδια κάρτα -> σφάλμα', $threw, 'ναι');

// ── Μνήμη cache: έλεγχος περιεχομένου πριν από το REPLACE ──────────────────
echo "\n═══ fuel_cache_invalid: έλεγχος περιεχομένου, όχι μόνο έγκυρο JSON ═══\n";
$bad = function ($mut) use ($cache) { $c = $cache; $mut($c); return fuel_cache_invalid($c); };
ck('fixture 099ce2e -> έγκυρο',            fuel_cache_invalid($cache), null);
ck('κενό air -> άκυρο',                    $bad(function (&$c) { $c['air'] = array(); }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('κενό road -> άκυρο',                   $bad(function (&$c) { $c['road'] = array(); }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('λείπει road -> άκυρο',                 $bad(function (&$c) { unset($c['road']); }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('air όχι πίνακας -> άκυρο',             $bad(function (&$c) { $c['air'] = 'x'; }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('λείπει fetched_at -> άκυρο',           $bad(function (&$c) { unset($c['fetched_at']); }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('κενό fetched_at -> άκυρο',             $bad(function (&$c) { $c['fetched_at'] = ' '; }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('εβδομάδα χωρίς week_start -> άκυρο',   $bad(function (&$c) { unset($c['air'][1]['week_start']); }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('εβδομάδα χωρίς week_end -> άκυρο',     $bad(function (&$c) { unset($c['road'][4]['week_end']); }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('week_end όχι ημερομηνία -> άκυρο',     $bad(function (&$c) { $c['air'][0]['week_end'] = 'Οκτ 18'; }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('εβδομάδα χωρίς pct -> άκυρο',          $bad(function (&$c) { unset($c['air'][2]['pct']); }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('pct όχι αριθμός -> άκυρο',             $bad(function (&$c) { $c['road'][0]['pct'] = 'n/a'; }) !== null ? 'άκυρο' : 'έγκυρο', 'άκυρο');
ck('ο λόγος κατονομάζει το πρόβλημα',      $bad(function (&$c) { $c['air'] = array(); }), 'κενός ή άκυρος πίνακας air');
ck('ο λόγος δείχνει θέση εβδομάδας',       $bad(function (&$c) { unset($c['road'][4]['week_end']); }), 'road[4] χωρίς έγκυρο week_end');
ck('null -> άκυρο',                        fuel_cache_invalid(null), 'όχι αντικείμενο JSON');

echo "\n═══ fuel_cache_load: πότε γράφει και τι κρατά ═══\n";
$GOOD    = json_encode($cache);
$c2      = $cache; $c2['air'] = array();
$EMPTY   = json_encode($c2);
$OLDBODY = json_encode(array_merge($cache, array('fetched_at' => '2026-10-01T07:00:00')));
// Τρέχει τη fuel_cache_load με ψεύτικες εξαρτήσεις και καταγράφει τι έγινε.
$run = function ($row, $fetchRes, $writeThrows = false) {
    $o = (object)array('writes' => array(), 'fetches' => 0, 'logs' => array());
    list($data, $meta) = fuel_cache_load(
        function () use ($row) { if ($row instanceof Exception) throw $row; return $row; },
        function () use ($o, $fetchRes) { $o->fetches++; return $fetchRes; },
        function ($b) use ($o, $writeThrows) { if ($writeThrows) throw new RuntimeException('σφάλμα εγγραφής'); $o->writes[] = $b; return '2026-10-07 10:00:00'; },
        function ($m) use ($o) { $o->logs[] = $m; }
    );
    $o->data = $data; $o->meta = $meta;
    return $o;
};
$oldRow   = array('body' => $OLDBODY, 'read_at' => '2026-10-07 09:00:00', 'fresh' => '0');
$freshRow = array('body' => $OLDBODY, 'read_at' => '2026-10-07 09:00:00', 'fresh' => '1');

$r = $run($oldRow, array(200, $EMPTY, ''));
ck('κενό air από GitHub -> ΔΕΝ γράφει',            (string)count($r->writes), '0');
ck('κενό air -> κρατά το προηγούμενο καλό',        $r->data['fetched_at'], '2026-10-01T07:00:00');
ck('κενό air -> stale=true',                       $r->meta['stale'] ? 'true' : 'false', 'true');
ck('κενό air -> error_log με τον λόγο',            (string)(int)(count($r->logs) === 1 && strpos($r->logs[0], 'κενός ή άκυρος πίνακας air') !== false), '1');

$r = $run(false, array(200, $EMPTY, ''));
ck('κενό air, χωρίς αντίγραφο -> ΔΕΝ γράφει',      (string)count($r->writes), '0');
ck('κενό air, χωρίς αντίγραφο -> null, ok=false',  ($r->data === null && $r->meta['ok'] === false) ? 'ναι' : 'όχι', 'ναι');

$r = $run($oldRow, array(200, $GOOD, ''));
ck('καλό από GitHub -> γράφει μία φορά',           (string)count($r->writes), '1');
ck('γράφει το body αυτούσιο',                      (string)(int)($r->writes[0] === $GOOD), '1');
ck('καλό -> read_at = αυτό που γράφτηκε',          $r->meta['read_at'], '2026-10-07 10:00:00');
ck('καλό -> νέα δεδομένα, stale=false',            $r->data['fetched_at'] . '/' . ($r->meta['stale'] ? 'true' : 'false'), $cache['fetched_at'] . '/false');

$r = $run($freshRow, array(200, $GOOD, ''));
ck('φρέσκια γραμμή -> καμία κλήση GitHub',         (string)$r->fetches, '0');
ck('φρέσκια γραμμή -> καμία εγγραφή',              (string)count($r->writes), '0');
ck('φρέσκια γραμμή -> τα αποθηκευμένα',            $r->data['fetched_at'], '2026-10-01T07:00:00');
ck('φρέσκια γραμμή -> read_at της γραμμής',        $r->meta['read_at'], '2026-10-07 09:00:00');

$r = $run(array('body' => $EMPTY, 'read_at' => '2026-10-07 09:00:00', 'fresh' => '1'), array(200, $GOOD, ''));
ck('φρέσκια αλλά άκυρη γραμμή -> ξαναδιαβάζει',    (string)$r->fetches, '1');
ck('φρέσκια αλλά άκυρη γραμμή -> γράφει το καλό',  (string)count($r->writes), '1');

$r = $run($oldRow, array(500, 'Server Error', ''));
ck('HTTP 500 -> ΔΕΝ γράφει',                       (string)count($r->writes), '0');
ck('HTTP 500 -> το προηγούμενο, stale=true',       $r->data['fetched_at'] . '/' . ($r->meta['stale'] ? 'true' : 'false'), '2026-10-01T07:00:00/true');

$r = $run($oldRow, array(200, '<html>rate limited</html>', ''));
ck('μη-JSON με HTTP 200 -> ΔΕΝ γράφει',            (string)count($r->writes), '0');

$r = $run($oldRow, array(0, false, 'Could not resolve host'));
ck('χωρίς δίκτυο -> ΔΕΝ γράφει, κρατά το παλιό',   count($r->writes) . '/' . $r->data['fetched_at'], '0/2026-10-01T07:00:00');

$r = $run(new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table '4a_fuel_cache_mem' doesn't exist"), array(200, $GOOD, ''));
ck('λείπει ο πίνακας -> null (weeks: [])',         $r->data === null ? 'null' : 'δεδομένα', 'null');
ck('λείπει ο πίνακας -> καμία κλήση GitHub',       (string)$r->fetches, '0');
ck('λείπει ο πίνακας -> καμία εγγραφή',            (string)count($r->writes), '0');
ck('λείπει ο πίνακας -> error_log με το migration', (string)(int)(count($r->logs) === 1 && strpos($r->logs[0], '2026-10-07d') !== false), '1');

$r = $run($oldRow, array(200, $GOOD, ''), true);
ck('η εγγραφή αποτυγχάνει -> δεδομένα από GitHub', $r->data['fetched_at'], $cache['fetched_at']);
ck('η εγγραφή αποτυγχάνει -> read_at null',        $r->meta['read_at'], null);
ck('η εγγραφή αποτυγχάνει -> error_log',           (string)count($r->logs), '1');

printf("\n═══ ΣΥΝΟΛΟ: %d OK, %d ΛΑΘΟΣ ═══\n\n", $pass, $fail);
exit($fail ? 1 : 0);
