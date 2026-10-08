<?php
/* tests/php/fuel_weekly.test.php
 *
 *   php tests/php/fuel_weekly.test.php        (από τη ρίζα του repo)
 *
 * Το στιγμιότυπο της εργασίας fuel_weekly (api/fuel_lib.php):
 * fuel_effective_date, fuel_weekly_due_at, fuel_weekly_payload.
 *
 * Ο κατάλογος CMS είναι οι 12 εγγραφές και η 1 εξαίρεση του migration
 * 2026-10-07c, σε SQLite στη μνήμη. Το cache είναι το παγωμένο
 * tests/frontend/fuel.fixture.099ce2e.json (εβδομάδα 2026-10-12: AIR 49,
 * ROAD 40.75). Καμία πραγματική βάση, κανένα δίκτυο. Χρειάζεται pdo_sqlite.
 */

require_once __DIR__ . '/../../api/fuel_lib.php';

$pass = 0; $fail = 0;
function ck($label, $ok, $extra = '')
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("   %s %s%s\n", $ok ? 'OK   ' : 'ΛΑΘΟΣ', $label, $ok ? '' : "   → $extra");
}

echo "\n═══ fuel_effective_date: dd-Mon-yyyy ═══\n";
ck('2026-10-12 -> 12-Oct-2026', fuel_effective_date('2026-10-12') === '12-Oct-2026', fuel_effective_date('2026-10-12'));
ck('2026-01-05 -> 05-Jan-2026', fuel_effective_date('2026-01-05') === '05-Jan-2026');
ck('άκυρη ημερομηνία -> null', fuel_effective_date('2026-02-30') === null);
ck('κενό -> null', fuel_effective_date('') === null);

echo "\n═══ fuel_weekly_due_at: Παρασκευή 17:00 Αθήνας πριν το week_start ═══\n";
ck('2026-10-12 (Δευ) -> 2026-10-09 17:00', fuel_weekly_due_at('2026-10-12') === '2026-10-09 17:00:00', fuel_weekly_due_at('2026-10-12'));
ck('2026-10-26 (Δευ, μετά την αλλαγή ώρας) -> 2026-10-23 17:00', fuel_weekly_due_at('2026-10-26') === '2026-10-23 17:00:00');
ck('2026-10-16 (Παρ) -> η ΠΡΟΗΓΟΥΜΕΝΗ Παρασκευή 2026-10-09', fuel_weekly_due_at('2026-10-16') === '2026-10-09 17:00:00', fuel_weekly_due_at('2026-10-16'));
ck('2027-01-04 (Δευ) -> 2027-01-01 17:00 (αλλαγή έτους)', fuel_weekly_due_at('2027-01-04') === '2027-01-01 17:00:00');
ck('άκυρη -> null', fuel_weekly_due_at('x') === null);

echo "\n═══ fuel_weekly_payload: εβδομάδα 2026-10-12 από το fixture 099ce2e ═══\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE `4a_fuel_types` (`code` TEXT PRIMARY KEY, `label_el` TEXT, `label_en` TEXT,
           `source` TEXT, `multiplier` TEXT, `sort_order` INTEGER, `active` INTEGER)');
