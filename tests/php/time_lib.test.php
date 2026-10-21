<?php
/* tests/php/time_lib.test.php
 *
 *   php tests/php/time_lib.test.php        (από τη ρίζα του repo)
 *
 * Ζώνη ώρας (απόφαση 21/10): api/time_lib.php, η ΜΙΑ πηγή για κάθε «τώρα»,
 * σύγκριση και μετατροπή. Στιγμές της βάσης = UTC, προθεσμίες = ώρα Αθήνας.
 * Ώρες πριν / μετά την αλλαγή ώρας της Κυρ 25/10/2026 03:00 (EEST UTC+3 ->
 * EET UTC+2). Καμία βάση, κανένα δίκτυο. Η date.timezone της PHP αλλάζει
 * επίτηδες για να φανεί ότι δεν παίζει ρόλο.
 */

require_once __DIR__ . '/../../api/time_lib.php';

$pass = 0; $fail = 0;
function ck($label, $ok, $extra = '')
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("   %s %s%s\n", $ok ? 'OK   ' : 'ΛΑΘΟΣ', $label, $ok ? '' : "   → $extra");
}

foreach (array('UTC', 'Europe/Athens', 'America/New_York') as $phpTz) {
date_default_timezone_set($phpTz);
echo "\n═══ date.timezone της PHP = $phpTz (δεν πρέπει να αλλάζει τίποτα) ═══\n";

echo "── time_db_to_local: στιγμή της βάσης (UTC) -> ώρα Αθήνας\n";
ck('#109 λήψη 21/10 08:45:47 UTC -> 11:45:47 (EEST, +3)', time_db_to_local('2026-10-21 08:45:47') === '2026-10-21 11:45:47', time_db_to_local('2026-10-21 08:45:47'));
ck('#109 ανάθεση 20/10 13:09:02 UTC -> 16:09:02', time_db_to_local('2026-10-20 13:09:02') === '2026-10-20 16:09:02');
ck('ΜΕΤΑ την αλλαγή: 26/10 08:45 UTC -> 10:45 (EET, +2)', time_db_to_local('2026-10-26 08:45:00') === '2026-10-26 10:45:00');
ck('οριακά: 25/10 00:59:59 UTC -> 03:59:59 (ακόμα +3)', time_db_to_local('2026-10-25 00:59:59') === '2026-10-25 03:59:59');
ck('οριακά: 25/10 01:00:00 UTC -> 03:00:00 (ΗΔΗ +2: η ώρα 03 ξαναπερνά)', time_db_to_local('2026-10-25 01:00:00') === '2026-10-25 03:00:00');
ck('αλλαγή ημέρας: 25/10 22:30 UTC -> Δευ 26/10 00:30', time_db_to_local('2026-10-25 22:30:00') === '2026-10-26 00:30:00');
ck('null / κενό -> null', time_db_to_local(null) === null && time_db_to_local('') === null);
ck('μη ημερομηνία -> όπως ήρθε (καμία σιωπηλή αλλοίωση)', time_db_to_local('x') === 'x' && time_db_to_local('2026-02-30 10:00:00') === '2026-02-30 10:00:00');

echo "── εκπρόθεσμο: στιγμή της βάσης (UTC) έναντι προθεσμίας (Αθήνα) ΜΟΝΟ μέσω time_db_after_local\n";
ck('② ανέβηκε 23/10 17:30 UTC (= 20:30 Αθήνας), προθεσμία 19:00 -> ΕΚΠΡΟΘΕΣΜΟ', time_db_after_local('2026-10-23 17:30:00', '2026-10-23 19:00:00') === true);
ck('...ενώ η ωμή σύγκριση κειμένου «17:30 < 19:00» θα το έλεγε εμπρόθεσμο (το εύρημα)', '2026-10-23 17:30:00' < '2026-10-23 19:00:00');
ck('23/10 15:59:59 UTC (18:59:59) -> εμπρόθεσμο', time_db_after_local('2026-10-23 15:59:59', '2026-10-23 19:00:00') === false);
ck('23/10 16:00:00 UTC (= 19:00:00 ακριβώς) -> εμπρόθεσμο (ΟΧΙ μετά)', time_db_after_local('2026-10-23 16:00:00', '2026-10-23 19:00:00') === false);
ck('ΜΕΤΑ την αλλαγή: 30/10 16:30 UTC (= 18:30 EET), προθεσμία 19:00 -> εμπρόθεσμο (σταθερό +3 θα έλεγε 19:30)', time_db_after_local('2026-10-30 16:30:00', '2026-10-30 19:00:00') === false);
ck('ΜΕΤΑ την αλλαγή: 30/10 17:00:01 UTC (= 19:00:01) -> εκπρόθεσμο', time_db_after_local('2026-10-30 17:00:01', '2026-10-30 19:00:00') === true);
ck('άκυρη είσοδος -> null', time_db_after_local('x', '2026-10-30 19:00:00') === null && time_db_after_local('2026-10-30 17:00:00', null) === null);

echo "── time_reached_local: Δ-Α8 «Δευ 00:00 Αθήνας» έναντι «τώρα» (UTC)\n";
ck('εβδομάδα 19/10 (+3): 18/10 20:59:59 UTC -> όχι ακόμα', time_reached_local('2026-10-19 00:00:00', '2026-10-18 20:59:59') === false);
ck('...18/10 21:00:00 UTC -> ναι', time_reached_local('2026-10-19 00:00:00', '2026-10-18 21:00:00') === true);
ck('εβδομάδα 26/10 (+2): 25/10 21:30 UTC -> όχι ακόμα (σταθερό +3 θα έλεγε ναι)', time_reached_local('2026-10-26 00:00:00', '2026-10-25 21:30:00') === false);
ck('...25/10 22:00:00 UTC -> ναι', time_reached_local('2026-10-26 00:00:00', '2026-10-25 22:00:00') === true);
ck('άκυρη ώρα Αθήνας -> null', time_reached_local('2026-10-26', '2026-10-25 22:00:00') === null);

echo "── time_local_today\n";
ck('10/10 22:30 UTC -> Κυρ 11/10 στην Αθήνα', time_local_today('2026-10-10 22:30:00') === '2026-10-11');
ck('25/10 21:30 UTC -> ακόμα 25/10 (+2)', time_local_today('2026-10-25 21:30:00') === '2026-10-25');
ck('25/10 22:00 UTC -> 26/10', time_local_today('2026-10-25 22:00:00') === '2026-10-26');
ck('χωρίς όρισμα: η πραγματική ημέρα Αθήνας', time_local_today() === (new DateTime('now', new DateTimeZone('Europe/Athens')))->format('Y-m-d'));
ck('άκυρο «τώρα» -> εξαίρεση (ποτέ σιωπηλά «τώρα»)', (function () { try { time_now('x'); return false; } catch (InvalidArgumentException $e) { return true; } })());
}

