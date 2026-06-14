<?php
// permissions.php | v1.0 | 13-06-2026
require_once 'config.php';
require_once 'auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$session = require_user();
$perms   = get_user_permissions($session['id']);

if (empty($perms)) respond(['error' => 'User not found'], 404);

respond(['ok' => true, 'data' => $perms]);