$db->exec("INSERT INTO `4a_fuel_types` VALUES
  ('AIR','Αεροπορικός','Air','air','1.0000',10,1), ('ROAD','Οδικός','Road','road','1.0000',20,1),
  ('AIR_CY','Air Cyprus','Air Cyprus','air','1.0600',30,1), ('NONE','Χωρίς επίναυλο','No fuel surcharge',NULL,NULL,40,1)");
$db->exec('CREATE TABLE `4a_fuel_cms_services` (`id` INTEGER PRIMARY KEY, `cms_service` TEXT, `service_name` TEXT,
           `station_country` TEXT, `direction` TEXT, `zones` TEXT, `fuel_type` TEXT, `is_combi` INTEGER,
           `visible_scope` TEXT, `sort_order` INTEGER, `active` INTEGER)');
$db->exec("INSERT INTO `4a_fuel_cms_services`
  (`cms_service`,`service_name`,`station_country`,`direction`,`zones`,`fuel_type`,`is_combi`,`visible_scope`,`sort_order`,`active`) VALUES
  ('S1003','Export Express','GR','export','Z1,Z2,Z3,Z4,Z5,Z6,Z7,Z9,Z10','AIR',0,'GR',10,1),
  ('S1012','IMPORT EXPRESS','GR','import','Z1,Z2,Z3,Z4,Z5,Z6,Z7','AIR',0,'GR',100,1),
  ('S1026','CARGO EXPORT','GR','export','Z1,Z2,Z3,Z4,Z5,Z6,Z7','AIR',0,'GR',170,1),
  ('S1029','Bio Express','GR','export','Z1,Z2,Z3,Z4,Z5,Z6,Z7,Z9,Z10','AIR',0,'GR',250,1),
  ('S1032','Airletter 500gr','GR','export','Z1,Z2,Z3,Z4,Z5,Z6,Z7,Z9,Z10','AIR',0,'GR',340,1),
  ('S1027','Air Cyprus','GR','export','Z9','AIR_CY',0,'GR',430,1),
  ('S1010','Road 4-8 export','GR','export','Z1,Z2,Z3','ROAD',0,'GR',440,1),
  ('S1041','IMPORT ROAD','GR','import','Z1,Z2,Z3','ROAD',0,'GR',470,1),
  ('S1003','Export Express','CY','export','Z1,Z2,Z3,Z4,Z5,Z6,Z7','AIR',0,'CY',500,1),
  ('S1012','IMPORT EXPRESS','CY','import','Z1,Z2,Z3,Z4,Z5,Z6,Z7','AIR',0,'CY',570,1),
  ('S1050','EXPORTAIRANDROAD','CY','export','Z1,Z2,Z3','ROAD',1,'CY',640,1),
  ('S1051','IMPORT ROAD AND AIR','CY','import','Z1,Z2,Z3','ROAD',1,'CY',670,1)");
$db->exec('CREATE TABLE `4a_fuel_cms_extra_rows` (`id` INTEGER PRIMARY KEY, `station_country` TEXT, `origin_zone` TEXT,
           `origin_country` TEXT, `zone` TEXT, `delivery_country` TEXT, `cms_service` TEXT, `service_name` TEXT,
           `fuel_type` TEXT, `note` TEXT, `sort_order` INTEGER, `active` INTEGER)');
$db->exec("INSERT INTO `4a_fuel_cms_extra_rows`
  (`station_country`,`origin_zone`,`origin_country`,`zone`,`delivery_country`,`cms_service`,`service_name`,`fuel_type`,`note`,`sort_order`,`active`)
  VALUES ('GR','','GR','Z5','BG','S1026','CARGO EXPORT','AIR','ειδική γραμμή Βουλγαρίας',240,1)");

$types = array();
foreach ($db->query('SELECT `code`,`label_el`,`label_en`,`source`,`multiplier` FROM `4a_fuel_types`')->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $types[$t['code']] = $t;
}
$cache = json_decode(file_get_contents(__DIR__ . '/../frontend/fuel.fixture.099ce2e.json'), true);
$p = fuel_weekly_payload($db, $cache, $types, '2026-10-12');

ck('payload υπάρχει', is_array($p));
ck('kind fuel_weekly', $p['kind'] === 'fuel_weekly');
ck('week_start/week_end', $p['week_start'] === '2026-10-12' && $p['week_end'] === '2026-10-18');
ck('effective_date 12-Oct-2026', $p['effective_date'] === '12-Oct-2026', $p['effective_date']);
ck('τιμές: AIR 49.00, ROAD 40.75, AIR_CY 51.94, NONE null',
   $p['prices'] === array('AIR' => '49.00', 'ROAD' => '40.75', 'AIR_CY' => '51.94', 'NONE' => null), json_encode($p['prices']));
ck('rows_count 69', $p['rows_count'] === 69, $p['rows_count']);
$rows = $p['file']['rows'];
ck('φύλλο Table', $p['file']['sheet'] === 'Table');
ck('70 γραμμές αρχείου (κεφαλίδα + 69)', count($rows) === 70, count($rows));
ck('κεφαλίδα 9 στηλών', $rows[0] === array('ClientCode','StationCountryCode','OriginZoneCode','OriginCountryCode',
                                          'ZoneCode','DeliveryCountryCode','ServiceTypeCode','FuelCharge','EffectiveDate'));
$allStr = true; $all9 = true;
foreach ($rows as $r) { if (count($r) !== 9) $all9 = false; foreach ($r as $v) if (!is_string($v)) $allStr = false; }
ck('κάθε γραμμή 9 στήλες', $all9);
ck('ΟΛΑ τα κελιά string', $allStr);
$charges = array_count_values(array_map(function ($r) { return $r[7]; }, array_slice($rows, 1)));
ksort($charges);
ck('FuelCharge: 40.75 ×12, 49 ×56, 51.94 ×1 (χωρίς μηδενικά στο τέλος)',
   $charges === array('40.75' => 12, '49' => 56, '51.94' => 1), json_encode($charges));
$eff = array_unique(array_map(function ($r) { return $r[8]; }, array_slice($rows, 1)));
ck('EffectiveDate 12-Oct-2026 σε όλες', array_values($eff) === array('12-Oct-2026'), json_encode(array_values($eff)));
ck('ClientCode κενό σε όλες', count(array_filter(array_slice($rows, 1), function ($r) { return $r[0] !== ''; })) === 0);
$bg = array_values(array_filter($rows, function ($r) { return $r[5] === 'BG'; }));
ck('η γραμμή Βουλγαρίας: GR,,GR,Z5,BG,S1026,49',
   count($bg) === 1 && array_slice($bg[0], 1, 7) === array('GR', '', 'GR', 'Z5', 'BG', 'S1026', '49'), json_encode($bg));
$cy = array_values(array_filter($rows, function ($r) { return $r[6] === 'S1027'; }));
ck('S1027 Air Cyprus: GR,,GR,Z9,,51.94', count($cy) === 1 && array_slice($cy[0], 1, 7) === array('GR', '', 'GR', 'Z9', '', 'S1027', '51.94'), json_encode($cy));

// Σύγκριση ταυτότητας με το seed CSV (ό,τι δέχτηκε το CMS 07-10-2026), ως σύνολο.
$csv = array_map('str_getcsv', array_filter(preg_split('/\r?\n/', file_get_contents(__DIR__ . '/../../db/seeds/fuel_cms_rows_seed.csv')), 'strlen'));
$h = array_shift($csv);
$want = array();
foreach ($csv as $r) { $r = array_combine($h, $r);
    $want[] = implode('|', array($r['station_country'], $r['origin_zone'], $r['origin_country'], $r['zone'], $r['delivery_country'], $r['cms_service'])); }
$got = array_map(function ($r) { return implode('|', array_slice($r, 1, 6)); }, array_slice($rows, 1));
ck('ταυτότητα γραμμών = seed CSV: 69 = 69, επιπλέον 0, λείπουν 0, διπλές 0',
   count($got) === 69 && !array_diff($got, $want) && !array_diff($want, $got) && count(array_unique($got)) === 69,
   count($got) . ' / +' . count(array_diff($got, $want)) . ' / -' . count(array_diff($want, $got)));

ck('εβδομάδα που λείπει από το cache -> null', fuel_weekly_payload($db, $cache, $types, '2026-11-02') === null);

// Το fixture του frontend (tests/frontend/fuel_weekly_payload.fixture.json)
// παράχθηκε από ΑΥΤΗ τη συνάρτηση. Εδώ ελέγχεται ότι ταυτίζεται ακόμα, ώστε
// το harness του tasks.html να μη δοκιμάζει φανταστικό payload.
$fx = json_decode(file_get_contents(__DIR__ . '/../frontend/fuel_weekly_payload.fixture.json'), true);
ck('payload = tests/frontend/fuel_weekly_payload.fixture.json', $fx === json_decode(json_encode($p), true));

printf("\n═══ ΣΥΝΟΛΟ: %d OK, %d ΛΑΘΟΣ ═══\n\n", $pass, $fail);
exit($fail ? 1 : 0);
