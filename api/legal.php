<?php
// legal.php | v1.1 | 19-08-2026
// Νομικά στοιχεία ανά χώρα (4a_legal) — για 4A-FORM-001 & footers
// GET  : legal/view   (v1.0: docs/view)
// POST : legal/edit   (v1.0: require_admin — τώρα μέσω RBAC module 'legal')

error_reporting(E_ALL); ini_set("log_errors",1); ini_set("error_log","/tmp/shelf_errors.log");
require_once 'config.php';
require_once 'auth.php';

// Επιτρεπόμενες χώρες — σκληρά κλειδωμένες. Καμία εισαγωγή/διαγραφή.
const LEGAL_COUNTRIES = ['GR', 'CY'];

// Πεδία που επιτρέπεται να ενημερωθούν από UI
const LEGAL_FIELDS = [
    'legal_name', 'trade_name', 'legal_form', 'tax_id', 'tax_office',
    'company_reg_no', 'regulator_name', 'regulator_number', 'regulator_law',
    'registered_addr', 'email_accounts',
];

// Πεδία χωρίς τα οποία ΔΕΝ παράγεται PDF
const LEGAL_REQUIRED = ['legal_name', 'regulator_number', 'email_accounts'];

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// ─────────────────────────────────────────────────────────────
// GET — επιστροφή και των δύο χωρών
// ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    require_permission('legal', 'view');

    $rows = db()->query(
        "SELECT * FROM `4a_legal` ORDER BY FIELD(country,'GR','CY')"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $missing = [];
        foreach (LEGAL_REQUIRED as $f) {
            if (trim((string)($r[$f] ?? '')) === '') $missing[] = $f;
        }
        $r['missing']  = $missing;   // ποια κρίσιμα λείπουν
        $r['complete'] = empty($missing);
        $out[] = $r;
    }

    respond(['legal' => $out]);
}

// ─────────────────────────────────────────────────────────────
// POST — αποθήκευση (ΜΟΝΟ administrator)
// ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $admin = require_permission('legal', 'edit');

    $b      = body();
    $action = $b['action'] ?? '';

    if ($action !== 'save_legal') {
        respond(['error' => 'Bad request'], 400);
    }

    $rows = $b['rows'] ?? [];
    if (!is_array($rows) || empty($rows)) {
        respond(['error' => 'No rows supplied'], 400);
    }

    // Ποιος αποθήκευσε — για audit
    $who = '';
    try {
        $st = db()->prepare("SELECT code, name FROM 4a_users WHERE id = ?");
        $st->execute([$admin['id']]);
        if ($u = $st->fetch()) {
            $who = trim(($u['code'] ?? '') . ' ' . ($u['name'] ?? ''));
        }
    } catch (Exception $e) {
        $who = 'user#' . $admin['id'];
    }
    if ($who === '') $who = 'user#' . $admin['id'];

    // Δυναμικό UPDATE μόνο στα επιτρεπόμενα πεδία
    $set = [];
    foreach (LEGAL_FIELDS as $f) { $set[] = "`$f` = ?"; }
    $sql = "UPDATE `4a_legal` SET " . implode(', ', $set)
         . ", verified_at = CURDATE(), verified_by = ? WHERE country = ?";
    $stmt = db()->prepare($sql);

    $saved = [];
    foreach ($rows as $row) {
        $c = strtoupper(trim($row['country'] ?? ''));
        if (!in_array($c, LEGAL_COUNTRIES, true)) continue;   // άγνωστη χώρα → αγνοείται

        $params = [];
        foreach (LEGAL_FIELDS as $f) {
            $params[] = trim((string)($row[$f] ?? ''));
        }
        $params[] = $who;
        $params[] = $c;

        $stmt->execute($params);
        $saved[] = $c;
    }

    if (empty($saved)) {
        respond(['error' => 'No valid country rows'], 400);
    }

    respond(['ok' => true, 'saved' => $saved, 'verified_by' => $who]);
}

respond(['error' => 'Bad request'], 400);
