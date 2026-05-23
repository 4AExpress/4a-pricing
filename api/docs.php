<?php
// docs.php | v1.0 | 23-05-2026
error_reporting(E_ALL); ini_set("log_errors",1); ini_set("error_log","/tmp/shelf_errors.log");
require_once 'config.php';

// Create tables if needed
db()->exec("CREATE TABLE IF NOT EXISTS 4a_docs (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    icon VARCHAR(20) DEFAULT '📄',
    description TEXT,
    active TINYINT(1) DEFAULT 1,
    locked TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

db()->exec("CREATE TABLE IF NOT EXISTS 4a_settings_text (
    key_name VARCHAR(100) PRIMARY KEY,
    value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $section = $_GET['section'] ?? 'docs';

    if ($section === 'help') {
        $stmt = db()->prepare("SELECT value FROM 4a_settings_text WHERE key_name = 'help_text'");
        $stmt->execute();
        $row = $stmt->fetch();
        respond(['help' => $row ? $row['value'] : '']);
    }

    // Load docs
    $rows = db()->query("SELECT * FROM 4a_docs ORDER BY sort_order ASC")->fetchAll();
    if (empty($rows)) {
        respond(['docs' => []]);
    }
    $docs = array_map(function($r) {
        return [
            'id'     => $r['id'],
            'name'   => $r['name'],
            'icon'   => $r['icon'],
            'desc'   => $r['description'],
            'active' => (bool)$r['active'],
            'locked' => (bool)$r['locked'],
        ];
    }, $rows);
    respond(['docs' => $docs]);
}

if ($method === 'POST') {
    $b = body();
    $action = $b['action'] ?? '';

    if ($action === 'save_docs') {
        $docs = $b['docs'] ?? [];
        $stmt = db()->prepare("INSERT INTO 4a_docs (id, name, icon, description, active, locked, sort_order)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE name=VALUES(name), icon=VALUES(icon), description=VALUES(description),
            active=VALUES(active), locked=VALUES(locked), sort_order=VALUES(sort_order)");
        foreach ($docs as $i => $doc) {
            $stmt->execute([
                $doc['id'],
                $doc['name'],
                $doc['icon'] ?? '📄',
                $doc['desc'] ?? '',
                $doc['active'] ? 1 : 0,
                $doc['locked'] ? 1 : 0,
                $i
            ]);
        }
        respond(['ok' => true]);
    }

    if ($action === 'save_help') {
        $text = $b['help'] ?? '';
        $stmt = db()->prepare("INSERT INTO 4a_settings_text (key_name, value) VALUES ('help_text', ?)
            ON DUPLICATE KEY UPDATE value=VALUES(value)");
        $stmt->execute([$text]);
        respond(['ok' => true]);
    }
}

respond(['error' => 'Bad request'], 400);
