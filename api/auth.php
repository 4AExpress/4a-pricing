<?php
// auth.php | v1.1 | 28-05-2026
// Provides require_admin() and require_user() — call after require_once 'config.php'

db()->exec("CREATE TABLE IF NOT EXISTS `4a_sessions` (
    `token`      VARCHAR(64)  NOT NULL PRIMARY KEY,
    `user_id`    INT          NOT NULL,
    `user_role`  VARCHAR(32)  NOT NULL DEFAULT 'user',
    `expires_at` DATETIME     NOT NULL,
    `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/**
 * Validates the Bearer token and returns ['id'=>int, 'role'=>string].
 * Responds with 401/403 and exits on failure.
 * Only passes when role === 'administrator'.
 */
function require_admin(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        respond(['error' => 'Unauthorized'], 401);
    }

    $stmt = db()->prepare(
        "SELECT user_id, user_role, expires_at FROM `4a_sessions`
         WHERE token = ? AND expires_at > NOW()"
    );
    $stmt->execute([$m[1]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) respond(['error' => 'Unauthorized'], 401);
    if ($row['user_role'] !== 'administrator') respond(['error' => 'Forbidden'], 403);

    return ['id' => (int)$row['user_id'], 'role' => $row['user_role'], 'expires_at' => $row['expires_at'] ?? null];
}

/**
 * Validates the Bearer token and returns ['id'=>int, 'role'=>string].
 * Responds with 401 and exits on failure. Accepts any role.
 */
function require_user(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        respond(['error' => 'Unauthorized'], 401);
    }

    $stmt = db()->prepare(
        "SELECT user_id, user_role, expires_at FROM `4a_sessions`
         WHERE token = ? AND expires_at > NOW()"
    );
    $stmt->execute([$m[1]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) respond(['error' => 'Unauthorized'], 401);

    return ['id' => (int)$row['user_id'], 'role' => $row['user_role'], 'expires_at' => $row['expires_at'] ?? null];
}
/**
 * Returns all permissions for a given user_id.
 * ['role'=>string, 'stations'=>array, 'pricelist_scope'=>string, 'permissions'=>array]
 */
function get_user_permissions(int $user_id): array {
    $stmt = db()->prepare(
        "SELECT u.role, u.stations, u.pricelist_scope,
                rp.module_id,
                rp.can_view, rp.can_add, rp.can_edit, rp.can_delete, rp.can_export
         FROM 4a_users u
         LEFT JOIN roles r ON r.name COLLATE utf8mb4_0900_ai_ci = u.role
         LEFT JOIN role_permissions rp ON rp.role_id = r.id
         WHERE u.id = ? AND u.active = 1"
    );
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return [];
    $first = $rows[0];
    $stations = array_map('trim', explode(',', $first['stations'] ?? 'ATH'));
    $permissions = [];
    foreach ($rows as $row) {
        if (!$row['module_id']) continue;
        $permissions[$row['module_id']] = [
            'view'   => (int)$row['can_view'],
            'add'    => (int)$row['can_add'],
            'edit'   => (int)$row['can_edit'],
            'delete' => (int)$row['can_delete'],
            'export' => (int)$row['can_export'],
        ];
    }
    // ── Overrides ───────────────────────────────────────────────────────────
    // Ο ρόλος δίνει τη ΒΑΣΗ. Οι administrators έχουν πάντα πλήρη πρόσβαση σε
    // όλα τα modules — αγνοούν το user_permissions, ώστε να μην μπορεί κανείς
    // (ούτε ο ίδιος) να κλειδωθεί έξω. Για κάθε άλλο ρόλο, το user_permissions
    // ΑΝΤΙΚΑΘΙΣΤΑ ολόκληρο το module όπου υπάρχει εγγραφή· όπου δεν υπάρχει,
    // ισχύει αυτούσιος ο ρόλος.
    // Τα try/catch είναι σκόπιμα: αν λείπει ο πίνακας, πέφτουμε πίσω στον
    // ρόλο αντί να ρίξουμε 500 σε κάθε endpoint της πλατφόρμας.
    if ($first['role'] === 'administrator') {
        $full = ['view' => 1, 'add' => 1, 'edit' => 1, 'delete' => 1, 'export' => 1];
        foreach ($permissions as $m => $_) $permissions[$m] = $full;
        try {
            $mods = db()->query("SELECT id FROM modules WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($mods as $m) $permissions[$m] = $full;
        } catch (Exception $e) { /* metadata table — ο ρόλος αρκεί */ }
    } else {
        try {
            $ups = db()->prepare(
                "SELECT module_id, can_view, can_add, can_edit, can_delete, can_export
                 FROM user_permissions WHERE user_id = ?"
            );
            $ups->execute([$user_id]);
            foreach ($ups->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $permissions[$row['module_id']] = [
                    'view'   => (int)$row['can_view'],
                    'add'    => (int)$row['can_add'],
                    'edit'   => (int)$row['can_edit'],
                    'delete' => (int)$row['can_delete'],
                    'export' => (int)$row['can_export'],
                ];
            }
        } catch (Exception $e) { /* χωρίς πίνακα → ισχύει ο ρόλος, ποτέ lockout */ }
    }

    return [
        'role'            => $first['role'],
        'stations'        => $stations,
        'pricelist_scope' => $first['pricelist_scope'] ?? 'GR',
        'permissions'     => $permissions,
    ];
}

/**
 * Validates Bearer token AND checks module permission.
 * Responds with 401/403 and exits on failure.
 */
function require_permission(string $module, string $action = 'view'): array {
    $session = require_user();
    $perms   = get_user_permissions($session['id']);
    if (empty($perms)) respond(['error' => 'Forbidden'], 403);
    $mod = $perms['permissions'][$module] ?? null;
    if (!$mod || !($mod[$action] ?? 0)) {
        respond(['error' => 'Forbidden'], 403);
    }
    return array_merge($session, ['permissions' => $perms]);
}