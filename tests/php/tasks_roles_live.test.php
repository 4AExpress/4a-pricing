<?php
/* tests/php/tasks_roles_live.test.php
 *
 * ΖΩΝΤΑΝΗ MySQL, ΜΟΝΟ ΑΝΑΓΝΩΣΗ. Τρέχει ΜΟΝΟ στον server, στο Β8, μέσω
 * stdin (bundle του tools/bundle_php_tests.js). Τοπικά δεν έχει βάση.
 *
 * Γιατί υπάρχει (13/10/2026): τα άλλα τεστ τρέχουν σε SQLite, που δεν έχει
 * collations. Το COALESCE(t.country, c.country) με δύο διαφορετικά
 * collation έδινε 500 σε κάθε μη-admin με πεδίο χώρας, και κανένα τεστ
 * δεν το είδε. Εδώ τρέχει το ΠΡΑΓΜΑΤΙΚΟ SQL στην ΠΡΑΓΜΑΤΙΚΗ βάση.
 *
 * Για κάθε συνδυασμό (ρόλος, pricelist_scope) με ενεργό χρήστη, με τα
 * ΠΡΑΓΜΑΤΙΚΑ δικαιώματα (auth.php get_user_permissions):
 *   χωρίς tasks.view          mine, queue, all, badge -> 403 (όπως το require_permission)
 *   administrator             mine, queue, all, badge -> 200
 *   κάθε άλλος με tasks.view  mine, queue, badge -> 200 · all -> 403 με μήνυμα
 * ΠΟΤΕ 500. Κάθε 403 έχει μη κενό μήνυμα.
 *
 * Ασφάλεια:
 *   · ENGINE των 4a_tasks, 4a_task_events = InnoDB, αλλιώς ΣΤΟΠ (exit 1)
 *     πριν από οτιδήποτε άλλο.
 *   · Το auth.php εκτελεί στο include CREATE TABLE IF NOT EXISTS
 *     4a_sessions. Ελέγχεται ΠΡΙΝ ότι ο πίνακας υπάρχει (άρα no-op),
 *     αλλιώς ΣΤΟΠ. Το include γίνεται ΕΞΩ από τη συναλλαγή.
 *   · Συνεδρία READ ONLY, beginTransaction … rollBack ΠΑΝΤΑ (finally).
 *   · 4a_tasks / 4a_task_events μετρώνται πριν και μετά: πρέπει ίσα.
 *   · Τυπώνει ΜΟΝΟ ρόλο, scope, view, κωδικό και, σε σφάλμα, μήνυμα +
 *     αρχείο:γραμμή. Κανένα όνομα, κανένα στοιχείο σύνδεσης.
 * Έξοδος 1 αν έστω ένας έλεγχος πέσει.
 */

chdir(dirname(__DIR__ . '/../../api/config.php'));
require_once __DIR__ . '/../../api/config.php';

$pass = 0; $fail = 0;
function ck($label, $ok, $extra = '')
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("   %s %s%s\n", $ok ? 'OK   ' : 'ΛΑΘΟΣ', $label, $ok ? '' : "   → $extra");
}

$db = db();

echo "\n═══ προϋποθέσεις (πριν από οτιδήποτε) ═══\n";
$eng = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME IN ('4a_tasks', '4a_task_events', '4a_sessions')")
          ->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['4a_tasks', '4a_task_events'] as $t) {
    $e = isset($eng[$t]) ? $eng[$t] : '(λείπει)';
    if ($e !== 'InnoDB') { echo "   ΣΤΟΠ: $t ENGINE = $e, όχι InnoDB. Δεν τρέχει τίποτα.\n"; exit(1); }
    echo "   OK    $t ENGINE = InnoDB\n";
}
if (!isset($eng['4a_sessions'])) { echo "   ΣΤΟΠ: λείπει ο 4a_sessions, το auth.php θα έκανε CREATE. Δεν τρέχει τίποτα.\n"; exit(1); }
echo "   OK    4a_sessions υπάρχει (το CREATE IF NOT EXISTS του auth.php = no-op)\n";

require_once __DIR__ . '/../../api/auth.php';
require_once __DIR__ . '/../../api/tasks_lib.php';

$count = function () use ($db) {
    return [(int)$db->query('SELECT COUNT(*) FROM `4a_tasks`')->fetchColumn(),
            (int)$db->query('SELECT COUNT(*) FROM `4a_task_events`')->fetchColumn()];
};

$db->exec('SET SESSION TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $before = $count();

    // Ένας χρήστης ανά (ρόλος, scope): το φίλτρο χώρας εξαρτάται από το scope.
    $users = $db->query("SELECT `role`, COALESCE(`pricelist_scope`, 'GR') AS scope, MIN(`id`) AS id
                           FROM `4a_users` WHERE `active` = 1
                          GROUP BY `role`, COALESCE(`pricelist_scope`, 'GR')
                          ORDER BY `role`, scope")->fetchAll(PDO::FETCH_ASSOC);

    $sawAdmin = false; $sawOther = false;
    foreach ($users as $u) {
        $role = $u['role']; $scope = $u['scope'];
        echo "\n═══ role=$role scope=$scope ═══\n";
        $perms  = get_user_permissions((int)$u['id']);
        $canView = $perms && !empty($perms['permissions']['tasks']['view']);
        $isAdmin = $perms && tasks_is_admin($perms);
        if ($isAdmin) $sawAdmin = true; elseif ($canView) $sawOther = true;
        $session = ['id' => (int)$u['id'], 'role' => $role, 'permissions' => $perms];

        foreach (['mine', 'queue', 'all', 'badge'] as $v) {
            // Ό,τι θα έβλεπε ο browser από το tasks.php
            if (!$canView) {
                $code = 403; $msg = 'Forbidden';                // require_permission
            } else {
                try {
                    $r = $v === 'badge' ? tasks_badge($db, $session, $perms)
                                        : tasks_list($db, $session, $perms, $v);
                    $code = (int)$r['code'];
                    $msg  = $r['ok'] ? '' : (string)$r['error'];
                    if ($r['ok'] && json_encode($r) === false) { $code = 500; $msg = 'json_encode: ' . json_last_error_msg(); }
                } catch (Throwable $e) {
                    $code = 500;
                    $msg  = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                }
            }
            $want = !$canView ? 403 : (($v === 'all' && !$isAdmin) ? 403 : 200);
            ck(sprintf('%-5s -> %d', $v, $want), $code === $want, "πήρε $code $msg");
            if ($code === 403) ck("$v: 403 με μήνυμα", trim($msg) !== '', 'κενό μήνυμα');
            if ($code === 500) ck("$v: ΠΟΤΕ 500", false, $msg);
        }
    }

    echo "\n═══ κάλυψη ═══\n";
    ck('υπάρχει administrator', $sawAdmin);
    ck('υπάρχει μη-admin με tasks.view', $sawOther);

    echo "\n═══ καμία εγγραφή ═══\n";
    $after = $count();
    ck("4a_tasks {$before[0]}/{$after[0]}, 4a_task_events {$before[1]}/{$after[1]}", $before === $after);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    echo "   rollBack ok\n";
}

printf("\n═══ ΣΥΝΟΛΟ: %d OK, %d ΛΑΘΟΣ ═══\n", $pass, $fail);
exit($fail ? 1 : 0);
