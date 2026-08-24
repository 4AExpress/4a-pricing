<?php
// roles.php | v1.0 | 24-08-2026
// Διαχείριση της ΒΑΣΗΣ των δικαιωμάτων (role_permissions), ανά ρόλο.
// GET  : users/view — ρόλοι, modules, πλήρες grid, πλήθος χρηστών & εξαιρέσεων
// POST : ΜΟΝΟ administrator — ο ρόλος επηρεάζει όλους τους χρήστες του
//
// Καμία δημιουργία/διαγραφή ρόλου από εδώ: τι θα γινόταν με τους χρήστες ενός
// διαγραμμένου ρόλου είναι ερώτημα που δεν λύνεται σε ένα endpoint.
//
// ΠΑΓΙΔΑ COLLATION: roles.name και role_permissions.module_id είναι
// utf8mb4_unicode_ci, ενώ αλλού (π.χ. 4a_users.role) είναι utf8mb4_0900_ai_ci.
// Το shelf.php χωρίς ρητό COLLATE έριχνε ERROR 1267. Εδώ αποφεύγουμε εντελώς τα
// JOIN column-to-column: παίρνουμε πρώτα το role_id με ξεχωριστό query.

require_once 'config.php';
require_once 'auth.php';

const ROLE_ACTIONS = ['view', 'add', 'edit', 'delete', 'export'];

/** role_id από όνομα ρόλου, ή null. Χωρίς JOIN — σύγκριση στήλης με literal. */
function role_id_of(string $name): ?int {
    $st = db()->prepare('SELECT id FROM roles WHERE name = ?');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    return ($id === false) ? null : (int)$id;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// ─────────────────────────────────────────────────────────────────────────
// GET
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    require_permission('users', 'view');

    $roles   = db()->query('SELECT id, name, label FROM roles ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $modules = db()->query('SELECT id, label, icon FROM modules WHERE active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);

    // Πλήρες grid: role_id → module_id → {view,add,...}
    $grid = [];
    foreach (db()->query('SELECT * FROM role_permissions')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $grid[(int)$r['role_id']][$r['module_id']] = [
            'view'   => (int)$r['can_view'],   'add'    => (int)$r['can_add'],
            'edit'   => (int)$r['can_edit'],   'delete' => (int)$r['can_delete'],
            'export' => (int)$r['can_export'],
        ];
    }

    // Πόσοι ενεργοί χρήστες ανά ρόλο, και πόσοι από αυτούς έχουν εξαιρέσεις.
    // Το 4a_users.role συγκρίνεται με literal (όχι στήλη), οπότε καμία παγίδα.
    $counts = [];
    foreach ($roles as $r) {
        $st = db()->prepare('SELECT COUNT(*) FROM 4a_users WHERE active = 1 AND role = ?');
        $st->execute([$r['name']]);
        $users = (int)$st->fetchColumn();

        $withExc = 0;
        try {
            $st = db()->prepare(
                'SELECT COUNT(DISTINCT up.user_id)
                 FROM user_permissions up
                 JOIN 4a_users u ON u.id = up.user_id
                 WHERE u.active = 1 AND u.role = ?'
            );
            $st->execute([$r['name']]);
            $withExc = (int)$st->fetchColumn();
        } catch (Exception $e) { /* χωρίς πίνακα → 0 εξαιρέσεις */ }

        $counts[$r['name']] = ['users' => $users, 'with_exceptions' => $withExc];
    }

    respond([
        'ok'          => true,
        'roles'       => $roles,
        'modules'     => $modules,
        'permissions' => (object)$grid,
        'counts'      => (object)$counts,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────
// POST — ΜΟΝΟ administrator
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    require_admin();

    $b      = body();
    $action = $b['action'] ?? '';
    if ($action !== 'save_role_permissions') {
        respond(['error' => 'Bad request'], 400);
    }

    $role = trim((string)($b['role'] ?? ''));
    $perms = $b['permissions'] ?? null;
    if ($role === '' || !is_array($perms)) {
        respond(['error' => 'Λείπει ο ρόλος ή τα δικαιώματα.'], 400);
    }

    // Ο administrator παρακάμπτει το RBAC στο auth.php — αποθήκευση εδώ θα
    // έφτιαχνε ένα ακόμη checkbox που δεν κάνει τίποτα.
    if ($role === 'administrator') {
        respond(['error' => 'Ο ρόλος Administrator έχει πάντα πλήρη πρόσβαση και δεν αποθηκεύεται.'], 400);
    }

    $role_id = role_id_of($role);
    if ($role_id === null) {
        respond(['error' => 'Άγνωστος ρόλος: ' . $role], 400);
    }

    $whitelist = db()->query('SELECT id FROM modules WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN);

    $ins = db()->prepare(
        'INSERT INTO role_permissions (role_id, module_id, can_view, can_add, can_edit, can_delete, can_export)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_add=VALUES(can_add),
                                 can_edit=VALUES(can_edit), can_delete=VALUES(can_delete),
                                 can_export=VALUES(can_export)'
    );

    $saved = [];
    foreach ($perms as $mid => $p) {
        if (!in_array($mid, $whitelist, true)) {
            respond(['error' => 'Άγνωστο module: ' . $mid], 400);
        }
        if (!is_array($p)) continue;
        $v = [];
        foreach (ROLE_ACTIONS as $a) $v[$a] = !empty($p[$a]) ? 1 : 0;
        $ins->execute([$role_id, $mid, $v['view'], $v['add'], $v['edit'], $v['delete'], $v['export']]);
        $saved[] = $mid;
    }

    respond(['ok' => true, 'role' => $role, 'saved' => $saved]);
}

respond(['error' => 'Bad request'], 400);
