<?php
// fuel_verify_lib.php | v1.1 | 21-10-2026
// v1.1: ζώνη ώρας (σύμβαση 21/10, time_lib.php). Οι στιγμές της βάσης (UTC)
//       φεύγουν σε ώρα Αθήνας: at των απαντήσεων, wrong_at, alert.at. Δ-Α8
//       (tasks_close_gate) και from_week μέσω της κοινής συνάρτησης· το $now
//       των τεστ = UTC. Ημερομηνίες εβδομάδας με ρητή ζώνη Αθήνας.
// ΕΠΑΛΗΘΕΥΤΗΣ του fuel_weekly_verify (σχέδιο 4a-private/verifier_plan.md,
// αποφάσεις 19/10). Migrations 2026-10-23a (πίνακες) και 2026-10-23b
// (δικαίωμα fuel_verify_values).
//
// Ο επαληθευτής ανοίγει στο CMS κάθε γραμμή ελέγχου (πελάτης, σταθμός,
// υπηρεσία, ζώνη) και διαλέγει ανάμεσα σε 4 τιμές αυτή που δείχνει το CMS.
//
//   · Η ΣΩΣΤΗ = payload.prices[fuel_type] της ίδιας της εργασίας verify.
//     Ο έλεγχος σωστού/λάθους γίνεται ΜΟΝΟ εδώ· ο browser δεν τη μαθαίνει,
//     εκτός αν ο χρήστης έχει το δικαίωμα fuel_verify_values (view).
//   · Οι 4 τιμές κληρώνονται ΜΙΑ φορά ανά εργασία και γύρο, και
//     αποθηκεύονται: αλλιώς δύο ανανεώσεις θα έδειχναν τη σωστή ως τη μόνη
//     τιμή που μένει ίδια. Ανακατεύεται μόνο η σειρά, σε κάθε άνοιγμα.
//   · ΜΙΑ υποβολή ανά γύρο. Όλες σωστές -> η εργασία κλείνει (verify_ok).
//     Κάποια λάθος -> verify_wrong, και ο επαληθευτής ΚΛΕΙΔΩΝΕΙ μέχρι την
//     επαναφορά από admin (verify_reset, με λόγο): νέος γύρος, νέα κλήρωση.
//   · Δ-Α8: η υποβολή περνά από την tasks_close_gate ΠΡΙΝ γραφτεί τίποτα.
//
// ΔΙΑΚΟΠΤΗΣ (όπως ο 4a_task_steps): τύπος εργασίας με ενεργές γραμμές στον
// 4a_fuel_verify_checks ΚΑΙ εβδομάδα >= from_week. Αλλιώς ΑΚΡΙΒΩΣ η
// σημερινή ροή, και κανένα νέο κλειδί στο JSON. Χωρίς πίνακες (ο κώδικας
// ανέβηκε πριν από το migration): ανενεργός.
//
// Ίδιες αρχές με το tasks_lib.php: καθαρές συναρτήσεις που δέχονται PDO,
// τίποτα δεν εκτελείται κατά το include, δοκιμάζονται σε SQLite.
// Η τυχαιότητα έρχεται από $rand(n) -> ακέραιος στο [0, n): random_int
// στην παραγωγή, ορισμένη ακολουθία στα τεστ.

// Ζώνη ώρας: ΜΙΑ πηγή (time_lib.php, 21/10).
require_once __DIR__ . '/time_lib.php';

