<?php
// help.php | v1.0 | 23-05-2026
error_reporting(E_ALL); ini_set("log_errors",1); ini_set("error_log","/tmp/shelf_errors.log");
require_once 'config.php';
require_once 'auth.php';

db()->exec("CREATE TABLE IF NOT EXISTS 4a_help (
    id VARCHAR(100) PRIMARY KEY,
    icon VARCHAR(20) DEFAULT '📌',
    title VARCHAR(200) NOT NULL,
    content LONGTEXT,
    sort_order INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method === 'GET')  { require_permission('help', 'view'); }
if ($method === 'POST') { require_permission('help', 'edit'); }

if ($method === 'GET') {
    $rows = db()->query("SELECT * FROM 4a_help ORDER BY sort_order ASC, updated_at DESC")->fetchAll();
    $sections = array_map(function($r) {
        return [
            'id'         => $r['id'],
            'icon'       => $r['icon'],
            'title'      => $r['title'],
            'content'    => $r['content'],
            'updated_at' => $r['updated_at'],
        ];
    }, $rows);
    respond(['sections' => $sections]);
}

if ($method === 'POST') {
    $b = body();
    $action = $b['action'] ?? '';

    if ($action === 'save_section') {
        $stmt = db()->prepare("INSERT INTO 4a_help (id, content) VALUES (?,?)
            ON DUPLICATE KEY UPDATE content=VALUES(content)");
        $stmt->execute([$b['id'], $b['content'] ?? '']);
        respond(['ok' => true]);
    }

    if ($action === 'add_section') {
        $count = db()->query("SELECT COUNT(*) FROM 4a_help")->fetchColumn();
        $stmt = db()->prepare("INSERT INTO 4a_help (id, icon, title, content, sort_order) VALUES (?,?,?,?,?)");
        $stmt->execute([$b['id'], $b['icon'] ?? '📌', $b['title'], '', $count]);
        respond(['ok' => true]);
    }

    if ($action === 'delete_section') {
        $stmt = db()->prepare("DELETE FROM 4a_help WHERE id=?");
        $stmt->execute([$b['id']]);
        respond(['ok' => true]);
    }
}

respond(['error' => 'Bad request'], 400);
