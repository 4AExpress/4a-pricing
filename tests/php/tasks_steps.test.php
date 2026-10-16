<?php
/* tests/php/tasks_steps.test.php
 *
 *   php tests/php/tasks_steps.test.php        (από τη ρίζα του repo)
 *
 * Κάρτα βημάτων (api/tasks_steps.php, Φάση 5 Κ2α) πάνω στις ΠΡΑΓΜΑΤΙΚΕΣ
 * συναρτήσεις του tasks_lib.php, σε SQLite στη μνήμη. Δύο μέρη:
 *
 *   ΑΝΕΝΕΡΓΟ  4a_task_steps άδειος (= μετά το Κ1α/Κ2α, πριν από το Κ4α):
 *             ΚΑΘΕ νέα ενέργεια 409, κανένα γεγονός, καμία αλλαγή στο
 *             4a_tasks, η λίστα ΙΔΙΑ με χωρίς πίνακα, done/na όπως πριν.
 *   ΕΝΕΡΓΟ    βήματα ΜΟΝΟ για το fuel_weekly (= Κ4α): ① λήψη, ② Merge
 *             Result (Π), παράκαμψη admin, «Το είδα», χρονολόγιο.
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

$PAYLOAD = json_decode(file_get_contents(__DIR__ . '/../frontend/fuel_weekly_payload.fixture.json'), true);

/**
 * $steps: null = ΧΩΡΙΣ πίνακα 4a_task_steps (ο κώδικας πριν από το migration),
 *         'empty' = πίνακας άδειος, 'fuel' = βήματα για το fuel_weekly.
 */
