<?php
/* tests/php/ui_texts.test.php
 *
 *   php tests/php/ui_texts.test.php        (από τη ρίζα του repo)
 *
 * Φάση Α, Α1 (Π1): api/ui_texts_lib.php, api/ui_texts.php και το
 * migration 2026-10-15b. SQLite στη μνήμη, γεμισμένο με το ΙΔΙΟ seed του
 * migration (διαβάζεται από το αρχείο). Κανένα δίκτυο, τίποτα δεν γράφεται.
 * Έξοδος 1 αν έστω ένας έλεγχος πέσει.
 */

require_once __DIR__ . '/../../api/ui_texts_lib.php';

$pass = 0; $fail = 0;
function ck($label, $ok, $extra = '')
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("   %s %s%s\n", $ok ? 'OK   ' : 'ΛΑΘΟΣ', $label, $ok ? '' : "   → $extra");
}

$mig = file_get_contents(__DIR__ . '/../../db/migrations/2026-10-15b_ui_texts.sql');
$rb  = file_get_contents(__DIR__ . '/../../db/migrations/rollback/2026-10-15b_ui_texts.rollback.sql');
$ep  = file_get_contents(__DIR__ . '/../../api/ui_texts.php');

// Οι γραμμές του seed, όπως είναι γραμμένες στο migration.
preg_match_all("/\\(\\s*'([a-z_]+)',\\s*'(\\w+)',\\s*'(text|color)',\\s*'([^']*)'\\)/u", $mig, $m, PREG_SET_ORDER);
$seed = array();
foreach ($m as $r) $seed[] = array('scope' => $r[1], 'k' => $r[2], 'kind' => $r[3], 'text' => $r[4]);

// Ο πίνακας Δ του K4a_review_1014.md, ΟΠΩΣ ΕΓΚΡΙΘΗΚΕ 14/10 (με τις αλλαγές της ίδιας μέρας).
$APPROVED = array(
    'common'  => array('link_icon' => '🔗',
                       'badge_override_tip' => '{n} παρακάμψεις διαχειριστή περιμένουν «Το είδα»'),
    'clients' => array('clients_back_to_tasks' => 'Εργασίες',
                       'clients_back_to_tasks_title' => 'Πίσω στις εργασίες'),
    'tasks'   => array('task_group_client_link' => 'Πελάτης',
                       'task_group_client_link_title' => 'Άνοιγμα πελάτη',
                       'client_no_account' => 'χωρίς AccountNo',
                       'client_no_offer' => 'χωρίς προσφορά',
                       'mine_empty_queue' => '→ Δείτε την Ουρά αδιάθετων ({n})',
                       'fuel_next_todo' => '◷ ΠΡΟΣ ΕΚΤΕΛΕΣΗ · προθεσμία {due}',
                       'fuel_uploaded' => '⏳ Ανέβηκε · εκκρεμεί επαλήθευση',
                       'fuel_next_ready' => '✓ Έτοιμη · ισχύει από {day}',
                       'fuel_now_ok' => '✓ Σε ισχύ · CMS ενημερωμένο',
                       'fuel_now_missing' => '⚠ Σε ισχύ · CMS ΜΗ ενημερωμένο',
                       'fuel_older' => 'Παλαιότερες εβδομάδες ({n})',
                       'grp_tip_collapse' => 'Collapse — Μάζεμα της ομάδας',
                       'grp_tip_expand' => 'Expand — Άνοιγμα της ομάδας',
                       'color_open_head' => '#FCFF29'),
);

