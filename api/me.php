<?php
// me.php | v1.0 | 12-06-2026
require_once 'config.php';
require_once 'auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$session = require_user();

$stmt = db()->prepare('SELECT * FROM 4a_users WHERE id=? AND active=1');
$stmt->execute([$session['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) respond(['error' => 'User not found'], 404);

unset($user['pin'], $user['otp'], $user['otp_expires']);
respond(['ok' => true, 'user' => $user]);