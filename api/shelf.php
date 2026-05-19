<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS shelf (
    id         VARCHAR(64)  PRIMARY KEY,
    name       VARCHAR(255) NOT NULL DEFAULT '',
    service_id VARCHAR(64)  NOT NULL DEFAULT '',
    rows       TEXT         DEFAULT NULL,
    markup     FLOAT        NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("ALTER TABLE shelf ADD COLUMN IF NOT EXISTS rows TEXT DEFAULT NULL");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM shelf ORDER BY created_at DESC');
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['rows'] = isset($row['rows']) ? (json_decode($row['rows'], true) ?? []) : [];
        $result[$row['id']] = $row;
    }
    echo json_encode($result);
    exit;
}

if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'save') {
        $id         = $input['id']         ?? null;
        $name       = $input['name']       ?? '';
        $service_id = $input['service_id'] ?? ($input['service'] ?? '');
        $rows       = json_encode($input['rows'] ?? []);
        $markup     = (float)($input['markup'] ?? $input['global_markup'] ?? 0);
        $created_at = $input['created_at'] ?? date('Y-m-d H:i:s');

        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO shelf (id, name, service_id, rows, markup, created_at)
             VALUES (:id, :name, :service_id, :rows, :markup, :created_at)
             ON DUPLICATE KEY UPDATE
                 name       = VALUES(name),
                 service_id = VALUES(service_id),
                 rows       = VALUES(rows),
                 markup     = VALUES(markup)"
        );
        $stmt->execute([
            ':id'         => $id,
            ':name'       => $name,
            ':service_id' => $service_id,
            ':rows'       => $rows,
            ':markup'     => $markup,
            ':created_at' => $created_at,
        ]);

        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'delete') {
        $id = $input['id'] ?? null;
        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            exit;
        }
        $stmt = $pdo->prepare('DELETE FROM shelf WHERE id = :id');
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