function fresh_db($steps)
{
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
            `allow_na` INTEGER NOT NULL DEFAULT 1)',
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

    if ($steps !== null) {
        $db->exec('CREATE TABLE `4a_task_steps` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `task_code` TEXT NOT NULL,
            `kind` TEXT NOT NULL DEFAULT \'step\', `step_code` TEXT NOT NULL, `sort_order` INTEGER NOT NULL DEFAULT 0,
            `action` TEXT, `done_event` TEXT, `is_final` INTEGER NOT NULL DEFAULT 0, `status_label` TEXT, `hint` TEXT,
            `button_label` TEXT, `button_icon` TEXT, `timeline_label` TEXT, `alert` INTEGER NOT NULL DEFAULT 0, `text` TEXT)');
    }
    if ($steps === 'fuel') {
        // Ίδιο σχήμα με το migration ενεργοποίησης (Κ4α). Τα κείμενα εδώ
        // είναι ΔΟΚΙΜΑΣΤΙΚΑ: τα αληθινά περιμένουν έγκριση (Υ2).
        $db->exec("INSERT INTO `4a_task_steps` (`task_code`,`kind`,`step_code`,`sort_order`,`action`,`done_event`,`is_final`,
                     `status_label`,`hint`,`button_label`,`button_icon`,`timeline_label`,`alert`) VALUES
            ('fuel_weekly','step','download',10,'download','downloaded',0,'Αναμονή λήψης αρχείου','Κατέβασε το αρχείο','Λήψη αρχείου CMS','⬇','Λήψη αρχείου',0),
            ('fuel_weekly','step','upload',20,'upload','uploaded',0,'Αναμονή ανεβάσματος','Ανέβασε το αρχείο στο CMS','Επιβεβαίωση ανεβάσματος','⬆','Ανέβηκε στο CMS',0),
            ('fuel_weekly','step','uploaded',30,NULL,NULL,1,'Ανέβηκε · ισχύει από Δευ {week_start}','Η επαλήθευση γίνεται Δευ {week_start}.',NULL,NULL,NULL,0),
            ('fuel_weekly','event','upload_failed',0,NULL,NULL,0,NULL,NULL,NULL,NULL,'Ανέβασμα χωρίς επιβεβαίωση',0),
            ('fuel_weekly','event','admin_override',0,NULL,NULL,0,NULL,NULL,NULL,NULL,'Παράκαμψη από διαχειριστή',1),
            ('fuel_weekly','event','done',0,NULL,NULL,0,NULL,NULL,NULL,NULL,'Ολοκληρώθηκε (παλιά ροή)',0)");
        $db->exec("INSERT INTO `4a_task_steps` (`task_code`,`kind`,`step_code`,`text`) VALUES
            ('fuel_weekly','ui','h_do','ΤΙ ΠΡΕΠΕΙ ΝΑ ΚΑΝΕΙΣ'), ('fuel_weekly','ui','h_status','ΚΑΤΑΣΤΑΣΗ')");
        $db->exec("UPDATE `4a_task_types` SET `allow_na` = 0 WHERE `code` = 'fuel_weekly'");
    }

    $db->exec("INSERT INTO `4a_task_types` (`code`,`label`,`sort_order`,`kind`,`depends_on`,`exclude_prev_assignee`) VALUES
        ('open_code','Άνοιγμα κωδικού',10,'client',NULL,0),
        ('cms_rates','Τιμές CMS',20,'client','open_code',0),
        ('fuel_weekly','Γενικός επίναυλος εβδομάδας — αρχείο CMS',900,'system',NULL,0),
        ('fuel_weekly_verify','Έλεγχος γενικού επίναυλου στο CMS',910,'system','fuel_weekly',1)");
    if ($steps === 'fuel') $db->exec("UPDATE `4a_task_types` SET `allow_na` = 0 WHERE `code` = 'fuel_weekly'");
    // 1, 2 διαχειριστές με δεξιότητα BOTH · 3 χρήστης GR · 9 διαχειριστής χωρίς δεξιότητες.
    $db->exec("INSERT INTO `4a_users` VALUES (1,'u1','administrator',1),(2,'u2','administrator',1),(3,'u3','user',1),(9,'adm','administrator',1)");
    $db->exec("INSERT INTO `4a_user_task_skills` VALUES
        (1,'fuel_weekly','BOTH'),(1,'fuel_weekly_verify','BOTH'),
        (2,'fuel_weekly','BOTH'),(2,'fuel_weekly_verify','BOTH'),
        (3,'open_code','GR')");
    return $db;
}

$P  = array('role' => 'user', 'pricelist_scope' => 'GR');
$A  = array('role' => 'administrator');
$PU = function ($id) use ($P, $A) { return in_array($id, [1, 2, 9], true) ? $A : $P; };
$S  = function ($id) { return array('id' => $id); };
$events = function ($db, $taskId) {
    $st = $db->prepare('SELECT `event`, `actor_id`, `note`, `meta` FROM `4a_task_events` WHERE `task_id` = ? ORDER BY `id`');
    $st->execute([$taskId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$row = function ($db, $taskId) {
    $st = $db->prepare('SELECT `status`, `assigned_to`, `closed_by`, `needs_attention` FROM `4a_tasks` WHERE `id` = ?');
    $st->execute([$taskId]);
    return $st->fetch(PDO::FETCH_ASSOC);
};
$listOne = function ($db, $me, $perms, $taskId) {
    $r = tasks_list($db, array('id' => $me), $perms, 'all');
    foreach ($r['tasks'] as $t) if ((int)$t['id'] === (int)$taskId) return $t;
    return null;
};
// Αλυσίδα fuel_weekly -> verify της εβδομάδας 12/10, και μία εργασία πελάτη.
$chain = function ($db) use ($PAYLOAD) {
    $w = tasks_create_system($db, 'fuel_weekly', 'fuel:2026-10-12', $PAYLOAD, 'BOTH', '2026-10-09 17:00:00');
    $v = tasks_create_system($db, 'fuel_weekly_verify', 'fuel:2026-10-12', $PAYLOAD, 'BOTH', '2026-10-09 17:00:00');
    $db->exec("INSERT INTO `4a_clients` VALUES (7,'Π','GR','A7','0','P-7','{}','[]')");
    tasks_create_for_client($db, 7, null, null, 'accepted');
    $c = (int)$db->query("SELECT `id` FROM `4a_tasks` WHERE `client_id` = 7 AND `task_code` = 'open_code'")->fetchColumn();
    $doer = (int)tasks_fetch_one($db, $w['task_id'])['assigned_to'];
    return array((int)$w['task_id'], (int)$v['task_id'], $c, $doer, $doer === 1 ? 2 : 1);
};
$NUMS = array('total' => 69, 'merge' => 0, 'updated' => 69, 'exception' => 0);

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ ΑΝΕΝΕΡΓΟ: λίστα ΙΔΙΑ με και χωρίς πίνακα βημάτων ═══\n";
$lists = array();
foreach (array(null, 'empty') as $mode) {
    $db = fresh_db($mode);
    mt_srand(1);
    list($w, $v, $c, $doer) = $chain($db);
    // Ο ανάδοχος είναι τυχαίος: τον σταθεροποιούμε για να συγκριθούν οι λίστες.
    $db->exec("UPDATE `4a_tasks` SET `assigned_to` = 1 WHERE `id` = $w");
    $l = tasks_list($db, $S(1), $A, 'all')['tasks'];
    foreach ($l as $i => $t) unset($l[$i]['created_at']);
    $lists[] = json_encode($l, JSON_UNESCAPED_UNICODE);
}
ck('tasks_list: χωρίς πίνακα === πίνακας άδειος (byte-προς-byte)', $lists[0] === $lists[1]);
ck('tasks_list: κανένα step / timeline / override', strpos($lists[1], '"step"') === false
   && strpos($lists[1], '"timeline"') === false && strpos($lists[1], '"override"') === false);

echo "\n═══ ΑΝΕΝΕΡΓΟ: κάθε νέα ενέργεια 409, κανένα γεγονός, καμία αλλαγή ═══\n";
foreach (array(null, 'empty') as $mode) {
    $tag = $mode === null ? 'χωρίς πίνακα' : 'άδειος πίνακας';
    $db = fresh_db($mode);
    list($w, $v, $c, $doer) = $chain($db);
    $before = array($row($db, $w), count($events($db, $w)));
    $calls = array(
        'download'       => function () use ($db, $S, $PU, $doer, $w) { return tasks_download($db, $S($doer), $PU($doer), $w); },
        'upload'         => function () use ($db, $S, $PU, $doer, $w, $NUMS) { return tasks_upload($db, $S($doer), $PU($doer), $w, $NUMS); },
        'admin_override' => function () use ($db, $S, $A, $w) { return tasks_admin_override($db, $S(9), $A, $w, 'λόγος δοκιμής αρκετά μεγάλος'); },
    );
    foreach ($calls as $name => $fn) {
        $r = $fn();
        ck("$tag: $name -> 409 «δεν έχει ενεργοποιηθεί»", $r['ok'] === false && $r['code'] === 409 && $r['error'] === TASKS_STEPS_OFF, $r['code'] . ' ' . $r['error']);
    }
    $r = tasks_override_ack($db, $S(1), $A, $w);
    ck("$tag: override_ack -> 409 (δεν υπάρχει παράκαμψη)", $r['code'] === 409);
    ck("$tag: καμία αλλαγή στο 4a_tasks, κανένα νέο γεγονός", array($row($db, $w), count($events($db, $w))) === $before,
       json_encode(array($row($db, $w), $events($db, $w))));
    $b = tasks_badge($db, $S(1), $A);
    ck("$tag: σήμα overrides = 0", $b['overrides'] === 0, json_encode($b));
    $na = tasks_na($db, $S($doer), $PU($doer), $w, 'δοκιμή');
    ck("$tag: na όπως πριν (allow_na = 1)", $na['ok'] === true, $na['code'] . ' ' . $na['error']);
    $db2 = fresh_db($mode);
    list($w2, $v2, $c2, $doer2) = $chain($db2);
    $d = tasks_done($db2, $S($doer2), $PU($doer2), $w2);
    ck("$tag: done όπως πριν, και ξεκλειδώνει το verify", $d['ok'] === true && (int)$d['unlocked'] === 1, json_encode($d));
    $e = $events($db2, $w2);
    ck("$tag: γεγονός done «ολοκληρώθηκε»", end($e)['event'] === 'done' && end($e)['note'] === 'ολοκληρώθηκε');
}

// ═════════════════════════════════════════════════════════════════════
echo "\n═══ ΕΝΕΡΓΟ: η λίστα — βήμα μόνο στο fuel_weekly ═══\n";
$db = fresh_db('fuel');
list($w, $v, $c, $doer, $other) = $chain($db);
$t = $listOne($db, $doer, $PU($doer), $w);
ck('fuel_weekly: βήμα download', isset($t['step']) && $t['step']['code'] === 'download' && $t['step']['action'] === 'download', json_encode($t['step'] ?? null));
ck('fuel_weekly: ετικέτα και κουμπί από τη βάση', $t['step']['status_label'] === 'Αναμονή λήψης αρχείου' && $t['step']['button_label'] === 'Λήψη αρχείου CMS');
ck('fuel_weekly: χρονολόγιο άδειο, καμία παράκαμψη', $t['timeline'] === array() && $t['override'] === null);
$ui = json_decode(json_encode($t['ui']), true);
ck('fuel_weekly: κείμενα κάρτας (ui) από τη βάση', $ui === array('h_do' => 'ΤΙ ΠΡΕΠΕΙ ΝΑ ΚΑΝΕΙΣ', 'h_status' => 'ΚΑΤΑΣΤΑΣΗ'), json_encode($t['ui']));
ck('...ΚΑΝΕΝΑ ui ως step ή event', $t['step']['code'] === 'download' && count($t['timeline']) === 0);
$tv = $listOne($db, 1, $A, $v);
ck('verify (χωρίς βήματα): κανένα νέο κλειδί', !array_key_exists('step', $tv) && !array_key_exists('timeline', $tv));
$tc = $listOne($db, 1, $A, $c);
ck('εργασία πελάτη: κανένα νέο κλειδί', !array_key_exists('step', $tc) && !array_key_exists('timeline', $tc) && !array_key_exists('override', $tc));

echo "\n═══ ΕΝΕΡΓΟ: γενικό done και na κλειστά ═══\n";
$before = array($row($db, $w), count($events($db, $w)));
$d = tasks_done($db, $S($doer), $PU($doer), $w);
ck('done -> 409', $d['code'] === 409 && strpos($d['error'], 'βήματά της') !== false, $d['code'] . ' ' . $d['error']);
$n = tasks_na($db, $S($doer), $PU($doer), $w, 'δοκιμή');
ck('na με allow_na = 0 -> 409', $n['code'] === 409, $n['code'] . ' ' . $n['error']);
ck('...καμία αλλαγή, κανένα γεγονός', array($row($db, $w), count($events($db, $w))) === $before);
$nv = tasks_na($db, $S(1), $A, $v, 'δοκιμή verify');
ck('na στο verify (allow_na = 1) -> δουλεύει όπως πριν', $nv['ok'] === true, $nv['code'] . ' ' . $nv['error']);

echo "\n═══ ΕΝΕΡΓΟ: ① λήψη — κλείνει ΜΟΝΟ από τον ανάδοχο ═══\n";
$db = fresh_db('fuel');
list($w, $v, $c, $doer, $other) = $chain($db);
$u = tasks_upload($db, $S($doer), $PU($doer), $w, $NUMS);
ck('upload πριν από τη λήψη -> 409', $u['code'] === 409 && $u['error'] === 'πρώτα η λήψη του αρχείου', $u['code'] . ' ' . $u['error']);
$r = tasks_download($db, $S(3), $P, $w);
ck('λήψη από άσχετο χρήστη -> 403, κανένα γεγονός', $r['code'] === 403 && count($events($db, $w)) === 2, $r['code'] . ' ' . json_encode($events($db, $w)));
$r = tasks_download($db, $S(9), $A, $w);
ck('λήψη από admin που δεν είναι ανάδοχος -> file_viewed', $r['ok'] === true && $r['event'] === 'file_viewed');
ck('...το βήμα μένει download', $listOne($db, $doer, $PU($doer), $w)['step']['code'] === 'download');
$r = tasks_download($db, $S($doer), $PU($doer), $v);
ck('λήψη από την κάρτα του verify (χωρίς βήματα) -> 409', $r['code'] === 409);
$r = tasks_download($db, $S($doer), $PU($doer), $w);
ck('λήψη από τον ανάδοχο -> downloaded', $r['ok'] === true && $r['event'] === 'downloaded');
$t = $listOne($db, $doer, $PU($doer), $w);
ck('...βήμα upload', $t['step']['code'] === 'upload' && $t['step']['action'] === 'upload');
ck('...χρονολόγιο: «Λήψη αρχείου» με χρήστη', count($t['timeline']) === 1 && $t['timeline'][0]['label'] === 'Λήψη αρχείου'
   && $t['timeline'][0]['actor'] === 'u' . $doer && $t['timeline'][0]['late'] === false, json_encode($t['timeline']));
$r = tasks_download($db, $S($doer), $PU($doer), $w);
ck('δεύτερη λήψη από τον ανάδοχο -> file_viewed (καταγράφεται, δεν ξανακλείνει)', $r['event'] === 'file_viewed');
ck('...το file_viewed ΔΕΝ μπαίνει στο χρονολόγιο (χωρίς ετικέτα)', count($listOne($db, $doer, $PU($doer), $w)['timeline']) === 1);

echo "\n═══ ΕΝΕΡΓΟ: ② Merge Result (Π) ═══\n";
$r = tasks_upload($db, $S($other), $PU($other), $w, $NUMS);
ck('upload από admin που δεν είναι ανάδοχος -> 403', $r['code'] === 403, $r['code'] . ' ' . $r['error']);
$n0 = count($events($db, $w));
foreach (array(
    'αρνητικό'   => array('total' => 69, 'merge' => -1, 'updated' => 69, 'exception' => 0),
    'κείμενο'    => array('total' => '69a', 'merge' => 0, 'updated' => 69, 'exception' => 0),
    'λείπει'     => array('total' => 69, 'merge' => 0, 'updated' => 69),
    'δεκαδικό'   => array('total' => 69.5, 'merge' => 0, 'updated' => 69, 'exception' => 0),
) as $k => $nums) {
    $r = tasks_upload($db, $S($doer), $PU($doer), $w, $nums);
    ck("άκυρα νούμερα ($k) -> 400", $r['code'] === 400, $r['code'] . ' ' . $r['error']);
}
ck('...κανένα γεγονός από τα άκυρα', count($events($db, $w)) === $n0);
$r = tasks_upload($db, $S($doer), $PU($doer), $w, array('total' => 68, 'merge' => 0, 'updated' => 68, 'exception' => 0));
ck('Total 68 ≠ 69 -> 409', $r['code'] === 409 && strpos($r['error'], 'Total 68') !== false, $r['code'] . ' ' . $r['error']);
$e = $events($db, $w); $last = end($e); $m = json_decode($last['meta'], true);
ck('...γεγονός upload_failed με τα 4 νούμερα', $last['event'] === 'upload_failed' && $m['total'] === 68 && $m['rows_count'] === 69, json_encode($last));
ck('...η εργασία ΜΕΝΕΙ ανοιχτή στον ανάδοχο', $row($db, $w)['status'] === 'in_progress' && (int)$row($db, $w)['assigned_to'] === $doer);
$r = tasks_upload($db, $S($doer), $PU($doer), $w, array('total' => '69', 'merge' => '0', 'updated' => '68', 'exception' => '1'));
ck('Exception 1 -> 409', $r['code'] === 409 && strpos($r['error'], 'Exception 1') !== false, $r['code'] . ' ' . $r['error']);
ck('...βήμα ακόμα upload (το upload_failed δεν κλείνει βήμα)', $listOne($db, $doer, $PU($doer), $w)['step']['code'] === 'upload');
$r = tasks_upload($db, $S($doer), $PU($doer), $w, array('total' => 69, 'merge' => 1, 'updated' => 69, 'exception' => 0));
ck('Total 69, Exception 0 -> κλείνει', $r['ok'] === true && $row($db, $w)['status'] === 'done', $r['code'] . ' ' . $r['error']);
ck('...ξεκλείδωσε το verify', isset($r['unlocked']) && (int)$r['unlocked'] === 1, json_encode($r['unlocked'] ?? null));
ck("...το verify πήγε στον ΑΛΛΟ ($other)", (int)$row($db, $v)['assigned_to'] === $other, json_encode($row($db, $v)));
$e = $events($db, $w); $last = end($e); $m = json_decode($last['meta'], true);
ck('...γεγονός uploaded με τα 4 νούμερα', $last['event'] === 'uploaded' && $m['total'] === 69 && $m['merge'] === 1
   && $m['updated'] === 69 && $m['exception'] === 0 && $m['rows_count'] === 69, json_encode($m));
ck('...προειδοποιήσεις (όχι φραγή): Merge+Updated≠Total, Updated>0',
   isset($m['warnings']) && $m['warnings'] === array('merge_updated_sum', 'updated_nonzero'), json_encode($m));
$t = $listOne($db, $doer, $PU($doer), $w);
ck('...τελικό βήμα με {week_start} = 12/10', $t['step']['code'] === 'uploaded' && $t['step']['final'] === true
   && $t['step']['status_label'] === 'Ανέβηκε · ισχύει από Δευ 12/10', json_encode($t['step']));
$labels = array_map(function ($x) { return $x['label']; }, $t['timeline']);
ck('...χρονολόγιο με τη σειρά: λήψη, 2 αποτυχίες, ανέβασμα',
   $labels === array('Λήψη αρχείου', 'Ανέβασμα χωρίς επιβεβαίωση', 'Ανέβασμα χωρίς επιβεβαίωση', 'Ανέβηκε στο CMS'), json_encode($labels));
$r = tasks_upload($db, $S($doer), $PU($doer), $w, $NUMS);
ck('δεύτερο upload σε κλειστή -> 409 με σημαία already_closed', $r['code'] === 409 && !empty($r['already_closed']), json_encode($r));
$db = fresh_db('fuel');
list($w, $v, $c, $doer, $other) = $chain($db);
tasks_download($db, $S($doer), $PU($doer), $w);
// «Καθαρό» κατά τον κανόνα Π: Merge = Total και Updated = 0.
$r = tasks_upload($db, $S($doer), $PU($doer), $w, array('total' => 69, 'merge' => 69, 'updated' => 0, 'exception' => 0));
$e = $events($db, $w); $m = json_decode(end($e)['meta'], true);
ck('καθαρό Merge Result (Merge 69 / Updated 0): χωρίς warnings', $r['ok'] === true && !isset($m['warnings']), json_encode($m));
$db = fresh_db('fuel');
list($w, $v, $c, $doer, $other) = $chain($db);
tasks_download($db, $S($doer), $PU($doer), $w);
$r = tasks_upload($db, $S($doer), $PU($doer), $w, $NUMS);
$e = $events($db, $w); $m = json_decode(end($e)['meta'], true);
ck('Merge 0 / Updated 69: κλείνει, μόνο updated_nonzero', $r['ok'] === true && $m['warnings'] === array('updated_nonzero'), json_encode($m));

echo "\n═══ ΕΝΕΡΓΟ: παλιά done χωρίς τα νέα γεγονότα (Ν) ═══\n";
$db = fresh_db('fuel');
$db->exec("INSERT INTO `4a_tasks` (`subject_key`,`country`,`task_code`,`assigned_to`,`status`,`closed_by`,`payload`)
           VALUES ('fuel:2026-10-05','BOTH','fuel_weekly',1,'done',1,'{\"kind\":\"fuel_weekly\",\"week_start\":\"2026-10-05\"}')");
$h = (int)$db->lastInsertId();
$db->exec("INSERT INTO `4a_task_events` (`task_id`,`event`,`actor_id`,`note`) VALUES ($h,'done',1,'ιστορικό')");
$t = $listOne($db, 1, $A, $h);
ck('ιστορική done: τελικό βήμα, «ισχύει από Δευ 05/10»', $t['step']['code'] === 'uploaded' && $t['step']['status_label'] === 'Ανέβηκε · ισχύει από Δευ 05/10', json_encode($t['step']));
ck('...χρονολόγιο από τα υπάρχοντα γεγονότα', count($t['timeline']) === 1 && $t['timeline'][0]['label'] === 'Ολοκληρώθηκε (παλιά ροή)');
$db->exec("INSERT INTO `4a_task_events` (`task_id`,`event`,`actor_id`,`note`,`meta`) VALUES ($h,'uploaded',1,'εκ των υστέρων','{\"occurred_at\":\"2026-10-07 07:31:00\"}')");
$t = $listOne($db, 1, $A, $h);
$last = end($t['timeline']);
ck('γεγονός εκ των υστέρων: ώρα από meta.occurred_at, σημαία late', $last['at'] === '2026-10-07 07:31:00' && $last['late'] === true, json_encode($last));

echo "\n═══ ΕΝΕΡΓΟ: reopened — τα βήματα μετρούν από την αρχή ═══\n";
$db = fresh_db('fuel');
list($w, $v, $c, $doer, $other) = $chain($db);
tasks_download($db, $S($doer), $PU($doer), $w);
$db->exec("INSERT INTO `4a_task_events` (`task_id`,`event`,`actor_id`,`note`) VALUES ($w,'reopened',$other,'δοκιμή')");
ck('μετά το reopened: βήμα ξανά download', $listOne($db, $doer, $PU($doer), $w)['step']['code'] === 'download');
$r = tasks_upload($db, $S($doer), $PU($doer), $w, $NUMS);
ck('...και upload -> 409 μέχρι νέα λήψη', $r['code'] === 409);

echo "\n═══ ΕΝΕΡΓΟ: παράκαμψη admin ═══\n";
$db = fresh_db('fuel');
list($w, $v, $c, $doer, $other) = $chain($db);
tasks_download($db, $S($doer), $PU($doer), $w);
$r = tasks_admin_override($db, $S(3), $P, $w, 'λόγος αρκετά μεγάλος');
ck('μη-admin -> 403', $r['code'] === 403);
$r = tasks_admin_override($db, $S($doer), $A, $w, 'λόγος αρκετά μεγάλος');
ck('admin που είναι ο ανάδοχος -> 403', $r['code'] === 403, $r['code'] . ' ' . $r['error']);
$r = tasks_admin_override($db, $S(9), $A, $w, 'σύντομος');
ck('λόγος < 10 χαρακτήρες -> 400', $r['code'] === 400);
$r = tasks_admin_override($db, $S(9), $A, $w, '   ');
ck('κενός λόγος -> 400', $r['code'] === 400);
ck('...καμία αλλαγή μέχρι εδώ', $row($db, $w)['status'] === 'in_progress');
// Συμμετοχή: ο $other γίνεται ανάδοχος με steal-όπως UPDATE, κατεβάζει, και μετά επιστρέφει ο doer.
$db->exec("UPDATE `4a_tasks` SET `assigned_to` = $other WHERE `id` = $w");
tasks_download($db, $S($other), $A, $w);
$db->exec("INSERT INTO `4a_task_events` (`task_id`,`event`,`actor_id`,`note`) VALUES ($w,'upload_failed',$other,'δοκιμή')");
$db->exec("UPDATE `4a_tasks` SET `assigned_to` = $doer WHERE `id` = $w");
$r = tasks_admin_override($db, $S($other), $A, $w, 'λόγος αρκετά μεγάλος');
ck("admin που συμμετείχε (upload_failed), χωρίς να είναι ανάδοχος -> 403", $r['code'] === 403, $r['code'] . ' ' . $r['error']);
$r = tasks_admin_override($db, $S(9), $A, $w, 'Το CMS δίνει Exception χωρίς λόγο');
ck('άλλος admin με λόγο -> κλείνει', $r['ok'] === true && $row($db, $w)['status'] === 'done', $r['code'] . ' ' . $r['error']);
ck('...ξεκλείδωσε το verify', isset($r['unlocked']) && (int)$r['unlocked'] === 1);
$e = $events($db, $w); $last = end($e); $m = json_decode($last['meta'], true);
ck('...γεγονός admin_override, λόγος στο note, βήμα στο meta', $last['event'] === 'admin_override' && (int)$last['actor_id'] === 9
   && $last['note'] === 'Το CMS δίνει Exception χωρίς λόγο' && $m['step'] === 'upload', json_encode($last));
$t = $listOne($db, 1, $A, $w);
$ov = end($t['timeline']);
ck('...κόκκινη γραμμή στο χρονολόγιο', $ov['event'] === 'admin_override' && $ov['alert'] === true && $ov['mark'] === 'event', json_encode($ov));
ck('...η λήψη στο χρονολόγιο είναι βήμα (✓)', $t['timeline'][0]['event'] === 'downloaded' && $t['timeline'][0]['mark'] === 'step', json_encode($t['timeline'][0]));
ck('...override στη λίστα: ποιος, χωρίς επιβεβαίωση', $t['override']['actor_id'] === 9 && $t['override']['acked'] === false);

echo "\n═══ ΕΝΕΡΓΟ: κόκκινο σήμα στον ΑΛΛΟ admin, σβήνει ΜΟΝΟ με «Το είδα» ═══\n";
ck('σήμα: ο admin της παράκαμψης (9) -> 0', tasks_badge($db, $S(9), $A)['overrides'] === 0);
ck('σήμα: άλλος admin (1) -> 1', tasks_badge($db, $S(1), $A)['overrides'] === 1);
ck('σήμα: άλλος admin (2) -> 1', tasks_badge($db, $S(2), $A)['overrides'] === 1);
ck('σήμα: μη-admin -> 0', tasks_badge($db, $S(3), $P)['overrides'] === 0);
$r = tasks_override_ack($db, $S(9), $A, $w);
ck('«Το είδα» από τον ίδιο (9) -> 403', $r['code'] === 403);
$r = tasks_override_ack($db, $S(3), $P, $w);
ck('«Το είδα» από μη-admin -> 403', $r['code'] === 403);
ck('...το σήμα μένει', tasks_badge($db, $S(1), $A)['overrides'] === 1);
$r = tasks_override_ack($db, $S(1), $A, $w);
ck('«Το είδα» από τον 1 -> OK', $r['ok'] === true, $r['code'] . ' ' . $r['error']);
ck('...σβήνει για τον 1 ΚΑΙ για τον 2', tasks_badge($db, $S(1), $A)['overrides'] === 0 && tasks_badge($db, $S(2), $A)['overrides'] === 0);
$e = $events($db, $w);
ck('...γεγονός override_acknowledged', end($e)['event'] === 'override_acknowledged');
$r = tasks_override_ack($db, $S(2), $A, $w);
ck('δεύτερο «Το είδα» -> 409', $r['code'] === 409);
ck('...override στη λίστα: acked', $listOne($db, 1, $A, $w)['override']['acked'] === true);
$r = tasks_admin_override($db, $S(9), $A, $w, 'ξανά σε κλειστή εργασία');
ck('παράκαμψη σε κλειστή -> 409 με σημαία already_closed', $r['code'] === 409 && !empty($r['already_closed']));

echo "\n═══ tasks_close: ταυτόχρονο κλείσιμο — system φράζεται, πελάτη ΟΠΩΣ ΠΡΙΝ ═══\n";
$isDone = function ($e) { return $e['event'] === 'done'; };
$db = fresh_db('empty');
list($w, $v, $c, $doer, $other) = $chain($db);
// «Ταυτόχρονο»: δύο αιτήματα πέρασαν και τα δύο τον έλεγχο με την ΙΔΙΑ εικόνα της εργασίας.
$t = tasks_fetch_one($db, $w);
$a = tasks_close($db, $t, $doer, 'done', 'α');
$b = tasks_close($db, $t, $doer, 'done', 'β');
ck('system: πρώτο κλείσιμο OK, δεύτερο 409 με already_closed', $a['ok'] === true && $b['code'] === 409 && !empty($b['already_closed']), json_encode($b));
ck('system: ένα μόνο γεγονός done', count(array_filter($events($db, $w), $isDone)) === 1);
$db->exec("UPDATE `4a_tasks` SET `assigned_to` = 3, `status` = 'in_progress' WHERE `id` = $c");
$tc = tasks_fetch_one($db, $c);
$a = tasks_close($db, $tc, 3, 'done', 'α');
$b = tasks_close($db, $tc, 3, 'done', 'β');
ck('πελάτης: και τα δύο «περνούν», όπως το UPDATE πριν από τη Φάση 5', $a['ok'] === true && $b['ok'] === true, json_encode($b));
ck('πελάτης: χωρίς σημαία already_closed', !isset($b['already_closed']));

echo "\n═══ Εργασία ΠΕΛΑΤΗ: done / na / διπλό done όπως πριν ═══\n";
foreach (array(null, 'empty', 'fuel') as $mode) {
    $tag = $mode === null ? 'χωρίς πίνακα' : ($mode === 'empty' ? 'άδειος πίνακας' : 'ενεργό fuel_weekly');
    $db = fresh_db($mode);
    list($w, $v, $c, $doer, $other) = $chain($db);
    $db->exec("UPDATE `4a_tasks` SET `assigned_to` = 3, `status` = 'in_progress' WHERE `id` = $c");
    $d1 = tasks_done($db, $S(3), $P, $c);
    $d2 = tasks_done($db, $S(3), $P, $c);
    ck("$tag: done πελάτη OK, ξεκλειδώνει το cms_rates", $d1['ok'] === true && (int)$d1['unlocked'] === 1, json_encode($d1));
    ck("$tag: δεύτερο done -> 409 «η εργασία έχει ήδη κλείσει», ΧΩΡΙΣ σημαία",
       $d2['code'] === 409 && $d2['error'] === 'η εργασία έχει ήδη κλείσει' && !isset($d2['already_closed']), json_encode($d2));
    ck("$tag: ένα γεγονός done «ολοκληρώθηκε»", count(array_filter($events($db, $c), $isDone)) === 1);
    $cr = (int)$db->query("SELECT `id` FROM `4a_tasks` WHERE `client_id` = 7 AND `task_code` = 'cms_rates'")->fetchColumn();
    $db->exec("UPDATE `4a_tasks` SET `assigned_to` = 3, `status` = 'in_progress' WHERE `id` = $cr");
    $n = tasks_na($db, $S(3), $P, $cr, 'λόγος');
    ck("$tag: na πελάτη OK (allow_na = 1)", $n['ok'] === true, json_encode($n));
}

echo "\n═══ Α2α (εύρημα 16/10): chain = ΟΛΗ η αλυσίδα, ΙΔΙΑ σε κάθε καρτέλα ═══\n";
$db = fresh_db('empty');
list($w, $v, $c, $doer, $other) = $chain($db);
$byId = function ($ts, $id) { foreach ($ts as $t) if ((int)$t['id'] === $id) return $t; return null; };
$all = tasks_list($db, $S(9), $A, 'all')['tasks'];
$tw = $byId($all, $w); $tv = $byId($all, $v); $tc = $byId($all, $c);
ck('ρίζα και verify: chain με ΔΥΟ εγγραφές, ρίζα πρώτη', array_column($tw['chain'], 'id') === array($w, $v) && $tv['chain'] === $tw['chain'], json_encode($tw['chain']));
ck('...τα πεδία της εγγραφής, με αυτή τη σειρά', array_keys($tw['chain'][0]) === array('id', 'task_code', 'depends_on', 'status', 'due_at', 'closed_at',
   'locked', 'assigned_name', 'closed_by_name', 'has_steps', 'task_label', 'task_icon', 'task_title_template'), json_encode(array_keys($tw['chain'][0])));
ck('...ο τίτλος του τύπου, ίδιος με της γραμμής (τίτλος ομάδας χωρίς τη ρίζα)', $tw['chain'][0]['task_label'] === $tw['task_label']
   && $tw['chain'][0]['task_icon'] === $tw['task_icon'] && $tw['chain'][0]['task_title_template'] === $tw['task_title_template']
   && $tw['chain'][1]['task_label'] === $tv['task_label']);
ck('...locked ΙΔΙΟ με της tasks_base_sql (ρίζα 0, verify 1)', $tw['chain'][0]['locked'] === (int)$tw['locked'] && $tw['chain'][1]['locked'] === (int)$tv['locked']
   && $tw['chain'][1]['locked'] === 1);
ck('...ανάδοχος, κατάσταση, προθεσμία από τη βάση', $tw['chain'][0]['assigned_name'] === 'u' . $doer && $tw['chain'][0]['status'] === $tw['status']
   && $tw['chain'][1]['due_at'] === $tv['due_at'] && $tw['chain'][0]['closed_by_name'] === null);
ck('...has_steps false χωρίς γραμμές στον 4a_task_steps', $tw['chain'][0]['has_steps'] === false);
ck('εργασία πελάτη: ΚΑΝΕΝΑ chain', !array_key_exists('chain', $tc));
$q = $byId(tasks_list($db, $S(9), $A, 'queue')['tasks'], $v);
ck('«Ουρά»: το verify (χωρίς τη ρίζα στη λίστα) έχει ΟΛΗ την αλυσίδα, ίδια με την «Όλες»', $q !== null && $q['chain'] === $tv['chain'], json_encode($q));
tasks_done($db, $S($doer), $PU($doer), $w);
$mine = tasks_list($db, $S($other), $PU($other), 'mine')['tasks'];
$all = tasks_list($db, $S(9), $A, 'all')['tasks'];
ck('«Οι εργασίες μου» του άλλου: μόνο το verify, αλλά chain ΙΔΙΟ με την «Όλες»',
   count($mine) === 1 && (int)$mine[0]['id'] === $v && $mine[0]['chain'] === $byId($all, $v)['chain'], json_encode($mine[0]['chain'] ?? null));
$r0 = $mine[0]['chain'][0];
ck("...ρίζα done, closed_by_name = u$doer, closed_at από τη βάση (για «✓ ① Ανέβηκε από …»)",
   $r0['status'] === 'done' && $r0['closed_by_name'] === 'u' . $doer && $r0['closed_at'] !== null
   && $r0['closed_at'] === $db->query("SELECT `closed_at` FROM `4a_tasks` WHERE `id` = $w")->fetchColumn(), json_encode($r0));
ck('...το verify ξεκλείδωσε και στο chain', $mine[0]['chain'][1]['locked'] === 0 && (int)$mine[0]['locked'] === 0);
$db->exec("UPDATE `4a_tasks` SET `status` = 'na' WHERE `id` = $w");
ck('ρίζα «na»: το verify μένει ξεκλείδωτο στο chain (κανόνας της tasks_base_sql)',
   $byId(tasks_list($db, $S(9), $A, 'all')['tasks'], $v)['chain'][1]['locked'] === 0);
$db = fresh_db('fuel');
list($w) = $chain($db);
ck('τύπος με βήματα: has_steps true στη ρίζα, false στο verify', (function () use ($db, $A, $S, $byId, $w) {
    $ch = $byId(tasks_list($db, $S(9), $A, 'all')['tasks'], $w)['chain'];
    return $ch[0]['has_steps'] === true && $ch[1]['has_steps'] === false; })());
$db = fresh_db('empty');
$a = tasks_create_system($db, 'fuel_weekly', 'fuel:2026-10-12', $PAYLOAD, 'BOTH', '2026-10-09 17:00:00');
$b = tasks_create_system($db, 'fuel_weekly', 'fuel:2026-10-19', $PAYLOAD, 'BOTH', '2026-10-16 17:00:00');
$l = tasks_list($db, $S(9), $A, 'all')['tasks'];
ck('δύο εβδομάδες: κάθε μία τη ΔΙΚΗ της αλυσίδα (dedupe_key)', array_column($byId($l, (int)$a['task_id'])['chain'], 'id') === array((int)$a['task_id'])
   && array_column($byId($l, (int)$b['task_id'])['chain'], 'id') === array((int)$b['task_id']));
ck('ΕΝΑ ερώτημα για όλη τη λίστα (όχι ανά εργασία)', substr_count(file_get_contents(__DIR__ . '/../../api/tasks_lib.php'), 'LEFT JOIN `4a_users` cb ON cb.`id` = d.`closed_by`') === 1
   && preg_match('/function tasks_chain\(.*?\n}\n/s', file_get_contents(__DIR__ . '/../../api/tasks_lib.php'), $m) && substr_count($m[0], '->prepare(') === 1);

printf("\n═══ ΣΥΝΟΛΟ: %d OK, %d ΛΑΘΟΣ ═══\n\n", $pass, $fail);
exit($fail ? 1 : 0);
