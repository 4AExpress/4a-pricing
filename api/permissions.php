<?php
// permissions.php | v1.1 | 20-08-2026
// GET            → τα effective δικαιώματα του συνδεδεμένου χρήστη
// GET ?role=X    → τα δικαιώματα ΤΟΥ ΡΟΛΟΥ X + η λίστα modules (για το grid
//                  στο users.html, ώστε να μην υπάρχει hardcoded καθρέφτης στο JS)
require_once 'config.php';
require_once 'auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$role = trim($_GET['role'] ?? '');

if ($role !== '') {
    require_permission('users', 'view');

    $modules = [];
    try {
        $modules = db()->query(
            'SELECT id, label, icon FROM modules WHERE active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* metadata — το grid πέφτει πίσω στα κλειδιά των permissions */ }

    // Χωρίς JOIN column-to-column: πρώτα το role_id, μετά τα δικαιώματα.
    // Έτσι αποφεύγεται η ασυμφωνία collation μεταξύ roles.name και 4a_users.role.
    $stmt = db()->prepare('SELECT id FROM roles WHERE name = ?');
    $stmt->execute([$role]);
    $role_id = $stmt->fetchColumn();
    if ($role_id === false) respond(['error' => 'Άγνωστος ρόλος: ' . $role], 404);

    $stmt = db()->prepare(
        'SELECT module_id, can_view, can_add, can_edit, can_delete, can_export
         FROM role_permissions WHERE role_id = ?'
    );
    $stmt->execute([$role_id]);

    $perms = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $perms[$r['module_id']] = [
            'view'   => (int)$r['can_view'],   'add'    => (int)$r['can_add'],
            'edit'   => (int)$r['can_edit'],   'delete' => (int)$r['can_delete'],
            'export' => (int)$r['can_export'],
        ];
    }

    respond(['ok' => true, 'role' => $role, 'modules' => $modules, 'permissions' => (object)$perms]);
}

$session = require_user();
$perms   = get_user_permissions($session['id']);

if (empty($perms)) respond(['error' => 'User not found'], 404);

respond(['ok' => true, 'data' => $perms]);
