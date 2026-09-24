<?php
// tasks_lib.php | v1.0 | 23-09-2026
// Φάση 3 — η λογική της οθόνης εργασιών. Υλοποιεί το
// docs/tasks_phase3_spec.md.
//
// ΔΕΝ κάνει require config.php/auth.php και δεν εκτελεί τίποτα κατά το
// include: καθαρές συναρτήσεις που δέχονται PDO, όπως το tasks_create.php.
// Έτσι δοκιμάζονται με stub, χωρίς βάση και χωρίς HTTP. Το api/tasks.php
// είναι λεπτό routing από πάνω.
//
// ΚΑΘΕ ΣΥΝΑΡΤΗΣΗ ΕΝΕΡΓΕΙΑΣ επιστρέφει:
//   ['ok' => bool, 'code' => int (HTTP), 'error' => ?string, 'task' => ?array]

// ─────────────────────────────────────────────────────────────────────
// Κοινό SELECT. Το `locked` υπολογίζεται ΕΔΩ, στο SQL — ποτέ στον browser:
//   · ο server πρέπει να το ξαναελέγξει στο claim ούτως ή άλλως, και δύο
//     υλοποιήσεις του ίδιου κανόνα αποκλίνουν
//   · η ουρά δείχνει εργασίες πολλών πελατών· ο browser θα χρειαζόταν
//     ΟΛΕΣ τις εργασίες καθενός για να κρίνει κλείδωμα
//
// Το d.status IN ('done','na') είναι σκόπιμο: αν η προηγούμενη σημανθεί
// «δεν εφαρμόζεται», η εξαρτημένη ΠΡΕΠΕΙ να ξεκλειδώσει — αλλιώς η
// αλυσίδα κολλάει για πάντα.
// ─────────────────────────────────────────────────────────────────────
function tasks_base_sql()
{
    return 'SELECT t.`id`, t.`client_id`, t.`task_code`, t.`offer_number`,
                   t.`assigned_to`, t.`status`, t.`created_at`, t.`due_at`,
                   t.`closed_at`, t.`closed_by`, t.`close_reason`, t.`payload`,
                   t.`needs_attention`,
                   c.`name` AS client_name, c.`country` AS client_country,
                   c.`account` AS client_account,
                   tt.`label` AS task_label, tt.`sort_order`, tt.`depends_on`,
                   dt.`label` AS blocked_by_label,
                   u.`name` AS assigned_name,
                   CASE WHEN tt.`depends_on` IS NULL THEN 0
                        WHEN EXISTS (SELECT 1 FROM `4a_tasks` d
                                      WHERE d.`client_id`    = t.`client_id`
                                        AND d.`offer_number` = t.`offer_number`
                                        AND d.`task_code`    = tt.`depends_on`
                                        AND d.`status` IN (\'done\',\'na\'))
                        THEN 0 ELSE 1 END AS `locked`
              FROM `4a_tasks` t
              JOIN `4a_task_types` tt ON tt.`code` = t.`task_code`
              JOIN `4a_clients`    c  ON c.`id`    = t.`client_id`
         LEFT JOIN `4a_task_types` dt ON dt.`code` = tt.`depends_on`
         LEFT JOIN `4a_users`      u  ON u.`id`    = t.`assigned_to`';
}

function tasks_is_admin($perms)
{
    return isset($perms['role']) && $perms['role'] === 'administrator';
}

/**
 * Οι καταστάσεις με ετικέτα και χρώμα, από τον 4a_task_statuses.
 * Η οθόνη ΔΕΝ κρατά κανένα χρώμα σταθερό — όλα έρχονται από εδώ, ώστε
 * νέο στάδιο να μη χρειάζεται deploy σε HTML.
 *
 * Αν ο πίνακας λείπει (π.χ. το migration δεν έτρεξε ακόμα), γυρνάμε
 * άδειο αντί να ρίξουμε 500: η λίστα πρέπει να δουλεύει και χωρίς χρώματα.
 */
function tasks_statuses($db)
{
    try {
        return $db->query('SELECT `code`, `label`, `color`, `sort_order`
                             FROM `4a_task_statuses`
                            WHERE `active` = 1 ORDER BY `sort_order`, `code`')
                  ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('tasks_statuses: ' . $e->getMessage());
        return [];
    }
}

/**
 * Ποιες χώρες πελατών βλέπει ο χρήστης, από το pricelist_scope.
 * null = όλες (administrator ή BOTH). [] = καμία (scope NONE).
 * Ίδιος κανόνας με το api/clients.php:26,28 — ο χρήστης δεν πρέπει να
 * βλέπει εργασίες πελατών που δεν βλέπει.
 */
function tasks_scope_countries($perms)
{
    if (tasks_is_admin($perms)) return null;
    $scope = isset($perms['pricelist_scope']) ? $perms['pricelist_scope'] : 'GR';
    if ($scope === 'BOTH') return null;
    if ($scope === 'NONE') return [];
    return [$scope];
}

/** Μία εργασία με όλα τα παράγωγα πεδία, ή null. */
function tasks_fetch_one($db, $taskId)
{
    $st = $db->prepare(tasks_base_sql() . ' WHERE t.`id` = ?');
    $st->execute([(int)$taskId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

/** Έχει ο χρήστης τη δεξιότητα για αυτή την εργασία, σε αυτή τη χώρα; */
function tasks_has_skill($db, $userId, $taskCode, $country)
{
    $st = $db->prepare('SELECT COUNT(*) FROM `4a_user_task_skills`
                         WHERE `user_id` = ? AND `task_code` = ?
                           AND `country` IN (?, \'BOTH\')');
    $st->execute([(int)$userId, $taskCode, $country]);
    return ((int)$st->fetchColumn()) > 0;
}

/**
 * Το `note` είναι ΑΝΘΡΩΠΙΝΟ κείμενο — αυτό που διαβάζεται στο ιστορικό.
 * Το `meta` κρατά τα δομημένα (to_user, from_user, reason_code, why), ώστε
 * το «ποιος τράβηξε από ποιον» να διαβάζεται χωρίς ανάλυση κειμένου.
 */
function tasks_event($db, $taskId, $event, $actorId, $note, $meta = null)
{
    $db->prepare('INSERT INTO `4a_task_events` (`task_id`,`event`,`actor_id`,`note`,`meta`)
                  VALUES (?,?,?,?,?)')
       ->execute([(int)$taskId, $event, $actorId === null ? null : (int)$actorId, $note,
                  $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE)]);
}

// ─────────────────────────────────────────────────────────────────────
// ΔΕΣΜΕΥΣΗ ΤΟΥ STEAL (απόφαση 24/09/2026)
//
// Όποιος τραβά εργασία συναδέλφου δεσμεύεται να την ολοκληρώσει. ΔΕΝ
// μπλοκάρουμε την απόρριψη ή το «Αφήνω» — καταγράφουμε. Ένας κανόνας που
// μπλοκάρει παράγει ψεύτικα «ολοκληρώθηκε»· ένας κανόνας που καταγράφει
// παράγει συζήτηση.
//
// Και οι δύο έξοδοι (rejected, unassigned) σημαδεύονται, αλλιώς ο κανόνας
// παρακάμπτεται με ένα κλικ: τράβα -> άφησε -> καθαρό ιστορικό.
//
// ΓΙΑΤΙ ΚΟΙΤΑΜΕ ΜΟΝΟ ΤΟ ΤΕΛΕΥΤΑΙΟ ΓΕΓΟΝΟΣ ΑΝΑΘΕΣΗΣ: η τρέχουσα κατοχή
// ορίζεται από το πιο πρόσφατο από τα τρία που δίνουν ανάδοχο (stolen,
// auto_assigned, assigned). Αν αυτό είναι stolen με to_user = ο χρήστης,
// τότε κρατά εργασία που τράβηξε. Ένα παλιό stolen πριν από νεότερη
// ανάθεση ΔΕΝ μετράει — γι' αυτό LIMIT 1 σε φθίνουσα σειρά και όχι
// EXISTS. Το `assigned` καλύπτει και την ανάληψη από την ουρά και την
// ανάθεση από διαχειριστή· καμία από τις δύο δεν είναι steal.
// ─────────────────────────────────────────────────────────────────────
if (!defined('TASKS_AFTER_STEAL')) {
    define('TASKS_AFTER_STEAL', 'εργασία που είχε αναλάβει από συνάδελφο');
}

function tasks_held_by_steal($db, $taskId, $userId)
{
    $st = $db->prepare('SELECT `event`,
                               JSON_UNQUOTE(JSON_EXTRACT(`meta`, \'$.to_user\')) AS `to_user`
                          FROM `4a_task_events`
                         WHERE `task_id` = ?
                           AND `event` IN (\'stolen\', \'auto_assigned\', \'assigned\')
                         ORDER BY `id` DESC LIMIT 1');
    $st->execute([(int)$taskId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return false;
    return $r['event'] === 'stolen' && (int)$r['to_user'] === (int)$userId;
}

// ─────────────────────────────────────────────────────────────────────
// ΦΑΣΗ 4 — τυχαία ανάθεση
//
// Η αρχή: ανάθεση τη στιγμή που μια εργασία γίνεται ΔΙΑΘΕΣΙΜΗ.
//   · ρίζες (depends_on IS NULL) -> στη δημιουργία, από tasks_create.php
//   · εξαρτημένες                -> στο κλείσιμο της προηγούμενης
// Δεν υπάρχει τρίτη στιγμή: το `locked` είναι υπολογισμός, όχι γεγονός.
// ─────────────────────────────────────────────────────────────────────

/**
 * Οι δικαιούχοι μιας εργασίας: δεξιότητα για τον κωδικό ΚΑΙ τη χώρα,
 * ενεργοί, ΧΩΡΙΣ τους διαχειριστές, και ΠΟΤΕ όσοι την έχουν ήδη απορρίψει.
 * Το NOT IN διαβάζει τον 4a_task_rejections — εκεί ζει ο κανόνας.
 */
function tasks_candidate_pool($db, $taskId, $taskCode, $country)
{
    $st = $db->prepare(
        'SELECT DISTINCT s.`user_id`
           FROM `4a_user_task_skills` s
           JOIN `4a_users` u ON u.`id` = s.`user_id`
          WHERE s.`task_code` = ?
            AND s.`country` IN (?, \'BOTH\')
            AND u.`active` = 1
            AND u.`role` <> \'administrator\'
            AND s.`user_id` NOT IN (SELECT `user_id` FROM `4a_task_rejections`
                                     WHERE `task_id` = ?)');
    $st->execute([$taskCode, $country, (int)$taskId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Τυχαία ανάθεση σε έναν δικαιούχο. Επιστρέφει το user_id ή null.
 *
 * Ο τυχαίος διαλέγεται ΕΔΩ και όχι με ORDER BY RAND(): σε join δύο
 * πινάκων το RAND() είναι αδιαφανές, ενώ μια λίστα 3-5 ονομάτων
 * ελέγχεται σε test.
 *
 * ΧΩΡΙΣ ΔΙΚΑΙΟΥΧΟ: η εργασία ΔΕΝ ανατίθεται — σημαίνεται. Η ορατή στοίβα
 * είναι το ζητούμενο, όχι σιωπηλή εξαφάνιση.
 */
function tasks_auto_assign($db, $task, $actorId, $why)
{
    $taskId = (int)$task['id'];
    $pool = tasks_candidate_pool($db, $taskId, $task['task_code'], $task['client_country']);

    if (!$pool) {
        $db->prepare('UPDATE `4a_tasks` SET `needs_attention` = 1 WHERE `id` = ?')->execute([$taskId]);
        tasks_event($db, $taskId, 'no_candidate', null,
                    'κανένας δικαιούχος — μένει στην ουρά με σήμανση', ['why' => $why]);
        return null;
    }

    $winner = $pool[random_int(0, count($pool) - 1)];

    // Ίδιο μοτίβο με το claim: ο όρος ΜΕΣΑ στο UPDATE, ώστε δύο
    // ταυτόχρονες αναθέσεις να μη συγκρούονται.
    $st = $db->prepare('UPDATE `4a_tasks`
                           SET `assigned_to` = ?, `status` = \'in_progress\', `needs_attention` = 0
                         WHERE `id` = ? AND `assigned_to` IS NULL AND `status` = \'open\'');
    $st->execute([$winner, $taskId]);
    if ($st->rowCount() === 0) return null;   // κάποιος πρόλαβε — δεν είναι σφάλμα

    tasks_event($db, $taskId, 'auto_assigned', $actorId, 'τυχαία ανάθεση — ' . $why,
                ['to_user' => $winner, 'why' => $why]);
    return $winner;
}

/**
 * Μετά το κλείσιμο μιας εργασίας: βρες όσες εξαρτιόνταν από αυτήν, για
 * ΤΟΝ ΙΔΙΟ πελάτη και ΤΗΝ ΙΔΙΑ προσφορά, και ανάθεσε όσες ξεκλείδωσαν.
 *
 * Το `locked` ΞΑΝΑΕΛΕΓΧΕΤΑΙ με τον ίδιο υπολογισμό — δεν υποθέτουμε ότι
 * ξεκλείδωσε: με βαθύτερη αλυσίδα μπορεί να περιμένει και άλλη.
 */
function tasks_unlock_and_assign($db, $clientId, $offerNo, $closedCode, $actorId)
{
    $st = $db->prepare(tasks_base_sql() . '
        WHERE t.`client_id` = ? AND t.`offer_number` = ? AND tt.`depends_on` = ?
          AND t.`status` = \'open\' AND t.`assigned_to` IS NULL');
    $st->execute([(int)$clientId, (string)$offerNo, (string)$closedCode]);

    $assigned = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if ((int)$t['locked'] === 1) continue;
        $w = tasks_auto_assign($db, $t, $actorId, 'ξεκλείδωσε μετά το ' . $closedCode);
        if ($w !== null) $assigned[(int)$t['id']] = $w;
    }
    return $assigned;
}

function tasks_err($code, $msg)
{
    return ['ok' => false, 'code' => $code, 'error' => $msg, 'task' => null];
}

function tasks_okres($db, $taskId)
{
    return ['ok' => true, 'code' => 200, 'error' => null, 'task' => tasks_fetch_one($db, $taskId)];
}

// ─────────────────────────────────────────────────────────────────────
// LIST — τρεις προβολές
// ─────────────────────────────────────────────────────────────────────
function tasks_list($db, $session, $perms, $view)
{
    $me      = (int)$session['id'];
    $isAdmin = tasks_is_admin($perms);

    if (!in_array($view, ['mine', 'queue', 'all'], true)) {
        return tasks_err(400, 'άγνωστη προβολή');
    }
    // «Όλες» είναι η ουρά διαχειριστή — δείχνει και τις κυπριακές που
    // κανείς άλλος δεν βλέπει.
    if ($view === 'all' && !$isAdmin) {
        return tasks_err(403, 'η προβολή «Όλες» είναι μόνο για διαχειριστές');
    }

    $where  = [];
    $params = [];

    if ($view === 'mine') {
        // ΔΕΝ φιλτράρεται με δεξιότητες: αν αφαιρεθεί δεξιότητα από
        // κάποιον που ήδη ανέλαβε, η εργασία δεν πρέπει να εξαφανιστεί
        // από την οθόνη του ενώ παραμένει δική του.
        $where[]  = 't.`assigned_to` = ?';
        $params[] = $me;
        $where[]  = "t.`status` IN ('in_progress','paused')";
    } elseif ($view === 'queue') {
        $where[] = 't.`assigned_to` IS NULL';
        $where[] = "t.`status` = 'open'";
        if (!$isAdmin) {
            $where[]  = 'EXISTS (SELECT 1 FROM `4a_user_task_skills` s
                                  WHERE s.`user_id` = ? AND s.`task_code` = t.`task_code`
                                    AND s.`country` IN (c.`country`, \'BOTH\'))';
            $params[] = $me;
        }
    }

    // Φίλτρο χώρας — ισχύει και στις τρεις προβολές.
    $countries = tasks_scope_countries($perms);
    if (is_array($countries)) {
        if (!$countries) return ['ok' => true, 'code' => 200, 'error' => null, 'tasks' => []];
        $where[] = 'c.`country` IN (' . implode(',', array_fill(0, count($countries), '?')) . ')';
        foreach ($countries as $c) $params[] = $c;
    }

    $sql = tasks_base_sql()
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY t.`client_id`, tt.`sort_order`, t.`id`';

    $st = $db->prepare($sql);
    $st->execute($params);
    return ['ok' => true, 'code' => 200, 'error' => null, 'tasks' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

// ─────────────────────────────────────────────────────────────────────
// CLAIM — ανάληψη
// ─────────────────────────────────────────────────────────────────────
function tasks_claim($db, $session, $perms, $taskId)
{
    $me   = (int)$session['id'];
    $task = tasks_fetch_one($db, $taskId);
    if (!$task) return tasks_err(404, 'η εργασία δεν βρέθηκε');

    $countries = tasks_scope_countries($perms);
    if (is_array($countries) && !in_array($task['client_country'], $countries, true)) {
        return tasks_err(403, 'ο πελάτης είναι εκτός του πεδίου σας');
    }
    if ($task['status'] !== 'open')        return tasks_err(409, 'η εργασία δεν είναι ανοιχτή');
    if ($task['assigned_to'] !== null)     return tasks_err(409, 'την ανέλαβε ήδη κάποιος άλλος');

    // Ο server ΞΑΝΑΫΠΟΛΟΓΙΖΕΙ το κλείδωμα. Το κουμπί που λείπει από το UI
    // δεν είναι έλεγχος.
    if ((int)$task['locked'] === 1) {
        $b = $task['blocked_by_label'] !== null ? $task['blocked_by_label'] : $task['depends_on'];
        return tasks_err(409, 'κλειδωμένη — περιμένει: ' . $b);
    }

    if (!tasks_is_admin($perms)
        && !tasks_has_skill($db, $me, $task['task_code'], $task['client_country'])) {
        return tasks_err(403, 'δεν έχετε τη δεξιότητα για αυτή την εργασία');
    }

    // ΤΟ ΚΛΕΙΔΙ ΤΟΥ ΑΓΩΝΑ ΤΑΧΥΤΗΤΑΣ: το `AND assigned_to IS NULL` μέσα στο
    // UPDATE. Δύο ταυτόχρονα claim -> το ένα γράφει, το άλλο κάνει 0 γραμμές.
    // Ένα SELECT πριν το UPDATE θα άφηνε και τα δύο να περάσουν.
    $st = $db->prepare('UPDATE `4a_tasks`
                           SET `assigned_to` = ?, `status` = \'in_progress\'
                         WHERE `id` = ? AND `assigned_to` IS NULL AND `status` = \'open\'');
    $st->execute([$me, (int)$taskId]);
    if ($st->rowCount() === 0) return tasks_err(409, 'την ανέλαβε ήδη κάποιος άλλος');

    tasks_event($db, $taskId, 'assigned', $me, 'ανάληψη από την ουρά');
    return tasks_okres($db, $taskId);
}

// ─────────────────────────────────────────────────────────────────────
// Κοινός έλεγχος για release/done/na: ο ανάδοχος ή administrator
// ─────────────────────────────────────────────────────────────────────
function tasks_guard_owner($db, $session, $perms, $taskId)
{
    $task = tasks_fetch_one($db, $taskId);
    if (!$task) return [null, tasks_err(404, 'η εργασία δεν βρέθηκε')];

    $countries = tasks_scope_countries($perms);
    if (is_array($countries) && !in_array($task['client_country'], $countries, true)) {
        return [null, tasks_err(403, 'ο πελάτης είναι εκτός του πεδίου σας')];
    }
    if (in_array($task['status'], ['done', 'na'], true)) {
        return [null, tasks_err(409, 'η εργασία έχει ήδη κλείσει')];
    }
    if (!tasks_is_admin($perms) && (int)$task['assigned_to'] !== (int)$session['id']) {
        return [null, tasks_err(403, 'η εργασία δεν είναι δική σας')];
    }
    return [$task, null];
}

function tasks_release($db, $session, $perms, $taskId, $note = null)
{
    list($task, $err) = tasks_guard_owner($db, $session, $perms, $taskId);
    if ($err) return $err;
    if ($task['assigned_to'] === null) return tasks_err(409, 'η εργασία είναι ήδη αδιάθετη');

    $me = (int)$session['id'];
    // Ίδια δέσμευση με την απόρριψη: αν την είχε τραβήξει, φαίνεται.
    $afterSteal = tasks_held_by_steal($db, $taskId, $me);

    $db->prepare('UPDATE `4a_tasks` SET `assigned_to` = NULL, `status` = \'open\',
                         `paused_at` = NULL WHERE `id` = ?')
       ->execute([(int)$taskId]);

    $evNote = $note !== null && trim($note) !== '' ? trim($note) : 'επιστροφή στην ουρά';
    $evMeta = null;
    if ($afterSteal) {
        $evNote .= ' — άφησε ' . TASKS_AFTER_STEAL;
        $evMeta  = ['after_steal' => true];
    }
    tasks_event($db, $taskId, 'unassigned', $me, $evNote, $evMeta);
    return tasks_okres($db, $taskId);
}

function tasks_done($db, $session, $perms, $taskId, $note = null)
{
    list($task, $err) = tasks_guard_owner($db, $session, $perms, $taskId);
    if ($err) return $err;

    $db->prepare('UPDATE `4a_tasks` SET `status` = \'done\', `closed_at` = NOW(),
                         `closed_by` = ? WHERE `id` = ?')
       ->execute([(int)$session['id'], (int)$taskId]);
    tasks_event($db, $taskId, 'done', (int)$session['id'],
                $note !== null && trim($note) !== '' ? trim($note) : 'ολοκληρώθηκε');

    // Το ΜΟΝΟ σημείο που μπορεί να ξεκλειδώσει άλλη εργασία. Τρέχει και για
    // εργασία που είχε ανατεθεί με force — η αλυσίδα όντως προχώρησε.
    tasks_unlock_and_assign($db, $task['client_id'], $task['offer_number'],
                            $task['task_code'], (int)$session['id']);
    return tasks_okres($db, $taskId);
}

function tasks_na($db, $session, $perms, $taskId, $reason)
{
    list($task, $err) = tasks_guard_owner($db, $session, $perms, $taskId);
    if ($err) return $err;

    // Ο λόγος είναι ΥΠΟΧΡΕΩΤΙΚΟΣ: το 'na' υπάρχει ώστε να μη διαγράφεται
    // εργασία που αποδείχθηκε άσχετη. Χωρίς λόγο, η εγγραφή δεν εξηγεί
    // τίποτα σε όποιον τη δει σε έξι μήνες.
    $reason = trim((string)($reason === null ? '' : $reason));
    if ($reason === '') return tasks_err(400, 'απαιτείται λόγος');
    if (mb_strlen($reason) > 200) $reason = mb_substr($reason, 0, 200);

    $db->prepare('UPDATE `4a_tasks` SET `status` = \'na\', `closed_at` = NOW(),
                         `closed_by` = ?, `close_reason` = ? WHERE `id` = ?')
       ->execute([(int)$session['id'], $reason, (int)$taskId]);
    tasks_event($db, $taskId, 'na', (int)$session['id'], $reason);

    // Το 'na' ξεκλειδώνει κι αυτό: αν η προηγούμενη δεν εφαρμόζεται, η
    // εξαρτημένη ΠΡΕΠΕΙ να προχωρήσει, αλλιώς η αλυσίδα κολλάει για πάντα.
    tasks_unlock_and_assign($db, $task['client_id'], $task['offer_number'],
                            $task['task_code'], (int)$session['id']);
    return tasks_okres($db, $taskId);
}

// ─────────────────────────────────────────────────────────────────────
// REJECT — ο ΑΝΑΔΟΧΟΣ απορρίπτει με κωδικοποιημένο λόγο
//
// ΔΕΝ κλείνει την εργασία: την αποδεσμεύει και την ξαναμοιράζει σε ΑΛΛΟΝ.
// Ο απορρίπτων γράφεται στο 4a_task_rejections και το UNIQUE(task_id,
// user_id) εγγυάται ότι δεν θα του ξανάρθει ποτέ.
//
// ΧΩΡΙΣ παράκαμψη διαχειριστή: αν απέρριπτε admin «εκ μέρους» κάποιου, θα
// καταγραφόταν ο admin ως απορρίπτων και ο πραγματικός ανάδοχος θα έμενε
// υποψήφιος. Ο admin έχει το assign γι' αυτή τη δουλειά.
// ─────────────────────────────────────────────────────────────────────
function tasks_reject($db, $session, $perms, $taskId, $reasonCode, $note = null)
{
    $me   = (int)$session['id'];
    $task = tasks_fetch_one($db, $taskId);
    if (!$task) return tasks_err(404, 'η εργασία δεν βρέθηκε');

    $countries = tasks_scope_countries($perms);
    if (is_array($countries) && !in_array($task['client_country'], $countries, true)) {
        return tasks_err(403, 'ο πελάτης είναι εκτός του πεδίου σας');
    }
    if (in_array($task['status'], ['done', 'na'], true)) {
        return tasks_err(409, 'η εργασία έχει ήδη κλείσει');
    }
    if ($task['assigned_to'] === null || (int)$task['assigned_to'] !== $me) {
        return tasks_err(403, 'απορρίπτει μόνο ο ανάδοχος');
    }

    $st = $db->prepare('SELECT `code`, `label`, `requires_note`
                          FROM `4a_task_reject_reasons`
                         WHERE `code` = ? AND `active` = 1');
    $st->execute([(string)$reasonCode]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return tasks_err(400, 'άγνωστος ή ανενεργός λόγος απόρριψης');

    // Ο κανόνας «αυτός ο λόγος θέλει εξήγηση» διαβάζεται από τη ΣΤΗΛΗ,
    // όχι από σύγκριση με 'other' — νέος τέτοιος λόγος δεν θέλει deploy.
    $note = trim((string)($note === null ? '' : $note));
    if ((int)$r['requires_note'] === 1 && $note === '') {
        return tasks_err(400, 'ο λόγος «' . $r['label'] . '» απαιτεί σχόλιο');
    }
    if (mb_strlen($note) > 200) $note = mb_substr($note, 0, 200);

    // INSERT IGNORE: διπλή απόρριψη από τον ίδιο δεν είναι σφάλμα, είναι
    // αδύνατη — το UNIQUE την απορρίπτει σιωπηλά.
    $db->prepare('INSERT IGNORE INTO `4a_task_rejections`
                    (`task_id`,`user_id`,`reason_code`,`note`) VALUES (?,?,?,?)')
       ->execute([(int)$taskId, $me, $r['code'], $note !== '' ? $note : null]);

    // ΠΡΙΝ καθαρίσει η ανάθεση: μόλις μπει το rejected, το «πώς την πήρε»
    // δεν αλλάζει, αλλά ο υπολογισμός διαβάζεται πιο εύκολα εδώ.
    $afterSteal = tasks_held_by_steal($db, $taskId, $me);

    $db->prepare('UPDATE `4a_tasks` SET `assigned_to` = NULL, `status` = \'open\',
                         `paused_at` = NULL WHERE `id` = ?')
       ->execute([(int)$taskId]);

    $evNote = $r['label'] . ($note !== '' ? ' — ' . $note : '');
    $evMeta = ['reason_code' => $r['code']];
    if ($afterSteal) {
        $evNote .= ' — απέρριψε ' . TASKS_AFTER_STEAL;
        $evMeta['after_steal'] = true;
    }
    tasks_event($db, $taskId, 'rejected', $me, $evNote, $evMeta);

    // Ξαναμοίρασμα. Το pool διαβάζει ξανά τις απορρίψεις, οπότε ο
    // απορρίπτων έχει ήδη βγει έξω.
    $fresh = tasks_fetch_one($db, $taskId);
    if ($fresh) tasks_auto_assign($db, $fresh, $me, 'μετά από απόρριψη');

    return tasks_okres($db, $taskId);
}

// ─────────────────────────────────────────────────────────────────────
// STEAL — ελεύθερο για όποιον ΕΧΕΙ τη δεξιότητα
//
// Δεν ζητά άδεια από τον προηγούμενο κάτοχο — αυτό αποφασίστηκε. Αλλά
// καταγράφεται πάντα, με from_user/to_user στο meta ώστε το «ποιος
// τράβηξε από ποιον» να διαβάζεται δομημένα.
//
// ΧΩΡΙΣ παράκαμψη διαχειριστή, σκόπιμα: οι admins έχουν μηδέν δεξιότητες
// και δικό τους εργαλείο, το assign. Το steal είναι για συναδέλφους που
// μπορούν όντως να κάνουν τη δουλειά.
// ─────────────────────────────────────────────────────────────────────
function tasks_steal($db, $session, $perms, $taskId)
{
    $me   = (int)$session['id'];
    $task = tasks_fetch_one($db, $taskId);
    if (!$task) return tasks_err(404, 'η εργασία δεν βρέθηκε');

    $countries = tasks_scope_countries($perms);
    if (is_array($countries) && !in_array($task['client_country'], $countries, true)) {
        return tasks_err(403, 'ο πελάτης είναι εκτός του πεδίου σας');
    }
    if (in_array($task['status'], ['done', 'na'], true)) {
        return tasks_err(409, 'η εργασία έχει ήδη κλείσει');
    }
    if ($task['assigned_to'] === null) {
        return tasks_err(409, 'η εργασία είναι αδιάθετη — κάντε ανάληψη');
    }
    if ((int)$task['assigned_to'] === $me) {
        return tasks_err(409, 'η εργασία είναι ήδη δική σας');
    }
    if ((int)$task['locked'] === 1) {
        $b = $task['blocked_by_label'] !== null ? $task['blocked_by_label'] : $task['depends_on'];
        return tasks_err(409, 'κλειδωμένη — περιμένει: ' . $b);
    }
    if (!tasks_has_skill($db, $me, $task['task_code'], $task['client_country'])) {
        return tasks_err(403, 'δεν έχετε τη δεξιότητα για αυτή την εργασία');
    }

    $from     = (int)$task['assigned_to'];
    $fromName = $task['assigned_name'] !== null ? $task['assigned_name'] : ('#' . $from);

    $st = $db->prepare('UPDATE `4a_tasks`
                           SET `assigned_to` = ?, `status` = \'in_progress\', `needs_attention` = 0
                         WHERE `id` = ? AND `assigned_to` = ?');
    $st->execute([$me, (int)$taskId, $from]);
    if ($st->rowCount() === 0) return tasks_err(409, 'άλλαξε ανάδοχος στο μεταξύ');

    tasks_event($db, $taskId, 'stolen', $me, 'ανέλαβε από ' . $fromName,
                ['from_user' => $from, 'to_user' => $me]);
    return tasks_okres($db, $taskId);
}

// ─────────────────────────────────────────────────────────────────────
// Λόγοι, ιστορικό, σήμα
// ─────────────────────────────────────────────────────────────────────

/** Οι ενεργοί λόγοι για έναν τύπο εργασίας (καθολικοί + ειδικοί). */
function tasks_reject_reasons($db, $taskCode = null)
{
    $st = $db->prepare('SELECT `code`, `label`, `requires_note`
                          FROM `4a_task_reject_reasons`
                         WHERE `active` = 1 AND (`task_code` IS NULL OR `task_code` = ?)
                         ORDER BY `sort_order`, `code`');
    $st->execute([$taskCode]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Το ιστορικό μιας εργασίας. Ορατό σε ΟΠΟΙΟΝ βλέπει την εργασία —
 * απόφαση πολιτικής 24/09: το no_knowledge είναι σήμα εκπαίδευσης, όχι
 * κατηγορία· αν κρυφτεί, γίνεται ντροπή.
 *
 * Το LEFT JOIN στους λόγους είναι ΧΩΡΙΣ φίλτρο `active`: το active αφορά
 * το τι μπορείς να ΕΠΙΛΕΞΕΙΣ, ποτέ το τι ΕΓΙΝΕ. Αλλιώς μια απόρριψη με
 * λόγο που απενεργοποιήθηκε αργότερα θα έδειχνε γυμνό κωδικό.
 */
function tasks_history($db, $session, $perms, $taskId)
{
    $task = tasks_fetch_one($db, $taskId);
    if (!$task) return tasks_err(404, 'η εργασία δεν βρέθηκε');

    $countries = tasks_scope_countries($perms);
    if (is_array($countries) && !in_array($task['client_country'], $countries, true)) {
        return tasks_err(403, 'ο πελάτης είναι εκτός του πεδίου σας');
    }

    $st = $db->prepare('SELECT e.`event`, e.`note`, e.`meta`, e.`created_at`,
                               u.`name` AS actor_name,
                               r.`label` AS reason_label, r.`active` AS reason_active
                          FROM `4a_task_events` e
                          LEFT JOIN `4a_users` u ON u.`id` = e.`actor_id`
                          LEFT JOIN `4a_task_reject_reasons` r
                                 ON r.`code` = JSON_UNQUOTE(JSON_EXTRACT(e.`meta`, \'$.reason_code\'))
                         WHERE e.`task_id` = ?
                         ORDER BY e.`id`');
    $st->execute([(int)$taskId]);
    return ['ok' => true, 'code' => 200, 'error' => null,
            'events' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

/**
 * Οι δύο αριθμοί του σήματος. Το `attention` ΔΕΝ φιλτράρεται με
 * δεξιότητες: είναι σήμα ότι κάτι κόλλησε, και ακριβώς οι εργασίες χωρίς
 * δικαιούχο δεν θα φαίνονταν σε κανέναν. Το scope χώρας ισχύει.
 */
function tasks_badge($db, $session, $perms)
{
    $me = (int)$session['id'];
    $countries = tasks_scope_countries($perms);

    $where  = ['t.`needs_attention` = 1', 't.`status` = \'open\''];
    $params = [];
    if (is_array($countries)) {
        if (!$countries) return ['ok' => true, 'code' => 200, 'error' => null,
                                 'attention' => 0, 'mine' => 0];
        $where[] = 'c.`country` IN (' . implode(',', array_fill(0, count($countries), '?')) . ')';
        foreach ($countries as $c) $params[] = $c;
    }
    $st = $db->prepare('SELECT COUNT(*) FROM `4a_tasks` t
                          JOIN `4a_clients` c ON c.`id` = t.`client_id`
                         WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    $attention = (int)$st->fetchColumn();

    $st = $db->prepare('SELECT COUNT(*) FROM `4a_tasks`
                         WHERE `assigned_to` = ? AND `status` IN (\'in_progress\',\'paused\')');
    $st->execute([$me]);

    return ['ok' => true, 'code' => 200, 'error' => null,
            'attention' => $attention, 'mine' => (int)$st->fetchColumn()];
}

// ─────────────────────────────────────────────────────────────────────
// ASSIGN — ΜΟΝΟ administrators, σε ΟΠΟΙΟΝΔΗΠΟΤΕ χρήστη
//
// Απόφαση 23/09: ο διαχειριστής μπορεί να αναθέσει ακόμα και σε χρήστη
// ΧΩΡΙΣ τη δεξιότητα. Είναι ο μόνος τρόπος να ξεκολλήσει η κυπριακή
// στοίβα, αφού κανείς δεν έχει δεξιότητες CY. Η παράκαμψη καταγράφεται
// ρητά στο event, ώστε να μη μοιάζει με κανονική ανάληψη.
// ─────────────────────────────────────────────────────────────────────
function tasks_assign($db, $session, $perms, $taskId, $toUserId, $force = false)
{
    if (!tasks_is_admin($perms)) return tasks_err(403, 'μόνο διαχειριστής μπορεί να αναθέσει');

    $task = tasks_fetch_one($db, $taskId);
    if (!$task) return tasks_err(404, 'η εργασία δεν βρέθηκε');
    if (in_array($task['status'], ['done', 'na'], true)) {
        return tasks_err(409, 'η εργασία έχει ήδη κλείσει');
    }

    // Το κλείδωμα ισχύει ΚΑΙ εδώ. Χωρίς αυτόν τον έλεγχο, μια ανάθεση
    // παρέκαμπτε σιωπηλά την αλυσίδα depends_on: ο ανάδοχος θα μπορούσε να
    // κλείσει εργασία που ακόμα περίμενε άλλη. Η παράκαμψη παραμένει
    // δυνατή, αλλά πρέπει να ζητηθεί ΡΗΤΑ και καταγράφεται.
    $lockedNote = '';
    if ((int)$task['locked'] === 1) {
        $waits = $task['blocked_by_label'] !== null ? $task['blocked_by_label'] : $task['depends_on'];
        if (!$force) {
            return [
                'ok' => false, 'code' => 409, 'task' => null,
                'error'      => 'κλειδωμένη — περιμένει: ' . $waits,
                'locked'     => true,          // ώστε το UI να ξεχωρίσει
                'blocked_by' => $waits,        // το κλείδωμα από άλλο 409
            ];
        }
        $lockedNote = ' — ΠΑΡΑΚΑΜΨΗ ΚΛΕΙΔΩΜΑΤΟΣ, περίμενε: ' . $waits;
    }

    $toUserId = (int)$toUserId;
    $st = $db->prepare('SELECT `name`, `active` FROM `4a_users` WHERE `id` = ?');
    $st->execute([$toUserId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u)                   return tasks_err(404, 'ο χρήστης δεν βρέθηκε');
    if ((int)$u['active'] !== 1) return tasks_err(400, 'ο χρήστης είναι ανενεργός');

    $hasSkill = tasks_has_skill($db, $toUserId, $task['task_code'], $task['client_country']);
    $note = 'ανάθεση από διαχειριστή σε ' . $u['name']
          . ($hasSkill ? '' : ' — ΧΩΡΙΣ καταχωρημένη δεξιότητα, παράκαμψη')
          . $lockedNote;

    $db->prepare('UPDATE `4a_tasks` SET `assigned_to` = ?, `status` = \'in_progress\'
                   WHERE `id` = ?')
       ->execute([$toUserId, (int)$taskId]);
    tasks_event($db, $taskId, 'assigned', (int)$session['id'], $note);
    return tasks_okres($db, $taskId);
}