echo "\n═══ migration 2026-10-15b: σχήμα ═══\n";
ck('CREATE TABLE IF NOT EXISTS `4a_ui_texts`', strpos($mig, 'CREATE TABLE IF NOT EXISTS `4a_ui_texts` (') !== false);
ck('UNIQUE (scope, k)', strpos($mig, 'UNIQUE KEY `uq_ui_texts_scope_k` (`scope`, `k`)') !== false);
ck("kind ENUM('text','color') DEFAULT 'text'", strpos($mig, "`kind`       ENUM('text','color') NOT NULL DEFAULT 'text'") !== false);
ck('CHECK: color ΜΟΝΟ #rrggbb', strpos($mig, "CHECK (`kind` <> 'color' OR `text` REGEXP '^#[0-9A-Fa-f]{6}\$')") !== false);
ck('ΚΑΜΙΑ ρητή collation ή charset στον πίνακα (παίρνει της βάσης)',
   preg_match('/CREATE TABLE IF NOT EXISTS `4a_ui_texts` \([^;]*(COLLATE|CHARSET)/i', $mig) === 0);
ck('seed χωρίς αντικατάσταση υπαρχόντων (ON DUPLICATE KEY UPDATE k = k)',
   strpos($mig, 'ON DUPLICATE KEY UPDATE `k` = `k`;') !== false && preg_match('/REPLACE\s+INTO|DELETE\s+FROM|UPDATE\s+`4a_ui_texts`/i', $mig) === 0);
ck('κανένα ; ή \' μέσα σε σχόλιο (ο διαχωρισμός του Β4 είναι ανά ; έξω από \'...\')',
   !preg_match("/^\\s*--[^\\n]*[;']/m", $mig . "\n" . $rb));
ck('rollback: ΜΟΝΟ DROP TABLE IF EXISTS `4a_ui_texts`',
   strpos($rb, 'DROP TABLE IF EXISTS `4a_ui_texts`;') !== false && preg_match_all('/\b(DROP|DELETE|UPDATE|ALTER|INSERT)\b/', preg_replace('/^\s*--.*$/m', '', $rb)) === 1);

echo "\n═══ migration 2026-10-15b: seed = ο πίνακας Δ που εγκρίθηκε ═══\n";
ck('18 γραμμές', count($seed) === 18, (string)count($seed));
$keys = array_column($seed, 'k');
ck('κάθε κλειδί ΜΟΝΑΔΙΚΟ σε όλα τα scope (ο loader τα κρατά σε ένα επίπεδο)', count(array_unique($keys)) === count($keys));
$got = array();
foreach ($seed as $r) $got[$r['scope']][$r['k']] = $r['text'];
foreach ($APPROVED as $s => $kv) {
    foreach ($kv as $k => $t) {
        ck("{$s}.{$k} = «{$t}»", isset($got[$s][$k]) && $got[$s][$k] === $t, isset($got[$s][$k]) ? $got[$s][$k] : 'λείπει');
    }
}
ck('κανένα κλειδί εκτός του πίνακα Δ', count($seed) === array_sum(array_map('count', $APPROVED)));
$colors = array_filter($seed, function ($r) { return $r['kind'] === 'color'; });
ck('μόνο το color_open_head είναι color, και περνά το hex',
   array_column($colors, 'k') === array('color_open_head') && ui_texts_color_ok(reset($colors)['text']));
ck('κάθε άλλο είναι text', count($seed) - count($colors) === 17);

