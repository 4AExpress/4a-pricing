<?php
// charge_limits.php | v1.1 | ΟΡΙΑ ΧΡΕΩΣΕΩΝ (4a_charge_limits)
error_reporting(E_ALL); ini_set("log_errors", 1); ini_set("error_log", "/tmp/charge_limits_errors.log");
require_once 'config.php';
require_once 'auth.php';

// χρησιμοποιει την respond() του codebase (CORS+exit). Fallback μονο αν λειπει (π.χ. τοπικο test).
if (!function_exists('respond')) {
    function respond($x, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($x, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
$pdo = db();

/* ---- GET ---- */
if ($method === 'GET') {
    $charge = $_GET['charge'] ?? 'COD';

    // ιστορικο (admin only)
    if (isset($_GET['history'])) {
        require_admin();
        $st = $pdo->prepare("
            SELECT cl.id, cl.scope, cl.param_key, cl.label, cl.value, cl.unit, cl.mode,
                   cl.effective_date, cl.note, cl.created_at,
                   COALESCE(u.name, 'system') AS by_admin
            FROM `4a_charge_limits` cl
            LEFT JOIN `4a_users` u ON u.id = cl.created_by
            WHERE cl.charge_code = :cc
            ORDER BY cl.param_key, cl.effective_date DESC, cl.id DESC
        ");
        $st->execute([':cc' => $charge]);
        respond(['ok' => true, 'charge' => $charge, 'history' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ισχυοντα ορια (snapshot) - καθε logged-in χρηστης
    require_user();
    $service = $_GET['service'] ?? '*';
    $asof    = $_GET['asof']    ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asof)) respond(['error' => 'bad asof date'], 400);

    $st = $pdo->prepare("
        SELECT param_key, value, unit, mode, label, scope, effective_date
        FROM `4a_charge_limits`
        WHERE charge_code = :cc AND active = 1
          AND scope IN (:svc1, '*')
          AND effective_date <= :asof
        ORDER BY param_key, (scope = :svc2) DESC, effective_date DESC, id DESC
    ");
    $st->execute([':cc' => $charge, ':svc1' => $service, ':svc2' => $service, ':asof' => $asof]);

    $limits = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($limits[$r['param_key']])) {
            $limits[$r['param_key']] = [
                'value' => (float)$r['value'], 'unit' => $r['unit'],
                'mode'  => $r['mode'], 'label' => $r['label'],
                'effective_date' => $r['effective_date'],
            ];
        }
    }
    respond(['ok' => true, 'charge' => $charge, 'service' => $service, 'asof' => $asof, 'limits' => $limits]);
}

/* ---- POST (admin only, append-only) ---- */
if ($method === 'POST') {
    $admin = require_admin();
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) respond(['error' => 'bad json'], 400);
    foreach (['charge_code','param_key','label','value','effective_date'] as $k) {
        if (!isset($b[$k]) || $b[$k] === '') respond(['error' => "missing $k"], 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $b['effective_date'])) respond(['error' => 'bad effective_date'], 400);
    if (!is_numeric($b['value'])) respond(['error' => 'value must be numeric'], 400);

    $st = $pdo->prepare("
        INSERT INTO `4a_charge_limits`
            (charge_code, scope, param_key, label, value, unit, mode, effective_date, note, created_by)
        VALUES (:cc, :scope, :pk, :lbl, :val, :unit, :mode, :eff, :note, :by)
        ON DUPLICATE KEY UPDATE
            value=VALUES(value), label=VALUES(label), unit=VALUES(unit),
            mode=VALUES(mode), note=VALUES(note), created_by=VALUES(created_by)
    ");
    $st->execute([
        ':cc'=>$b['charge_code'], ':scope'=>$b['scope'] ?? '*', ':pk'=>$b['param_key'],
        ':lbl'=>$b['label'], ':val'=>$b['value'], ':unit'=>$b['unit'] ?? 'EUR',
        ':mode'=>$b['mode'] ?? 'floor', ':eff'=>$b['effective_date'],
        ':note'=>$b['note'] ?? null, ':by'=>$admin['id'] ?? null,
    ]);
    respond(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'by_id' => $admin['id'] ?? null]);
}

respond(['error' => 'Method not allowed'], 405);
