<?php
// clients_flags.php | v1.0 | 25-09-2026
// Φρουρός και αρχείο της σημαίας `4a_clients.is_demo`.
// Υλοποιεί το docs/tasks_demo_mode_spec.md §5β.
//
// ΔΕΝ κάνει require config.php ή auth.php και δεν εκτελεί τίποτα κατά το
// include — καθαρές συναρτήσεις που δέχονται PDO. Ίδιο μοτίβο με τα
// tasks_create.php / tasks_lib.php, ώστε ο φρουρός να δοκιμάζεται με
// stub χωρίς βάση και χωρίς HTTP.

/**
 * Ποια τιμή θα γραφτεί στο `is_demo`, και επιτρέπεται καν;
 *
 * ΚΑΝΟΝΑΣ: τη σημαία την αλλάζει ΜΟΝΟ administrator.
 *
 *   · διαχειριστής                      -> γράφεται ό,τι έστειλε
 *   · μη-διαχειριστής, ίδια τιμή ή τίποτα -> η αποθήκευση προχωρά,
 *                                            η τιμή μένει ως έχει
 *   · μη-διαχειριστής, ΔΙΑΦΟΡΕΤΙΚΗ τιμή  -> ok=false, 403
 *
 * Η τρίτη περίπτωση είναι σκόπιμα σφάλμα και ΟΧΙ σιωπηλή αγνόηση. Μια
 * σιωπηλή αγνόηση θα έλεγε στον χρήστη ότι αποθήκευσε κάτι που δεν
 * αποθηκεύτηκε — και το πρόβλημα που λύνει αυτό το αρχείο είναι ακριβώς
 * οι σιωπηλές αλλαγές σημαίας.
 *
 * @param array      $perms     από get_user_permissions()
 * @param int|null   $oldValue  η αποθηκευμένη τιμή, ή null για νέο πελάτη
 * @param mixed      $submitted ό,τι ήρθε στο σώμα, ή null αν δεν ήρθε
 */
function clients_demo_guard($perms, $oldValue, $submitted)
{
    $old = $oldValue === null ? 0 : (int)$oldValue;

    // Δεν στάλθηκε τίποτα: κρατάμε ό,τι υπάρχει. Ένας πελάτης δεν
    // ξε-σημαδεύεται επειδή μια παλιά φόρμα δεν ξέρει το πεδίο.
    if ($submitted === null) {
        return ['ok' => true, 'value' => $old, 'changed' => false, 'error' => null];
    }

    $new = (int)((bool)$submitted);   // '1', 1, true, 'true' -> 1

    $isAdmin = isset($perms['role']) && $perms['role'] === 'administrator';
    if ($isAdmin) {
        return ['ok' => true, 'value' => $new, 'changed' => ($new !== $old), 'error' => null];
    }

    if ($new === $old) {
        return ['ok' => true, 'value' => $old, 'changed' => false, 'error' => null];
    }

    return ['ok' => false, 'value' => $old, 'changed' => false,
            'error' => 'η σημαία «πελάτης επίδειξης» αλλάζει μόνο από διαχειριστή'];
}

/**
 * Καταγραφή αλλαγής σημαίας. Καλείται ΜΟΝΟ όταν changed === true.
 *
 * ΔΕΝ πετάει ποτέ: ίδια αρχή με τη Φάση 2 — το νέο σύστημα δεν χαλάει
 * ποτέ το παλιό. Αν το αρχείο αποτύχει, η αποθήκευση του πελάτη έχει
 * ήδη πετύχει και δεν την ακυρώνουμε αναδρομικά· το σφάλμα πάει στο
 * error_log για να βρεθεί αργότερα.
 */
function clients_log_flag($db, $clientId, $flag, $oldValue, $newValue, $actorId)
{
    try {
        $st = $db->prepare('INSERT INTO `4a_client_flag_log`
                              (`client_id`,`flag`,`old_value`,`new_value`,`changed_by`)
                            VALUES (?,?,?,?,?)');
        $st->execute([(int)$clientId, (string)$flag,
                      $oldValue === null ? null : (int)$oldValue,
                      (int)$newValue,
                      $actorId === null ? null : (int)$actorId]);
        return true;
    } catch (Exception $e) {
        error_log('clients_log_flag: ' . $e->getMessage());
        return false;
    }
}
