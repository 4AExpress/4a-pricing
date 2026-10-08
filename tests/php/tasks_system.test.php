<?php
/* tests/php/tasks_system.test.php
 *
 *   php tests/php/tasks_system.test.php        (από τη ρίζα του repo)
 *
 * Εργασίες συστήματος (api/tasks_system.php) και η αλυσίδα του
 * εβδομαδιαίου επίναυλου, πάνω στις ΠΡΑΓΜΑΤΙΚΕΣ συναρτήσεις του
 * tasks_lib.php, tasks_create.php και fuel_lib.php, σε SQLite στη μνήμη.
 * Καμία πραγματική βάση, κανένα δίκτυο, κανένα config. Χρειάζεται pdo_sqlite.
 *
 * Η SQLite δεν ξέρει INSERT IGNORE και NOW(). Το TestPDO μεταφράζει ΜΟΝΟ
 * αυτά τα δύο, ώστε να τρέχει αυτούσιο το SQL του κώδικα.
 */

require_once __DIR__ . '/../../api/tasks_create.php';
require_once __DIR__ . '/../../api/tasks_system.php';
require_once __DIR__ . '/../../api/fuel_lib.php';

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
        'CREATE TABLE `4a_clients` (`id` INTEGER PRIMARY KEY, `name` TEXT, `country` TEXT, `account` TEXT,
            `is_demo` INTEGER NOT NULL DEFAULT 0, `offer_number` TEXT, `cod` TEXT, `pricelists` TEXT)',
        'CREATE TABLE `4a_users` (`id` INTEGER PRIMARY KEY, `name` TEXT, `role` TEXT, `active` INTEGER)',
        'CREATE TABLE `4a_task_types` (`code` TEXT PRIMARY KEY, `label` TEXT, `sort_order` INTEGER,
            `active` INTEGER NOT NULL DEFAULT 1, `kind` TEXT NOT NULL DEFAULT \'client\', `icon` TEXT, `title_template` TEXT, `condition_key` TEXT,
            `depends_on` TEXT, `exclude_prev_assignee` INTEGER NOT NULL DEFAULT 0, `sla_hours` INTEGER,
            `action_url` TEXT, `action_label` TEXT, `action_module` TEXT,
            `ready_check` TEXT, `ready_hint` TEXT, `ready_enforced` INTEGER)',
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
        'CREATE TABLE `4a_task_events` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `task_id` INTEGER, `event` TEXT,
            `actor_id` INTEGER, `note` TEXT, `meta` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)',
    ) as $ddl) $db->exec($ddl);

    // Τύποι: δύο πελάτη (όπως στην παραγωγή) και οι δύο συστήματος.
    $db->exec("INSERT INTO `4a_task_types` (`code`,`label`,`sort_order`,`kind`,`depends_on`,`exclude_prev_assignee`) VALUES
        ('open_code','Άνοιγμα κωδικού',10,'client',NULL,0),
        ('cms_rates','Τιμές CMS',20,'client','open_code',0),
        ('fuel_weekly','Γενικός επίναυλος εβδομάδας — αρχείο CMS',900,'system',NULL,0),
        ('fuel_weekly_verify','Έλεγχος γενικού επίναυλου στο CMS',910,'system','fuel_weekly',1)");
    // Χρήστες 1 και 2: ΔΙΑΧΕΙΡΙΣΤΕΣ με δεξιότητα BOTH στους τύπους συστήματος.
    // Ο 1 έχει ΚΑΙ δεξιότητα σε τύπο πελάτη (open_code), για να φανεί ότι
    // εκεί μένει εκτός δεξαμενής. 3: χρήστης με δεξιότητα GR. 9: διαχειριστής χωρίς δεξιότητες.
    $db->exec("INSERT INTO `4a_users` VALUES (1,'u1','administrator',1),(2,'u2','administrator',1),(3,'u3','user',1),(9,'adm','administrator',1)");
    $db->exec("INSERT INTO `4a_user_task_skills` VALUES
        (1,'fuel_weekly','BOTH'),(1,'fuel_weekly_verify','BOTH'),
        (2,'fuel_weekly','BOTH'),(2,'fuel_weekly_verify','BOTH'),
        (3,'fuel_weekly','GR'),(3,'fuel_weekly_verify','GR'),(3,'open_code','GR'),
        (1,'open_code','GR')");
    return $db;
}

$P  = array('role' => 'user', 'pricelist_scope' => 'GR');
$A  = array('role' => 'administrator');
// Τα δικαιώματα που θα είχε ο χρήστης στη συνεδρία του: 1, 2, 9 διαχειριστές.
$PU = function ($id) use ($P, $A) { return in_array($id, [1, 2, 9], true) ? $A : $P; };
$S  = function ($id) { return array('id' => $id); };
$ev = function ($db, $taskId, $event) {
    $st = $db->prepare('SELECT `actor_id`, `note` FROM `4a_task_events` WHERE `task_id` = ? AND `event` = ?');
    $st->execute([$taskId, $event]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};

echo "\n═══ tasks_create_system: δημιουργία, διπλότυπο, σύστημα ως actor ═══\n";
$db  = fresh_db();
$due = fuel_weekly_due_at('2026-10-12');
$r1  = tasks_create_system($db, 'fuel_weekly', 'fuel:2026-10-12', array('kind' => 'fuel_weekly', 'week_start' => '2026-10-12'), 'BOTH', $due);
ck('δημιουργήθηκε', $r1['created'] === true && $r1['error'] === null, json_encode($r1));
$t1 = tasks_fetch_one($db, $r1['task_id']);
ck('client_id NULL, subject_key fuel:2026-10-12', $t1['client_id'] === null && $t1['subject_key'] === 'fuel:2026-10-12');
ck('country BOTH', $t1['task_country'] === 'BOTH' && $t1['client_country'] === 'BOTH');
ck('dedupe_key = subject_key', $t1['dedupe_key'] === 'fuel:2026-10-12', $t1['dedupe_key']);
ck('due_at Παρασκευή 17:00 Αθήνας', $t1['due_at'] === '2026-10-09 17:00:00', $t1['due_at']);
$c = $ev($db, $r1['task_id'], 'created');
ck('event created με actor NULL (σύστημα)', count($c) === 1 && $c[0]['actor_id'] === null, json_encode($c));
ck('ρίζα: ανατέθηκε τυχαία σε 1 ή 2', in_array((int)$t1['assigned_to'], [1, 2], true), $t1['assigned_to']);
$r1b = tasks_create_system($db, 'fuel_weekly', 'fuel:2026-10-12', array('kind' => 'fuel_weekly'), 'BOTH', $due);
ck('δεύτερη κλήση: created=false, χωρίς σφάλμα', $r1b['created'] === false && $r1b['error'] === null, json_encode($r1b));
ck('δεύτερη κλήση: καμία νέα γραμμή', (int)$db->query("SELECT COUNT(*) FROM `4a_tasks` WHERE `task_code`='fuel_weekly'")->fetchColumn() === 1);
$bad = tasks_create_system($db, 'open_code', 'x:1', array());
ck('τύπος πελάτη μέσω tasks_create_system -> σφάλμα', $bad['created'] === false && $bad['error'] !== null, json_encode($bad));
$bad = tasks_create_system($db, 'fuel_weekly', '', array());
ck('κενό subject_key -> σφάλμα', $bad['error'] !== null);

echo "\n═══ Αλυσίδα: verify κλειδωμένο, ξεκλειδώνει στο done, πάει σε ΑΛΛΟΝ ═══\n";
$db = fresh_db();
$w  = tasks_create_system($db, 'fuel_weekly', 'fuel:2026-10-12', array('kind' => 'fuel_weekly'), 'BOTH', $due);
$v  = tasks_create_system($db, 'fuel_weekly_verify', 'fuel:2026-10-12', array('kind' => 'fuel_weekly'), 'BOTH', $due);
$tv = tasks_fetch_one($db, $v['task_id']);
ck('verify: locked=1 όσο το fuel_weekly είναι ανοιχτό', (int)$tv['locked'] === 1);
ck('verify: χωρίς ανάθεση στη δημιουργία (εξαρτημένη)', $tv['assigned_to'] === null);
$doer = (int)tasks_fetch_one($db, $w['task_id'])['assigned_to'];
$other = $doer === 1 ? 2 : 1;
$d = tasks_done($db, $S($doer), $PU($doer), $w['task_id']);
ck('done του fuel_weekly από τον ανάδοχο', $d['ok'] === true, $d['code'] . ' ' . $d['error']);
ck('ξεκλείδωσε 1', isset($d['unlocked']) && (int)$d['unlocked'] === 1, json_encode(isset($d['unlocked']) ? $d['unlocked'] : null));
$tv = tasks_fetch_one($db, $v['task_id']);
ck('verify: locked=0', (int)$tv['locked'] === 0);
ck("verify: ανατέθηκε στον ΑΛΛΟ ($other), όχι σε όποιον έκανε το αρχείο ($doer)", (int)$tv['assigned_to'] === $other, $tv['assigned_to']);

echo "\n═══ exclude_prev_assignee: claim / steal / δεξαμενή ═══\n";
$db = fresh_db();
$w  = tasks_create_system($db, 'fuel_weekly', 'fuel:2026-10-19', array(), 'BOTH', null);
$v  = tasks_create_system($db, 'fuel_weekly_verify', 'fuel:2026-10-19', array(), 'BOTH', null);
$doer = (int)tasks_fetch_one($db, $w['task_id'])['assigned_to'];
$other = $doer === 1 ? 2 : 1;
// Ο άλλος απορρίπτει το verify αφού ανατεθεί, ώστε να μείνει μόνο ο doer ως υποψήφιος.
tasks_done($db, $S($doer), $PU($doer), $w['task_id']);
$pool = tasks_candidate_pool($db, $v['task_id'], 'fuel_weekly_verify', 'BOTH');
ck("δεξαμενή verify: χωρίς τον $doer", !in_array($doer, $pool, true), json_encode($pool));
ck('δεξαμενή verify: χωρίς τον 3 (δεξιότητα GR)', !in_array(3, $pool, true), json_encode($pool));
$db->exec('UPDATE `4a_tasks` SET `assigned_to` = NULL, `status` = \'open\' WHERE `id` = ' . (int)$v['task_id']);
$c = tasks_claim($db, $S($doer), $PU($doer), $v['task_id']);
ck("claim από τον $doer (έκανε το αρχείο) -> 403", $c['code'] === 403, $c['code'] . ' ' . $c['error']);
$c = tasks_claim($db, $S($other), $PU($other), $v['task_id']);
ck("claim από τον $other -> OK", $c['ok'] === true, $c['code'] . ' ' . $c['error']);
$s = tasks_steal($db, $S($doer), $PU($doer), $v['task_id']);
ck("steal από τον $doer -> 403", $s['code'] === 403, $s['code'] . ' ' . $s['error']);
$a = tasks_assign($db, $S(9), array('role' => 'administrator'), $v['task_id'], $doer);
ck('assign από διαχειριστή στον doer: επιτρέπεται', $a['ok'] === true, $a['code'] . ' ' . $a['error']);
$n = $ev($db, $v['task_id'], 'assigned');
$last = end($n);
ck('...και καταγράφεται η παράκαμψη', $last && strpos($last['note'], 'ΙΔΙΟΣ με την προηγούμενη') !== false, $last ? $last['note'] : '');
$plain = tasks_fetch_one($db, $w['task_id']);
ck('το fuel_weekly (exclude=0) δεν επηρεάζεται', tasks_is_prev_assignee($db, $w['task_id'], $doer) === false);

echo "\n═══ Ρόλοι: διαχειριστές ΜΕΣΑ στη δεξαμενή μόνο για kind = system ═══\n";
$db = fresh_db();
$w  = tasks_create_system($db, 'fuel_weekly', 'fuel:2026-10-26', array(), 'BOTH', null);
$pool = tasks_candidate_pool($db, $w['task_id'], 'fuel_weekly', 'BOTH'); sort($pool);
ck('fuel_weekly: δεξαμενή = διαχειριστές 1, 2 (δεξιότητα BOTH)', $pool === array(1, 2), json_encode($pool));
ck('fuel_weekly: ανατέθηκε τυχαία σε 1 ή 2', in_array((int)tasks_fetch_one($db, $w['task_id'])['assigned_to'], [1, 2], true));
ck('fuel_weekly: ο 9 (διαχειριστής ΧΩΡΙΣ δεξιότητα) εκτός', !in_array(9, $pool, true));
ck('fuel_weekly: ο 3 (δεξιότητα GR) εκτός', !in_array(3, $pool, true));
$seen = array();
for ($i = 0; $i < 40; $i++) {
    $x = tasks_create_system($db, 'fuel_weekly', 'fuel:t' . $i, array(), 'BOTH', null);
    $seen[(int)tasks_fetch_one($db, $x['task_id'])['assigned_to']] = true;
}
ksort($seen);
ck('τυχαία: σε 40 δημιουργίες ανατέθηκε και στον 1 και στον 2', array_keys($seen) === array(1, 2), json_encode(array_keys($seen)));
$db->exec("INSERT INTO `4a_clients` VALUES (7,'Π','GR','A7','0','P-7','{}','[]')");
tasks_create_for_client($db, 7, null, null, 'accepted');
$oc = (int)$db->query("SELECT `id` FROM `4a_tasks` WHERE `client_id` = 7 AND `task_code` = 'open_code'")->fetchColumn();
$pool = tasks_candidate_pool($db, $oc, 'open_code', 'GR');
ck('εργασία πελάτη: ο διαχειριστής 1 με δεξιότητα open_code ΕΚΤΟΣ δεξαμενής', !in_array(1, $pool, true), json_encode($pool));
ck('εργασία πελάτη: δεξαμενή = [3], όπως πριν', $pool === array(3), json_encode($pool));
ck('εργασία πελάτη: ανατέθηκε στον 3', (int)tasks_fetch_one($db, $oc)['assigned_to'] === 3);

echo "\n═══ tasks_create_for_client: ΠΟΤΕ τύποι συστήματος ανά πελάτη ═══\n";
$db = fresh_db();
$db->exec("INSERT INTO `4a_clients` VALUES (5,'Πελάτης','GR','ACC','0','P-1','{}','[]')");
$r = tasks_create_for_client($db, 5, null, null, 'accepted');
ck('ran, χωρίς σφάλμα', $r['ran'] === true && $r['error'] === null, json_encode($r));
$codes = $db->query('SELECT `task_code` FROM `4a_tasks` WHERE `client_id` = 5 ORDER BY `task_code`')->fetchAll(PDO::FETCH_COLUMN);
ck('δημιουργήθηκαν μόνο cms_rates, open_code', $codes === array('cms_rates', 'open_code'), json_encode($codes));
ck('κανένα fuel_weekly για πελάτη', !in_array('fuel_weekly', $codes, true));
$t = tasks_fetch_one($db, (int)$db->query("SELECT `id` FROM `4a_tasks` WHERE `task_code`='cms_rates'")->fetchColumn());
ck('εργασία πελάτη: dedupe_key c:5:P-1', $t['dedupe_key'] === 'c:5:P-1', $t['dedupe_key']);
ck('εργασία πελάτη: cms_rates κλειδωμένο μέχρι το open_code', (int)$t['locked'] === 1);
$o = (int)$db->query("SELECT `id` FROM `4a_tasks` WHERE `task_code`='open_code'")->fetchColumn();
$db->exec("UPDATE `4a_tasks` SET `assigned_to` = 3, `status` = 'in_progress' WHERE `id` = $o");
$d = tasks_done($db, $S(3), $P, $o);
ck('done του open_code ξεκλειδώνει το cms_rates (ίδιος πελάτης/προσφορά)', isset($d['unlocked']) && (int)$d['unlocked'] === 1, json_encode($d['unlocked'] ?? null));

printf("\n═══ ΣΥΝΟΛΟ: %d OK, %d ΛΑΘΟΣ ═══\n\n", $pass, $fail);
exit($fail ? 1 : 0);
