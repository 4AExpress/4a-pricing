<?php
// tasks.php | v1.0 | 23-09-2026
// Endpoint της οθόνης εργασιών. Λεπτό routing — η λογική ζει στο
// tasks_lib.php ώστε να δοκιμάζεται χωρίς βάση και χωρίς HTTP.
//
// GET  ?view=mine|queue|all
// POST {action: claim|release|done|na|assign, id, ...}
error_reporting(E_ALL);
ini_set('log_errors', 1);
require_once 'config.php';
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
    $view = isset($_GET['view']) ? $_GET['view'] : 'queue';
    $r = tasks_list($db, $session, $perms, $view);
    if (!$r['ok']) respond(['error' => $r['error']], $r['code']);
    // Οι καταστάσεις ταξιδεύουν μαζί με τη λίστα: ένα round-trip, και η
    // οθόνη δεν χρειάζεται να ξέρει κανένα χρώμα εκ των προτέρων.
    respond([
        'ok'       => true,
        'view'     => $view,
        'tasks'    => $r['tasks'],
        'statuses' => tasks_statuses($db),
        'is_admin' => tasks_is_admin($perms),
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
        case 'assign':
            // force: ΜΟΝΟ αν σταλεί ρητά true. Παρακάμπτει το κλείδωμα
            // depends_on και καταγράφεται στο event.
            $r = tasks_assign($db, $session, $perms, $id,
                              isset($b['to_user_id']) ? $b['to_user_id'] : 0,
                              isset($b['force']) && $b['force'] === true); break;
        default:
            respond(['error' => 'άγνωστη ενέργεια'], 400);
    }

    if (!$r['ok']) {
        // Το κλείδωμα στην ανάθεση φεύγει με σημαία, ώστε η οθόνη να
        // ξεχωρίσει «περιμένει άλλη εργασία» από κάθε άλλο 409 και να
        // προτείνει παράκαμψη.
        $out = ['error' => $r['error']];
        if (!empty($r['locked'])) {
            $out['locked']     = true;
            $out['blocked_by'] = isset($r['blocked_by']) ? $r['blocked_by'] : null;
        }
        respond($out, $r['code']);
    }
    respond(['ok' => true, 'task' => $r['task']]);
}

respond(['error' => 'Method not allowed'], 405);