echo "\n═══ ΜΙΑ πηγή: καμία άλλη ζώνη ή «τώρα» στον κώδικα των εργασιών ═══\n";
// Από τη διαδρομή του time_lib.php: τοπικά το repo, στο bundle του Β8 τα ΑΝΕΒΑΣΜΕΝΑ αρχεία του server.
$api = dirname(__DIR__ . '/../../api/time_lib.php') . '/';
$files = array('tasks_lib.php', 'tasks_steps.php', 'tasks_create.php', 'tasks_system.php', 'tasks.php', 'fuel_weekly.php', 'fuel_lib.php');
$hits = array();
foreach ($files as $f) {
    $src = preg_replace('~^\s*(//|\*|/\*).*$~m', '', file_get_contents($api . $f));   // χωρίς σχόλια
    foreach (array("'Europe/Athens'", "new DateTimeZone('UTC')", 'date_default_timezone', 'strtotime(', "new DateTime('now'", '>= NOW()', '< NOW()', '> NOW()') as $needle)
        if (strpos($src, $needle) !== false) $hits[] = "$f: $needle";
}
ck('tasks_* / fuel_weekly / fuel_lib: καμία σταθερή ζώνη, κανένα δικό τους «τώρα», καμία σύγκριση με NOW()', !$hits, implode(' · ', $hits));
ck("η ζώνη Αθήνας γράφεται ΜΟΝΟ στο time_lib.php", substr_count(file_get_contents($api . 'time_lib.php'), "'Europe/Athens'") === 1);
$REQ = "~require_once _{2}DIR_{2} \\. '/time_lib\\.php';~";   // χωρίς τη λέξη αυτούσια: ο bundler του Β8 μετρά κάθε εμφάνισή της
ck('tasks_lib / tasks_steps / fuel_lib φορτώνουν το time_lib.php', preg_match($REQ, file_get_contents($api . 'tasks_lib.php'))
   && preg_match($REQ, file_get_contents($api . 'tasks_steps.php')) && preg_match($REQ, file_get_contents($api . 'fuel_lib.php')));

