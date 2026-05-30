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
        "SELECT user_id, user_role FROM `4a_sessions`
         WHERE token = ? AND expires_at > NOW()"
    );
    $stmt->execute([$m[1]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) respond(['error' => 'Unauthorized'], 401);
    if ($row['user_role'] !== 'administrator') respond(['error' => 'Forbidden'], 403);

    return ['id' => (int)$row['user_id'], 'role' => $row['user_role']];
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
        "SELECT user_id, user_role FROM `4a_sessions`
         WHERE token = ? AND expires_at > NOW()"
    );
    $stmt->execute([$m[1]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) respond(['error' => 'Unauthorized'], 401);

    return ['id' => (int)$row['user_id'], 'role' => $row['user_role']];
}
