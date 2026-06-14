<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

const COD_SERVICES    = ['S1003_GR', 'S1012_GR', 'S1039', 'S1059'];
const COD_RATE        = 0.015;   // 1.5% of COD amount
const COD_MIN_FEE     = 1.30;
const COD_DEFAULT_VAL = 5.00;

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method === 'GET')  { require_permission('pricelist-editor', 'view'); }
if ($method === 'POST') { require_permission('pricelist-editor', 'edit'); }

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'services') {
        echo json_encode([
            'services'      => COD_SERVICES,
            'min_fee'       => COD_MIN_FEE,
            'default_value' => COD_DEFAULT_VAL,
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'calculate') {
        $cod_amount = max(0, (float)($body['cod_amount'] ?? 0));
        $natural    = round($cod_amount * COD_RATE, 2);
        $cod_fee    = max(COD_MIN_FEE, $natural);
        echo json_encode([
            'cod_fee'       => $cod_fee,
            'min_fee'       => COD_MIN_FEE,
            'default_value' => COD_DEFAULT_VAL,
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
