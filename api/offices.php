<?php
// offices.php | v1.2 | 06-06-2026
require_once 'config.php';
require_once 'auth.php';

db()->exec("CREATE TABLE IF NOT EXISTS `4a_offices` (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    prefix   VARCHAR(10)  NOT NULL DEFAULT '',
    city     VARCHAR(100) NOT NULL DEFAULT '',
    country  CHAR(2)      NOT NULL DEFAULT 'GR',
    addr     VARCHAR(200) NOT NULL DEFAULT '',
    maps_url TEXT                  DEFAULT NULL,
    tel      VARCHAR(50)  NOT NULL DEFAULT '',
    email    VARCHAR(100) NOT NULL DEFAULT '',
    manager  VARCHAR(100) NOT NULL DEFAULT '',
    company  VARCHAR(200) NOT NULL DEFAULT '',
    vat      VARCHAR(20)  NOT NULL DEFAULT '',
    active   TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method === 'GET')  { require_permission('services', 'view'); }
if ($method === 'POST') { require_permission('services', 'edit'); }

if ($method === 'GET') {
    $offices = db()->query('SELECT * FROM `4a_offices` ORDER BY prefix ASC')->fetchAll();
    respond($offices);
}

if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? 'save';

    if ($action === 'save') {
        $pdo      = db();
        $prefix   = $b['prefix']   ?? '';
        $city     = $b['city']     ?? '';
        $country  = $b['country']  ?? 'GR';
        $tel      = $b['tel']      ?? '';
        $email    = $b['email']    ?? '';
        $manager  = $b['manager']  ?? '';
        $active   = isset($b['active']) ? (int)$b['active'] : 1;
        $addr     = $b['addr']     ?? '';
        $maps_url = $b['maps_url'] ?? null;
        $company  = $b['company']  ?? '';
        $vat      = $b['vat']      ?? '';

        if (!empty($b['id'])) {
            $stmt = $pdo->prepare('UPDATE `4a_offices` SET prefix=?, city=?, country=?, addr=?, maps_url=?, tel=?, email=?, manager=?, company=?, vat=?, active=? WHERE id=?');
            $stmt->execute([$prefix, $city, $country, $addr, $maps_url, $tel, $email, $manager, $company, $vat, $active, $b['id']]);
            respond(['ok' => true, 'id' => (int)$b['id']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO `4a_offices` (prefix, city, country, addr, maps_url, tel, email, manager, company, vat, active) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$prefix, $city, $country, $addr, $maps_url, $tel, $email, $manager, $company, $vat, $active]);
            respond(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        }
    }
}

respond(['error' => 'Bad request'], 400);