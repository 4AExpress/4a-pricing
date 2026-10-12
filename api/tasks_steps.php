<?php
// tasks_steps.php | v1.0 | 12-10-2026
// Φάση 5, Κ2α — ΚΑΡΤΑ ΒΗΜΑΤΩΝ (fuel_weekly). Σχέδιο: phase5_plan.md, Ε.10.
//
// ΟΙ ΚΑΝΟΝΕΣ ΤΗΣ ΝΕΑΣ ΚΑΡΤΑΣ ΖΟΥΝ ΣΤΗ ΒΑΣΗ, στον 4a_task_steps
// (migration 2026-10-12b). Ο πίνακας είναι ΚΑΙ ο διακόπτης:
//
//   τύπος ΧΩΡΙΣ γραμμές  -> ΑΚΡΙΒΩΣ η σημερινή συμπεριφορά. Καμία νέα
//                           ενέργεια δεν δουλεύει (409), η λίστα δεν
//                           αποκτά κανένα νέο πεδίο, το done/na όπως πριν.
//   τύπος ΜΕ γραμμές     -> κάρτα βημάτων: η εργασία κλείνει ΜΟΝΟ από τα
//                           βήματά της, το γενικό done απαντά 409.
//
// Έτσι ο κώδικας ανεβαίνει ΑΝΕΝΕΡΓΟΣ, και η ενεργοποίηση είναι ένα
// migration με δικό του αντίστροφο. Αν ο πίνακας λείπει (ο κώδικας ανέβηκε
// πριν από το migration), όλα συμπεριφέρονται σαν «χωρίς γραμμές».
//
// Ίδιες αρχές με το tasks_lib.php: καθαρές συναρτήσεις που δέχονται PDO,
// τίποτα δεν εκτελείται κατά το include, δοκιμάζονται σε SQLite.
//
// ΤΟ ΒΗΜΑ ΒΓΑΙΝΕΙ ΑΠΟ ΤΑ ΓΕΓΟΝΟΤΑ (4a_task_events), ποτέ από αποθηκευμένη
// στήλη: κανένα UPDATE σε υπάρχουσα εργασία, και μια παλιά `done` χωρίς τα
// νέα γεγονότα (η ιστορική εγγραφή 05–11/10) δείχνει απλώς το τελικό βήμα.