echo "\n═══ migration 2026-10-19b (Α2): τα 29 κείμενα της εβδομάδας και της λωρίδας, όπως εγκρίθηκαν 16/10 ═══\n";
$mig19 = file_get_contents(__DIR__ . '/../../db/migrations/2026-10-19b_ui_texts_fuel_week.sql');
$rb19  = file_get_contents(__DIR__ . '/../../db/migrations/rollback/2026-10-19b_ui_texts_fuel_week.rollback.sql');
preg_match_all("/\\(\\s*'([a-z_]+)',\\s*'(\\w+)',\\s*'(text|color)',\\s*'([^']*)'\\)/u", $mig19, $m19, PREG_SET_ORDER);
$APPROVED19 = array(
    'fuel_w_upload'       => '◷ ① Ανέβασμα στο CMS · τώρα · έως {due}',
    'fuel_w_upload_late'  => '⚠ ① Ανέβασμα στο CMS · εκπρόθεσμο από {due}',
    'fuel_w_verify_wait'  => '⏳ ② Επαλήθευση · από {from} · έως {time}',
    'fuel_w_verify_now'   => '◷ ② Επαλήθευση · τώρα · έως {time}',
    'fuel_w_verify_late'  => '⚠ ② Επαλήθευση · εκπρόθεσμη από {due}',
    'fuel_w_done'         => '✓ Σε ισχύ · ανέβηκε και επαληθεύτηκε',
    'strip_l1'            => '① Λήψη',
    'strip_l2'            => '② Ανέβασμα στο CMS',
    'strip_l3'            => '③ Επαλήθευση',
    'strip_m2'            => 'έως {due}',
    'strip_m3'            => '{name} · {from} 00:00–{time}',
    'strip_unassigned'    => 'αδιάθετο',
    'strip_who'           => 'Το κάνει: {name}',
    'strip_wait_assign'   => 'Περιμένει ανάθεση',
    'strip_b3'            => '🔍 Επαλήθευση',
    'strip_d1'            => '✓ Κατέβηκε · ⬇ ξανά',
    'strip_upload_done'   => '✓ Ανέβηκε στο CMS',
    'strip_d3'            => '✓ Επαληθεύτηκε',
    'strip_when'          => '{at} · {name}',
    'strip_tip_locked'    => 'Πρώτα το προηγούμενο βήμα',
    'strip_tip_monday'    => 'Ανοίγει Δευ {date} 00:00',
    'strip_tip_done'      => 'Completed — Ολοκληρώθηκε, δεν ξαναγίνεται',
    'strip_notes_h'       => '📧 Ειδοποιήσεις που στάλθηκαν',
    'strip_notes_none'    => 'Καμία ακόμα',
    'strip_note_to'       => 'προς {name}',
    'strip_menu_assign'   => 'Ανάθεση {n}…',
    'strip_menu_hist'     => 'ιστορικό {n}',
    'color_step_done'     => '#5a5d60',
    'color_step_locked'   => '#ececec',
);
$got19 = array();
foreach ($m19 as $r) $got19[$r[2]] = array($r[1], $r[3], $r[4]);
ck('29 γραμμές, ΟΛΕΣ scope tasks, kind text εκτός από color_step_done / color_step_locked', count($m19) === 29
   && count(array_filter($got19, function ($r) { return $r[0] === 'tasks'; })) === 29
   && array_keys(array_filter($got19, function ($r) { return $r[1] === 'color'; })) === array('color_step_done', 'color_step_locked'), (string)count($m19));
ck('τα δύο χρώματα της λωρίδας περνούν τον έλεγχο hex του endpoint', ui_texts_color_ok($got19['color_step_done'][2]) && ui_texts_color_ok($got19['color_step_locked'][2]));
foreach ($APPROVED19 as $k => $t) ck("tasks.{$k} = «{$t}»", isset($got19[$k]) && $got19[$k][2] === $t, isset($got19[$k]) ? $got19[$k][2] : 'λείπει');
ck('κανένα κλειδί κοινό με το 15b (ο loader τα κρατά σε ένα επίπεδο)', !array_intersect(array_keys($got19), $keys));
ck('κανένα «19:00» ή «17:00» στα κείμενα: οι ώρες έρχονται από τα δεδομένα',
   !preg_match('/\b(19|17):00\b/', implode("\n", $APPROVED19)));
ck('seed χωρίς αντικατάσταση (ON DUPLICATE KEY UPDATE k = k), κανένα DDL',
   strpos($mig19, 'ON DUPLICATE KEY UPDATE `k` = `k`;') !== false
   && preg_match('/\b(CREATE|ALTER|DROP)\b|REPLACE\s+INTO|DELETE\s+FROM/i', preg_replace('/^\s*--.*$/m', '', $mig19)) === 0);
