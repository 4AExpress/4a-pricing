<?php
/* tests/php/fuel_verify.test.php
 *
 *   php tests/php/fuel_verify.test.php        (από τη ρίζα του repo)
 *
 * ΕΠΑΛΗΘΕΥΤΗΣ του fuel_weekly_verify (api/fuel_verify_lib.php, σχέδιο
 * 4a-private/verifier_plan.md, αποφάσεις 19/10) πάνω στις ΠΡΑΓΜΑΤΙΚΕΣ
 * συναρτήσεις του tasks_lib.php / tasks_steps.php, σε SQLite στη μνήμη.
 *
 * Οι γραμμές ελέγχου και οι ρυθμίσεις διαβάζονται από το ΙΔΙΟ το migration
 * 2026-10-23a: ό,τι ελέγχεται εδώ είναι αυτό που θα μπει στη βάση.
 *
 * Η SQLite δεν ξέρει INSERT IGNORE και NOW(): το TestPDO μεταφράζει μόνο αυτά.
 */

require_once __DIR__ . '/../../api/tasks_create.php';
require_once __DIR__ . '/../../api/tasks_system.php';
require_once __DIR__ . '/../../api/fuel_lib.php';

class TestPDO extends PDO
{
    private function fix($sql)
    {
        $sql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
        $sql = preg_replace('/JSON_UNQUOTE\(JSON_EXTRACT\(([^()]*)\)\)/', 'json_extract($1)', $sql);
        return str_replace('NOW()', "datetime('now')", $sql);
    }
    // Κάθε SQL που ετοιμάστηκε: για τα τεστ «καμία νέα κλήρωση».
    public static $log = array();
    #[\ReturnTypeWillChange]
    public function prepare($sql, $o = []) { self::$log[] = $sql; return parent::prepare($this->fix($sql), $o); }
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

// ── Το migration, όπως θα τρέξει ─────────────────────────────────────
$MIG = file_get_contents(__DIR__ . '/../../db/migrations/2026-10-23a_fuel_verify.sql');
preg_match_all("/\('(fuel_weekly_verify)',\s*'(\d+)',\s*'(GR|CY)',\s*'(\w+)',\s*'(\w+)',\s*'(\w+)',\s*(\d+),\s*(\d)\)/", $MIG, $mc, PREG_SET_ORDER);
preg_match_all("/\('(\w+)',\s*'([^']*)',\s*'[^']*'\)/", substr($MIG, strpos($MIG, '`4a_fuel_verify_settings` (`k`')), $ms, PREG_SET_ORDER);
$SEED_CHECKS = array_map(function ($m) { return array_slice($m, 1); }, $mc);
$SEED_SETTINGS = array();
foreach ($ms as $m) $SEED_SETTINGS[$m[1]] = $m[2];

$PAYLOAD = json_decode(file_get_contents(__DIR__ . '/../frontend/fuel_weekly_payload.fixture.json'), true);
$week = function ($ws, $prices) use ($PAYLOAD) {
    // Όπως η fuel_weekly_payload(): εβδομάδα, ισχύς και η στήλη EffectiveDate του αρχείου από το week_start.
    $p = $PAYLOAD; $p['week_start'] = $ws; $p['prices'] = $prices;
    $p['week_end'] = (new DateTime($ws))->modify('+6 days')->format('Y-m-d');
    $p['effective_date'] = fuel_effective_date($ws);
    foreach ($p['file']['rows'] as $i => $r) if ($i > 0) $p['file']['rows'][$i][8] = $p['effective_date'];
    return $p;
};
// Η εβδομάδα του επαληθευτή (26/10) και η προηγούμενη (19/10).
$P19 = array('AIR' => '48.50', 'ROAD' => '40.75', 'AIR_CY' => '51.41', 'NONE' => null);
$P26 = array('AIR' => '49.00', 'ROAD' => '40.75', 'AIR_CY' => '51.94', 'NONE' => null);

function fresh_db($withVerifier = true)
{
    global $SEED_CHECKS, $SEED_SETTINGS;
    $db = new TestPDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (array(
        'CREATE TABLE `4a_clients` (`id` INTEGER PRIMARY KEY, `name` TEXT, `country` TEXT, `account` TEXT,
            `is_demo` INTEGER NOT NULL DEFAULT 0, `offer_number` TEXT, `cod` TEXT, `pricelists` TEXT)',
        'CREATE TABLE `4a_users` (`id` INTEGER PRIMARY KEY, `name` TEXT, `role` TEXT, `active` INTEGER)',
        'CREATE TABLE `4a_task_types` (`code` TEXT PRIMARY KEY, `label` TEXT, `sort_order` INTEGER,
            `active` INTEGER NOT NULL DEFAULT 1, `kind` TEXT NOT NULL DEFAULT \'client\', `icon` TEXT, `title_template` TEXT, `due_warn_hours` INTEGER, `due_urgent_hours` INTEGER, `condition_key` TEXT,
            `depends_on` TEXT, `exclude_prev_assignee` INTEGER NOT NULL DEFAULT 0, `sla_hours` INTEGER,
            `action_url` TEXT, `action_label` TEXT, `action_module` TEXT,
            `ready_check` TEXT, `ready_hint` TEXT, `ready_enforced` INTEGER,
            `allow_na` INTEGER NOT NULL DEFAULT 1, `close_from_week_start` INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE `4a_tasks` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `client_id` INTEGER, `subject_key` TEXT,
            `country` TEXT, `task_code` TEXT NOT NULL, `offer_number` TEXT NOT NULL DEFAULT \'\',
            `assigned_to` INTEGER, `status` TEXT NOT NULL DEFAULT \'open\', `needs_attention` INTEGER NOT NULL DEFAULT 0,
            `created_at` TEXT DEFAULT CURRENT_TIMESTAMP, `due_at` TEXT, `paused_at` TEXT, `closed_at` TEXT,
            `closed_by` INTEGER, `close_reason` TEXT, `payload` TEXT,
            `dedupe_key` TEXT GENERATED ALWAYS AS (CASE WHEN `client_id` IS NOT NULL
                THEN \'c:\' || `client_id` || \':\' || COALESCE(`offer_number`, \'\') ELSE `subject_key` END) STORED,
            CHECK (`client_id` IS NOT NULL OR `subject_key` IS NOT NULL))',
        'CREATE UNIQUE INDEX `uq_task_dedupe` ON `4a_tasks` (`task_code`, `dedupe_key`)',
        'CREATE TABLE `4a_user_task_skills` (`user_id` INTEGER, `task_code` TEXT, `country` TEXT)',
        'CREATE TABLE `4a_task_rejections` (`task_id` INTEGER, `user_id` INTEGER)',
        'CREATE TABLE `4a_task_reject_reasons` (`code` TEXT, `label` TEXT, `active` INTEGER)',
        'CREATE TABLE `4a_task_events` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `task_id` INTEGER, `event` TEXT,
            `actor_id` INTEGER, `note` TEXT, `meta` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE `4a_task_steps` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `task_code` TEXT NOT NULL,
            `kind` TEXT NOT NULL DEFAULT \'step\', `step_code` TEXT NOT NULL, `sort_order` INTEGER NOT NULL DEFAULT 0,
            `action` TEXT, `done_event` TEXT, `is_final` INTEGER NOT NULL DEFAULT 0, `status_label` TEXT, `hint` TEXT,
            `button_label` TEXT, `button_icon` TEXT, `timeline_label` TEXT, `alert` INTEGER NOT NULL DEFAULT 0, `text` TEXT)',
        'CREATE TABLE `4a_fuel_types` (`code` TEXT PRIMARY KEY, `label_el` TEXT, `label_en` TEXT, `source` TEXT,
            `multiplier` REAL, `sort_order` INTEGER, `active` INTEGER)',
    ) as $ddl) $db->exec($ddl);

    // Το ① → ② του fuel_weekly όπως στο Α4 (κείμενα ΔΟΚΙΜΑΣΤΙΚΑ).
    $db->exec("INSERT INTO `4a_task_steps` (`task_code`,`kind`,`step_code`,`sort_order`,`action`,`done_event`,`is_final`,
                 `status_label`,`button_label`,`button_icon`,`timeline_label`) VALUES
        ('fuel_weekly','step','download',10,'download','downloaded',0,'Αναμονή λήψης','Λήψη','⬇','Λήψη'),
        ('fuel_weekly','step','upload',20,'upload','uploaded',0,'Αναμονή ανεβάσματος','Ολοκλήρωση','✓','Ανέβηκε'),
        ('fuel_weekly','step','uploaded',30,NULL,NULL,1,'Ανέβηκε',NULL,NULL,NULL)");
    // icon / title_template όπως το migration 2026-10-08a.
    $db->exec("INSERT INTO `4a_task_types` (`code`,`label`,`sort_order`,`kind`,`depends_on`,`exclude_prev_assignee`,`close_from_week_start`,`icon`,`title_template`) VALUES
        ('open_code','Άνοιγμα κωδικού',10,'client',NULL,0,0,NULL,NULL),
        ('fuel_weekly','Γενικός επίναυλος εβδομάδας — αρχείο CMS',900,'system',NULL,0,0,'⛽','Γενικός επίναυλος {week} — αρχείο CMS'),
        ('fuel_weekly_verify','Έλεγχος γενικού επίναυλου στο CMS',910,'system','fuel_weekly',1,1,'⛽','Έλεγχος γενικού επίναυλου {week} στο CMS')");
    $db->exec("INSERT INTO `4a_fuel_types` VALUES ('AIR','Αεροπορικός','Air','air',1,10,1),('ROAD','Οδικός','Road','road',1,20,1),
        ('AIR_CY','Air Cyprus','Air Cyprus','air',1.06,30,1),('NONE','Χωρίς επίναυλο','No fuel surcharge',NULL,NULL,40,1)");
    // 1, 9 διαχειριστές · 2, 3 managers. Δεξιότητες BOTH στους 1, 2, 3.
    $db->exec("INSERT INTO `4a_users` VALUES (1,'u1','administrator',1),(2,'u2','manager',1),(3,'u3','manager',1),(9,'adm','administrator',1)");
    foreach (array(1, 2, 3) as $u) {
        $db->exec("INSERT INTO `4a_user_task_skills` VALUES ($u,'fuel_weekly','BOTH'),($u,'fuel_weekly_verify','BOTH')");
    }
    if ($withVerifier) {
        $db->exec('CREATE TABLE `4a_fuel_verify_checks` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `task_code` TEXT NOT NULL,
            `client_code` TEXT NOT NULL, `station_country` TEXT NOT NULL, `cms_service` TEXT NOT NULL, `zone` TEXT NOT NULL,
            `fuel_type` TEXT NOT NULL, `sort_order` INTEGER NOT NULL DEFAULT 0, `active` INTEGER NOT NULL DEFAULT 1,
            `note` TEXT NOT NULL DEFAULT \'\')');
        $db->exec('CREATE TABLE `4a_fuel_verify_settings` (`k` TEXT PRIMARY KEY, `v` TEXT NOT NULL, `note` TEXT NOT NULL DEFAULT \'\')');
        $db->exec('CREATE TABLE `4a_fuel_verify_options` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `task_id` INTEGER NOT NULL,
            `round` INTEGER NOT NULL, `check_id` INTEGER NOT NULL, `client_code` TEXT NOT NULL, `station_country` TEXT NOT NULL,
            `cms_service` TEXT NOT NULL, `zone` TEXT NOT NULL, `fuel_type` TEXT NOT NULL, `sort_order` INTEGER NOT NULL DEFAULT 0,
            `options` TEXT NOT NULL, `correct` NUMERIC NOT NULL, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (`task_id`, `round`, `check_id`))');
        $db->exec('CREATE TABLE `4a_fuel_verify_answers` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `task_id` INTEGER NOT NULL,
            `round` INTEGER NOT NULL, `check_id` INTEGER NOT NULL, `chosen` NUMERIC NOT NULL, `is_correct` INTEGER NOT NULL,
            `user_id` INTEGER NOT NULL, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (`task_id`, `round`, `check_id`))');
        $ins = $db->prepare('INSERT INTO `4a_fuel_verify_checks` (`task_code`,`client_code`,`station_country`,`cms_service`,`zone`,`fuel_type`,`sort_order`,`active`)
                             VALUES (?,?,?,?,?,?,?,?)');
        foreach ($SEED_CHECKS as $c) $ins->execute($c);
        $ins = $db->prepare('INSERT INTO `4a_fuel_verify_settings` (`k`,`v`) VALUES (?,?)');
        foreach ($SEED_SETTINGS as $k => $v) $ins->execute(array($k, $v));
    }
    return $db;
}

// Δικαιώματα όπως τα δίνει το get_user_permissions (auth.php).
$SEE   = array('fuel_verify_values' => array('view' => 1));
$NOSEE = array('fuel_verify_values' => array('view' => 0));
$ADM   = array('role' => 'administrator', 'permissions' => $SEE);
$MGR   = array('role' => 'manager', 'pricelist_scope' => 'GR', 'permissions' => $NOSEE);
$MGRS  = array('role' => 'manager', 'pricelist_scope' => 'GR', 'permissions' => $SEE);
$S = function ($id) { return array('id' => $id); };
$NUMS = array('total' => 69, 'merge' => 0, 'updated' => 69, 'exception' => 0);
$MON  = '2026-10-26 09:00:00';      // Δευτέρα της εβδομάδας: Δ-Α8 ανοιχτό
$SUN  = '2026-10-25 23:59:59';      // ένα δευτερόλεπτο πριν
$ev = function ($db, $id) {
    $st = $db->prepare('SELECT `event`, `actor_id`, `note`, `meta` FROM `4a_task_events` WHERE `task_id` = ? ORDER BY `id`');
    $st->execute(array($id)); return $st->fetchAll(PDO::FETCH_ASSOC);
};
$cnt = function ($db, $sql) { return (int)$db->query($sql)->fetchColumn(); };
$seq = function (array $vals) { $i = 0; return function ($n) use (&$i, $vals) { $v = $vals[$i++ % count($vals)]; return $n <= 1 ? 0 : $v % $n; }; };

/**
 * Η εβδομάδα $ws: ① και ② από τον u$rootWho (ρίζα κλείνει με upload), verify
 * στον u$verWho, in_progress. Η προηγούμενη εβδομάδα ($prev) ως ιστορικό.
 */
$weekChain = function ($db, $ws, $prices, $prev = null, $rootWho = 3, $verWho = 2) use ($week, $S, $ADM, $NUMS) {
    // Προθεσμίες όπως ο κανόνας του τύπου (2026-10-14b): ① Παρ πριν 19:00, ③ Δευ 17:00.
    $due = function ($d, $off, $t) { return (new DateTime($d))->modify("$off days")->format('Y-m-d') . ' ' . $t; };
    if ($prev !== null) {
        // Η προηγούμενη εβδομάδα κλειστή με την ΠΑΛΙΑ ροή (όπως οι #87/#88): γεγονός done, χωρίς λήψη.
        $pws = (new DateTime($ws))->modify('-7 days')->format('Y-m-d');
        $pw = (int)tasks_create_system($db, 'fuel_weekly', 'fuel:' . $pws, $week($pws, $prev), 'BOTH', $due($pws, -3, '19:00:00'))['task_id'];
        $pv = (int)tasks_create_system($db, 'fuel_weekly_verify', 'fuel:' . $pws, $week($pws, $prev), 'BOTH', $due($pws, 0, '17:00:00'))['task_id'];
        $pwc = $due($pws, -3, '07:47:25'); $pvc = $due($pws, 0, '09:13:28');
        $db->exec("UPDATE `4a_tasks` SET `status` = 'done', `assigned_to` = $rootWho, `closed_by` = $rootWho, `closed_at` = '$pwc' WHERE `id` = $pw");
        $db->exec("UPDATE `4a_tasks` SET `status` = 'done', `assigned_to` = $verWho, `closed_by` = $verWho, `closed_at` = '$pvc' WHERE `id` = $pv");
        $db->exec("INSERT INTO `4a_task_events` (`task_id`,`event`,`actor_id`,`note`,`created_at`) VALUES
                     ($pw,'done',$rootWho,'ολοκληρώθηκε','$pwc'), ($pv,'done',$verWho,'ολοκληρώθηκε','$pvc')");
    }
    $w = (int)tasks_create_system($db, 'fuel_weekly', 'fuel:' . $ws, $week($ws, $prices), 'BOTH', $due($ws, -3, '19:00:00'))['task_id'];
    $v = (int)tasks_create_system($db, 'fuel_weekly_verify', 'fuel:' . $ws, $week($ws, $prices), 'BOTH', $due($ws, 0, '17:00:00'))['task_id'];
    $db->exec("UPDATE `4a_tasks` SET `assigned_to` = $rootWho, `status` = 'in_progress' WHERE `id` = $w");
    $P = array('role' => 'manager', 'pricelist_scope' => 'GR', 'permissions' => array());
    tasks_download($db, $S($rootWho), $P, $w);
    $u = tasks_upload($db, $S($rootWho), $P, $w, $NUMS);
    if (!$u['ok']) throw new Exception('upload: ' . $u['error']);
    $db->exec("UPDATE `4a_tasks` SET `assigned_to` = $verWho, `status` = 'in_progress' WHERE `id` = $v");
    return array($w, $v);
};
// Οι σωστές απαντήσεις (από τη φόρμα του admin) και μία λάθος.
$rightOf = function ($r) { $a = array(); foreach ($r['form']['lines'] as $l) $a[$l['check_id']] = $l['expected']; return $a; };

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ Migration 2026-10-23a: γραμμές ελέγχου και ρυθμίσεις ═══\n";
ck('4 γραμμές ελέγχου στο seed', count($SEED_CHECKS) === 4, count($SEED_CHECKS));
ck('οι 4 γραμμές όπως ζητήθηκαν (πελάτης/σταθμός/υπηρεσία/ζώνη/τύπος)',
   array_map(function ($c) { return implode('/', array_slice($c, 1, 5)); }, $SEED_CHECKS) === array(
     '1731000/GR/S1003/Z1/AIR', '1731000/GR/S1010/Z1/ROAD', '1731000/GR/S1027/Z9/AIR_CY', '40006012/CY/S1012/Z1/AIR'),
   json_encode($SEED_CHECKS));
// Ο κατάλογος CMS (migration 2026-10-07c), ο ίδιος έλεγχος με το SELECT επαλήθευσης του migration.
preg_match_all("/\('(S\d+)',\s*'[^']*',\s*'(GR|CY)',\s*'(?:export|import)',\s*'([^']*)',\s*'(\w+)',\s*\d,\s*'\w+',\s*\d+,\s*1\)/",
               file_get_contents(__DIR__ . '/../../db/migrations/2026-10-07c_fuel_cms_catalog.sql'), $cat, PREG_SET_ORDER);
$bad = array();
foreach ($SEED_CHECKS as $c) {
    $hit = false;
    foreach ($cat as $k) if ($k[1] === $c[3] && $k[2] === $c[2] && $k[4] === $c[5] && in_array($c[4], explode(',', $k[3]), true)) $hit = true;
    if (!$hit) $bad[] = implode('/', $c);
}
ck('κάθε γραμμή συμφωνεί με τον κατάλογο CMS (ίδιος τύπος, ζώνη μέσα στις ζώνες)', count($cat) > 10 && !$bad, implode(' | ', $bad));
ck('ρυθμίσεις: options 4 · near_deltas 0.25/0.50/0.75/1.00 · from_week 2026-10-26',
   $SEED_SETTINGS['options'] === '4' && $SEED_SETTINGS['near_deltas'] === '0.25,0.50,0.75,1.00' && $SEED_SETTINGS['from_week'] === '2026-10-26',
   json_encode($SEED_SETTINGS));
$db = fresh_db();
$s = fuel_verify_settings($db);
ck('οι ρυθμίσεις του migration περνούν την επικύρωση', $s['valid'] === true && $s['options'] === 4 && $s['near_deltas'] === array(25, 50, 75, 100)
   && $s['other_type'] === array('AIR' => 'ROAD', 'ROAD' => 'AIR', 'AIR_CY' => 'AIR') && $s['max_draws'] === 50
   && $s['reason_min'] === 10 && $s['reason_max'] === 500, json_encode($s));
ck('7 ρυθμίσεις στο migration', count($SEED_SETTINGS) === 7, json_encode(array_keys($SEED_SETTINGS)));
foreach (array('reason_min', 'reason_max', 'max_draws', 'near_deltas', 'options', 'from_week') as $k) {
    $dbk = fresh_db(); $dbk->exec("DELETE FROM `4a_fuel_verify_settings` WHERE `k` = '$k'");
    ck("χωρίς τη ρύθμιση $k: μη έγκυρες (fail closed, κανένα όριο από τον κώδικα)", fuel_verify_settings($dbk)['valid'] === false);
}
ck('κανένα σταθερό όριο λόγου στη βιβλιοθήκη', !preg_match('/mb_strlen\([^)]*\)\s*[<>]=?\s*\d/', file_get_contents(__DIR__ . '/../../api/fuel_verify_lib.php')));
ck('κανένα κείμενο τιμής (π.χ. 49.00) ή κωδικός πελάτη μέσα στη βιβλιοθήκη',
   !preg_match('/1731000|40006012|\b49\.00\b|S1003|S1010|S1027|S1012/', file_get_contents(__DIR__ . '/../../api/fuel_verify_lib.php')));
$rb = file_get_contents(__DIR__ . '/../../db/migrations/rollback/2026-10-23a_fuel_verify.rollback.sql');
ck('rollback: σβήνει και τους 4 πίνακες', substr_count($rb, 'DROP TABLE IF EXISTS') === 4);
$m2 = file_get_contents(__DIR__ . '/../../db/migrations/2026-10-23b_fuel_verify_values_perm.sql');
ck('2026-10-23b: module fuel_verify_values, ρόλοι 2–5 can_view 0', preg_match_all("/\(([2-5]), 'fuel_verify_values', 0, 0, 0, 0, 0\)/", $m2) === 4
   && strpos($m2, "('fuel_verify_values',") !== false);
ck('το δικαίωμα στον κώδικα = το module του migration', FV_PERM_MODULE === 'fuel_verify_values');

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ Κλήρωση επιλογών (Ε1) ═══\n";
$s = fuel_verify_settings(fresh_db());
$o = fuel_verify_draw(4900, 4850, 4075, $s, $seq(array(0, 0, 1)));
ck('σωστή + (α) + (β) + μία (γ): 4 διαφορετικές', $o === array(4900, 4850, 4075, 4925), json_encode($o));
$o = fuel_verify_draw(4900, 4900, 4075, $s, $seq(array(0, 1, 1, 0, 2, 0)));
ck('(α) = σωστή (η τιμή δεν άλλαξε): η θέση της πάει σε (γ)', count($o) === 4 && count(array_unique($o)) === 4 && $o[0] === 4900 && $o[1] === 4075, json_encode($o));
$o = fuel_verify_draw(4075, null, 4075, $s, $seq(array(0, 3, 1, 0, 1, 0, 0, 2, 1)));
ck('χωρίς προηγούμενη και (β) = σωστή: 3 κληρώσεις (γ) γύρω από τη σωστή', count(array_unique($o)) === 4 && $o[0] === 4075
   && !array_diff(array_map(function ($x) { return abs($x - 4075); }, array_slice($o, 1)), array(25, 50, 75, 100)), json_encode($o));
$o = fuel_verify_draw(4900, 4850, 4075, $s, $seq(array(1, 2, 0)));
ck('(γ) με βάση την (α): 48.50 − 0.75 = 47.75', $o[3] === 4775, json_encode($o));
$o = fuel_verify_draw(4900, 4850, 4075, $s, $seq(array(0, 0, 1, 1, 0, 1, 0, 1, 0)));
ck('σύμπτωση (γ) με (α) -> νέα κλήρωση', count(array_unique($o)) === 4, json_encode($o));
$tiny = $s; $tiny['near_deltas'] = array(100); $tiny['max_draws'] = 20;
ck('ποτέ τιμή ≤ 0, και null αν δεν βγαίνουν 4 μέσα σε max_draws', fuel_verify_draw(10, null, null, $tiny, fuel_verify_rand()) === null);
$okAll = true; $seenBase = array('c' => 0, 'a' => 0); $seenD = array();
for ($i = 0; $i < 3000; $i++) {
    $c = random_int(100, 9000); $a = random_int(0, 3) ? $c + random_int(-150, 150) : null; $b = random_int(0, 3) ? random_int(100, 9000) : $c;
    $o = fuel_verify_draw($c, $a, $b, $s, fuel_verify_rand());
    if ($o === null || count($o) !== 4 || count(array_unique($o)) !== 4 || $o[0] !== $c || min($o) <= 0) { $okAll = false; break; }
}
ck('3000 τυχαίες κληρώσεις: πάντα 4 διαφορετικές, > 0, η σωστή μέσα', $okAll);
for ($i = 0; $i < 2000; $i++) {
    $o = fuel_verify_draw(4900, 4850, 4075, $s, fuel_verify_rand());
    $g = $o[3];
    foreach (array('c' => 4900, 'a' => 4850) as $k => $base) if (in_array(abs($g - $base), array(25, 50, 75, 100), true)) { $seenBase[$k]++; $seenD[abs($g - $base)] = 1; }
}
ck('(γ): βάση και η σωστή και η (α), όλες οι αποστάσεις της λίστας', $seenBase['c'] > 200 && $seenBase['a'] > 200 && count($seenD) === 4, json_encode(array($seenBase, array_keys($seenD))));
ck('η σωστή ΔΕΝ είναι η μόνη τιμή με «γείτονα» στα 0.50 (η (γ) γύρω και από την (α))', $seenBase['a'] > 0);

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ ΑΝΕΝΕΡΓΟΣ: χωρίς πίνακες, ή εβδομάδα πριν το from_week ═══\n";
$lists = array();
foreach (array(false, true) as $with) {
    $db = fresh_db($with);
    list($w, $v) = $weekChain($db, '2026-10-19', $P19);
    $l = tasks_list($db, $S(1), $ADM, 'all')['tasks'];
    foreach ($l as $i => $t) unset($l[$i]['created_at']);
    $lists[] = json_encode($l, JSON_UNESCAPED_UNICODE);
}
ck('εβδομάδα 19/10 (< from_week): λίστα ΙΔΙΑ byte-προς-byte με και χωρίς πίνακες επαληθευτή', $lists[0] === $lists[1]);
ck('...κανένα `verifier`, και τα prices στη θέση τους', strpos($lists[1], '"verifier"') === false && strpos($lists[1], 'prices') !== false);
$db = fresh_db(true);
list($w, $v) = $weekChain($db, '2026-10-19', $P19);
ck('εβδομάδα 19/10: ο ανάδοχος του verify (χωρίς δικαίωμα) κατεβάζει το αρχείο όπως πριν', tasks_download($db, $S(2), $MGR, $w)['ok'] === true);
ck('εβδομάδα 19/10: verify_form -> 409 vf=off', fuel_verify_form($db, $S(2), $MGR, $v)['vf'] === 'off');
$d = tasks_done($db, $S(2), $MGR, $v, null, '2026-10-19 09:00:00');
ck('εβδομάδα 19/10: «Ολοκλήρωση» όπως πριν (με Δ-Α8)', $d['ok'] === true, json_encode($d));
$db = fresh_db(false);
list($w, $v) = $weekChain($db, '2026-10-26', $P26);
ck('χωρίς πίνακες: εβδομάδα 26/10 με την παλιά ροή (done)', tasks_done($db, $S(2), $MGR, $v, null, $MON)['ok'] === true);

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ Φόρμα: επιλογές σταθερές, σωστή ΜΟΝΟ με το δικαίωμα ═══\n";
$db = fresh_db();
list($w, $v) = $weekChain($db, '2026-10-26', $P26, $P19);
$fa = fuel_verify_form($db, $S(1), $ADM, $v, $seq(array(3, 1, 2, 0, 1)));
$fm = fuel_verify_form($db, $S(2), $MGR, $v, $seq(array(0, 2, 1, 3)), $MON);
ck('admin: 4 γραμμές, 4 επιλογές η καθεμία, με `expected`', $fa['ok'] && count($fa['form']['lines']) === 4 && $fa['form']['show_expected'] === true
   && count(array_filter($fa['form']['lines'], function ($l) { return count($l['options']) === 4 && isset($l['expected']); })) === 4, json_encode($fa));
ck('admin: expected = τιμή εβδομάδας του τύπου (49.00 · 40.75 · 51.94 · 49.00)', array_column($fa['form']['lines'], 'expected') === array('49.00', '40.75', '51.94', '49.00'));
$jm = json_encode($fm);
ck('manager (ανάδοχος): ΧΩΡΙΣ expected / correct πουθενά στο JSON', $fm['ok'] && $fm['form']['show_expected'] === false
   && !preg_match('/"expected":|correct/', $jm), $jm);
$sets = function ($f) { $o = array(); foreach ($f['form']['lines'] as $l) { $x = $l['options']; sort($x); $o[$l['check_id']] = $x; } return $o; };
ck('ΙΔΙΟ σύνολο τιμών σε δύο ανοίγματα (δεν προδίδεται η σωστή με ανανέωση)', $sets($fa) === $sets($fm));
ck('...αλλά άλλη σειρά', array_column($fa['form']['lines'], 'options') !== array_column($fm['form']['lines'], 'options'));
ck('οι επιλογές γράφτηκαν ΜΙΑ φορά: 4 γραμμές στον 4a_fuel_verify_options', $cnt($db, 'SELECT COUNT(*) FROM `4a_fuel_verify_options`') === 4);
$l0 = $fa['form']['lines'][0];
ck('γραμμή 1: 1731000 · GR · S1003 · Z1 · Αεροπορικός, με τη σωστή και την (α) 48.50', $l0['client_code'] === '1731000' && $l0['station_country'] === 'GR'
   && $l0['cms_service'] === 'S1003' && $l0['zone'] === 'Z1' && $l0['fuel_label'] === 'Αεροπορικός'
   && in_array('49.00', $l0['options'], true) && in_array('48.50', $l0['options'], true) && in_array('40.75', $l0['options'], true), json_encode($l0));
ck('manager με το δικαίωμα (μόνο αλλαγή γραμμής στη βάση): βλέπει expected', isset(fuel_verify_form($db, $S(2), $MGRS, $v)['form']['lines'][0]['expected']));
ck('manager που δεν είναι ανάδοχος, χωρίς δικαίωμα: 403', fuel_verify_form($db, $S(3), $MGR, $v)['code'] === 403);
ck('can_submit: ανάδοχος ναι, admin όχι', $fm['form']['can_submit'] === true && $fa['form']['can_submit'] === false);
ck('πριν τη Δευτέρα: η φόρμα ανοίγει, gate = «η επαλήθευση ανοίγει Δευ 26/10», can_submit false',
   ($f = fuel_verify_form($db, $S(2), $MGR, $v, null, $SUN)) && $f['form']['gate'] === 'η επαλήθευση ανοίγει Δευ 26/10' && $f['form']['can_submit'] === false);
$db2 = fresh_db();
$w2 = (int)tasks_create_system($db2, 'fuel_weekly', 'fuel:2026-10-26', $week('2026-10-26', $P26), 'BOTH', null)['task_id'];
$v2 = (int)tasks_create_system($db2, 'fuel_weekly_verify', 'fuel:2026-10-26', $week('2026-10-26', $P26), 'BOTH', null)['task_id'];
$db2->exec("UPDATE `4a_tasks` SET `assigned_to` = 2 WHERE `id` = $v2");
ck('verify κλειδωμένο (το ② δεν έγινε): 409 «κλειδωμένη»', strpos((string)fuel_verify_form($db2, $S(2), $MGR, $v2)['error'], 'κλειδωμένη') === 0);
$P26x = $P26; unset($P26x['AIR_CY']);
$db2 = fresh_db(); list($w2, $v2) = $weekChain($db2, '2026-10-26', $P26x);
ck('τιμή τύπου που λείπει από το payload: 409 vf=no_prices, καμία επιλογή γραμμένη', fuel_verify_form($db2, $S(2), $MGR, $v2)['vf'] === 'no_prices'
   && $cnt($db2, 'SELECT COUNT(*) FROM `4a_fuel_verify_options`') === 0);
$db2 = fresh_db(); list($w2, $v2) = $weekChain($db2, '2026-10-26', $P26);
$db2->exec("UPDATE `4a_fuel_verify_settings` SET `v` = 'x' WHERE `k` = 'options'");
ck('ρυθμίσεις μη έγκυρες: 409 vf=config (fail closed)', fuel_verify_form($db2, $S(2), $MGR, $v2)['vf'] === 'config');
ck('...και το «Ολοκλήρωση» μένει κλειστό (vf=use_verifier)', tasks_done($db2, $S(2), $MGR, $v2, null, $MON)['vf'] === 'use_verifier');

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ Ορατότητα στη λίστα, στις απαντήσεις και στη λήψη (Ε2) ═══\n";
$hasPrices = function ($t) { $p = json_decode($t['payload'], true); return isset($p['prices']) || isset($p['file']) || isset($p['source']); };
$byId = function ($ts, $id) { foreach ($ts as $t) if ((int)$t['id'] === (int)$id) return $t; return null; };
$mine2 = tasks_list($db, $S(2), $MGR, 'mine')['tasks'];
$tv = $byId($mine2, $v);
ck('«Οι εργασίες μου» του αναδόχου verify: payload ΧΩΡΙΣ prices / source / file', $tv !== null && !$hasPrices($tv), $tv['payload']);
ck('...αλλά με week_start, effective_date και rows_count', ($p = json_decode($tv['payload'], true)) && $p['week_start'] === '2026-10-26' && isset($p['effective_date'], $p['rows_count']));
ck('...και καμία τιμή (49.00, 40.75, 51.94) πουθενά στο JSON', !preg_match('/49\.00|40\.75|51\.94/', json_encode($mine2)));
$all1 = tasks_list($db, $S(1), $ADM, 'all')['tasks'];
ck('admin («Όλες»): payload με prices και file, και στη ρίζα και στο verify', $hasPrices($byId($all1, $v)) && $hasPrices($byId($all1, $w)));
$db->exec("UPDATE `4a_tasks` SET `assigned_to` = 3, `status` = 'in_progress' WHERE `id` = $w");
$t3 = tasks_list($db, $S(3), $MGR, 'mine')['tasks'];
ck('ανάδοχος της ρίζας (①–②), χωρίς δικαίωμα: κρατά τις τιμές (χωρίς αυτές δεν φτιάχνει το αρχείο)', $hasPrices($byId($t3, $w)));
$db->exec("UPDATE `4a_tasks` SET `status` = 'done' WHERE `id` = $w");
ck('λήψη αρχείου: ανάδοχος verify χωρίς δικαίωμα -> 403 vf=no_download', ($r = tasks_download($db, $S(2), $MGR, $w)) && $r['code'] === 403 && $r['vf'] === 'no_download', json_encode($r));
ck('λήψη αρχείου: ο ίδιος ΜΕ το δικαίωμα -> επιτρέπεται (file_viewed)', tasks_download($db, $S(2), $MGRS, $w)['event'] === 'file_viewed');
ck('λήψη αρχείου: admin και ανάδοχος ρίζας -> επιτρέπεται', tasks_download($db, $S(1), $ADM, $w)['ok'] && tasks_download($db, $S(3), $MGR, $w)['ok']);
$r = tasks_claim($db, $S(2), $MGR, $v);
$red = fuel_verify_redact($db, $S(2), $MGR, tasks_fetch_one($db, $v));
ck('απάντηση ενέργειας (`task` του tasks.php): περνά από τον ίδιο καθαρισμό', !$hasPrices($red) && $hasPrices(tasks_fetch_one($db, $v)));
$ch = $byId($all1, $w)['chain'];
ck('chain (admin): το verify έχει `verifier` {round 1, locked false, alert null}', isset($ch[1]['verifier']) && $ch[1]['verifier']['round'] === 1
   && $ch[1]['verifier']['locked'] === false && array_key_exists('alert', $ch[1]['verifier']) && $ch[1]['verifier']['alert'] === null, json_encode($ch[1]));
ck('chain: η ρίζα ΔΕΝ έχει `verifier`', !isset($ch[0]['verifier']));
$ch2 = $byId($mine2, $v)['chain'];
ck('chain (manager χωρίς δικαίωμα): `verifier` ΧΩΡΙΣ alert', isset($ch2[1]['verifier']) && !array_key_exists('alert', $ch2[1]['verifier']));

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ Υποβολή: Δ-Α8 και έλεγχοι ΠΡΙΝ γραφτεί οτιδήποτε ═══\n";
$right = $rightOf(fuel_verify_form($db, $S(1), $ADM, $v));
$before = array($cnt($db, 'SELECT COUNT(*) FROM `4a_fuel_verify_answers`'), count($ev($db, $v)));
$r = fuel_verify_submit($db, $S(2), $MGR, $v, $right, $SUN);
ck('Κυρ 25/10 23:59:59: 409 «η επαλήθευση ανοίγει Δευ 26/10»', $r['code'] === 409 && $r['error'] === 'η επαλήθευση ανοίγει Δευ 26/10', json_encode($r));
$r = fuel_verify_submit($db, $S(2), $MGR, $v, array_slice($right, 0, 3, true), $MON);
ck('λείπει γραμμή: 400 vf=incomplete', $r['code'] === 400 && $r['vf'] === 'incomplete');
$bv = $right; $bv[key($bv)] = '12.34';
$r = fuel_verify_submit($db, $S(2), $MGR, $v, $bv, $MON);
ck('τιμή εκτός επιλογών: 400 vf=bad_value', $r['code'] === 400 && $r['vf'] === 'bad_value');
ck('όχι ο ανάδοχος (ούτε ο admin): 403 vf=owner', fuel_verify_submit($db, $S(1), $ADM, $v, $right, $MON)['vf'] === 'owner');
ck('...κανένα από αυτά δεν έγραψε απάντηση ή γεγονός', array($cnt($db, 'SELECT COUNT(*) FROM `4a_fuel_verify_answers`'), count($ev($db, $v))) === $before);
ck('«Ολοκλήρωση» στο verify: 409 vf=use_verifier', tasks_done($db, $S(2), $MGR, $v, null, $MON)['vf'] === 'use_verifier');
ck('«Δεν εφαρμόζεται» πριν τη Δευτέρα: Δ-Α8', tasks_na($db, $S(2), $MGR, $v, 'δοκιμή', $SUN)['error'] === 'η επαλήθευση ανοίγει Δευ 26/10');
$db3 = fresh_db(); list($w3, $v3) = $weekChain($db3, '2026-10-26', $P26);
ck('χωρίς άνοιγμα φόρμας (καμία κλήρωση): 409 vf=reload', fuel_verify_submit($db3, $S(2), $MGR, $v3, $right, $MON)['vf'] === 'reload');

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ (3) Όλες σωστές -> ολοκλήρωση ═══\n";
$dbOk = fresh_db(); list($wO, $vO) = $weekChain($dbOk, '2026-10-26', $P26, $P19);
$rO = $rightOf(fuel_verify_form($dbOk, $S(1), $ADM, $vO));
$r = fuel_verify_submit($dbOk, $S(2), $MGR, $vO, $rO, $MON);
$t = tasks_fetch_one($dbOk, $vO);
ck('ok, η εργασία done από τον u2', $r['ok'] === true && $t['status'] === 'done' && (int)$t['closed_by'] === 2, json_encode($r));
$e = $ev($dbOk, $vO); $last = end($e);
ck('γεγονός verify_ok «επαλήθευση στο CMS: 4/4 σωστές»', $last['event'] === 'verify_ok' && $last['note'] === 'επαλήθευση στο CMS: 4/4 σωστές', json_encode($last));
$m = json_decode($last['meta'], true);
ck('meta: round 1, total 4, wrong [], 4 γραμμές ok — ΧΩΡΙΣ τιμές', $m['round'] === 1 && $m['total'] === 4 && $m['wrong'] === array()
   && count(array_filter($m['lines'], function ($l) { return $l['ok'] === true; })) === 4 && !preg_match('/\d+\.\d\d/', $last['meta']), $last['meta']);
$pa = $byId(tasks_list($dbOk, $S(1), $ADM, 'all')['tasks'], $vO)['verifier'];
$pm = fuel_verify_info($dbOk, $S(2), $MGR, tasks_fetch_one($dbOk, $vO), tasks_steps_events($dbOk, array((int)$vO))[(int)$vO]);   // κλειστή: όχι στο «mine»
ck('`verifier.passed` {n 4, total 4} από το verify_ok, ΙΔΙΟ για admin και manager (πλήθη, καμία τιμή)',
   $pa['passed'] === array('n' => 4, 'total' => 4) && $pm['passed'] === array('n' => 4, 'total' => 4), json_encode(array($pa, $pm)));
ck('4 απαντήσεις: ποιος, γραμμή, τιμή, σωστή', $cnt($dbOk, 'SELECT COUNT(*) FROM `4a_fuel_verify_answers` WHERE `is_correct` = 1 AND `user_id` = 2 AND `round` = 1') === 4);
ck('ξανά υποβολή σε κλειστή: 409 ήδη κλειστή', !empty(fuel_verify_submit($dbOk, $S(2), $MGR, $vO, $rO, $MON)['already_closed']));
$fc = fuel_verify_form($dbOk, $S(2), $MGR, $vO);
ck('φόρμα μετά: closed, ✓ σε κάθε γραμμή, can_submit false', $fc['form']['closed'] === true && $fc['form']['can_submit'] === false
   && count(array_filter($fc['form']['lines'], function ($l) { return $l['answer']['ok'] === true; })) === 4);

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ (4) Μία λάθος -> δεν κλείνει, verify_wrong, κλείδωμα (Ε3) ═══\n";
$dbW = fresh_db(); list($wW, $vW) = $weekChain($dbW, '2026-10-26', $P26, $P19);
$fW = fuel_verify_form($dbW, $S(1), $ADM, $vW);
$ans = $rightOf($fW);
$l3 = $fW['form']['lines'][2];                         // 1731000 / S1027 / Z9 / Air Cyprus
$wrongVal = current(array_diff($l3['options'], array($l3['expected'])));
$ans[$l3['check_id']] = $wrongVal;
$r = fuel_verify_submit($dbW, $S(2), $MGR, $vW, $ans, $MON);
ck('409 vf=wrong, wrong = [γραμμή 3], total 4', $r['code'] === 409 && $r['vf'] === 'wrong' && $r['wrong'] === array($l3['check_id']) && $r['total'] === 4, json_encode($r));
ck('η εργασία ΜΕΝΕΙ in_progress στον u2', tasks_fetch_one($dbW, $vW)['status'] === 'in_progress' && (int)tasks_fetch_one($dbW, $vW)['assigned_to'] === 2);
$e = $ev($dbW, $vW); $last = end($e); $m = json_decode($last['meta'], true);
ck('γεγονός verify_wrong «επαλήθευση: 1 από 4 λάθος», actor u2', $last['event'] === 'verify_wrong' && $last['note'] === 'επαλήθευση: 1 από 4 λάθος' && (int)$last['actor_id'] === 2);
ck('meta.wrong: ποια γραμμή ΚΑΙ ποιος τύπος καυσίμου (AIR_CY), χωρίς τιμές', count($m['wrong']) === 1 && $m['wrong'][0]['fuel_type'] === 'AIR_CY'
   && $m['wrong'][0]['cms_service'] === 'S1027' && !preg_match('/\d+\.\d\d/', $last['meta']), $last['meta']);
ck('4 απαντήσεις γράφτηκαν (3 σωστές, 1 λάθος) με την τιμή που επέλεξε', $cnt($dbW, 'SELECT COUNT(*) FROM `4a_fuel_verify_answers` WHERE `is_correct` = 1') === 3
   && $cnt($dbW, "SELECT COUNT(*) FROM `4a_fuel_verify_answers` WHERE `is_correct` = 0 AND `chosen` = $wrongVal") === 1);
ck('δεύτερη προσπάθεια: 409 vf=locked, τίποτα νέο', fuel_verify_submit($dbW, $S(2), $MGR, $vW, $rightOf($fW), $MON)['vf'] === 'locked'
   && $cnt($dbW, 'SELECT COUNT(*) FROM `4a_fuel_verify_answers`') === 4);
$fl = fuel_verify_form($dbW, $S(2), $MGR, $vW, null, $MON);
ck('φόρμα του επαληθευτή: locked, can_submit false, ✗ ΜΟΝΟ στη γραμμή 3', $fl['form']['locked'] === true && $fl['form']['can_submit'] === false
   && array_map(function ($l) { return $l['answer']['ok']; }, $fl['form']['lines']) === array(true, true, false, true));
ck('...και ακόμα χωρίς expected', !preg_match('/"expected":/', json_encode($fl)));
$vi = $byId(tasks_list($dbW, $S(1), $ADM, 'all')['tasks'], $vW)['verifier'];
ck('admin: κόκκινο σήμα (alert) με γραμμή, τύπο, ποιος, πότε', $vi['locked'] === true && $vi['alert']['by'] === 'u2' && $vi['alert']['total'] === 4
   && $vi['alert']['wrong'][0]['fuel_type'] === 'AIR_CY' && $vi['alert']['at'] !== null, json_encode($vi));
$vm = $byId(tasks_list($dbW, $S(2), $MGR, 'mine')['tasks'], $vW)['verifier'];
ck('manager: locked true, ΧΩΡΙΣ alert', $vm['locked'] === true && !array_key_exists('alert', $vm));
ck('μετά από λάθος: `passed` null (admin και manager)', array_key_exists('passed', $vi) && $vi['passed'] === null && $vm['passed'] === null);
$h = tasks_history($dbW, $S(1), $ADM, $vW);
ck('ιστορικό: verify_wrong με ποιος/πότε', count(array_filter($h['events'], function ($x) { return $x['event'] === 'verify_wrong' && $x['actor_name'] === 'u2'; })) === 1);

echo "\n═══ Επαναφορά από admin (Ε3): νέος γύρος, νέα κλήρωση ═══\n";
ck('manager: 403 vf=admin', fuel_verify_reset($dbW, $S(2), $MGR, $vW, 'έλεγξα το CMS ξανά')['vf'] === 'admin');
ck('λόγος < 10 χαρακτήρες: 400 vf=reason «τουλάχιστον 10»', ($r = fuel_verify_reset($dbW, $S(1), $ADM, $vW, 'σύντομο')) && $r['vf'] === 'reason'
   && $r['error'] === 'απαιτείται λόγος, τουλάχιστον 10 χαρακτήρες', json_encode($r));
$dbW->exec("UPDATE `4a_fuel_verify_settings` SET `v` = '30' WHERE `k` = 'reason_min'");
ck('reason_min = 30 στη βάση: λόγος 20 χαρακτήρων απορρίπτεται «τουλάχιστον 30»', fuel_verify_reset($dbW, $S(1), $ADM, $vW, 'έλεγχος είκοσι χαρ.')['error'] === 'απαιτείται λόγος, τουλάχιστον 30 χαρακτήρες');
$dbW->exec("UPDATE `4a_fuel_verify_settings` SET `v` = '10' WHERE `k` = 'reason_min'");
$r = fuel_verify_reset($dbW, $S(1), $ADM, $vW, 'ξανανέβηκε το Air Cyprus στο CMS');
$e = $ev($dbW, $vW); $last = end($e);
ck('επαναφορά: γεγονός verify_reset με τον λόγο, round 1 -> 2', $r['ok'] && $last['event'] === 'verify_reset' && $last['note'] === 'ξανανέβηκε το Air Cyprus στο CMS'
   && json_decode($last['meta'], true)['to_round'] === 2);
ck('ξανά επαναφορά (όχι κλειδωμένος): 409 vf=not_locked', fuel_verify_reset($dbW, $S(9), $ADM, $vW, 'ξανά επαναφορά για δοκιμή')['vf'] === 'not_locked');
$f2 = fuel_verify_form($dbW, $S(1), $ADM, $vW);
ck('γύρος 2: ξεκλείδωτος, ΝΕΕΣ επιλογές (4 νέες γραμμές), χωρίς απαντήσεις', $f2['form']['round'] === 2 && $f2['form']['locked'] === false
   && $cnt($dbW, 'SELECT COUNT(*) FROM `4a_fuel_verify_options` WHERE `round` = 2') === 4 && !isset($f2['form']['lines'][0]['answer']));
ck('admin: το κόκκινο σήμα έσβησε', $byId(tasks_list($dbW, $S(1), $ADM, 'all')['tasks'], $vW)['verifier']['alert'] === null);
$r = fuel_verify_submit($dbW, $S(2), $MGR, $vW, $rightOf($f2), $MON);
ck('γύρος 2 όλες σωστές: done', $r['ok'] === true && tasks_fetch_one($dbW, $vW)['status'] === 'done');
// Επαναφορά από τον ίδιο που απάντησε λάθος.
$dbR = fresh_db(); list($wR, $vR) = $weekChain($dbR, '2026-10-26', $P26, $P19, 3, 1);
$fR = fuel_verify_form($dbR, $S(1), $ADM, $vR); $aR = $rightOf($fR);
$aR[$fR['form']['lines'][0]['check_id']] = current(array_diff($fR['form']['lines'][0]['options'], array($fR['form']['lines'][0]['expected'])));
fuel_verify_submit($dbR, $S(1), $ADM, $vR, $aR, $MON);
ck('admin που απάντησε λάθος: δεν κάνει επαναφορά (403 vf=reset_self), can_reset false',
   fuel_verify_reset($dbR, $S(1), $ADM, $vR, 'δική μου επαναφορά δοκιμής')['vf'] === 'reset_self' && fuel_verify_form($dbR, $S(1), $ADM, $vR)['form']['can_reset'] === false);
ck('...άλλος admin: can_reset true', fuel_verify_form($dbR, $S(9), $ADM, $vR)['form']['can_reset'] === true);

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ Παράκαμψη admin στο verify (Ε4) ═══\n";
ck('όποιος υπέβαλε απαντήσεις (u1) δεν κάνει παράκαμψη: 403', tasks_admin_override($dbR, $S(1), $ADM, $vR, 'λόγος παράκαμψης δοκιμής')['code'] === 403);
ck('λόγος < 10: 400', tasks_admin_override($dbR, $S(9), $ADM, $vR, 'σύντομο')['code'] === 400);
$dbR->exec("UPDATE `4a_fuel_verify_settings` SET `v` = '50' WHERE `k` = 'reason_min'");
ck('παράκαμψη στο verify: το όριο από τη ρύθμιση (reason_min = 50)', tasks_admin_override($dbR, $S(9), $ADM, $vR, 'το CMS δεν ανοίγει, ελέγχθηκε τηλεφωνικά')['error'] === 'απαιτείται λόγος, τουλάχιστον 50 χαρακτήρες');
$dbR->exec("UPDATE `4a_fuel_verify_settings` SET `v` = '10' WHERE `k` = 'reason_min'");
$dbO = fresh_db(); list($wo, $vo) = $weekChain($dbO, '2026-10-26', $P26);
$r = tasks_admin_override($dbO, $S(9), $ADM, $vo, 'το CMS δεν ανοίγει, ελέγχθηκε τηλεφωνικά', $SUN);
ck('παράκαμψη πριν τη Δευτέρα: Δ-Α8 (409), εργασία ανοιχτή', $r['code'] === 409 && $r['error'] === 'η επαλήθευση ανοίγει Δευ 26/10'
   && tasks_fetch_one($dbO, $vo)['status'] === 'in_progress');
$r = tasks_admin_override($dbO, $S(9), $ADM, $vo, 'το CMS δεν ανοίγει, ελέγχθηκε τηλεφωνικά', $MON);
ck('admin άλλος από τον ανάδοχο: κλείνει με admin_override (Δ-Α8 μέσω tasks_close)', $r['ok'] === true && tasks_fetch_one($dbO, $vo)['status'] === 'done'
   && ($eo = $ev($dbO, $vo)) && end($eo)['event'] === 'admin_override', json_encode($r));
ck('κόκκινο σήμα: overrides_pending = 1 για άλλον admin', tasks_overrides_pending($dbO, $S(1), $ADM) === 1);
ck('`verifier.override` στη λίστα', $byId(tasks_list($dbO, $S(1), $ADM, 'all')['tasks'], $vo)['verifier']['override']['actor_name'] === 'adm');

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ Κάθε προστασία ΧΩΡΙΣ την άλλη (19/10) ═══\n";
$insOpt = function () { return count(array_filter(TestPDO::$log, function ($q) { return stripos($q, 'INSERT') !== false && strpos($q, '4a_fuel_verify_options') !== false; })); };
$dbP = fresh_db(); list($wp, $vp) = $weekChain($dbP, '2026-10-26', $P26, $P19);
$tp = tasks_fetch_one($dbP, $vp);
ck('πρώτο άνοιγμα: 4 γραμμές κληρώθηκαν', count(fuel_verify_ensure($dbP, $tp, 1, fuel_verify_rand())[0]) === 4);
TestPDO::$log = array();
list($l2) = fuel_verify_ensure($dbP, $tp, 1, fuel_verify_rand());
ck('ΠΡΟΣΤΑΣΙΑ 1 (ανάγνωση πρώτα): δεύτερο άνοιγμα -> ΚΑΝΕΝΑ INSERT στις επιλογές', $insOpt() === 0, (string)$insOpt());
$before = fuel_verify_round_lines($dbP, $vp, 1);
list($l3, $e3) = fuel_verify_draw_round($dbP, $tp, 1, fuel_verify_rand());
ck('ΠΡΟΣΤΑΣΙΑ 2 (INSERT IGNORE): ταυτόχρονη δεύτερη κλήρωση -> χωρίς σφάλμα, μένουν οι ΠΡΩΤΕΣ επιλογές',
   $e3 === null && json_encode($l3) === json_encode($before) && $cnt($dbP, 'SELECT COUNT(*) FROM `4a_fuel_verify_options`') === 4, json_encode($e3));
$rp = $rightOf(fuel_verify_form($dbP, $S(1), $ADM, $vp));
$dbP->exec("INSERT INTO `4a_task_events` (`task_id`,`event`,`actor_id`,`note`,`meta`) VALUES ($vp,'verify_wrong',2,'x','{\"round\":1}')");
ck('ΚΛΕΙΔΩΜΑ 1 (γεγονός verify_wrong): χωρίς απαντήσεις στον γύρο -> 409 locked', fuel_verify_submit($dbP, $S(2), $MGR, $vp, $rp, $MON)['vf'] === 'locked'
   && $cnt($dbP, 'SELECT COUNT(*) FROM `4a_fuel_verify_answers`') === 0);
$dbQ = fresh_db(); list($wq, $vq) = $weekChain($dbQ, '2026-10-26', $P26, $P19);
$rq = $rightOf(fuel_verify_form($dbQ, $S(1), $ADM, $vq));
// Η απάντηση στην ΤΕΛΕΥΤΑΙΑ γραμμή: χωρίς τον έλεγχο, οι 1–3 θα γράφονταν πριν από τη σύγκρουση του UNIQUE (μερική εγγραφή).
$lastCid = max(array_keys($rq));
$dbQ->exec("INSERT INTO `4a_fuel_verify_answers` (`task_id`,`round`,`check_id`,`chosen`,`is_correct`,`user_id`) VALUES ($vq,1,$lastCid,'1.00',0,2)");
$rr = fuel_verify_submit($dbQ, $S(2), $MGR, $vq, $rq, $MON);
ck('ΚΛΕΙΔΩΜΑ 2 (απαντήσεις στον γύρο): χωρίς γεγονός verify_wrong -> 409 locked, εργασία ανοιχτή, ΚΑΜΙΑ νέα απάντηση', $rr['vf'] === 'locked'
   && tasks_fetch_one($dbQ, $vq)['status'] === 'in_progress' && $cnt($dbQ, 'SELECT COUNT(*) FROM `4a_fuel_verify_answers`') === 1, json_encode($rr));

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ «επιλέχθηκε / αναμενόμενη»: ΜΟΝΟ με το δικαίωμα (19/10) ═══\n";
$aw = $byId(tasks_list($dbW, $S(1), $ADM, 'all')['tasks'], $vW);
$hW = tasks_history($dbW, $S(1), $ADM, $vW)['events'];
$hWm = tasks_history($dbW, $S(2), $MGR, $vW)['events'];
$hv = array_values(array_filter($hW, function ($e) { return $e['event'] === 'verify_wrong'; }))[0]['verify'];
ck('ιστορικό admin: verify_wrong με 4 γραμμές, η 3η ✗ με picked = η λάθος, expected 51.94', count($hv['lines']) === 4 && $hv['round'] === 1
   && $hv['lines'][2]['ok'] === false && $hv['lines'][2]['picked'] === $wrongVal && $hv['lines'][2]['expected'] === '51.94'
   && $hv['lines'][2]['fuel_label'] === 'Air Cyprus' && $hv['lines'][0]['ok'] === true, json_encode($hv));
$hvm = array_values(array_filter($hWm, function ($e) { return $e['event'] === 'verify_wrong'; }))[0]['verify'];
ck('ιστορικό manager: ίδιες γραμμές και ✓/✗, ΧΩΡΙΣ picked / expected', count($hvm['lines']) === 4 && $hvm['lines'][2]['ok'] === false
   && !preg_match('/picked|expected/', json_encode($hvm)), json_encode($hvm));
$hok = array_values(array_filter($hW, function ($e) { return $e['event'] === 'verify_ok'; }))[0]['verify'];
ck('ιστορικό: και ο γύρος 2 (verify_ok) με τις δικές του γραμμές', $hok['round'] === 2 && count(array_filter($hok['lines'], function ($l) { return $l['ok'] === true; })) === 4);
ck('γύρος 2 σωστός μετά την επαναφορά: `passed` {n 4, total 4}', $aw['verifier']['passed'] === array('n' => 4, 'total' => 4), json_encode($aw['verifier']));
ck('ιστορικό: τα άλλα γεγονότα ΧΩΡΙΣ `verify`', !array_filter($hW, function ($e) { return !in_array($e['event'], array('verify_ok', 'verify_wrong'), true) && isset($e['verify']); }));
$dbA = fresh_db(); list($wA, $vA) = $weekChain($dbA, '2026-10-26', $P26, $P19);
$fA = fuel_verify_form($dbA, $S(1), $ADM, $vA); $aA = $rightOf($fA); $lA = $fA['form']['lines'][1];
$pickA = current(array_diff($lA['options'], array($lA['expected']))); $aA[$lA['check_id']] = $pickA;
fuel_verify_submit($dbA, $S(2), $MGR, $vA, $aA, $MON);
$al = $byId(tasks_list($dbA, $S(1), $ADM, 'all')['tasks'], $vA)['verifier']['alert'];
ck('κόκκινο σήμα admin: η λάθος γραμμή με picked / expected (40.75) και by_id', count($al['wrong']) === 1 && $al['wrong'][0]['picked'] === $pickA
   && $al['wrong'][0]['expected'] === '40.75' && $al['by_id'] === 2, json_encode($al));
$vmA = $byId(tasks_list($dbA, $S(2), $MGR, 'mine')['tasks'], $vA);
ck('manager: ΚΑΜΙΑ τιμή του γύρου (picked / expected) σε λίστα ή ιστορικό', !preg_match('/picked|expected/', json_encode($vmA))
   && !preg_match('/picked|expected/', json_encode(tasks_history($dbA, $S(2), $MGR, $vA))));
$e400 = fuel_verify_reset($dbA, $S(1), $ADM, $vA, 'λίγο');
ck('σφάλμα λόγου: το όριο ως `n` (για το κείμενο της οθόνης)', $e400['vf'] === 'reason' && $e400['n'] === 10, json_encode($e400));

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ Φρένο χωρίς κώδικα: from_week -> 2026-11-02 (Ε5) ═══\n";
$dbF = fresh_db(); list($wf, $vf) = $weekChain($dbF, '2026-10-26', $P26);
$dbF->exec("UPDATE `4a_fuel_verify_settings` SET `v` = '2026-11-02' WHERE `k` = 'from_week'");
ck('εβδομάδα 26/10: ανενεργός (vf=off), κανένα `verifier`, «Ολοκλήρωση» όπως πριν',
   fuel_verify_form($dbF, $S(2), $MGR, $vf)['vf'] === 'off' && !isset($byId(tasks_list($dbF, $S(1), $ADM, 'all')['tasks'], $vf)['verifier'])
   && tasks_done($dbF, $S(2), $MGR, $vf, null, $MON)['ok'] === true);
ck('...και ο ανάδοχος verify κατεβάζει ξανά το αρχείο', tasks_download($dbF, $S(2), $MGR, $wf)['ok'] === true);

echo "\n═══ ΣΥΝΟΛΟ: $pass OK, $fail ΛΑΘΟΣ ═══\n";
exit($fail ? 1 : 0);
