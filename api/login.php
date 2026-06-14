<?php
// login.php | v1.2 | 09-05-2026
require_once 'config.php';
require_once 'auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'POST only'], 405);

$b      = body();
$action = $b['action'] ?? 'verify';

// === STEP 1: Αποστολή OTP ===
if ($action === 'send_otp') {
    $code = trim($b['user_code'] ?? '');
    if (!$code) respond(['error' => 'Missing user_code'], 400);

    $stmt = db()->prepare('SELECT * FROM 4a_users WHERE user_code=? AND active=1');
    $stmt->execute([$code]);
    $user = $stmt->fetch();

    if (!$user) respond(['error' => 'User not found'], 404);
    if (!$user['email']) respond(['error' => 'No email'], 400);

    // Δημιουργία 6ψήφιου OTP
    $otp     = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    db()->prepare('UPDATE 4a_users SET otp=?, otp_expires=? WHERE user_code=?')
        ->execute([$otp, $expires, $code]);

    // Αποστολή email
    $from    = 'pricing@4aexpress.com';
    $headers = "From: 4A Express <{$from}>\r\nContent-Type: text/html; charset=UTF-8\r\nMIME-Version: 1.0\r\n";
    $body    = "
    <div style='font-family:Arial,sans-serif;max-width:400px;margin:0 auto;'>
      <div style='background:#cc0000;padding:16px 20px;border-radius:6px 6px 0 0;'>
        <span style='color:white;font-size:18px;font-weight:700;'>4A EXPRESS</span>
      </div>
      <div style='background:white;padding:24px;border:1px solid #ddd;border-top:none;border-radius:0 0 6px 6px;'>
        <h2 style='color:#333;font-size:16px;'>Κωδικός Πρόσβασης OTP</h2>
        <p style='color:#555;'>Χρησιμοποίησε τον παρακάτω κωδικό για να συνδεθείς:</p>
        <div style='background:#f8f8f8;border:2px solid #cc0000;border-radius:8px;padding:20px;text-align:center;margin:16px 0;'>
          <span style='font-size:36px;font-weight:700;color:#cc0000;font-family:monospace;letter-spacing:8px;'>{$otp}</span>
        </div>
        <p style='color:#c62828;font-size:12px;'>⚠️ Ισχύει για 10 λεπτά.</p>
        <p style='color:#aaa;font-size:11px;'>4A Express Worldwide | pricing@4aexpress.com</p>
      </div>
    </div>";

    $ok = mail($user['email'], '4A Express — Κωδικός OTP', $body, $headers);
    respond(['ok' => $ok, 'sent_to' => substr($user['email'], 0, 3) . '***']);
}

// === STEP 2: Επαλήθευση OTP ===
if ($action === 'verify') {
    $code = trim($b['user_code'] ?? '');
    $otp  = trim($b['otp']       ?? '');

    if (!$code || !$otp) respond(['error' => 'Missing fields'], 400);

    $stmt = db()->prepare('SELECT * FROM 4a_users WHERE user_code=? AND otp=? AND active=1');
    $stmt->execute([$code, $otp]);
    $user = $stmt->fetch();

    if (!$user) respond(['error' => 'Invalid OTP'], 401);

    // Έλεγχος λήξης
    if ($user['otp_expires'] && strtotime($user['otp_expires']) < time()) {
        respond(['error' => 'OTP expired'], 401);
    }

    // Ακύρωση OTP μετά χρήση
    db()->prepare('UPDATE 4a_users SET otp=NULL, otp_expires=NULL WHERE user_code=?')
        ->execute([$code]);

    unset($user['pin'], $user['otp'], $user['otp_expires']);
    $token = bin2hex(random_bytes(32));
    $exp   = date('Y-m-d H:i:s', strtotime('+30 days'));
    $role  = $user['role'] ?? 'user';
    db()->prepare('INSERT INTO 4a_sessions (token, user_id, user_role, expires_at) VALUES (?,?,?,?)')
        ->execute([$token, $user['id'], $role, $exp]);
    respond(['ok' => true, 'user' => $user, 'token' => $token]);
}

// === FORGOT PIN (backward compat) ===
if ($action === 'forgot_pin') {
    $code = trim($b['user_code'] ?? '');
    // Redirect σε send_otp
    $b['action'] = 'send_otp';
    $_POST = $b;
    // Re-call
    $stmt = db()->prepare('SELECT * FROM 4a_users WHERE user_code=? AND active=1');
    $stmt->execute([$code]);
    $user = $stmt->fetch();
    if (!$user) respond(['error' => 'User not found'], 404);

    $otp     = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    db()->prepare('UPDATE 4a_users SET otp=?, otp_expires=? WHERE user_code=?')
        ->execute([$otp, $expires, $code]);

    $from    = 'pricing@4aexpress.com';
    $headers = "From: 4A Express <{$from}>\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $body    = "<div style='font-family:Arial;'><h2>OTP: <span style='color:#cc0000;letter-spacing:4px;'>{$otp}</span></h2><p>Ισχύει 10 λεπτά.</p></div>";
    $ok = mail($user['email'], '4A Express — Κωδικός OTP', $body, $headers);
    respond(['ok' => $ok, 'sent_to' => substr($user['email'], 0, 3) . '***']);
}

respond(['error' => 'Unknown action'], 400);