ck('κανένα ; ή \' μέσα σε σχόλιο (19b και rollback)', !preg_match("/^\\s*--[^\\n]*[;']/m", $mig19 . "\n" . $rb19));
preg_match("/DELETE FROM `4a_ui_texts`\\s+WHERE `scope` = 'tasks'\\s+AND `k` IN \\(([^)]*)\\)/", $rb19, $dm);
preg_match_all("/'(\\w+)'/", isset($dm[1]) ? $dm[1] : '', $dk);
$dk = $dk[1]; sort($dk); $ak = array_keys($APPROVED19); sort($ak);
ck('rollback: DELETE ΜΟΝΟ των 29 κλειδιών του 19b, σε scope tasks', $dk === $ak, implode(',', $dk));
ck('rollback: καμία άλλη εντολή αλλαγής',
   preg_match_all('/\b(DROP|DELETE|UPDATE|ALTER|INSERT)\b/', preg_replace('/^\s*--.*$/m', '', $rb19)) === 1);

echo "\n═══ migration 2026-10-22b: ορολογία «μη ανατεθειμέν…» (απόφαση 20/10) ═══\n";
$mig22 = file_get_contents(__DIR__ . '/../../db/migrations/2026-10-22b_ui_texts_unassigned.sql');
$rb22  = file_get_contents(__DIR__ . '/../../db/migrations/rollback/2026-10-22b_ui_texts_unassigned.rollback.sql');
$tuples = function ($sql) {
    preg_match_all("/\\(\\s*'([a-z_]+)',\\s*'(\\w+)',\\s*'(text|color)',\\s*'([^']*)'\\)/u", $sql, $mm, PREG_SET_ORDER);
    $o = array();
    foreach ($mm as $r) $o[$r[1] . '.' . $r[2]] = array($r[3], $r[4]);
    return $o;
};
$NEW22 = array('tasks.mine_empty_queue' => array('text', '→ Δείτε την Ουρά μη ανατεθειμένων ({n})'),
               'tasks.strip_unassigned' => array('text', 'μη ανατεθειμένο'),
               // ΝΕΟ κλειδί (21/10): γραμμή ΤΩΡΑ όταν δεν υπάρχει ανάδοχος.
               'tasks.strip_who_none'   => array('text', 'Μη ανατεθειμένο · περιμένει ανάθεση'));
$OLD22 = array('tasks.mine_empty_queue' => array('text', '→ Δείτε την Ουρά αδιάθετων ({n})'),
               'tasks.strip_unassigned' => array('text', 'αδιάθετο'));
ck('22b: ΜΟΝΟ τα 3 κείμενα (2 αλλαγμένα + strip_who_none), με τα νέα', $tuples($mig22) === $NEW22, json_encode($tuples($mig22), JSON_UNESCAPED_UNICODE));
ck('rollback: τα 2 αλλαγμένα κλειδιά, με τις τιμές των 15b / 19b', $tuples($rb22) === $OLD22);
ck('rollback: σβήνει ΜΟΝΟ το νέο strip_who_none (ένα DELETE)',
   strpos($rb22, "DELETE FROM `4a_ui_texts` WHERE `scope` = 'tasks' AND `k` = 'strip_who_none';") !== false
   && preg_match_all('/DELETE\s+FROM/i', preg_replace('/^\s*--.*$/m', '', $rb22)) === 1);
ck('strip_who_none: ΝΕΟ κλειδί (κανένα παλιό migration δεν το γράφει)', !isset($tuples($mig)['tasks.strip_who_none']) && !isset($tuples($mig19)['tasks.strip_who_none']));
ck('οι παλιές τιμές του rollback = ό,τι έγραψαν τα 15b / 19b',
   $tuples($mig)['tasks.mine_empty_queue'] === $OLD22['tasks.mine_empty_queue']
   && $tuples($mig19)['tasks.strip_unassigned'] === $OLD22['tasks.strip_unassigned']);
ck('22b: αντικατάσταση κειμένου (ON DUPLICATE KEY UPDATE text = VALUES(text)), κανένα DDL / DELETE',
   strpos($mig22, 'ON DUPLICATE KEY UPDATE `text` = VALUES(`text`);') !== false
   && preg_match('/\b(CREATE|ALTER|DROP)\b|REPLACE\s+INTO|DELETE\s+FROM/i', preg_replace('/^\s*--.*$/m', '', $mig22)) === 0);
