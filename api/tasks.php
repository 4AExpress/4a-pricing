<?php
// tasks.php | v1.3 | 14-10-2026
// v1.3: api_errors.php, κανένα σκέτο 500 (αφορμή: collation 13/10). Ό,τι
//       ξεφεύγει απαντά JSON 500 με μήνυμα και κωδικό, και γράφεται στο
//       δικό μας log με αρχείο:γραμμή. Η άρνηση δικαιώματος μένει 403.
// v1.2: κάρτα βημάτων (Φάση 5 Κ2α): download, upload, admin_override,
//       override_ack. Σε τύπο χωρίς βήματα απαντούν 409 (tasks_steps.php).
// Endpoint της οθόνης εργασιών. Λεπτό routing — η λογική ζει στο
// tasks_lib.php ώστε να δοκιμάζεται χωρίς βάση και χωρίς HTTP.
//
// GET  ?view=mine|queue|all
// POST {action: claim|release|done|na|assign|reject|steal|download|upload|admin_override|override_ack, id, ...}
error_reporting(E_ALL);
ini_set('log_errors', 1);
require_once 'config.php';
require_once 'api_errors.php';
api_errors_install('tasks');
require_once 'auth.php';
require_once 'tasks_lib.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// view = ανάγνωση, edit = κάθε ενέργεια. Ο readonly έχει view χωρίς edit,
// άρα παίρνει 403 σε κάθε POST — από το auth, πριν τρέξει τίποτα δικό μας.
if ($method === 'GET')  { $session = require_permission('tasks', 'view'); }
if ($method === 'POST') { $session = require_permission('tasks', 'edit'); }

$perms = $session['permissions'];
$db    = db();

if ($method === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    // Το σήμα: δύο αριθμοί, φορτώνεται από το tasks-badge.js σε κάθε σελίδα
    // που έχει τον σύνδεσμο. Κρατιέται φτηνό — δύο COUNT σε ευρετήρια.
    if ($action === 'badge') {
        $r = tasks_badge($db, $session, $perms);
        respond(['ok' => true, 'attention' => $r['attention'], 'mine' => $r['mine'],
                 'overrides' => $r['overrides']]);
    }

    if ($action === 'reject_reasons') {
        respond(['ok' => true, 'reasons' => tasks_reject_reasons(
            $db, isset($_GET['task_code']) ? $_GET['task_code'] : null)]);
    }

    // Ιστορικό ΚΑΤ' ΑΠΑΙΤΗΣΗ, ένα αίτημα ανά εργασία όταν ανοίγει το
    // πτυσσόμενο — όχι μαζί με τη λίστα.
    if ($action === 'history') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) respond(['error' => 'λείπει το id της εργασίας'], 400);
        $r = tasks_history($db, $session, $perms, $id);
        if (!$r['ok']) respond(['error' => $r['error']], $r['code']);
        respond(['ok' => true, 'events' => $r['events']]);
    }

    $view = isset($_GET['view']) ? $_GET['view'] : 'queue';
    $r = tasks_list($db, $session, $perms, $view);
    if (!$r['ok']) respond(['error' => $r['error']], $r['code']);
    // Οι καταστάσεις ταξιδεύουν μαζί με τη λίστα: ένα round-trip, και η
    // οθόνη δεν χρειάζεται να ξέρει κανένα χρώμα εκ των προτέρων.
    // Ο υπότιτλος της σελίδας από τη βάση (modules.description). NULL αν
    // λείπει η στήλη ή το κείμενο: η σελίδα τότε δεν δείχνει υπότιτλο.
    $description = null;
    try {
        $d = $db->query("SELECT `description` FROM `modules` WHERE `id` = 'tasks'")->fetchColumn();
        if (is_string($d) && trim($d) !== '') $description = $d;
    } catch (Exception $e) {
        error_log('tasks.php: modules.description: ' . $e->getMessage());
    }
    respond([
        'ok'          => true,
        'view'        => $view,
        'tasks'       => $r['tasks'],
        'statuses'    => tasks_statuses($db),
        'is_admin'    => tasks_is_admin($perms),
        'description' => $description,
    ]);
}

