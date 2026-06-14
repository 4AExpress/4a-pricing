<?php
// shelf.php | v1.2 | 22-05-2026
error_reporting(E_ALL); ini_set("log_errors",1); ini_set("error_log","/tmp/shelf_errors.log");
require_once 'config.php';
require_once 'auth.php';
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method === 'GET')  { require_permission('pricelist-editor', 'view'); }
if ($method === 'POST') { require_permission('pricelist-editor', 'edit'); }
if ($method === 'GET') {
    $rows = db()->query('SELECT * FROM 4a_shelf ORDER BY created_at DESC')->fetchAll();
    $shelf = [];
    foreach ($rows as $r) {
        $r['rows'] = array_map(function($row){
         $row['price'] = (float)sprintf('%.2f', (float)($row['price']??0));   
            return $row;
        }, json_decode($r['rows']??'[]',true));
        $shelf[$r['service_id']][] = $r;
    }
    respond((object)$shelf);
}
if ($method === 'POST') {
    $b = body();
    $action = $b['action'] ?? 'save';
    if ($action === 'save') {
        $stmt = db()->prepare('INSERT INTO 4a_shelf
            (id, name, service_id, service_name, markup, global_markup, account, user, office, date, created_at, `rows`)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            name=VALUES(name), markup=VALUES(markup), global_markup=VALUES(global_markup), `rows`=VALUES(`rows`)');
        $stmt->execute([
            $b['id'], $b['name'], $b['service_id'], $b['service_name'] ?? '',
            $b['markup'], $b['global_markup'] ?? $b['markup'],
            $b['account'] ?? '—', $b['user'] ?? '', $b['office'] ?? '',
            $b['date'] ?? '', $b['created_at'] ?? date('Y-m-d H:i:s'),
            json_encode(array_map(function($r){$r['price']=(float)number_format((float)($r['price']??0),2,'.','');return $r;},$b['rows']??[]))
        ]);
        respond(['ok' => true]);
    }
    if ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM 4a_shelf WHERE id=?');
        $stmt->execute([$b['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'sync') {
        $pdo = db();
        $pdo->exec('DELETE FROM 4a_shelf');
        $stmt = $pdo->prepare('INSERT INTO 4a_shelf
            (id, name, service_id, service_name, markup, global_markup, account, user, office, date, created_at, `rows`)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($b['items'] as $item) {
            $stmt->execute([
                $item['id'], $item['name'], $item['service_id'], $item['service_name'] ?? '',
                $item['markup'], $item['global_markup'] ?? $item['markup'],
                $item['account'] ?? '—', $item['user'] ?? '', $item['office'] ?? '',
                $item['date'] ?? '', $item['created_at'] ?? date('Y-m-d H:i:s'),
                json_encode(array_map(function($r){$r['price']=(float)number_format((float)($r['price']??0),2,'.','');return $r;},$item['rows']??[]))
            ]);
        }
        respond(['ok' => true, 'synced' => count($b['items'])]);
    }
}
respond(['error' => 'Bad request'], 400);