ck("22b: η ετικέτα της open -> «Μη ανατεθειμένη», rollback -> «Χωρίς ανάδοχο»",
   strpos($mig22, "UPDATE `4a_task_statuses` SET `label` = 'Μη ανατεθειμένη' WHERE `code` = 'open';") !== false
   && strpos($rb22, "UPDATE `4a_task_statuses` SET `label` = 'Χωρίς ανάδοχο' WHERE `code` = 'open';") !== false);
ck('22b: μία INSERT και μία UPDATE, τίποτε άλλο που γράφει',
   preg_match_all('/\b(INSERT|UPDATE|DELETE|DROP|ALTER)\b/', preg_replace(array('/^\s*--.*$/m', '/ON DUPLICATE KEY UPDATE/'), '', $mig22)) === 2);
ck("κανένα ; ή ' μέσα σε σχόλιο (22b και rollback)", !preg_match("/^\\s*--[^\\n]*[;']/m", $mig22 . "\n" . $rb22));
ck('22b: καμία παλιά λέξη στις τιμές που γράφει', !preg_match('/αδιάθετ|ανάδοχο/u', implode("\n", array_column($tuples($mig22), 1))));

echo "\n═══ ui_texts_scopes: λευκή λίστα ═══\n";
ck('«tasks,common» -> [tasks, common]', ui_texts_scopes('tasks,common') === array('tasks', 'common'));
ck('κενά γύρω -> κόβονται', ui_texts_scopes(' tasks , common ') === array('tasks', 'common'));
ck('διπλό -> μία φορά, η σειρά κρατιέται', ui_texts_scopes('common,tasks,common') === array('common', 'tasks'));
ck('κενό -> null', ui_texts_scopes('') === null && ui_texts_scopes(null) === null);
ck('«../x» -> null', ui_texts_scopes('../x') === null);
ck('κεφαλαία -> null', ui_texts_scopes('Tasks') === null);
ck("SQL -> null", ui_texts_scopes("tasks' OR 1=1") === null);
ck('21 χαρακτήρες -> null', ui_texts_scopes(str_repeat('a', 21)) === null && ui_texts_scopes(str_repeat('a', 20)) === array(str_repeat('a', 20)));
ck('6 scope -> null, 5 -> δεκτά', ui_texts_scopes('a,b,c,d,e,f') === null && count(ui_texts_scopes('a,b,c,d,e')) === 5);
ck('«tasks,» (κενό στο τέλος) -> null', ui_texts_scopes('tasks,') === null);
ck('πίνακας αντί για κείμενο -> null', ui_texts_scopes(array('tasks')) === null);

echo "\n═══ ui_texts_color_ok ═══\n";
ck('#FCFF29 / #fcff29 δεκτά', ui_texts_color_ok('#FCFF29') && ui_texts_color_ok('#fcff29'));
ck('#FFF, FCFF29, #FCFF290, red -> όχι',
   !ui_texts_color_ok('#FFF') && !ui_texts_color_ok('FCFF29') && !ui_texts_color_ok('#FCFF290') && !ui_texts_color_ok('red'));
ck("γραμμή μετά -> όχι", !ui_texts_color_ok("#FCFF29\n"));

echo "\n═══ ui_texts_fetch: πάνω στο seed του migration (SQLite) ═══\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE `4a_ui_texts` (`id` INTEGER PRIMARY KEY, `scope` TEXT NOT NULL, `k` TEXT NOT NULL,
           `kind` TEXT NOT NULL DEFAULT \'text\', `text` TEXT NOT NULL, `updated_at` TEXT, UNIQUE(`scope`, `k`))');
