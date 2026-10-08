<?php
/* tests/php/tasks_both.test.php
 *
 *   php tests/php/tasks_both.test.php        (από τη ρίζα του repo)
 *
 * Ο κανόνας BOTH του api/tasks_lib.php, ΑΥΣΤΗΡΗ ερμηνεία (07/10/2026):
 * εργασία με country = BOTH θέλει δεξιότητα για τον κωδικό με country =
 * BOTH. Δεξιότητα GR ή CY δεν αρκεί.
 *
 * Τρέχει τις ΠΡΑΓΜΑΤΙΚΕΣ συναρτήσεις και το ΠΡΑΓΜΑΤΙΚΟ SQL τους πάνω σε
 * SQLite στη μνήμη. Καμία πραγματική βάση, κανένα δίκτυο, κανένα config.
 * Χρειάζεται pdo_sqlite. Έξοδος 1 αν έστω ένας έλεγχος πέσει.
 */

require_once __DIR__ . '/../../api/tasks_lib.php';

$pass = 0; $fail = 0;
function ck($label, $ok, $extra = '')
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("   %s %s%s\n", $ok ? 'OK   ' : 'ΛΑΘΟΣ', $label, $ok ? '' : "   → $extra");
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (array(
    'CREATE TABLE `4a_clients` (`id` INTEGER PRIMARY KEY, `name` TEXT, `country` TEXT, `account` TEXT, `is_demo` INTEGER NOT NULL DEFAULT 0)',
    'CREATE TABLE `4a_users` (`id` INTEGER PRIMARY KEY, `name` TEXT, `role` TEXT, `active` INTEGER)',
    'CREATE TABLE `4a_task_types` (`code` TEXT PRIMARY KEY, `label` TEXT, `sort_order` INTEGER, `depends_on` TEXT,
        `kind` TEXT NOT NULL DEFAULT \'client\', `icon` TEXT, `title_template` TEXT, `due_warn_hours` INTEGER, `due_urgent_hours` INTEGER, `exclude_prev_assignee` INTEGER NOT NULL DEFAULT 0,
        `action_url` TEXT, `action_label` TEXT, `action_module` TEXT, `ready_check` TEXT, `ready_hint` TEXT, `ready_enforced` INTEGER)',
    'CREATE TABLE `4a_tasks` (`id` INTEGER PRIMARY KEY, `client_id` INTEGER, `subject_key` TEXT, `country` TEXT,
        `dedupe_key` TEXT, `task_code` TEXT, `offer_number` TEXT NOT NULL DEFAULT \'\', `assigned_to` INTEGER,
        `status` TEXT NOT NULL DEFAULT \'open\', `needs_attention` INTEGER NOT NULL DEFAULT 0,
        `created_at` TEXT, `due_at` TEXT, `closed_at` TEXT, `closed_by` INTEGER, `close_reason` TEXT, `payload` TEXT)',
    'CREATE TABLE `4a_user_task_skills` (`user_id` INTEGER, `task_code` TEXT, `country` TEXT)',
    'CREATE TABLE `4a_task_rejections` (`task_id` INTEGER, `user_id` INTEGER)',
    'CREATE TABLE `4a_task_events` (`id` INTEGER PRIMARY KEY, `task_id` INTEGER, `event` TEXT, `actor_id` INTEGER, `note` TEXT, `meta` TEXT)',
) as $ddl) $db->exec($ddl);

