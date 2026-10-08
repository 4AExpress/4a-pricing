<?php
// tasks_system.php | v1.0 | 07-10-2026
// Εργασίες ΣΥΣΤΗΜΑΤΟΣ: χωρίς πελάτη, με ταυτότητα subject_key
// (π.χ. fuel:2026-10-12). Πρώτη χρήση: εβδομαδιαίος γενικός επίναυλος.
//
// ΔΕΝ κάνει require config.php ή auth.php, και δεν εκτελεί τίποτα κατά το
// include. Καθαρές συναρτήσεις που δέχονται PDO, όπως το tasks_create.php,
// ώστε να δοκιμάζονται χωρίς πραγματική βάση.
//
// Η ΔΙΠΛΗ ΔΗΜΙΟΥΡΓΙΑ ΤΗ ΣΤΑΜΑΤΑ Η ΒΑΣΗ: UNIQUE(task_code, dedupe_key), με
// dedupe_key = subject_key για εργασίες χωρίς πελάτη. INSERT IGNORE, άρα
// δεύτερη κλήση για την ίδια εβδομάδα δεν κάνει τίποτα.
require_once __DIR__ . '/tasks_lib.php';

/**
 * Δημιουργεί μία εργασία συστήματος.
 *
 * ΔΕΝ πετάει. Επιστρέφει:
 *   ['created' => bool, 'task_id' => ?int, 'error' => ?string]
 *   created=false και error=null σημαίνει ότι υπήρχε ήδη.
 *
 * @param PDO         $db
 * @param string      $code        4a_task_types.code, τύπος με kind = 'system'
 * @param string      $subjectKey  π.χ. fuel:2026-10-12
 * @param array       $payload     στιγμιότυπο, αποθηκεύεται ως JSON
 * @param string      $country     GR | CY | BOTH
 * @param string|null $dueAt       'Y-m-d H:i:s' ή null
 */
function tasks_create_system($db, $code, $subjectKey, $payload, $country = 'BOTH', $dueAt = null)
{
    $out = ['created' => false, 'task_id' => null, 'error' => null];
    $subjectKey = trim((string)$subjectKey);
    if ($subjectKey === '') return array_merge($out, ['error' => 'λείπει το subject_key']);
    if (!in_array($country, ['GR', 'CY', 'BOTH'], true)) {
        return array_merge($out, ['error' => 'άκυρη χώρα: ' . $country]);
    }

    try {
        $st = $db->prepare('SELECT `depends_on`, `kind` FROM `4a_task_types`
                             WHERE `code` = ? AND `active` = 1');
        $st->execute([$code]);
        $type = $st->fetch(PDO::FETCH_ASSOC);
        if (!$type) return array_merge($out, ['error' => 'άγνωστος ή ανενεργός τύπος: ' . $code]);
        if ($type['kind'] !== 'system') {
            return array_merge($out, ['error' => 'ο τύπος ' . $code . ' δεν είναι εργασία συστήματος']);
        }

        $db->beginTransaction();
        $ins = $db->prepare('INSERT IGNORE INTO `4a_tasks`
            (`client_id`, `subject_key`, `country`, `task_code`, `offer_number`, `status`, `due_at`, `payload`)
            VALUES (NULL, ?, ?, ?, \'\', \'open\', ?, ?)');
        $ins->execute([$subjectKey, $country, $code, $dueAt,
                       json_encode($payload, JSON_UNESCAPED_UNICODE)]);

        // rowCount 0 => υπήρχε ήδη. ΔΕΝ διαβάζουμε lastInsertId().
        if ($ins->rowCount() === 0) { $db->commit(); return $out; }

        $taskId = (int)$db->lastInsertId();
        // actor NULL = σύστημα
        tasks_event($db, $taskId, 'created', null, 'αυτόματη δημιουργία εργασίας συστήματος');

        // Ρίζα: ανάθεση τη στιγμή που γεννιέται διαθέσιμη, όπως στις
        // εργασίες πελάτη. Εξαρτημένη: στο κλείσιμο της προηγούμενης.
        if ($type['depends_on'] === null) {
            $fresh = tasks_fetch_one($db, $taskId);
            if ($fresh) tasks_auto_assign($db, $fresh, null, 'ρίζα αλυσίδας');
        }
        $db->commit();
        return ['created' => true, 'task_id' => $taskId, 'error' => null];

    } catch (Throwable $e) {
        try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable $x) {}
        error_log('tasks_create_system: ' . $code . ' ' . $subjectKey . ' -> ' . $e->getMessage());
        return array_merge($out, ['error' => $e->getMessage()]);
    }
}