if (!function_exists('fuel_verify_settings')) {

// Το δικαίωμα (modules.id) που δείχνει τις αναμενόμενες τιμές.
if (!defined('FV_PERM_MODULE')) define('FV_PERM_MODULE', 'fuel_verify_values');
// Ο κωδικός σφάλματος (vf) ταξιδεύει στο JSON· η οθόνη δείχνει το κείμενό
// του από τον 4a_ui_texts (vf_err_<κωδικός>). Τα μηνύματα εδώ είναι για το
// log και για όποιον καλεί το API χωρίς οθόνη.
if (!defined('FV_ERR')) {
    define('FV_ERR', json_encode([
        'off'        => 'ο επαληθευτής δεν είναι ενεργός για αυτή την εργασία',
        'config'     => 'οι ρυθμίσεις του επαληθευτή δεν είναι έγκυρες',
        'no_prices'  => 'δεν υπάρχουν τιμές εβδομάδας για τον έλεγχο',
        'draw'       => 'δεν βγήκαν διαφορετικές επιλογές για κάθε γραμμή',
        'forbidden'  => 'ο επαληθευτής ανοίγει μόνο για τον ανάδοχο ή με το δικαίωμα αναμενόμενων τιμών',
        'owner'      => 'την επαλήθευση την υποβάλλει μόνο ο ανάδοχος',
        'locked'     => 'ο επαληθευτής κλείδωσε μετά από λάθος· περιμένει επαναφορά από διαχειριστή',
        'reload'     => 'οι επιλογές άλλαξαν· άνοιξε ξανά τον επαληθευτή',
        'incomplete' => 'διάλεξε τιμή σε όλες τις γραμμές',
        'bad_value'  => 'τιμή εκτός των επιλογών της γραμμής',
        'wrong'      => 'κάποιες γραμμές δεν συμφωνούν με το CMS',
        'not_locked' => 'ο επαληθευτής δεν είναι κλειδωμένος',
        'reset_self' => 'την επαναφορά την κάνει άλλος διαχειριστής από αυτόν που απάντησε',
        'reason'     => 'απαιτείται λόγος, τουλάχιστον {n} χαρακτήρες',
        'admin'      => 'μόνο διαχειριστής',
        'use_verifier' => 'η επαλήθευση κλείνει από τον επαληθευτή, όχι με «Ολοκλήρωση»',
        'no_download'  => 'το αρχείο CMS το κατεβάζει ο ανάδοχος του ανεβάσματος ή όποιος βλέπει τις αναμενόμενες τιμές',
    ], JSON_UNESCAPED_UNICODE));
}

/** Σφάλμα με κωδικό vf για την οθόνη. */
function fuel_verify_err($http, $vf, array $extra = [])
{
    $msgs = json_decode(FV_ERR, true);
    $m = isset($msgs[$vf]) ? $msgs[$vf] : $vf;
    foreach ($extra as $k => $v) if (is_scalar($v)) $m = str_replace('{' . $k . '}', (string)$v, $m);
    $e = tasks_err($http, $m);
    $e['vf'] = $vf;
    return $e + $extra;
}

/** Ποσό σε λεπτά (int), ή null αν δεν είναι αριθμός. */
function fuel_verify_cents($v)
{
    if (is_int($v) || is_float($v)) return (int)round($v * 100);
    if (!is_string($v) || !preg_match('/^\s*-?\d+(\.\d+)?\s*$/', $v)) return null;
    return (int)round(((float)trim($v)) * 100);
}

/** Λεπτά -> «ακέραιο.δδ». */
function fuel_verify_fmt($cents)
{
    $cents = (int)$cents;
    return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . sprintf('%02d', abs($cents) % 100);
}

/**
 * Οι ρυθμίσεις, επικυρωμένες. null = ΧΩΡΙΣ πίνακα (ανενεργός).
 * ['valid' => bool, 'options', 'near_deltas' (λεπτά), 'other_type' (map),
 *  'from_week' ('Y-m-d' ή null), 'max_draws', 'reason_min', 'reason_max']
 * ΚΑΘΕ όριο έρχεται από εδώ (απόφαση 19/10)· ρύθμιση που λείπει ή δεν
 * είναι έγκυρη -> valid = false (fail closed: κανένα όριο «από τον κώδικα»).
 */
function fuel_verify_settings($db)
{
    try {
        $rows = $db->query('SELECT `k`, `v` FROM `4a_fuel_verify_settings`')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        return null;
    }
    $s = ['valid' => true, 'options' => 0, 'near_deltas' => [], 'other_type' => [], 'from_week' => null, 'max_draws' => 0,
          'reason_min' => 0, 'reason_max' => 0];
    $n = isset($rows['options']) ? trim((string)$rows['options']) : '';
    // Τουλάχιστον 2: η σωστή και μία λάθος (όριο της έννοιας, όχι ρύθμιση).
    if (preg_match('/^\d+$/', $n) && (int)$n >= 2) $s['options'] = (int)$n; else $s['valid'] = false;
    foreach (explode(',', isset($rows['near_deltas']) ? (string)$rows['near_deltas'] : '') as $d) {
        $c = fuel_verify_cents($d);
        if ($c === null || $c <= 0) { $s['valid'] = false; continue; }
        $s['near_deltas'][] = $c;
    }
    if (!$s['near_deltas']) $s['valid'] = false;
    foreach (explode(',', isset($rows['other_type']) ? (string)$rows['other_type'] : '') as $kv) {
        $p = explode(':', trim($kv));
        if (count($p) === 2 && $p[0] !== '' && $p[1] !== '') $s['other_type'][trim($p[0])] = trim($p[1]);
    }
    $fw = isset($rows['from_week']) ? trim((string)$rows['from_week']) : '';
    $d  = DateTime::createFromFormat('!Y-m-d', $fw, new DateTimeZone(TIME_LOCAL_TZ));
    if ($d && $d->format('Y-m-d') === $fw) $s['from_week'] = $fw; else $s['valid'] = false;
    $m = isset($rows['max_draws']) ? trim((string)$rows['max_draws']) : '';
    if (preg_match('/^\d+$/', $m) && (int)$m >= 1) $s['max_draws'] = (int)$m; else $s['valid'] = false;
    foreach (['reason_min', 'reason_max'] as $k) {
        $x = isset($rows[$k]) ? trim((string)$rows[$k]) : '';
        if (preg_match('/^\d+$/', $x) && (int)$x >= 1) $s[$k] = (int)$x; else $s['valid'] = false;
    }
    if ($s['reason_max'] < $s['reason_min']) $s['valid'] = false;
    return $s;
}

/** Οι ενεργές γραμμές ελέγχου ενός τύπου, με τη σειρά τους. [] χωρίς πίνακα. */
function fuel_verify_checks($db, $taskCode)
{
    try {
        $st = $db->prepare('SELECT `id`, `client_code`, `station_country`, `cms_service`, `zone`, `fuel_type`, `sort_order`
                              FROM `4a_fuel_verify_checks`
                             WHERE `task_code` = ? AND `active` = 1
                             ORDER BY `sort_order`, `id`');
        $st->execute([(string)$taskCode]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/** Οι τύποι εργασίας με ενεργές γραμμές ελέγχου. */
function fuel_verify_types($db)
{
    try {
        return array_map('strval', $db->query('SELECT DISTINCT `task_code` FROM `4a_fuel_verify_checks` WHERE `active` = 1')
                                      ->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

/** Το week_start του payload ('Y-m-d'), ή null. */
function fuel_verify_week($task)
{
    $p  = isset($task['payload']) && is_string($task['payload']) ? json_decode($task['payload'], true) : null;
    $ws = is_array($p) && isset($p['week_start']) ? (string)$p['week_start'] : '';
    $d  = DateTime::createFromFormat('!Y-m-d', $ws, new DateTimeZone(TIME_LOCAL_TZ));
    return $d && $d->format('Y-m-d') === $ws ? $ws : null;
}

/**
 * Ο τύπος verify που αφορά η εργασία: ο ίδιος ο τύπος της, ή ο τύπος verify
 * που εξαρτάται από αυτόν (η ρίζα, για τη λήψη και το payload). null = καμία σχέση.
 */
function fuel_verify_type_for($db, $taskCode)
{
    $types = fuel_verify_types($db);
    if (!$types) return null;
    if (in_array((string)$taskCode, $types, true)) return (string)$taskCode;
    $st = $db->prepare('SELECT `code` FROM `4a_task_types` WHERE `depends_on` = ?');
    $st->execute([(string)$taskCode]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) if (in_array((string)$c, $types, true)) return (string)$c;
    return null;
}

/**
 * Ενεργός ο επαληθευτής για την εβδομάδα αυτής της εργασίας (verify ή ρίζα);
 * Ναι αν ο τύπος έχει γραμμές ελέγχου ΚΑΙ (week_start >= from_week, ή
 * ρυθμίσεις/εβδομάδα μη έγκυρες: FAIL CLOSED, ο επαληθευτής θα απαντήσει
 * vf=config / no_prices αντί να κλείσει η εργασία χωρίς έλεγχο).
 */
function fuel_verify_active($db, $task)
{
    if (array_key_exists('client_id', $task) && $task['client_id'] !== null) return false;
    $s = fuel_verify_settings($db);
    if ($s === null) return false;
    if (fuel_verify_type_for($db, $task['task_code']) === null) return false;
    if ($s['from_week'] === null) return true;
    $ws = fuel_verify_week($task);
    // Εβδομάδα από Δευ 00:00 (Αθήνα) του from_week και μετά: σύγκριση στιγμών, κοινή συνάρτηση.
    return $ws === null || time_cmp(time_instant($ws . ' 00:00:00', 'local'), time_instant($s['from_week'] . ' 00:00:00', 'local')) >= 0;
}

/** Βλέπει τις αναμενόμενες τιμές; ΜΟΝΟ από το δικαίωμα, κανένας έλεγχος ρόλου. */
function fuel_verify_can_see($perms)
{
    return is_array($perms) && isset($perms['permissions'][FV_PERM_MODULE]['view'])
        && (int)$perms['permissions'][FV_PERM_MODULE]['view'] === 1;
}

/** Οι τιμές της εβδομάδας από ένα payload (string ή array): code => λεπτά|null. */
function fuel_verify_prices($payload)
{
    $p = is_string($payload) ? json_decode($payload, true) : $payload;
    if (!is_array($p) || !isset($p['prices']) || !is_array($p['prices'])) return null;
    $out = [];
    foreach ($p['prices'] as $k => $v) $out[(string)$k] = $v === null ? null : fuel_verify_cents((string)$v);
    return $out;
}

/** Οι τιμές της ΠΡΟΗΓΟΥΜΕΝΗΣ εβδομάδας: εργασία συστήματος με subject <πρόθεμα><week_start − 7>. */
function fuel_verify_prev_prices($db, $task)
{
    $ws = fuel_verify_week($task);
    $sk = isset($task['subject_key']) ? (string)$task['subject_key'] : '';
    if ($ws === null || !preg_match('/^(.*)' . preg_quote($ws, '/') . '$/', $sk, $m)) return null;
    $prev = time_instant($ws . ' 00:00:00', 'local')->modify('-7 days')->format('Y-m-d');
    $st = $db->prepare('SELECT `payload` FROM `4a_tasks` WHERE `client_id` IS NULL AND `subject_key` = ? ORDER BY `id`');
    $st->execute([$m[1] . $prev]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pl) {
        $p = fuel_verify_prices($pl);
        if ($p !== null) return $p;
    }
    return null;
}

/**
 * Οι επιλογές μιας γραμμής, σε λεπτά. Η ΣΩΣΤΗ πρώτη (η σειρά εμφάνισης
 * ανακατεύεται αλλού). Κανόνες (απόφαση Ε1, 19/10):
 *   (α) ίδιος τύπος, προηγούμενη εβδομάδα
 *   (β) ο «άλλος» τύπος (other_type), ίδια εβδομάδα
 *   (γ) βάση τυχαία η σωστή ή η (α) · απόσταση τυχαία από near_deltas ·
 *       τυχαίο πρόσημο
 * ΟΛΕΣ διαφορετικές και > 0. Η (α) ή η (β) που λείπει ή συμπίπτει αφήνει
 * τη θέση της σε νέα κλήρωση (γ). Νέα κλήρωση μέχρι max_draws· μετά null.
 */
function fuel_verify_draw($correct, $prev, $other, array $s, callable $rand)
{
    $opts = [(int)$correct];
    foreach ([$prev, $other] as $c) {
        if ($c !== null && $c > 0 && !in_array((int)$c, $opts, true)) $opts[] = (int)$c;
    }
    $bases = [(int)$correct];
    if ($prev !== null && $prev > 0) $bases[] = (int)$prev;
    $draws = 0;
    while (count($opts) < $s['options']) {
        if ($draws++ >= $s['max_draws']) return null;
        $base = $bases[$rand(count($bases))];
        $d    = $s['near_deltas'][$rand(count($s['near_deltas']))];
        $g    = $base + ($rand(2) === 1 ? $d : -$d);
        if ($g > 0 && !in_array($g, $opts, true)) $opts[] = $g;
    }
    return array_slice($opts, 0, $s['options']);
}

/** random_int σε [0, n). */
function fuel_verify_rand()
{
    return function ($n) { return $n <= 1 ? 0 : random_int(0, $n - 1); };
}

/** Τα γεγονότα του επαληθευτή: γύρος, κλειδωμένος, ποιος απάντησε λάθος. */
function fuel_verify_state(array $events)
{
    $round = 1; $locked = false; $wrong = null;
    foreach ($events as $e) {
        if ($e['event'] === 'verify_reset')     { $round++; $locked = false; $wrong = null; }
        elseif ($e['event'] === 'verify_wrong') { $locked = true; $wrong = $e; }
    }
    return ['round' => $round, 'locked' => $locked, 'wrong' => $wrong];
}

/** Οι αποθηκευμένες γραμμές ενός γύρου (με τις επιλογές), check_id => γραμμή. */
function fuel_verify_round_lines($db, $taskId, $round)
{
    $st = $db->prepare('SELECT `check_id`, `client_code`, `station_country`, `cms_service`, `zone`, `fuel_type`,
                               `sort_order`, `options`, `correct`
                          FROM `4a_fuel_verify_options` WHERE `task_id` = ? AND `round` = ?
                         ORDER BY `sort_order`, `check_id`');
    $st->execute([(int)$taskId, (int)$round]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['options'] = json_decode($r['options'], true);
        $r['correct'] = fuel_verify_cents((string)$r['correct']);
        $out[(int)$r['check_id']] = $r;
    }
    return $out;
}

/**
 * Οι γραμμές του γύρου: οι αποθηκευμένες, ή ΝΕΑ κλήρωση για τις ενεργές
 * γραμμές ελέγχου (μία φορά: INSERT IGNORE στο UNIQUE task/round/check, και
 * ξανά ανάγνωση, ώστε δύο ταυτόχρονα ανοίγματα να βλέπουν το ΙΔΙΟ σύνολο).
 * @return [lines, err]
 */
function fuel_verify_ensure($db, $task, $round, callable $rand)
{
    // ΠΡΟΣΤΑΣΙΑ 1: ο γύρος έχει ήδη κληρωθεί -> οι ΙΔΙΕΣ επιλογές, καμία νέα κλήρωση.
    $lines = fuel_verify_round_lines($db, $task['id'], $round);
    if ($lines) return [$lines, null];
    return fuel_verify_draw_round($db, $task, $round, $rand);
}

/**
 * Νέα κλήρωση ενός γύρου. ΠΡΟΣΤΑΣΙΑ 2: INSERT IGNORE στο UNIQUE
 * task/round/check και ξανά ανάγνωση -> αν κάποιος άλλος (ταυτόχρονο
 * άνοιγμα) πρόλαβε, μένουν οι ΔΙΚΕΣ ΤΟΥ επιλογές, όχι οι δικές μας.
 */
function fuel_verify_draw_round($db, $task, $round, callable $rand)
{
    $s = fuel_verify_settings($db);
    if ($s === null || !$s['valid']) return [null, fuel_verify_err(409, 'config')];
    $checks = fuel_verify_checks($db, $task['task_code']);
    if (!$checks) return [null, fuel_verify_err(409, 'off')];
    $now  = fuel_verify_prices($task['payload']);
    $prev = fuel_verify_prev_prices($db, $task);
    if ($now === null) return [null, fuel_verify_err(409, 'no_prices')];

    $rows = [];
    foreach ($checks as $c) {
        $ft = (string)$c['fuel_type'];
        $correct = isset($now[$ft]) ? $now[$ft] : null;
        if ($correct === null || $correct <= 0) return [null, fuel_verify_err(409, 'no_prices')];
        $ot    = isset($s['other_type'][$ft]) ? $s['other_type'][$ft] : null;
        $other = $ot !== null && isset($now[$ot]) ? $now[$ot] : null;
        $pv    = $prev !== null && isset($prev[$ft]) ? $prev[$ft] : null;
        $opts  = fuel_verify_draw($correct, $pv, $other, $s, $rand);
        if ($opts === null) return [null, fuel_verify_err(409, 'draw')];
        $rows[] = [$c, $opts, $correct];
    }
    $ins = $db->prepare('INSERT IGNORE INTO `4a_fuel_verify_options`
                           (`task_id`, `round`, `check_id`, `client_code`, `station_country`, `cms_service`, `zone`,
                            `fuel_type`, `sort_order`, `options`, `correct`)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as list($c, $opts, $correct)) {
        $ins->execute([(int)$task['id'], (int)$round, (int)$c['id'], $c['client_code'], $c['station_country'],
                       $c['cms_service'], $c['zone'], $c['fuel_type'], (int)$c['sort_order'],
                       json_encode(array_map('fuel_verify_fmt', $opts)), fuel_verify_fmt($correct)]);
    }
    return [fuel_verify_round_lines($db, $task['id'], $round), null];
}

/** Οι απαντήσεις ενός γύρου: check_id => ['chosen' => λεπτά, 'ok' => bool, ...]. */
function fuel_verify_answers($db, $taskId, $round)
{
    $st = $db->prepare('SELECT a.`check_id`, a.`chosen`, a.`is_correct`, a.`user_id`, a.`created_at`, u.`name` AS user_name
                          FROM `4a_fuel_verify_answers` a LEFT JOIN `4a_users` u ON u.`id` = a.`user_id`
                         WHERE a.`task_id` = ? AND a.`round` = ? ORDER BY a.`id`');
    $st->execute([(int)$taskId, (int)$round]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['check_id']] = ['chosen' => fuel_verify_cents((string)$r['chosen']), 'ok' => (int)$r['is_correct'] === 1,
                                     'user_id' => (int)$r['user_id'], 'user_name' => $r['user_name'], 'at' => time_db_to_local($r['created_at'])];
    }
    return $out;
}

/** Fisher–Yates με το $rand. */
function fuel_verify_shuffle(array $a, callable $rand)
{
    for ($i = count($a) - 1; $i > 0; $i--) {
        $j = $rand($i + 1);
        $t = $a[$i]; $a[$i] = $a[$j]; $a[$j] = $t;
    }
    return $a;
}

/** Οι ετικέτες των τύπων καυσίμου (label_el). */
function fuel_verify_fuel_labels($db)
{
    try {
        return $db->query('SELECT `code`, `label_el` FROM `4a_fuel_types`')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        return [];
    }
}

/** Κοινός έλεγχος: υπάρχει, είναι στο πεδίο, είναι verify με ενεργό επαληθευτή. */
function fuel_verify_guard($db, $session, $perms, $taskId)
{
    $task = tasks_fetch_one($db, $taskId);
    if (!$task) return [null, tasks_err(404, 'η εργασία δεν βρέθηκε')];
    if (!tasks_country_ok($db, tasks_scope_countries($perms), $task, (int)$session['id'])) {
        return [null, tasks_err(403, 'ο πελάτης είναι εκτός του πεδίου σας')];
    }
    if (!in_array((string)$task['task_code'], fuel_verify_types($db), true) || !fuel_verify_active($db, $task)) {
        return [null, fuel_verify_err(409, 'off')];
    }
    return [$task, null];
}

// ─────────────────────────────────────────────────────────────────────
// ΦΟΡΜΑ — GET tasks.php?action=verify_form&id=
//
// Ανοίγει για τον ΑΝΑΔΟΧΟ, ή για όποιον έχει το fuel_verify_values. Οι
// επιλογές ΧΩΡΙΣ ένδειξη ποια είναι σωστή· το `expected` ΜΟΝΟ με το
// δικαίωμα. Μετά από υποβολή: ✓/✗ ανά γραμμή (Ε3: λέμε ΠΟΙΕΣ).
// ─────────────────────────────────────────────────────────────────────
function fuel_verify_form($db, $session, $perms, $taskId, $rand = null, $now = null)
{
    $rand = $rand ?: fuel_verify_rand();
    list($task, $err) = fuel_verify_guard($db, $session, $perms, $taskId);
    if ($err) return $err;
    $me    = (int)$session['id'];
    $see   = fuel_verify_can_see($perms);
    $owner = $task['assigned_to'] !== null && (int)$task['assigned_to'] === $me;
    if (!$owner && !$see) return fuel_verify_err(403, 'forbidden');
    if ((int)$task['locked'] === 1) {
        $b = $task['blocked_by_label'] !== null ? $task['blocked_by_label'] : $task['depends_on'];
        return tasks_err(409, 'κλειδωμένη — περιμένει: ' . $b);
    }

    $evs   = tasks_steps_events($db, [(int)$taskId]);
    $state = fuel_verify_state($evs[(int)$taskId]);
    $closed = in_array($task['status'], ['done', 'na'], true);
    if ($closed) {
        $lines = fuel_verify_round_lines($db, $taskId, $state['round']);
    } else {
        list($lines, $err) = fuel_verify_ensure($db, $task, $state['round'], $rand);
        if ($err) return $err;
    }
    $ans    = fuel_verify_answers($db, $taskId, $state['round']);
    $labels = fuel_verify_fuel_labels($db);
    $gate   = tasks_close_gate($db, $task, $now);

    $out = [];
    foreach ($lines as $cid => $l) {
        $row = ['check_id' => $cid, 'client_code' => $l['client_code'], 'station_country' => $l['station_country'],
                'cms_service' => $l['cms_service'], 'zone' => $l['zone'], 'fuel_type' => $l['fuel_type'],
                'fuel_label' => isset($labels[$l['fuel_type']]) ? $labels[$l['fuel_type']] : $l['fuel_type'],
                'options' => fuel_verify_shuffle($l['options'], $rand)];
        if ($see) $row['expected'] = fuel_verify_fmt($l['correct']);
        if (isset($ans[$cid])) $row['answer'] = ['chosen' => fuel_verify_fmt($ans[$cid]['chosen']), 'ok' => $ans[$cid]['ok']];
        $out[] = $row;
    }
    $w = $state['wrong'];
    return ['ok' => true, 'code' => 200, 'error' => null, 'form' => [
        'task_id'       => (int)$taskId,
        'week_start'    => fuel_verify_week($task),
        'round'         => $state['round'],
        'locked'        => $state['locked'],
        'closed'        => $closed,
        'show_expected' => $see,
        'gate'          => $gate === null ? null : $gate['error'],
        'can_submit'    => $owner && !$closed && !$state['locked'] && $gate === null
                           && in_array($task['status'], ['in_progress', 'paused'], true) && !$ans,
        'can_reset'     => $state['locked'] && !$closed && tasks_is_admin($perms)
                           && !($w !== null && (int)$w['actor_id'] === $me),
        'wrong_by'      => $w === null ? null : $w['actor_name'],
        'wrong_at'      => $w === null ? null : time_db_to_local($w['created_at']),
        'lines'         => $out,
    ]];
}

// ─────────────────────────────────────────────────────────────────────
// ΥΠΟΒΟΛΗ — POST {action: verify_submit, id, answers: {check_id: "<τιμή>"}}
//
// Σειρά (σχέδιο §6): guard -> ανάδοχος -> Δ-Α8 -> κλειδωμένος -> μία
// υποβολή ανά γύρο -> όλες οι γραμμές, τιμή από τις επιλογές -> βαθμολόγηση.
// Τίποτα δεν γράφεται πριν περάσουν ΟΛΟΙ οι έλεγχοι.
// ─────────────────────────────────────────────────────────────────────
function fuel_verify_submit($db, $session, $perms, $taskId, $answers, $now = null)
{
    list($task, $err) = fuel_verify_guard($db, $session, $perms, $taskId);
    if ($err) return $err;
    $me = (int)$session['id'];
    if (in_array($task['status'], ['done', 'na'], true)) return tasks_err_closed();
    if ($task['assigned_to'] === null || (int)$task['assigned_to'] !== $me) return fuel_verify_err(403, 'owner');

    $gate = tasks_close_gate($db, $task, $now);
    if ($gate !== null) return $gate;

    $evs   = tasks_steps_events($db, [(int)$taskId]);
    $state = fuel_verify_state($evs[(int)$taskId]);
    if ($state['locked']) return fuel_verify_err(409, 'locked');
    $lines = fuel_verify_round_lines($db, $taskId, $state['round']);
    if (!$lines) return fuel_verify_err(409, 'reload');
    if (fuel_verify_answers($db, $taskId, $state['round'])) return fuel_verify_err(409, 'locked');

    $answers = is_array($answers) ? $answers : [];
    $pick = [];
    foreach ($lines as $cid => $l) {
        $v = isset($answers[$cid]) ? $answers[$cid] : (isset($answers[(string)$cid]) ? $answers[(string)$cid] : null);
        if ($v === null || $v === '') return fuel_verify_err(400, 'incomplete');
        $c = fuel_verify_cents(is_string($v) ? $v : (is_numeric($v) ? (string)$v : ''));
        if ($c === null || !in_array(fuel_verify_fmt($c), $l['options'], true)) return fuel_verify_err(400, 'bad_value');
        $pick[$cid] = $c;
    }

    $ins = $db->prepare('INSERT INTO `4a_fuel_verify_answers` (`task_id`, `round`, `check_id`, `chosen`, `is_correct`, `user_id`)
                         VALUES (?, ?, ?, ?, ?, ?)');
    $res = []; $wrong = [];
    try {
        foreach ($lines as $cid => $l) {
            $ok = $pick[$cid] === $l['correct'];
            $ins->execute([(int)$taskId, $state['round'], $cid, fuel_verify_fmt($pick[$cid]), $ok ? 1 : 0, $me]);
            $res[] = ['check_id' => $cid, 'ok' => $ok];
            if (!$ok) $wrong[] = ['check_id' => $cid, 'client_code' => $l['client_code'], 'station_country' => $l['station_country'],
                                  'cms_service' => $l['cms_service'], 'zone' => $l['zone'], 'fuel_type' => $l['fuel_type']];
        }
    } catch (PDOException $e) {
        // UNIQUE task/round/check: ταυτόχρονη δεύτερη υποβολή.
        return fuel_verify_err(409, 'locked');
    }
    $total = count($lines);
    $meta  = ['round' => $state['round'], 'total' => $total, 'wrong' => $wrong, 'lines' => $res];

    if ($wrong) {
        // Φάση Β: η ειδοποίηση διαβάζει αυτό το γεγονός (το meta έχει ό,τι χρειάζεται).
        tasks_event($db, $taskId, 'verify_wrong', $me, 'επαλήθευση: ' . count($wrong) . ' από ' . $total . ' λάθος', $meta);
        return fuel_verify_err(409, 'wrong', ['wrong' => array_column($wrong, 'check_id'), 'total' => $total]);
    }
    return tasks_close($db, $task, $me, 'verify_ok', 'επαλήθευση στο CMS: ' . $total . '/' . $total . ' σωστές', $meta, $now);
}

// ─────────────────────────────────────────────────────────────────────
// ΕΠΑΝΑΦΟΡΑ — POST {action: verify_reset, id, reason}  (απόφαση Ε3)
//
// Μόνο διαχειριστής, ΑΛΛΟΣ από αυτόν που απάντησε λάθος, με λόγο >= reason_min
// χαρακτήρες (ρύθμιση), μόνο σε κλειδωμένο επαληθευτή. Νέος γύρος: οι επιλογές
// κληρώνονται ξανά στο επόμενο άνοιγμα.
// ─────────────────────────────────────────────────────────────────────
function fuel_verify_reset($db, $session, $perms, $taskId, $reason)
{
    if (!tasks_is_admin($perms)) return fuel_verify_err(403, 'admin');
    list($task, $err) = fuel_verify_guard($db, $session, $perms, $taskId);
    if ($err) return $err;
    $me = (int)$session['id'];
    if (in_array($task['status'], ['done', 'na'], true)) return tasks_err_closed();

    $evs   = tasks_steps_events($db, [(int)$taskId]);
    $state = fuel_verify_state($evs[(int)$taskId]);
    if (!$state['locked']) return fuel_verify_err(409, 'not_locked');
    if ((int)$state['wrong']['actor_id'] === $me) return fuel_verify_err(403, 'reset_self');
    list($reason, $err) = fuel_verify_reason($db, $reason);
    if ($err) return $err;

    tasks_event($db, $taskId, 'verify_reset', $me, $reason,
                ['from_round' => $state['round'], 'to_round' => $state['round'] + 1, 'reason' => $reason]);
    return tasks_okres($db, $taskId);
}

/**
 * Ο λόγος (επαναφορά, παράκαμψη στο verify) με τα όρια της βάσης:
 * [λόγος κομμένος στο reason_max, null] ή [null, σφάλμα].
 */
function fuel_verify_reason($db, $reason)
{
    $s = fuel_verify_settings($db);
    if ($s === null || !$s['valid']) return [null, fuel_verify_err(409, 'config')];
    $reason = trim((string)($reason === null ? '' : $reason));
    if (mb_strlen($reason) < $s['reason_min']) return [null, fuel_verify_err(400, 'reason', ['n' => $s['reason_min']])];
    if (mb_strlen($reason) > $s['reason_max']) $reason = mb_substr($reason, 0, $s['reason_max']);
    return [$reason, null];
}

/** Υπέβαλε απαντήσεις ο χρήστης σε αυτή την εργασία (οποιονδήποτε γύρο); */
function fuel_verify_participated($db, $taskId, $userId)
{
    try {
        $st = $db->prepare('SELECT COUNT(*) FROM `4a_fuel_verify_answers` WHERE `task_id` = ? AND `user_id` = ?');
        $st->execute([(int)$taskId, (int)$userId]);
        return (int)$st->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────
// ΛΙΣΤΑ ΚΑΙ ΑΠΑΝΤΗΣΕΙΣ ΕΝΕΡΓΕΙΩΝ
//
// fuel_verify_redact: για εβδομάδα με ενεργό επαληθευτή, χωρίς το
// δικαίωμα, το payload (ρίζας ΚΑΙ verify) χάνει prices / source / file.
// Εξαίρεση ο ανάδοχος της ρίζας (①–②): χωρίς το αρχείο δεν δουλεύει.
//
// fuel_verify_info: στο verify με ενεργό επαληθευτή, το κλειδί `verifier`
// (γύρος, κλειδωμένος, παράκαμψη) και ΜΟΝΟ με το δικαίωμα το `alert`
// (το κόκκινο σήμα: ποιες γραμμές, ποιος, πότε).
// ─────────────────────────────────────────────────────────────────────
if (!defined('FV_REDACT_KEYS')) define('FV_REDACT_KEYS', 'prices,source,file');

/** Ο ανάδοχος της ρίζας της εβδομάδας (ίδιο dedupe_key, τύπος = depends_on του verify). */
function fuel_verify_root_owner($db, $task, $verifyCode)
{
    $st = $db->prepare('SELECT t.`assigned_to` FROM `4a_tasks` t
                          JOIN `4a_task_types` v ON v.`code` = ?
                         WHERE t.`dedupe_key` = ? AND t.`task_code` = v.`depends_on` ORDER BY t.`id`');
    $st->execute([(string)$verifyCode, (string)$task['dedupe_key']]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (int)$v;
}

/** Ο χρήστης βλέπει τις τιμές της εβδομάδας αυτής της εργασίας; (δικαίωμα ή ανάδοχος ρίζας) */
function fuel_verify_sees_week($db, $session, $perms, $task)
{
    if (fuel_verify_can_see($perms)) return true;
    $vc = fuel_verify_type_for($db, $task['task_code']);
    if ($vc === null || !fuel_verify_active($db, $task)) return true;
    return fuel_verify_root_owner($db, $task, $vc) === (int)$session['id'];
}

/** Μία εργασία (γραμμή λίστας ή task απάντησης), με το payload καθαρισμένο αν χρειάζεται. */
function fuel_verify_redact($db, $session, $perms, $task)
{
    if (!is_array($task) || !array_key_exists('client_id', $task) || $task['client_id'] !== null) return $task;
    if (!isset($task['payload']) || !is_string($task['payload'])) return $task;
    if (fuel_verify_sees_week($db, $session, $perms, $task)) return $task;
    $p = json_decode($task['payload'], true);
    if (!is_array($p)) return $task;
    foreach (explode(',', FV_REDACT_KEYS) as $k) unset($p[$k]);
    $task['payload'] = json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $task;
}

/** Το `verifier` μιας εργασίας verify με ενεργό επαληθευτή, ή null. */
function fuel_verify_info($db, $session, $perms, $task, array $events)
{
    if (!in_array((string)$task['task_code'], fuel_verify_types($db), true) || !fuel_verify_active($db, $task)) return null;
    $state = fuel_verify_state($events);
    $info  = ['round' => $state['round'], 'locked' => $state['locked'], 'override' => tasks_steps_override($events), 'passed' => null];
    // ③ ΕΓΙΝΕ: «Επαληθεύτηκε n/total» από το ΤΕΛΕΥΤΑΙΟ verify_ok (πλήθη, καμία τιμή: για όλους).
    foreach ($events as $e) {
        if ($e['event'] !== 'verify_ok') continue;
        $m = is_array($e['meta']) ? $e['meta'] : [];
        $t = isset($m['total']) ? (int)$m['total'] : 0;
        $info['passed'] = $t > 0 ? ['n' => $t - (isset($m['wrong']) && is_array($m['wrong']) ? count($m['wrong']) : 0), 'total' => $t] : null;
    }
    if (fuel_verify_can_see($perms)) {
        $w = $state['wrong'];
        $info['alert'] = null;
        if ($w !== null) {
            // Το alert υπάρχει ΜΟΝΟ με το δικαίωμα: εδώ και η τιμή που
            // επιλέχθηκε και η αναμενόμενη ανά λάθος γραμμή (απόφαση 19/10).
            $m     = is_array($w['meta']) ? $w['meta'] : [];
            $round = isset($m['round']) ? (int)$m['round'] : $state['round'];
            $vals  = fuel_verify_line_values($db, (int)$task['id'], $round);
            $lbl   = fuel_verify_fuel_labels($db);
            $wrong = [];
            foreach (isset($m['wrong']) && is_array($m['wrong']) ? $m['wrong'] : [] as $x) {
                $cid = isset($x['check_id']) ? (int)$x['check_id'] : 0;
                $ft  = isset($x['fuel_type']) ? (string)$x['fuel_type'] : '';
                $wrong[] = $x + ['fuel_label' => isset($lbl[$ft]) ? $lbl[$ft] : $ft]
                              + (isset($vals[$cid]) ? $vals[$cid] : ['picked' => null, 'expected' => null]);
            }
            $info['alert'] = ['at' => time_db_to_local($w['created_at']), 'by' => $w['actor_name'], 'by_id' => (int)$w['actor_id'],
                              'wrong' => $wrong, 'total' => isset($m['total']) ? (int)$m['total'] : null];
        }
    }
    return $info;
}

/** Ανά γραμμή ενός γύρου: picked (η απάντηση) και expected (η σωστή), «ακέραιο.δδ». ΜΟΝΟ για χρήστη με το δικαίωμα. */
function fuel_verify_line_values($db, $taskId, $round)
{
    $out = [];
    $ans = fuel_verify_answers($db, $taskId, $round);
    foreach (fuel_verify_round_lines($db, $taskId, $round) as $cid => $l) {
        $out[$cid] = ['picked'   => isset($ans[$cid]) ? fuel_verify_fmt($ans[$cid]['chosen']) : null,
                      'expected' => fuel_verify_fmt($l['correct'])];
    }
    return $out;
}

/**
 * Το ιστορικό: στα γεγονότα verify_ok / verify_wrong το κλειδί `verify`
 * {round, lines: [{client_code, station_country, zone, cms_service,
 * fuel_type, fuel_label, ok}]}. ΜΟΝΟ με το δικαίωμα, ανά γραμμή, και τα
 * picked / expected. Τα υπόλοιπα γεγονότα αθικτα.
 */
function fuel_verify_history($db, $perms, $taskId, array $events)
{
    $see = fuel_verify_can_see($perms);
    $labels = null;
    foreach ($events as $i => $e) {
        if (!in_array($e['event'], ['verify_ok', 'verify_wrong'], true)) continue;
        $m = is_string($e['meta']) ? json_decode($e['meta'], true) : $e['meta'];
        if (!is_array($m) || !isset($m['round'])) continue;
        $round = (int)$m['round'];
        try {
            $lines = fuel_verify_round_lines($db, $taskId, $round);
            $ans   = fuel_verify_answers($db, $taskId, $round);
        } catch (Exception $x) {
            continue;                     // χωρίς πίνακες (rollback): το γεγονός μένει όπως είναι
        }
        if ($labels === null) $labels = fuel_verify_fuel_labels($db);
        $out = [];
        foreach ($lines as $cid => $l) {
            $row = ['client_code' => $l['client_code'], 'station_country' => $l['station_country'], 'zone' => $l['zone'],
                    'cms_service' => $l['cms_service'], 'fuel_type' => $l['fuel_type'],
                    'fuel_label' => isset($labels[$l['fuel_type']]) ? $labels[$l['fuel_type']] : $l['fuel_type'],
                    'ok' => isset($ans[$cid]) ? $ans[$cid]['ok'] : null];
            if ($see) {
                $row['picked']   = isset($ans[$cid]) ? fuel_verify_fmt($ans[$cid]['chosen']) : null;
                $row['expected'] = fuel_verify_fmt($l['correct']);
            }
            $out[] = $row;
        }
        $events[$i]['verify'] = ['round' => $round, 'lines' => $out];
    }
    return $events;
}

/**
 * Η λίστα: καθαρισμός payload και `verifier` στις γραμμές ΚΑΙ στις
 * εγγραφές του chain. Χωρίς ενεργό επαληθευτή: οι εργασίες επιστρέφονται
 * ΑΘΙΚΤΕΣ (ούτε ένα νέο κλειδί).
 */
function fuel_verify_list($db, $session, $perms, array $tasks)
{
    $types = fuel_verify_types($db);
    if (!$types || fuel_verify_settings($db) === null) return $tasks;
    $ids = [];
    foreach ($tasks as $t) {
        if ($t['client_id'] !== null) continue;
        if (in_array((string)$t['task_code'], $types, true)) $ids[] = (int)$t['id'];
        if (isset($t['chain'])) foreach ($t['chain'] as $c) if (in_array((string)$c['task_code'], $types, true)) $ids[] = (int)$c['id'];
    }
    $evs = tasks_steps_events($db, $ids);
    $info = [];
    foreach ($tasks as $i => $t) {
        if ($t['client_id'] !== null) continue;
        $tasks[$i] = fuel_verify_redact($db, $session, $perms, $t);
        if (in_array((string)$t['task_code'], $types, true)) {
            $v = fuel_verify_info($db, $session, $perms, $t, $evs[(int)$t['id']]);
            if ($v !== null) { $tasks[$i]['verifier'] = $v; $info[(int)$t['id']] = $v; }
        }
    }
    foreach ($tasks as $i => $t) {
        if (!isset($t['chain'])) continue;
        foreach ($t['chain'] as $j => $c) {
            if (!in_array((string)$c['task_code'], $types, true)) continue;
            if (!isset($info[(int)$c['id']])) {
                $full = tasks_fetch_one($db, (int)$c['id']);
                $v = $full ? fuel_verify_info($db, $session, $perms, $full, $evs[(int)$c['id']]) : null;
                if ($v === null) continue;
                $info[(int)$c['id']] = $v;
            }
            $tasks[$i]['chain'][$j]['verifier'] = $info[(int)$c['id']];
        }
    }
    return $tasks;
}

} // function_exists