if ($method === 'POST') {
    $b      = body();
    $action = isset($b['action']) ? $b['action'] : '';
    $id     = isset($b['id']) ? (int)$b['id'] : 0;
    if ($id <= 0) respond(['error' => 'λείπει το id της εργασίας'], 400);

    $note = isset($b['note']) ? $b['note'] : null;

    switch ($action) {
        case 'claim':
            $r = tasks_claim($db, $session, $perms, $id); break;
        case 'release':
            $r = tasks_release($db, $session, $perms, $id, $note); break;
        case 'done':
            $r = tasks_done($db, $session, $perms, $id, $note); break;
        case 'na':
            $r = tasks_na($db, $session, $perms, $id,
                          isset($b['close_reason']) ? $b['close_reason'] : null); break;
        case 'reject':
            $r = tasks_reject($db, $session, $perms, $id,
                              isset($b['reason_code']) ? $b['reason_code'] : '',
                              isset($b['note']) ? $b['note'] : null); break;
        case 'steal':
            $r = tasks_steal($db, $session, $perms, $id); break;
        case 'assign':
            // force: ΜΟΝΟ αν σταλεί ρητά true. Παρακάμπτει το κλείδωμα
            // depends_on και καταγράφεται στο event.
            $r = tasks_assign($db, $session, $perms, $id,
                              isset($b['to_user_id']) ? $b['to_user_id'] : 0,
                              isset($b['force']) && $b['force'] === true); break;
        // Κάρτα βημάτων. Σε τύπο ΧΩΡΙΣ γραμμές στον 4a_task_steps: 409.
        case 'download':
            $r = tasks_download($db, $session, $perms, $id); break;
        case 'upload':
            $r = tasks_upload($db, $session, $perms, $id, [
                'total'     => isset($b['total'])     ? $b['total']     : null,
                'merge'     => isset($b['merge'])     ? $b['merge']     : null,
                'updated'   => isset($b['updated'])   ? $b['updated']   : null,
                'exception' => isset($b['exception']) ? $b['exception'] : null,
            ]); break;
        case 'admin_override':
            $r = tasks_admin_override($db, $session, $perms, $id,
                                      isset($b['reason']) ? $b['reason'] : null); break;
        case 'override_ack':
            $r = tasks_override_ack($db, $session, $perms, $id); break;
        default:
            respond(['error' => 'άγνωστη ενέργεια'], 400);
    }

    if (!$r['ok']) {
        // Το κλείδωμα στην ανάθεση φεύγει με σημαία, ώστε η οθόνη να
        // ξεχωρίσει «περιμένει άλλη εργασία» από κάθε άλλο 409 και να
        // προτείνει παράκαμψη.
        $out = ['error' => $r['error']];
        // «ήδη κλειστή» (τύποι system): η οθόνη το δείχνει ως πληροφορία.
        if (!empty($r['already_closed'])) $out['already_closed'] = true;
        if (!empty($r['locked'])) {
            $out['locked']     = true;
            $out['blocked_by'] = isset($r['blocked_by']) ? $r['blocked_by'] : null;
        }
        respond($out, $r['code']);
    }
    // Το done/na προσθέτουν τι ξεκλείδωσε η πράξη. Οι άλλες ενέργειες δεν
    // ξεκλειδώνουν τίποτα, οπότε τα πεδία λείπουν εντελώς — η οθόνη δεν
    // πρέπει να δείχνει «Ξεκλείδωσαν 0» μετά από μια ανάληψη.
    $out = ['ok' => true, 'task' => $r['task']];
    if (isset($r['unlocked'])) {
        $out['unlocked']      = $r['unlocked'];
        $out['unlocked_mine'] = $r['unlocked_mine'];
    }
    // download: ποιο γεγονός γράφτηκε (downloaded = έκλεισε το ①).
    if (isset($r['event'])) $out['event'] = $r['event'];
    respond($out);
}

respond(['error' => 'Method not allowed'], 405);
