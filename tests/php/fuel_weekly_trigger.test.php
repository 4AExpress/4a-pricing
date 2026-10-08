<?php
/* tests/php/fuel_weekly_trigger.test.php
 *
 *   php tests/php/fuel_weekly_trigger.test.php        (από τη ρίζα του repo)
 *
 * Το έναυσμα της εβδομαδιαίας εργασίας (api/fuel_weekly.php): ποια
 * εβδομάδα, ιδεμποτεντικότητα, δοκιμή χωρίς εγγραφή, γραμμή log. Πάνω στις
 * ΠΡΑΓΜΑΤΙΚΕΣ συναρτήσεις, σε SQLite στη μνήμη. Καμία πραγματική βάση,
 * κανένα δίκτυο. Χρειάζεται pdo_sqlite.
 */

require_once __DIR__ . '/../../api/fuel_weekly.php';

class TestPDO extends PDO
{
    private function fix($sql)
    {
        $sql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
        return str_replace('NOW()', "datetime('now')", $sql);
    }
    #[\ReturnTypeWillChange]
    public function prepare($sql, $o = []) { return parent::prepare($this->fix($sql), $o); }
    #[\ReturnTypeWillChange]
    public function query($sql, $m = null, ...$a) { return parent::query($this->fix($sql)); }
    #[\ReturnTypeWillChange]
    public function exec($sql) { return parent::exec($this->fix($sql)); }
}

$pass = 0; $fail = 0;
function ck($label, $ok, $extra = '')
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("   %s %s%s\n", $ok ? 'OK   ' : 'ΛΑΘΟΣ', $label, $ok ? '' : "   → $extra");
}

