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

function tasks_event($db, $taskId, $event, $actorId, $note)
{
    $db->prepare('INSERT INTO `4a_task_events` (`task_id`,`event`,`actor_id`,`note`)
                  VALUES (?,?,?,?)')
       ->execute([(int)$taskId, $event, $actorId === null ? null : (int)$actorId, $note]);
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

    $db->prepare('UPDATE `4a_tasks` SET `assigned_to` = NULL, `status` = \'open\',
                         `paused_at` = NULL WHERE `id` = ?')
       ->execute([(int)$taskId]);
    tasks_event($db, $taskId, 'unassigned', (int)$session['id'],
                $note !== null && trim($note) !== '' ? trim($note) : 'επιστροφή στην ουρά');
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
    return tasks_okres($db, $taskId);
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