if (!function_exists('tasks_steps_load')) {

if (!defined('TASKS_STEPS_OFF')) {
    define('TASKS_STEPS_OFF', 'η λειτουργία δεν έχει ενεργοποιηθεί για αυτόν τον τύπο εργασίας');
}
// Γεγονότα με τα οποία «συμμετέχει» κάποιος στη δουλειά της εργασίας.
// Όποιος έχει ένα από αυτά ΔΕΝ μπορεί να κάνει admin_override (απόφαση 12/10).
if (!defined('TASKS_STEPS_WORK_EVENTS')) {
    define('TASKS_STEPS_WORK_EVENTS', 'downloaded,uploaded,upload_failed');
}

/**
 * Οι ορισμοί βημάτων για τους τύπους $codes, σε ΕΝΑ ερώτημα.
 *
 * @return array code => ['steps' => [γραμμές kind=step με σειρά],
 *                         'events' => [όνομα γεγονότος => γραμμή kind=event]]
 *               Τύπος χωρίς γραμμές ΔΕΝ εμφανίζεται στο αποτέλεσμα.
 */
function tasks_steps_load($db, array $codes)
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), 'strlen')));
    if (!$codes) return [];
    try {
        $st = $db->prepare('SELECT `task_code`, `kind`, `step_code`, `sort_order`, `action`, `done_event`,
                                   `is_final`, `status_label`, `hint`, `button_label`, `button_icon`,
                                   `timeline_label`, `alert`
                              FROM `4a_task_steps`
                             WHERE `task_code` IN (' . implode(',', array_fill(0, count($codes), '?')) . ')
                             ORDER BY `task_code`, `sort_order`, `id`');
        $st->execute($codes);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Ο πίνακας λείπει: ο κώδικας ανέβηκε πριν από το migration. Αυτό
        // είναι επιτρεπτή σειρά, όχι σφάλμα — όλα μένουν όπως σήμερα.
        error_log('tasks_steps_load: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $c = $r['task_code'];
        if (!isset($out[$c])) $out[$c] = ['steps' => [], 'events' => []];
        if ($r['kind'] === 'event') $out[$c]['events'][$r['step_code']] = $r;
        else                        $out[$c]['steps'][] = $r;
    }
    // Ορισμός χωρίς ΚΑΝΕΝΑ βήμα (μόνο ετικέτες γεγονότων) δεν ενεργοποιεί
    // τίποτα: μισή ρύθμιση στη βάση δεν πρέπει να κλειδώνει εργασίες.
    foreach ($out as $c => $d) if (!$d['steps']) unset($out[$c]);
    return $out;
}

/** Ο ορισμός ενός τύπου, ή null αν ο τύπος δεν έχει βήματα (= σημερινή ροή). */
function tasks_steps_def($db, $taskCode)
{
    $all = tasks_steps_load($db, [$taskCode]);
    return isset($all[$taskCode]) ? $all[$taskCode] : null;
}

/** Τα γεγονότα ανά εργασία, σε ΕΝΑ ερώτημα, με το meta αποκωδικοποιημένο. */
function tasks_steps_events($db, array $taskIds)
{
    $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
    if (!$taskIds) return [];
    $st = $db->prepare('SELECT e.`id`, e.`task_id`, e.`event`, e.`actor_id`, e.`note`, e.`meta`, e.`created_at`,
                               u.`name` AS actor_name
                          FROM `4a_task_events` e
                          LEFT JOIN `4a_users` u ON u.`id` = e.`actor_id`
                         WHERE e.`task_id` IN (' . implode(',', array_fill(0, count($taskIds), '?')) . ')
                         ORDER BY e.`id`');
    $st->execute($taskIds);
    $out = [];
    foreach ($taskIds as $id) $out[$id] = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $m = $e['meta'] !== null ? json_decode($e['meta'], true) : null;
        $e['meta'] = is_array($m) ? $m : null;
        $out[(int)$e['task_id']][] = $e;
    }
    return $out;
}

/** Τα γεγονότα ΜΕΤΑ το τελευταίο `reopened`: μόνο αυτά μετρούν για το βήμα. */
function tasks_steps_since_reopen(array $events)
{
    $from = 0;
    foreach ($events as $i => $e) if ($e['event'] === 'reopened') $from = $i + 1;
    return array_slice($events, $from);
}

/** dd/mm από το week_start του payload, για το {week_start} των κειμένων. */
function tasks_steps_fill($text, $payload)
{
    if ($text === null) return null;
    $ws = '';
    if (is_array($payload) && isset($payload['week_start'])
        && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$payload['week_start'], $m)) {
        $ws = $m[3] . '/' . $m[2];
    }
    return str_replace('{week_start}', $ws, (string)$text);
}

/**
 * Το τρέχον βήμα: η πρώτη γραμμή kind=step (εκτός τελικών) της οποίας το
 * done_event ΔΕΝ υπάρχει μετά το τελευταίο reopened. Κλειστή (done) ->
 * το τελικό βήμα, ΑΝΕΞΑΡΤΗΤΑ από τα γεγονότα (Ν: παλιές done).
 * Επιστρέφει τη γραμμή ορισμού, ή null για na.
 */
function tasks_steps_current(array $def, array $task, array $events)
{
    $final = null;
    foreach ($def['steps'] as $s) if ((int)$s['is_final'] === 1) { $final = $s; break; }

    if ($task['status'] === 'done') return $final;
    if ($task['status'] === 'na')   return null;

    $seen = [];
    foreach (tasks_steps_since_reopen($events) as $e) $seen[$e['event']] = true;
    foreach ($def['steps'] as $s) {
        if ((int)$s['is_final'] === 1) continue;
        if ($s['done_event'] === null || !isset($seen[$s['done_event']])) return $s;
    }
    return $final;
}

/** Η τελευταία παράκαμψη admin και αν την επιβεβαίωσε άλλος admin. */
function tasks_steps_override(array $events)
{
    $ov = null;
    foreach ($events as $e) {
        if ($e['event'] === 'admin_override') $ov = ['event_id' => (int)$e['id'], 'actor_id' => (int)$e['actor_id'],
                                                      'actor_name' => $e['actor_name'], 'at' => $e['created_at'],
                                                      'acked' => false, 'acked_by' => null];
        elseif ($e['event'] === 'override_acknowledged' && $ov !== null) {
            $ov['acked'] = true;
            $ov['acked_by'] = $e['actor_name'];
        }
    }
    return $ov;
}