$ins = $db->prepare('INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES (?, ?, ?, ?)');
foreach ($seed as $r) $ins->execute(array($r['scope'], $r['k'], $r['kind'], $r['text']));
$count = function () use ($db) { return (int)$db->query('SELECT COUNT(*) FROM `4a_ui_texts`')->fetchColumn(); };
$n0 = $count();

$t = ui_texts_fetch($db, array('tasks', 'common'));
ck('δύο scope, με τη σειρά τους', array_keys($t) === array('tasks', 'common'));
ck('tasks: 14 κλειδιά, common: 2', count($t['tasks']) === 14 && count($t['common']) === 2);
ck('κανένα κλειδί άλλου scope (clients)', !isset($t['tasks']['clients_back_to_tasks']) && !isset($t['common']['clients_back_to_tasks']));
ck('κείμενο αυτούσιο, με {n}', $t['tasks']['mine_empty_queue'] === '→ Δείτε την Ουρά αδιάθετων ({n})');
ck('χρώμα αυτούσιο', $t['tasks']['color_open_head'] === '#FCFF29');
$t = ui_texts_fetch($db, array('nothing'));
ck('scope χωρίς γραμμές -> υπάρχει, άδειο', $t === array('nothing' => array()));
ck('κανένα scope -> []', ui_texts_fetch($db, array()) === array());
$db->exec("INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES ('tasks', 'bad_color', 'color', 'red')");
$t = ui_texts_fetch($db, array('tasks'));
ck('χρώμα που δεν είναι #rrggbb ΔΕΝ επιστρέφεται (η σελίδα το βλέπει ως κλειδί που λείπει)',
   !isset($t['tasks']['bad_color']) && isset($t['tasks']['color_open_head']));
$db->exec("DELETE FROM `4a_ui_texts` WHERE `k` = 'bad_color'");
ck('η ανάγνωση δεν γράφει τίποτα', $count() === $n0);

echo "\n═══ api/ui_texts.php: λεπτό endpoint ═══\n";
$code = preg_replace('#^\s*//.*$#m', '', $ep);
ck('ΚΑΝΕΝΑ DDL (CREATE / ALTER / DROP)', preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $code) === 0);
ck('μόνο GET (405 αλλιώς), OPTIONS 204',
   strpos($code, "if (\$method !== 'GET') respond(") !== false && strpos($code, '405') !== false && strpos($code, 'http_response_code(204)') !== false);
ck('require_user() ΠΡΙΝ από κάθε ανάγνωση (χωρίς token -> 401 από το auth.php)',
   strpos($code, 'require_user();') !== false && strpos($code, 'require_user();') < strpos($code, 'ui_texts_fetch('));
ck('χωρίς token -> 401 ΠΡΙΝ από 405 και 400 (v1.1): require_user() αμέσως μετά το OPTIONS',
   strpos($code, 'http_response_code(204)') < strpos($code, 'require_user();')
   && strpos($code, 'require_user();') < strpos($code, "if (\$method !== 'GET')")
   && strpos($code, 'require_user();') < strpos($code, 'ui_texts_scopes('));
ck('άκυρο scope -> 400, ΠΡΙΝ από την ανάγνωση',
   strpos($code, 'if ($scopes === null) {') !== false
   && strpos($code, '], 400);', strpos($code, 'if ($scopes === null) {')) < strpos($code, 'ui_texts_fetch('));
// Το όνομα του αρχείου σκέτο, ΟΧΙ ως «require…»: ο έλεγχος του Β8 θεωρεί κάθε
// τέτοια συμβολοσειρά του bundle διαδρομή και απαιτεί να ξεκινά από το api.
ck('api_errors_install ΠΡΙΝ από το auth.php', strpos($code, "api_errors_install('ui_texts')") < strpos($code, "'auth.php'"));
ck('άδειο scope ως {} (object), όχι []', strpos($code, '$texts[$s] = (object)$kv;') !== false);

echo "\n═══ ΣΥΝΟΛΟ: $pass OK, $fail ΛΑΘΟΣ ═══\n";
exit($fail ? 1 : 0);
