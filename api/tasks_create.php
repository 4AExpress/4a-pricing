<?php
// tasks_create.php | v1.0 | 23-09-2026
// Φάση 2 του συστήματος εργασιών — παραγωγή εργασιών όταν ένας πελάτης
// περνά σε status 'accepted'. Υλοποιεί το docs/tasks_phase2_spec.md.
//
// ΔΕΝ κάνει require config.php ή auth.php, και δεν εκτελεί τίποτα κατά το
// include. Είναι καθαρές συναρτήσεις που δέχονται PDO — έτσι δοκιμάζονται
// με stub, χωρίς βάση, και το clients.php (που έχει ήδη φορτώσει τα δύο)
// το περιλαμβάνει με require_once.
//
// ΦΑΣΗ 4: χρειάζεται τις tasks_fetch_one() και tasks_auto_assign() για την
// τυχαία ανάθεση των ριζών. Το tasks_lib.php είναι κι αυτό καθαρές
// συναρτήσεις χωρίς παρενέργειες στο include, οπότε η εξάρτηση δεν σπάει
// τη δοκιμασιμότητα με stub.
require_once __DIR__ . '/tasks_lib.php';

// ΕΓΓΥΗΣΗ: η tasks_create_for_client() ΔΕΝ πετάει ΠΟΤΕ εξαίρεση. Κάθε
// σφάλμα πιάνεται, καταγράφεται στο error_log και επιστρέφεται ως τιμή.
// Η αποθήκευση του πελάτη δεν πρέπει να χαλάει επειδή έσπασε το νέο
// σύστημα. Βλ. και την απόκλιση από το spec, παρακάτω.

// U+2014 σε bytes. Γράφεται έτσι και όχι ως "\u{2014}" ώστε να δουλεύει
// ανεξάρτητα από έκδοση PHP.
if (!defined('TASKS_EM_DASH')) define('TASKS_EM_DASH', "\xE2\x80\x94");

/**
 * Κανονικοποίηση αριθμού προσφοράς πριν μπει στο 4a_tasks.offer_number.
 *
 * ΚΡΙΣΙΜΟ: το offer_number είναι μέρος του UNIQUE(client_id, task_code,
 * offer_number). Αν το '—' και το '' περνούσαν ως διαφορετικές τιμές, ο
 * ίδιος πελάτης θα έπαιρνε δεύτερο σετ εργασιών. Το em-dash δεν είναι
 * θεωρητικό: η ίδια φόρμα το έχει ήδη γράψει σε 26 από 46 πελάτες στη
 * στήλη `account` (έλεγχος παραγωγής 23/09).
 *
 * Η βάση ΔΕΝ μπορεί να το επιβάλει — το DEFAULT '' πιάνει μόνο τη στήλη
 * που λείπει, όχι το '—' που στέλνεται ρητά.
 */
function tasks_normalize_offer_no($raw)
{
    $s = trim((string)($raw === null ? '' : $raw));
    return ($s === TASKS_EM_DASH) ? '' : $s;
}

/**
 * Δημιουργεί τις εργασίες ενός πελάτη που μόλις έγινε 'accepted'.
 *
 * ΔΕΝ πετάει ποτέ. Επιστρέφει:
 *   ['ran' => bool, 'created' => int, 'task_ids' => int[], 'error' => ?string]
 *
 * @param PDO         $db         ήδη συνδεδεμένο
 * @param int|string  $clientId   4a_clients.id
 * @param int|null    $actorId    4a_users.id για το 4a_task_events, NULL = σύστημα
 * @param string|null $oldStatus  η κατάσταση ΠΡΙΝ το UPSERT (NULL = νέος πελάτης)
 * @param string|null $newStatus  η κατάσταση που μόλις γράφτηκε
 */