/**
 * Οι γραμμές του χρονολογίου, από τα ΥΠΑΡΧΟΝΤΑ γεγονότα. Μπαίνει μόνο ό,τι
 * έχει ετικέτα στη βάση: το done_event ενός βήματος (timeline_label του
 * βήματος) ή γεγονός με γραμμή kind=event. Τα υπόλοιπα (αναθέσεις κ.λπ.)
 * μένουν στο «ιστορικό».
 *
 * Γεγονός εκ των υστέρων: η ώρα από το meta.occurred_at, με σημαία late —
 * ποτέ πλαστό created_at (διόρθωση Ν, 09/10).
 */
function tasks_steps_timeline(array $def, array $events)
{
    $labels = [];
    foreach ($def['steps'] as $s) {
        if ($s['done_event'] !== null && $s['timeline_label'] !== null) {
            $labels[$s['done_event']] = ['label' => $s['timeline_label'], 'alert' => (int)$s['alert'] === 1];
        }
    }
    foreach ($def['events'] as $code => $r) {
        if ($r['timeline_label'] !== null) $labels[$code] = ['label' => $r['timeline_label'], 'alert' => (int)$r['alert'] === 1];
    }

    $out = [];
    foreach ($events as $e) {
        if (!isset($labels[$e['event']])) continue;
        $late = is_array($e['meta']) && isset($e['meta']['occurred_at']) && is_string($e['meta']['occurred_at']);
        $out[] = [
            'event'  => $e['event'],
            'label'  => $labels[$e['event']]['label'],
            'alert'  => $labels[$e['event']]['alert'],
            'at'     => $late ? $e['meta']['occurred_at'] : $e['created_at'],
            'late'   => $late,
            'actor'  => $e['actor_name'],
            'note'   => $e['note'],
            'meta'   => $e['meta'],
        ];
    }
    return $out;
}

/**
 * Προσθέτει `step`, `timeline` και `override` ΜΟΝΟ στις εργασίες τύπων με
 * βήματα. Όλες οι άλλες (κάθε εργασία πελάτη) επιστρέφονται ΑΘΙΚΤΕΣ: ούτε
 * ένα νέο κλειδί, ώστε το JSON τους να μένει byte-προς-byte ίδιο.
 */
function tasks_steps_attach($db, array $tasks)
{
    $defs = tasks_steps_load($db, array_map(function ($t) { return $t['task_code']; }, $tasks));
    if (!$defs) return $tasks;

    $ids = [];
    foreach ($tasks as $t) if (isset($defs[$t['task_code']])) $ids[] = (int)$t['id'];
    $evs = tasks_steps_events($db, $ids);

    foreach ($tasks as $i => $t) {
        if (!isset($defs[$t['task_code']])) continue;
        $def = $defs[$t['task_code']];
        $ev  = $evs[(int)$t['id']];
        $p   = isset($t['payload']) && is_string($t['payload']) ? json_decode($t['payload'], true) : null;
        $cur = tasks_steps_current($def, $t, $ev);

        $tasks[$i]['step'] = $cur === null ? null : [
            'code'         => $cur['step_code'],
            'action'       => $cur['action'],
            'final'        => (int)$cur['is_final'] === 1,
            'status_label' => tasks_steps_fill($cur['status_label'], $p),
            'hint'         => tasks_steps_fill($cur['hint'], $p),
            'button_label' => $cur['button_label'],
            'button_icon'  => $cur['button_icon'],
        ];
        $tasks[$i]['timeline'] = tasks_steps_timeline($def, $ev);
        $tasks[$i]['override'] = tasks_steps_override($ev);
    }
    return $tasks;
}

