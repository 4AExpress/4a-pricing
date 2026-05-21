<?php
// offices.php | v1.0 | 21-05-2026
require_once 'config.php';

db()->exec("CREATE TABLE IF NOT EXISTS `4a_offices` (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    prefix  VARCHAR(10)  NOT NULL DEFAULT '',
    city    VARCHAR(100) NOT NULL DEFAULT '',
    tel     VARCHAR(50)  NOT NULL DEFAULT '',
    email   VARCHAR(100) NOT NULL DEFAULT '',
    manager VARCHAR(100) NOT NULL DEFAULT '',
    active  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $offices = db()->query('SELECT * FROM `4a_offices` ORDER BY prefix ASC')->fetchAll();
    respond($offices);
}

if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? 'save';

    if ($action === 'save') {
        $pdo     = db();
        $prefix  = $b['prefix']  ?? '';
        $city    = $b['city']    ?? '';
        $tel     = $b['tel']     ?? '';
        $email   = $b['email']   ?? '';
        $manager = $b['manager'] ?? '';
        $active  = isset($b['active']) ? (int)$b['active'] : 1;

        if (!empty($b['id'])) {
            $stmt = $pdo->prepare('UPDATE `4a_offices` SET prefix=?, city=?, tel=?, email=?, manager=?, active=? WHERE id=?');
            $stmt->execute([$prefix, $city, $tel, $email, $manager, $active, $b['id']]);
            respond(['ok' => true, 'id' => (int)$b['id']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO `4a_offices` (prefix, city, tel, email, manager, active) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$prefix, $city, $tel, $email, $manager, $active]);
            respond(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        }
    }
}

respond(['error' => 'Bad request'], 400);