function fresh_db()
{
    $db = new TestPDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (array(
        'CREATE TABLE `4a_clients` (`id` INTEGER PRIMARY KEY, `name` TEXT, `country` TEXT, `account` TEXT, `is_demo` INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE `4a_users` (`id` INTEGER PRIMARY KEY, `name` TEXT, `role` TEXT, `active` INTEGER)',
        'CREATE TABLE `4a_task_types` (`code` TEXT PRIMARY KEY, `label` TEXT, `sort_order` INTEGER,
            `active` INTEGER NOT NULL DEFAULT 1, `kind` TEXT NOT NULL DEFAULT \'client\', `condition_key` TEXT,
            `depends_on` TEXT, `exclude_prev_assignee` INTEGER NOT NULL DEFAULT 0, `sla_hours` INTEGER,
            `action_url` TEXT, `action_label` TEXT, `action_module` TEXT, `ready_check` TEXT, `ready_hint` TEXT, `ready_enforced` INTEGER)',
        'CREATE TABLE `4a_tasks` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `client_id` INTEGER, `subject_key` TEXT,
            `country` TEXT, `task_code` TEXT NOT NULL, `offer_number` TEXT NOT NULL DEFAULT \'\',
            `assigned_to` INTEGER, `status` TEXT NOT NULL DEFAULT \'open\', `needs_attention` INTEGER NOT NULL DEFAULT 0,
            `created_at` TEXT DEFAULT CURRENT_TIMESTAMP, `due_at` TEXT, `paused_at` TEXT, `closed_at` TEXT,
            `closed_by` INTEGER, `close_reason` TEXT, `payload` TEXT,
            `dedupe_key` TEXT GENERATED ALWAYS AS (CASE WHEN `client_id` IS NOT NULL
                THEN \'c:\' || `client_id` || \':\' || COALESCE(`offer_number`, \'\') ELSE `subject_key` END) STORED)',
        'CREATE UNIQUE INDEX `uq_task_dedupe` ON `4a_tasks` (`task_code`, `dedupe_key`)',
        'CREATE TABLE `4a_user_task_skills` (`user_id` INTEGER, `task_code` TEXT, `country` TEXT)',
        'CREATE TABLE `4a_task_rejections` (`task_id` INTEGER, `user_id` INTEGER)',
        'CREATE TABLE `4a_task_events` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `task_id` INTEGER, `event` TEXT,
            `actor_id` INTEGER, `note` TEXT, `meta` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE `4a_fuel_types` (`code` TEXT PRIMARY KEY, `label_el` TEXT, `label_en` TEXT,
            `source` TEXT, `multiplier` TEXT, `sort_order` INTEGER, `active` INTEGER)',
        'CREATE TABLE `4a_fuel_cms_services` (`id` INTEGER PRIMARY KEY, `cms_service` TEXT, `service_name` TEXT,
            `station_country` TEXT, `direction` TEXT, `zones` TEXT, `fuel_type` TEXT, `is_combi` INTEGER,
            `visible_scope` TEXT, `sort_order` INTEGER, `active` INTEGER)',
        'CREATE TABLE `4a_fuel_cms_extra_rows` (`id` INTEGER PRIMARY KEY, `station_country` TEXT, `origin_zone` TEXT,
            `origin_country` TEXT, `zone` TEXT, `delivery_country` TEXT, `cms_service` TEXT, `service_name` TEXT,
            `fuel_type` TEXT, `note` TEXT, `sort_order` INTEGER, `active` INTEGER)',
    ) as $ddl) $db->exec($ddl);

    $db->exec("INSERT INTO `4a_task_types` (`code`,`label`,`sort_order`,`kind`,`depends_on`,`exclude_prev_assignee`) VALUES
        ('fuel_weekly','Γενικός επίναυλος εβδομάδας — αρχείο CMS',900,'system',NULL,0),
        ('fuel_weekly_verify','Έλεγχος γενικού επίναυλου στο CMS',910,'system','fuel_weekly',1)");
    $db->exec("INSERT INTO `4a_users` VALUES (1,'u1','administrator',1),(2,'u2','administrator',1)");
    $db->exec("INSERT INTO `4a_user_task_skills` VALUES (1,'fuel_weekly','BOTH'),(1,'fuel_weekly_verify','BOTH'),
                                                        (2,'fuel_weekly','BOTH'),(2,'fuel_weekly_verify','BOTH')");
    $db->exec("INSERT INTO `4a_fuel_types` VALUES ('AIR','Αεροπορικός','Air','air','1.0000',10,1),
        ('ROAD','Οδικός','Road','road','1.0000',20,1), ('AIR_CY','Air Cyprus','Air Cyprus','air','1.0600',30,1),
        ('NONE','Χωρίς επίναυλο','No fuel surcharge',NULL,NULL,40,1)");
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
    $db->exec("INSERT INTO `4a_fuel_cms_extra_rows`
      (`station_country`,`origin_zone`,`origin_country`,`zone`,`delivery_country`,`cms_service`,`service_name`,`fuel_type`,`note`,`sort_order`,`active`)
      VALUES ('GR','','GR','Z5','BG','S1026','CARGO EXPORT','AIR','ειδική γραμμή Βουλγαρίας',240,1)");
    return $db;
}

function types_of($db)
{
    $t = array();
    foreach ($db->query('SELECT `code`,`label_el`,`label_en`,`source`,`multiplier` FROM `4a_fuel_types`')->fetchAll(PDO::FETCH_ASSOC) as $r) $t[$r['code']] = $r;
    return $t;
}
$cache = json_decode(file_get_contents(__DIR__ . '/../frontend/fuel.fixture.099ce2e.json'), true);

echo "\n═══ fuel_weekly_today_athens ═══\n";
ck('2026-10-08 07:10 UTC -> 2026-10-08', fuel_weekly_today_athens('2026-10-08 07:10:00') === '2026-10-08');
ck('2026-10-10 22:30 UTC -> 2026-10-11 (Κυριακή στην Αθήνα)', fuel_weekly_today_athens('2026-10-10 22:30:00') === '2026-10-11');
ck('2026-10-25 21:30 UTC -> 2026-10-25 (μετά την αλλαγή ώρας, UTC+2)', fuel_weekly_today_athens('2026-10-25 21:30:00') === '2026-10-25');

echo "\n═══ fuel_weekly_targets: μόνο εβδομάδες ΜΕΤΑ το σήμερα, με air ΚΑΙ road ═══\n";
$t = fuel_weekly_targets($cache, '2026-10-08');
ck('όλες οι εβδομάδες του fixture (5)', count($t['all']) === 5, json_encode($t['all']));
ck('Πέμπτη 08/10 -> [2026-10-12]', $t['targets'] === array('2026-10-12'), json_encode($t['targets']));
ck('Σάββατο 10/10 -> [2026-10-12]', fuel_weekly_targets($cache, '2026-10-10')['targets'] === array('2026-10-12'));
ck('Κυριακή 11/10 -> [2026-10-12]', fuel_weekly_targets($cache, '2026-10-11')['targets'] === array('2026-10-12'));
ck('Δευτέρα 12/10 -> [] (η τρέχουσα δεν δημιουργείται εκ των υστέρων)', fuel_weekly_targets($cache, '2026-10-12')['targets'] === array());
$c2 = $cache; $c2['road'] = array_values(array_filter($c2['road'], function ($r) { return $r['week_start'] !== '2026-10-12'; }));
ck('χωρίς road για 2026-10-12 -> []', fuel_weekly_targets($c2, '2026-10-08')['targets'] === array());
$c3 = $cache;
$c3['air'][]  = array('week' => 'x', 'week_start' => '2027-01-04', 'week_end' => '2027-01-10', 'pct' => 50);
$c3['road'][] = array('week' => 'x', 'week_start' => '2027-01-04', 'week_end' => '2027-01-10', 'pct' => 41);
ck('αλλαγή έτους: 2026-12-31 -> [2027-01-04]', fuel_weekly_targets($c3, '2026-12-31')['targets'] === array('2027-01-04'));

echo "\n═══ fuel_weekly_ensure: δοκιμή, δημιουργία, δεύτερη εκτέλεση ═══\n";
$db = fresh_db(); $types = types_of($db);
$count = function ($db) { return (int)$db->query('SELECT COUNT(*) FROM `4a_tasks`')->fetchColumn(); };

$d = fuel_weekly_ensure($db, $cache, $types, '2026-10-08', true);
ck('δοκιμή: δύο «θα δημιουργούνταν»', array_column($d['actions'], 'status') === array('would_create', 'would_create'), json_encode(array_column($d['actions'], 'status')));
ck('δοκιμή: ΚΑΜΙΑ εγγραφή', $count($db) === 0);
ck('δοκιμή: προθεσμία 2026-10-09 17:00:00, 69 γραμμές', $d['actions'][0]['due_at'] === '2026-10-09 17:00:00' && $d['actions'][0]['rows'] === 69);

$r = fuel_weekly_ensure($db, $cache, $types, '2026-10-08');
ck('πραγματική: created, created', array_column($r['actions'], 'status') === array('created', 'created'), json_encode(array_column($r['actions'], 'status')));
ck('δύο εργασίες', $count($db) === 2);
$w = tasks_fetch_one($db, $r['actions'][0]['task_id']);
$v = tasks_fetch_one($db, $r['actions'][1]['task_id']);
ck('fuel_weekly: subject fuel:2026-10-12, BOTH, προθεσμία Παρ 17:00', $w['subject_key'] === 'fuel:2026-10-12' && $w['task_country'] === 'BOTH' && $w['due_at'] === '2026-10-09 17:00:00');
ck('fuel_weekly: ανατέθηκε σε 1 ή 2', in_array((int)$w['assigned_to'], array(1, 2), true), $w['assigned_to']);
ck('verify: κλειδωμένο, χωρίς ανάδοχο', (int)$v['locked'] === 1 && $v['assigned_to'] === null);
$p = json_decode($w['payload'], true);
ck('payload: ισχύς 12-Oct-2026, 70 γραμμές αρχείου, AIR_CY 51.94', $p['effective_date'] === '12-Oct-2026' && count($p['file']['rows']) === 70 && $p['prices']['AIR_CY'] === '51.94');

$r2 = fuel_weekly_ensure($db, $cache, $types, '2026-10-08');
ck('δεύτερη εκτέλεση: exists, exists', array_column($r2['actions'], 'status') === array('exists', 'exists'), json_encode(array_column($r2['actions'], 'status')));
ck('δεύτερη εκτέλεση: καμία νέα γραμμή', $count($db) === 2);
ck('δεύτερη εκτέλεση: ίδιος ανάδοχος', (int)tasks_fetch_one($db, $w['id'])['assigned_to'] === (int)$w['assigned_to']);

echo "\n═══ Χωρίς εγγραφή όταν δεν πρέπει ═══\n";
$db = fresh_db();
$bad = $cache; $bad['air'] = array();
$x = fuel_weekly_ensure($db, $bad, $types, '2026-10-08');
ck('άκυρο cache (κενό air): λόγος, καμία ενέργεια', $x['invalid'] !== null && $x['actions'] === array() && $count($db) === 0, json_encode($x['invalid']));
$x = fuel_weekly_ensure($db, $cache, $types, '2026-10-12');
ck('καμία μελλοντική εβδομάδα: καμία ενέργεια', $x['targets'] === array() && $count($db) === 0);

echo "\n═══ Γραμμή log: ώρα, εβδομάδες, νέες ή «καμία νέα», τίποτα άλλο ═══\n";
$db = fresh_db();
$l1 = fuel_weekly_log_line(fuel_weekly_ensure($db, $cache, $types, '2026-10-08'), '2026-10-08 07:10:00');
ck('δημιουργία', strpos($l1, '2026-10-08T07:10:00Z · εβδομάδες: 2026-09-14,2026-09-21,2026-09-28,2026-10-05,2026-10-12 · δημιουργήθηκαν: fuel_weekly 2026-10-12 #') === 0, $l1);
$l2 = fuel_weekly_log_line(fuel_weekly_ensure($db, $cache, $types, '2026-10-08'), '2026-10-08 13:00:00');
ck('επανάληψη -> «καμία νέα»', substr($l2, -strlen('· καμία νέα')) === '· καμία νέα', $l2);
$l3 = fuel_weekly_log_line(fuel_weekly_ensure($db, $cache, $types, '2026-10-12'), '2026-10-12 07:00:00');
ck('καθυστέρηση -> «καμία νέα · ΚΑΘΥΣΤΕΡΗΣΗ»', strpos($l3, 'καμία νέα · ΚΑΘΥΣΤΕΡΗΣΗ') !== false, $l3);
$l4 = fuel_weekly_log_line(fuel_weekly_ensure(fresh_db(), $cache, $types, '2026-10-08', true), '2026-10-08 07:10:00', true);
ck('δοκιμή -> «ΔΟΚΙΜΗ» και «θα δημιουργούνταν»', strpos($l4, 'ΔΟΚΙΜΗ') !== false && strpos($l4, 'θα δημιουργούνταν: fuel_weekly 2026-10-12, fuel_weekly_verify 2026-10-12') !== false, $l4);
ck('μία μόνο γραμμή', strpos($l1 . $l2 . $l3 . $l4, "\n") === false);

printf("\n═══ ΣΥΝΟΛΟ: %d OK, %d ΛΑΘΟΣ ═══\n\n", $pass, $fail);
exit($fail ? 1 : 0);