// ─────────────────────────────────────────────────────────────────────
// ΚΛΕΙΣΙΜΟ — ΕΝΑ σημείο για done, upload και admin_override (ε5)
//
// Το ξεκλείδωμα της αλυσίδας ζει ΜΟΝΟ εδώ (και στο na). Ο όρος status ΜΕΣΑ
// στο UPDATE: δύο ταυτόχρονα κλεισίματα -> το ένα γράφει, το άλλο 409.
// ─────────────────────────────────────────────────────────────────────
function tasks_close($db, $task, $actorId, $event, $note, $meta = null)
{
    $taskId = (int)$task['id'];
    $st = $db->prepare('UPDATE `4a_tasks` SET `status` = \'done\', `closed_at` = NOW(), `closed_by` = ?
                         WHERE `id` = ? AND `status` NOT IN (\'done\', \'na\')');
    $st->execute([(int)$actorId, $taskId]);
    if ($st->rowCount() === 0) return tasks_err(409, 'η εργασία έχει ήδη κλείσει');

    tasks_event($db, $taskId, $event, (int)$actorId, $note, $meta);
    $u = tasks_unlock_and_assign($db, $task['dedupe_key'], $task['task_code'], (int)$actorId);
    return tasks_okres($db, $taskId, $u);
}

/** Κοινός έλεγχος: υπάρχει, είναι στο πεδίο, ο τύπος έχει βήματα. */
function tasks_steps_guard($db, $session, $perms, $taskId)
{
    $task = tasks_fetch_one($db, $taskId);
    if (!$task) return [null, null, tasks_err(404, 'η εργασία δεν βρέθηκε')];
    $def = tasks_steps_def($db, $task['task_code']);
    if ($def === null) return [null, null, tasks_err(409, TASKS_STEPS_OFF)];
    if (!tasks_country_ok($db, tasks_scope_countries($perms), $task, (int)$session['id'])) {
        return [null, null, tasks_err(403, 'ο πελάτης είναι εκτός του πεδίου σας')];
    }
    return [$task, $def, null];
}

/** Συμμετείχε ο χρήστης στη δουλειά (μετά το τελευταίο reopened); */
function tasks_steps_participated(array $events, $userId)
{
    $work = array_flip(explode(',', TASKS_STEPS_WORK_EVENTS));
    foreach (tasks_steps_since_reopen($events) as $e) {
        if (isset($work[$e['event']]) && (int)$e['actor_id'] === (int)$userId) return true;
    }
    return false;
}

// ─────────────────────────────────────────────────────────────────────
// ① ΛΗΨΗ. Το αρχείο φτιάχνεται στον browser από το payload· εδώ μόνο
// καταγράφεται. ΚΑΘΕ λήψη γράφεται (απόφαση Ο).
//
// Το ① κλείνει (`downloaded`) ΜΟΝΟ με λήψη στο ίδιο task id από τον
// ΑΝΑΔΟΧΟ του, όσο το τρέχον βήμα είναι η λήψη. Κάθε άλλη λήψη —
// επαληθευτής, admin που δεν είναι ανάδοχος, ξανά-λήψη, κλειστή εργασία —
// γράφεται `file_viewed` και δεν αλλάζει βήμα (απόφαση 09/10).
//
// Δικαίωμα: ο ανάδοχος, ο ανάδοχος άλλης εργασίας της ίδιας αλυσίδας
// (dedupe_key), ή διαχειριστής.
// ─────────────────────────────────────────────────────────────────────
function tasks_download($db, $session, $perms, $taskId)
{
    list($task, $def, $err) = tasks_steps_guard($db, $session, $perms, $taskId);
    if ($err) return $err;
    $me = (int)$session['id'];

    $isOwner = $task['assigned_to'] !== null && (int)$task['assigned_to'] === $me;
    if (!$isOwner && !tasks_is_admin($perms)) {
        $st = $db->prepare('SELECT COUNT(*) FROM `4a_tasks` WHERE `dedupe_key` = ? AND `assigned_to` = ?');
        $st->execute([(string)$task['dedupe_key'], $me]);
        if ((int)$st->fetchColumn() === 0) return tasks_err(403, 'η εργασία δεν είναι δική σας');
    }

    $evs = tasks_steps_events($db, [(int)$taskId]);
    $cur = tasks_steps_current($def, $task, $evs[(int)$taskId]);
    $closes = $isOwner && in_array($task['status'], ['in_progress', 'paused'], true)
           && $cur !== null && $cur['action'] === 'download';

    if ($closes) tasks_event($db, $taskId, 'downloaded', $me, 'λήψη αρχείου CMS');
    else         tasks_event($db, $taskId, 'file_viewed', $me, 'προβολή αρχείου');
    $out = tasks_okres($db, $taskId);
    $out['event'] = $closes ? 'downloaded' : 'file_viewed';
    return $out;
}

// ─────────────────────────────────────────────────────────────────────
// ② ΕΠΙΒΕΒΑΙΩΣΗ ΑΝΕΒΑΣΜΑΤΟΣ — απόφαση Π.
//
// Τα 4 νούμερα του Merge Result του CMS (Total / Merge / Updated /
// Exception). Κλείνει ΜΟΝΟ αν Total = rows_count του payload ΚΑΙ
// Exception = 0. Αλλιώς: γεγονός upload_failed με τα νούμερα, 409, και η
// εργασία ΜΕΝΕΙ ανοιχτή (διόρθωση και ξανά ②).
//
// Προειδοποιήσεις, ΟΧΙ φραγή, στο meta.warnings:
//   merge_updated_sum   Merge + Updated ≠ Total
//   updated_nonzero     Updated > 0
//
// ΜΟΝΟ ο ανάδοχος: ο admin που δεν είναι ανάδοχος έχει το admin_override,
// που καταγράφει γιατί.
// ─────────────────────────────────────────────────────────────────────
function tasks_upload($db, $session, $perms, $taskId, $nums)
{
    list($task, $def, $err) = tasks_steps_guard($db, $session, $perms, $taskId);
    if ($err) return $err;
    $me = (int)$session['id'];

    if (in_array($task['status'], ['done', 'na'], true)) return tasks_err(409, 'η εργασία έχει ήδη κλείσει');
    if ($task['assigned_to'] === null || (int)$task['assigned_to'] !== $me) {
        return tasks_err(403, 'την επιβεβαίωση ανεβάσματος την κάνει μόνο ο ανάδοχος');
    }
    $evs = tasks_steps_events($db, [(int)$taskId]);
    $cur = tasks_steps_current($def, $task, $evs[(int)$taskId]);
    if ($cur === null || $cur['action'] !== 'upload') {
        return tasks_err(409, 'πρώτα η λήψη του αρχείου');
    }

    $n = [];
    foreach (['total', 'merge', 'updated', 'exception'] as $k) {
        $v = is_array($nums) && isset($nums[$k]) ? $nums[$k] : null;
        if (is_int($v)) $v = (string)$v;
        if (!is_string($v) || !preg_match('/^\d{1,7}$/', trim($v))) {
            return tasks_err(400, 'τα Total, Merge, Updated, Exception πρέπει να είναι ακέραιοι ≥ 0');
        }
        $n[$k] = (int)trim($v);
    }

    $p = is_string($task['payload']) ? json_decode($task['payload'], true) : null;
    if (!is_array($p) || !isset($p['rows_count']) || !is_numeric($p['rows_count'])) {
        return tasks_err(409, 'το στιγμιότυπο της εργασίας δεν έχει πλήθος γραμμών');
    }
    $rows = (int)$p['rows_count'];
    $meta = ['total' => $n['total'], 'merge' => $n['merge'], 'updated' => $n['updated'],
             'exception' => $n['exception'], 'rows_count' => $rows];

    $why = [];
    if ($n['total'] !== $rows)  $why[] = 'Total ' . $n['total'] . ' ≠ ' . $rows . ' γραμμές του αρχείου';
    if ($n['exception'] !== 0)  $why[] = 'Exception ' . $n['exception'] . ' ≠ 0';
    if ($why) {
        $meta['reasons'] = $why;
        tasks_event($db, $taskId, 'upload_failed', $me, implode(' · ', $why), $meta);
        return tasks_err(409, 'το ανέβασμα δεν επιβεβαιώνεται: ' . implode(' · ', $why));
    }

    $warn = [];
    if ($n['merge'] + $n['updated'] !== $n['total']) $warn[] = 'merge_updated_sum';
    if ($n['updated'] > 0)                           $warn[] = 'updated_nonzero';
    if ($warn) $meta['warnings'] = $warn;

    return tasks_close($db, $task, $me, 'uploaded', 'ανέβηκε στο CMS', $meta);
}

// ─────────────────────────────────────────────────────────────────────
// ΠΑΡΑΚΑΜΨΗ ADMIN (απόφαση 12/10, Κ4α)
//
// Η ΜΟΝΗ έξοδος όταν το CMS δεν δίνει Exception = 0 και το «Δεν
// εφαρμόζεται» είναι κλειστό. Όροι:
//   · μόνο διαχειριστής
//   · υποχρεωτικός λόγος, τουλάχιστον 10 χαρακτήρες
//   · ΠΟΤΕ από όποιον συμμετείχε (ανάδοχος, ή γεγονός λήψης/ανεβάσματος)
//   · γεγονός admin_override, κόκκινη γραμμή στο χρονολόγιο, και κόκκινο
//     σήμα στους ΑΛΛΟΥΣ διαχειριστές μέχρι ένας να πατήσει «Το είδα»
// ─────────────────────────────────────────────────────────────────────
function tasks_admin_override($db, $session, $perms, $taskId, $reason)
{
    if (!tasks_is_admin($perms)) return tasks_err(403, 'μόνο διαχειριστής μπορεί να κάνει παράκαμψη');
    list($task, $def, $err) = tasks_steps_guard($db, $session, $perms, $taskId);
    if ($err) return $err;
    $me = (int)$session['id'];

    if (in_array($task['status'], ['done', 'na'], true)) return tasks_err(409, 'η εργασία έχει ήδη κλείσει');
    if ((int)$task['locked'] === 1) {
        $b = $task['blocked_by_label'] !== null ? $task['blocked_by_label'] : $task['depends_on'];
        return tasks_err(409, 'κλειδωμένη — περιμένει: ' . $b);
    }
    $reason = trim((string)($reason === null ? '' : $reason));
    if (mb_strlen($reason) < 10) return tasks_err(400, 'απαιτείται λόγος, τουλάχιστον 10 χαρακτήρες');
    if (mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);

    $evs = tasks_steps_events($db, [(int)$taskId]);
    if (($task['assigned_to'] !== null && (int)$task['assigned_to'] === $me)
        || tasks_steps_participated($evs[(int)$taskId], $me)) {
        return tasks_err(403, 'δεν μπορείτε να παρακάμψετε εργασία στην οποία συμμετέχετε');
    }
    $cur = tasks_steps_current($def, $task, $evs[(int)$taskId]);

    return tasks_close($db, $task, $me, 'admin_override', $reason,
                       ['step' => $cur === null ? null : $cur['step_code'], 'reason' => $reason]);
}

/**
 * «Το είδα»: σβήνει το κόκκινο σήμα της τελευταίας παράκαμψης. Μόνο
 * διαχειριστής ΑΛΛΟΣ από αυτόν που την έκανε. Δεν ελέγχει τον διακόπτη:
 * μια παράκαμψη που έγινε πρέπει να μπορεί να επιβεβαιωθεί ακόμα κι αν
 * ο τύπος απενεργοποιηθεί στο μεταξύ.
 */
function tasks_override_ack($db, $session, $perms, $taskId)
{
    if (!tasks_is_admin($perms)) return tasks_err(403, 'μόνο διαχειριστής');
    $task = tasks_fetch_one($db, $taskId);
    if (!$task) return tasks_err(404, 'η εργασία δεν βρέθηκε');

    $evs = tasks_steps_events($db, [(int)$taskId]);
    $ov  = tasks_steps_override($evs[(int)$taskId]);
    if ($ov === null)                       return tasks_err(409, 'δεν υπάρχει παράκαμψη');
    if ($ov['acked'])                       return tasks_err(409, 'η παράκαμψη έχει ήδη επιβεβαιωθεί');
    if ($ov['actor_id'] === (int)$session['id']) {
        return tasks_err(403, 'την παράκαμψη την επιβεβαιώνει άλλος διαχειριστής');
    }
    tasks_event($db, $taskId, 'override_acknowledged', (int)$session['id'], 'το είδα',
                ['override_event_id' => $ov['event_id']]);
    return tasks_okres($db, $taskId);
}

/**
 * Πόσες παρακάμψεις περιμένουν «Το είδα» από ΑΥΤΟΝ τον διαχειριστή: όσες
 * δεν έκανε ο ίδιος και δεν έχει επιβεβαιώσει κανείς. 0 για μη-admin.
 * Ένα «Το είδα» από οποιονδήποτε άλλον admin τη σβήνει για όλους.
 */
function tasks_overrides_pending($db, $session, $perms)
{
    if (!tasks_is_admin($perms)) return 0;
    $st = $db->prepare('SELECT COUNT(DISTINCT o.`task_id`)
                          FROM `4a_task_events` o
                          JOIN `4a_tasks` t ON t.`id` = o.`task_id`
                     LEFT JOIN `4a_clients` c ON c.`id` = t.`client_id`
                         WHERE o.`event` = \'admin_override\'
                           AND o.`actor_id` <> ?
                           AND COALESCE(c.`is_demo`, 0) = 0
                           AND NOT EXISTS (SELECT 1 FROM `4a_task_events` a
                                            WHERE a.`task_id` = o.`task_id`
                                              AND a.`event` = \'override_acknowledged\'
                                              AND a.`id` > o.`id`)');
    $st->execute([(int)$session['id']]);
    return (int)$st->fetchColumn();
}

/** allow_na του τύπου. Χωρίς στήλη ή χωρίς τύπο = 1 (σημερινή συμπεριφορά). */
function tasks_allow_na($db, $taskCode)
{
    try {
        $st = $db->prepare('SELECT `allow_na` FROM `4a_task_types` WHERE `code` = ?');
        $st->execute([(string)$taskCode]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? true : ((int)$v === 1);
    } catch (Exception $e) {
        return true;
    }
}

} // function_exists