function tasks_create_for_client($db, $clientId, $actorId, $oldStatus, $newStatus)
{
    $clientId = (int)$clientId;
    $result   = ['ran' => false, 'created' => 0, 'task_ids' => [], 'error' => null];

    // Τίποτα δεν τρέχει αν ο πελάτης δεν είναι accepted.
    if ($newStatus !== 'accepted') return $result;

    $isTransition = ($oldStatus !== 'accepted');

    try {
        $reason = 'αυτόματη δημιουργία από μετάβαση σε accepted';

        if (!$isTransition) {
            // ΔΙΧΤΥ ΑΣΦΑΛΕΙΑΣ (επέκταση του spec).
            // Ο πελάτης ήταν ήδη accepted, άρα κανονικά δεν ξανατρέχουμε.
            // ΕΞΑΙΡΕΣΗ: αν δεν έχει ΚΑΜΙΑ εργασία, η πρώτη προσπάθεια
            // απέτυχε σιωπηλά — και επειδή η μετάβαση δεν ξανασυμβαίνει
            // ποτέ, ο πελάτης θα έμενε για πάντα χωρίς εργασίες. Αυτό
            // είναι το τίμημα του «η αποθήκευση δεν χαλάει ποτέ»: χωρίς
            // δίχτυ, μια αποτυχία θα ήταν μόνιμη και αόρατη.
            //
            // Ο έλεγχος είναι σκόπιμα «καμία εργασία ΚΑΘΟΛΟΥ» και όχι
            // «καμία για αυτόν τον offer_number»: αλλιώς μια διόρθωση
            // τυπογραφικού στον αριθμό προσφοράς θα παρήγαγε σιωπηλά
            // δεύτερο σετ. Η επανάληψη σε ανανέωση χρειάζεται ρητή
            // ενέργεια, όπως λέει το spec.
            $st = $db->prepare('SELECT COUNT(*) FROM `4a_tasks` WHERE `client_id` = ?');
            $st->execute([$clientId]);
            if ((int)$st->fetchColumn() > 0) return $result;
            $reason = 'επανάληψη: ο πελάτης ήταν accepted χωρίς καμία εργασία';
        }

        // Ο πελάτης όπως ΜΟΛΙΣ γράφτηκε. Διαβάζουμε από τη βάση και όχι από
        // το σώμα του αιτήματος: θέλουμε ό,τι πραγματικά αποθηκεύτηκε.
        $st = $db->prepare('SELECT `country`, `offer_number`, `account`, `cod`, `pricelists`
                              FROM `4a_clients` WHERE `id` = ?');
        $st->execute([$clientId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            $result['error'] = 'ο πελάτης ' . $clientId . ' δεν βρέθηκε μετά την αποθήκευση';
            error_log('tasks_create: ' . $result['error']);
            return $result;
        }

        $offerNo = tasks_normalize_offer_no($c['offer_number']);

        // has_cod: 4a_clients.cod (JSON) -> $.cod_enabled
        $cod    = json_decode((string)$c['cod'], true);
        $hasCod = is_array($cod) && !empty($cod['cod_enabled']);

        // has_fuel: 4a_clients.pricelists (JSON array) -> έστω ένα fuel_enabled=1
        // Το fuel είναι ανά ΤΙΜΟΚΑΤΑΛΟΓΟ, αλλά παράγεται ΕΝΑ αρχείο εισαγωγής,
        // άρα ΜΙΑ εργασία cms_fuel ανά πελάτη (απόφαση 23/09). Ποιοι
        // τιμοκατάλογοι αφορά -> στο payload.
        $pls = json_decode((string)$c['pricelists'], true);
        if (!is_array($pls)) $pls = [];
        $fuelSvcs = [];
        foreach ($pls as $pl) {
            if (is_array($pl) && !empty($pl['fuel_enabled'])) {
                $code = trim((string)(isset($pl['service_id']) ? $pl['service_id'] : ''));
                if ($code !== '') $fuelSvcs[] = $code;
            }
        }
        $fuelSvcs = array_values(array_unique($fuelSvcs));
        $hasFuel  = (count($fuelSvcs) > 0);

        // Το depends_on χρειάζεται για να ξεχωρίσουν οι ΡΙΖΕΣ, που είναι οι
        // μόνες που ανατίθενται τη στιγμή της δημιουργίας.
        $types = $db->query('SELECT `code`, `condition_key`, `depends_on` FROM `4a_task_types`
                              WHERE `active` = 1 ORDER BY `sort_order`, `code`')
                    ->fetchAll(PDO::FETCH_ASSOC);

        // account: snapshot τη στιγμή της δημιουργίας, για να μη χρειάζεται
        // join η οθόνη της ουράς. Πηγή αλήθειας παραμένει το 4a_clients.
        $account = trim((string)(isset($c['account']) ? $c['account'] : ''));
        if ($account === TASKS_EM_DASH) $account = '';

        $db->beginTransaction();

        $ins = $db->prepare('INSERT IGNORE INTO `4a_tasks`
            (`client_id`, `task_code`, `offer_number`, `status`, `payload`)
            VALUES (?, ?, ?, ?, ?)');
        $ev  = $db->prepare('INSERT INTO `4a_task_events`
            (`task_id`, `event`, `actor_id`, `note`) VALUES (?, ?, ?, ?)');

        foreach ($types as $t) {
            $cond = isset($t['condition_key']) ? $t['condition_key'] : null;
            if ($cond === 'has_cod'  && !$hasCod)  continue;
            if ($cond === 'has_fuel' && !$hasFuel) continue;

            // payload: ΜΟΝΟ account και, για το cms_fuel, οι υπηρεσίες με
            // επίναυλο. Τίποτα άλλο — κάθε επιπλέον πεδίο θα ήταν δεύτερο
            // αντίγραφο δεδομένων που παλιώνει (απόφαση 23/09).
            $payload = ['account' => $account];
            if ($t['code'] === 'cms_fuel') $payload['services'] = $fuelSvcs;

            $ins->execute([
                $clientId, $t['code'], $offerNo, 'open',
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            ]);

            // rowCount 0 => το UNIQUE το απέρριψε, υπήρχε ήδη. ΔΕΝ είναι
            // σφάλμα, και ΔΕΝ διαβάζουμε lastInsertId() — θα έδινε την
            // προηγούμενη τιμή και θα γράφαμε event σε λάθος εργασία.
            if ($ins->rowCount() === 0) continue;

            $taskId = (int)$db->lastInsertId();
            $result['task_ids'][] = $taskId;
            $ev->execute([$taskId, 'created', $actorId, $reason]);

            // ΦΑΣΗ 4 — οι ΡΙΖΕΣ ανατίθενται τυχαία εδώ.
            // Η αρχή είναι «ανάθεση όταν η εργασία γίνεται ΔΙΑΘΕΣΙΜΗ». Οι
            // εξαρτημένες γίνονται διαθέσιμες στο κλείσιμο της προηγούμενης·
            // οι ρίζες γεννιούνται διαθέσιμες, άρα η στιγμή τους είναι αυτή.
            // Χωρίς αυτό, η μοναδική ρίζα open_code δεν θα ανατίθετο ποτέ και
            // τίποτα δεν θα ξεκινούσε αυτόματα.
            if (!isset($t['depends_on']) || $t['depends_on'] === null) {
                $fresh = tasks_fetch_one($db, $taskId);
                if ($fresh) tasks_auto_assign($db, $fresh, $actorId, 'ρίζα αλυσίδας');
            }
        }

        $db->commit();
        $result['ran']     = true;
        $result['created'] = count($result['task_ids']);
        return $result;

    } catch (Exception $e) {
        return tasks_fail($db, $result, $clientId, $e);
    } catch (Throwable $e) {
        // PHP 7+: πιάνει και Error (π.χ. TypeError), όχι μόνο Exception.
        return tasks_fail($db, $result, $clientId, $e);
    }
}

/**
 * Κοινός χειρισμός αποτυχίας: rollback ΜΟΝΟ της δικής μας συναλλαγής,
 * καταγραφή, και επιστροφή ως τιμή. Ποτέ rethrow.
 */
function tasks_fail($db, $result, $clientId, $e)
{
    try {
        if (method_exists($db, 'inTransaction') && $db->inTransaction()) $db->rollBack();
    } catch (Exception $x) {
        // Αν αποτύχει και το rollback δεν έχουμε τι άλλο να κάνουμε — η
        // αποθήκευση του πελάτη έχει ήδη δεσμευτεί χωριστά και είναι ασφαλής.
    } catch (Throwable $x) {
    }
    $result['error'] = $e->getMessage();
    error_log('tasks_create: client ' . $clientId . ' -> ' . $e->getMessage());
    return $result;
}
