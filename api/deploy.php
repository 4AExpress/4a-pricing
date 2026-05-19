<?php
# v2 webhook ready
# webhook v3 test
# webhook auto test
# final webhook test
# webhook final v4
header('Content-Type: application/json');
$secret  = 'WEBHOOK_SECRET';
$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
    exit;
}
$output = [];
$code   = 0;
exec('cd /home/customer/www/4aexpress.com/public_html && git pull origin main 2>&1', $output, $code);
if ($code !== 0) {
    echo json_encode(['ok' => false, 'error' => implode("\n", $output)]);
    exit;
}
echo json_encode(['ok' => true, 'output' => implode("\n", $output)]);
