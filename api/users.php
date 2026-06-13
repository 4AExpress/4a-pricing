<?php
// users.php | v1.5 | 06-06-2026
require_once 'config.php';
require_once 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method === 'GET')  { require_permission('users', 'view'); }
if ($method === 'POST') { require_permission('users', 'edit'); }

if ($method === 'GET') {
    $rows = db()->query('SELECT id, user_code, name, office, role, email, stations, active_station, default_station, pricelist_scope, can_view, can_add, can_edit, can_delete, can_export, can_all, active, created_at FROM 4a_users WHERE active=1 ORDER BY user_code')->fetchAll();
    respond($rows);
}

if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? 'add';

    function nextUserCode($pdo) {
        $row = $pdo->query("SELECT MAX(CAST(SUBSTRING(user_code,2) AS UNSIGNED)) as mx FROM 4a_users WHERE user_code LIKE 'E%'")->fetch();
        $next = ($row['mx'] ?? 1000) + 1;
        return 'E' . $next;
    }

    if ($action === 'add') {
        $code = !empty($b['user_code']) ? strtoupper(trim($b['user_code'])) : nextUserCode(db());
        $stmt = db()->prepare('INSERT INTO 4a_users (user_code, name, office, role, pin, email, stations, active_station, default_station, can_view, can_add, can_edit, can_delete, can_export, can_all) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $code,
            $b['name'], $b['office'] ?? 'Αθήνα', $b['role'] ?? 'user',
            $b['pin'], $b['email'] ?? '',
            $b['stations'] ?? 'ATH',
            $b['default_station'] ?? 'ATH',
            $b['pricelist_scope'] ?? 'GR',
            $b['can_view'] ?? 1, $b['can_add'] ?? 0, $b['can_edit'] ?? 0,
            $b['can_delete'] ?? 0, $b['can_export'] ?? 0, $b['can_all'] ?? 0
        ]);
        respond(['ok' => true, 'id' => db()->lastInsertId(), 'user_code' => $code]);
    }

    if ($action === 'edit') {
        $sql = 'UPDATE 4a_users SET user_code=?, name=?, office=?, role=?, email=?, stations=?, default_station=?, pricelist_scope=?, can_view=?, can_add=?, can_edit=?, can_delete=?, can_export=?, can_all=?';
        $params = [
            $b['user_code'] ?? '', $b['name'], $b['office'] ?? 'Αθήνα', $b['role'] ?? 'user',
            $b['email'] ?? '', $b['stations'] ?? 'ATH',
            $b['default_station'] ?? 'ATH',
            $b['pricelist_scope'] ?? 'GR',
            $b['can_view'] ?? 1, $b['can_add'] ?? 0, $b['can_edit'] ?? 0,
            $b['can_delete'] ?? 0, $b['can_export'] ?? 0, $b['can_all'] ?? 0
        ];
        if (!empty($b['pin']) && strlen($b['pin']) === 4) {
            $sql .= ', pin=?';
            $params[] = $b['pin'];
        }
        $sql .= ' WHERE id=?';
        $params[] = $b['id'];
        db()->prepare($sql)->execute($params);
        respond(['ok' => true]);
    }

    if ($action === 'delete') {
        db()->prepare('UPDATE 4a_users SET active=0 WHERE id=?')->execute([$b['id']]);
        respond(['ok' => true]);
    }
}

respond(['error' => 'Bad request'], 400);