// Χρήστες: 10 δεξιότητα GR · 11 δεξιότητα BOTH, scope GR · 12 δεξιότητα BOTH, scope NONE ·
//          13 δεξιότητα CY · 99 διαχειριστής χωρίς δεξιότητες.
$db->exec("INSERT INTO `4a_users` VALUES (10,'u10','user',1),(11,'u11','user',1),(12,'u12','user',1),(13,'u13','user',1),(99,'adm','administrator',1)");
$db->exec("INSERT INTO `4a_task_types` (`code`,`label`,`sort_order`,`kind`) VALUES ('fuel_weekly','Επίναυλος εβδομάδας',10,'system'),('client_x','Εργασία πελάτη',20,'client')");
$db->exec("INSERT INTO `4a_user_task_skills` VALUES
           (10,'fuel_weekly','GR'),(11,'fuel_weekly','BOTH'),(12,'fuel_weekly','BOTH'),(13,'fuel_weekly','CY'),
           (10,'client_x','GR')");
$db->exec("INSERT INTO `4a_clients` VALUES (1,'Πελάτης GR','GR','ACC1',0)");
// 1: εργασία συστήματος BOTH · 2: εργασία πελάτη GR
$db->exec("INSERT INTO `4a_tasks` (`id`,`client_id`,`subject_key`,`country`,`dedupe_key`,`task_code`,`needs_attention`)
           VALUES (1,NULL,'fuel:2026-10-12','BOTH','fuel:2026-10-12','fuel_weekly',1),
                  (2,1,NULL,NULL,'c:1:','client_x',0)");

$P = function ($scope) { return array('role' => 'user', 'pricelist_scope' => $scope); };
$S = function ($id) { return array('id' => $id); };
$ids = function ($r) { return array_map(function ($t) { return (int)$t['id']; }, $r['tasks']); };

echo "\n═══ tasks_has_skill: εργασία BOTH θέλει δεξιότητα BOTH ═══\n";
ck('δεξιότητα GR  -> ΟΧΙ', !tasks_has_skill($db, 10, 'fuel_weekly', 'BOTH'));
ck('δεξιότητα CY  -> ΟΧΙ', !tasks_has_skill($db, 13, 'fuel_weekly', 'BOTH'));
ck('δεξιότητα BOTH -> ναι', tasks_has_skill($db, 11, 'fuel_weekly', 'BOTH'));
ck('εργασία GR, δεξιότητα GR -> ναι (αμετάβλητο)', tasks_has_skill($db, 10, 'client_x', 'GR'));

echo "\n═══ tasks_base_sql: εργασία χωρίς πελάτη ═══\n";
$t1 = tasks_fetch_one($db, 1);
ck('η εργασία BOTH επιστρέφεται (LEFT JOIN)', $t1 !== null);
ck('client_country = BOTH (COALESCE)', $t1 && $t1['client_country'] === 'BOTH', $t1 ? $t1['client_country'] : '');
ck('is_demo = 0 χωρίς πελάτη (COALESCE)', $t1 && (int)$t1['is_demo'] === 0);
$t2 = tasks_fetch_one($db, 2);
ck('εργασία πελάτη: client_country = GR', $t2 && $t2['client_country'] === 'GR');

echo "\n═══ Ουρά: δεν τη ΒΛΕΠΕΙ χρήστης με δεξιότητα GR ═══\n";
$q10 = $ids(tasks_list($db, $S(10), $P('GR'), 'queue'));
ck('χρήστης 10 (GR): ΟΧΙ η εργασία BOTH', !in_array(1, $q10, true), json_encode($q10));
ck('χρήστης 10 (GR): βλέπει την εργασία πελάτη GR', in_array(2, $q10, true), json_encode($q10));
$q13 = $ids(tasks_list($db, $S(13), $P('CY'), 'queue'));
ck('χρήστης 13 (CY): ΟΧΙ η εργασία BOTH', !in_array(1, $q13, true), json_encode($q13));
$q11 = $ids(tasks_list($db, $S(11), $P('GR'), 'queue'));
ck('χρήστης 11 (BOTH, scope GR): βλέπει την εργασία BOTH', in_array(1, $q11, true), json_encode($q11));
$q12 = $ids(tasks_list($db, $S(12), $P('NONE'), 'queue'));
ck('χρήστης 12 (BOTH, scope NONE): βλέπει την εργασία BOTH', $q12 === array(1), json_encode($q12));

echo "\n═══ Δεξαμενή τυχαίας ανάθεσης ═══\n";
$pool = tasks_candidate_pool($db, 1, 'fuel_weekly', 'BOTH');
sort($pool);
ck('μόνο οι χρήστες με δεξιότητα BOTH (11, 12)', $pool === array(11, 12), json_encode($pool));

echo "\n═══ Σήμα «προσοχή» ═══\n";
$b10 = tasks_badge($db, $S(10), $P('GR'));
ck('χρήστης 10 (GR): η εργασία BOTH ΔΕΝ μετρά', $b10['attention'] === 0, json_encode($b10));
$b11 = tasks_badge($db, $S(11), $P('GR'));
ck('χρήστης 11 (BOTH): μετρά', $b11['attention'] === 1, json_encode($b11));
$b12 = tasks_badge($db, $S(12), $P('NONE'));
ck('χρήστης 12 (BOTH, scope NONE): μετρά', $b12['attention'] === 1, json_encode($b12));

echo "\n═══ Claim: δεν την ΠΑΙΡΝΕΙ χρήστης με δεξιότητα GR ═══\n";
$c10 = tasks_claim($db, $S(10), $P('GR'), 1);
ck('χρήστης 10 (GR): 403', $c10['code'] === 403, $c10['code'] . ' ' . $c10['error']);
$c13 = tasks_claim($db, $S(13), $P('CY'), 1);
ck('χρήστης 13 (CY): 403', $c13['code'] === 403, $c13['code'] . ' ' . $c13['error']);
$after = tasks_fetch_one($db, 1);
ck('η εργασία έμεινε αδιάθετη', $after['assigned_to'] === null);
$c11 = tasks_claim($db, $S(11), $P('GR'), 1);
ck('χρήστης 11 (BOTH): ανάληψη OK', $c11['ok'] === true, $c11['code'] . ' ' . $c11['error']);
ck('ανάδοχος = 11', (int)tasks_fetch_one($db, 1)['assigned_to'] === 11);

echo "\n═══ Ανάθεση από διαχειριστή χωρίς δεξιότητα: ο ανάδοχος τη βλέπει ═══\n";
$db->exec("UPDATE `4a_tasks` SET `assigned_to` = 10, `status` = 'in_progress' WHERE `id` = 1");
$m10 = $ids(tasks_list($db, $S(10), $P('GR'), 'mine'));
ck('χρήστης 10 ως ανάδοχος: τη βλέπει στις «δικές μου»', in_array(1, $m10, true), json_encode($m10));
$t = tasks_fetch_one($db, 1);
ck('tasks_country_ok για τον ανάδοχο', tasks_country_ok($db, array('GR'), $t, 10));
ck('tasks_country_ok για άλλον με δεξιότητα GR -> ΟΧΙ', !tasks_country_ok($db, array('GR'), $t, 13));
ck('διαχειριστής: χωρίς φίλτρο χώρας', tasks_country_ok($db, null, $t, 99));

printf("\n═══ ΣΥΝΟΛΟ: %d OK, %d ΛΑΘΟΣ ═══\n\n", $pass, $fail);
exit($fail ? 1 : 0);