echo "\n═══ migration 2026-10-22d: η αναδρομική εβδομάδα 05/10 (07h) σε UTC ═══\n";
$mig = file_get_contents(__DIR__ . '/../../db/migrations/2026-10-22d_tz_fuel_week_1005_utc.sql');
$rb  = file_get_contents(__DIR__ . '/../../db/migrations/rollback/2026-10-22d_tz_fuel_week_1005_utc.rollback.sql');
$m07h = file_get_contents(__DIR__ . '/../../db/migrations/2026-10-07h_fuel_weekly_history.sql');
$utc = time_instant('2026-10-07 07:31:00', 'local')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
ck("07:31 Αθήνας (07/10, EEST) = $utc UTC, η τιμή του 22d", $utc === '2026-10-07 04:31:00' && substr_count($mig, "'2026-10-07 04:31:00'") === 3);
ck('το 07h έγραψε ΑΚΡΙΒΩΣ 07:31:00 (4 φορές: εργασία created/closed, 2 γεγονότα)', substr_count($m07h, "'2026-10-07 07:31:00'") === 4);
$stmts = function ($sql) {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql)), function ($x) { return $x !== '' && stripos($x, 'SET NAMES') !== 0 && stripos($x, 'SELECT') !== 0; }));
};
ck('22d και rollback: ΜΟΝΟ 2 UPDATE το καθένα, κανένα DDL / DELETE / INSERT', count($stmts($mig)) === 2 && count($stmts($rb)) === 2
   && !preg_match('/\b(CREATE|ALTER|DROP|DELETE|INSERT|REPLACE)\b/i', implode("\n", array_merge($stmts($mig), $stmts($rb)))));
ck("κανένα ; ή ' μέσα σε σχόλιο (22d και rollback)", !preg_match("/^\\s*--[^\\n]*[;']/m", $mig . "\n" . $rb));
// Εξομοίωση σε SQLite: η εβδομάδα 05/10, ΚΑΙ δολώματα που ΔΕΝ πρέπει να αλλάξουν.
$db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE `4a_tasks` (`id` INTEGER PRIMARY KEY, `task_code` TEXT, `dedupe_key` TEXT, `status` TEXT, `created_at` TEXT, `closed_at` TEXT)');
$db->exec('CREATE TABLE `4a_task_events` (`id` INTEGER PRIMARY KEY, `task_id` INTEGER, `event` TEXT, `created_at` TEXT)');
$db->exec("INSERT INTO `4a_tasks` VALUES (1,'fuel_weekly','fuel:2026-10-05','done','2026-10-07 07:31:00','2026-10-07 07:31:00'),
                                         (2,'fuel_weekly','fuel:2026-10-12','done','2026-10-07 07:31:00','2026-10-07 07:31:00'),
                                         (3,'fuel_weekly_verify','fuel:2026-10-05','open','2026-10-07 07:31:00',NULL)");
$db->exec("INSERT INTO `4a_task_events` VALUES (1,1,'created','2026-10-07 07:31:00'),(2,1,'done','2026-10-07 07:31:00'),
                                                (3,1,'note','2026-10-07 07:31:00'),(4,2,'created','2026-10-07 07:31:00'),(5,1,'file_viewed','2026-10-08 09:00:00')");
$snap = function () use ($db) { return array($db->query('SELECT * FROM `4a_tasks` ORDER BY `id`')->fetchAll(PDO::FETCH_NUM), $db->query('SELECT * FROM `4a_task_events` ORDER BY `id`')->fetchAll(PDO::FETCH_NUM)); };
$before = $snap();
$run = function ($sql) use ($db, $stmts) { $n = array(); foreach ($stmts($sql) as $q) $n[] = $db->exec($q); return $n; };
$n1 = $run($mig);
list($t, $e) = $snap();
ck('22d: γεγονότα 2 γραμμές, εργασία 1 γραμμή', $n1 === array(2, 1), json_encode($n1));
ck('...η εβδομάδα 05/10: created_at = closed_at = 04:31:00, created / done 04:31:00', $t[0][4] === '2026-10-07 04:31:00' && $t[0][5] === '2026-10-07 04:31:00'
   && $e[0][3] === '2026-10-07 04:31:00' && $e[1][3] === '2026-10-07 04:31:00', json_encode(array($t[0], $e[0], $e[1])));
ck('...δολώματα ΙΔΙΑ: άλλη εβδομάδα, verify, γεγονός note, άλλη ώρα', array($t[1], $t[2], $e[2], $e[3], $e[4]) === array($before[0][1], $before[0][2], $before[1][2], $before[1][3], $before[1][4]));
ck('22d δεύτερη φορά: 0 / 0 (idempotent)', $run($mig) === array(0, 0));
ck('rollback: 2 / 1 και ΟΛΑ ξανά όπως πριν', $run($rb) === array(2, 1) && $snap() === $before);
ck('rollback δεύτερη φορά: 0 / 0', $run($rb) === array(0, 0));
ck('μετά το 22d η οθόνη δείχνει ξανά 07:31 (UTC -> Αθήνα)', time_db_to_local('2026-10-07 04:31:00') === '2026-10-07 07:31:00');

echo "\n═══ ΣΥΝΟΛΟ: $pass OK, $fail ΛΑΘΟΣ ═══\n";
exit($fail ? 1 : 0);
