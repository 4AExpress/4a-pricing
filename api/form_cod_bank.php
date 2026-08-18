<?php
// form_cod_bank.php | v1.0 | 18-08-2026
// 4A-FORM-001 — έντυπο γνωστοποίησης τραπεζικού λογαριασμού για απόδοση αντικαταβολών.
// POST {client_id, station} →
//   {ok:true, pdf:base64, filename, suggested_email, company, country, ...}
//
// Η χώρα/νομική οντότητα βγαίνει από τον ΣΤΑΘΜΟ ΤΟΥ ΧΡΗΣΤΗ (όχι από τον πελάτη):
// αυτή είναι η οντότητα που εισπράττει και αποδίδει την αντικαταβολή.

require_once 'config.php';
require_once 'auth.php';

// Σταθμός → χώρα (ίδιο whitelist με το generate_cod_bank_form.py)
$STATION_COUNTRY = ['ATH' => 'GR', 'LCA' => 'CY', 'NIC' => 'CY', 'QLI' => 'CY'];
// Σταθμός → prefix γραφείου στον 4a_offices
$STATION_PREFIX  = ['ATH' => '0107', 'NIC' => '0108', 'QLI' => '0109', 'LCA' => '0110'];

$DOCS_SRC = '/home/customer/www/4aexpress.com/public_html/docs-src';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST')    respond(['error' => 'Bad request'], 400);

$session = require_permission('pricelist-clients', 'view');

$b         = body();
$client_id = $b['client_id'] ?? null;
$station   = strtoupper(trim((string)($b['station'] ?? '')));

if (!$client_id) {
    respond(['error' => 'Λείπει το client_id.'], 400);
}
if ($station === '') {
    respond(['error' => 'Δεν έχει επιλεγεί σταθμός. Επιλέξτε σταθμό και ξαναδοκιμάστε.'], 400);
}
if (!array_key_exists($station, $STATION_COUNTRY)) {
    respond(['error' => "Ο σταθμός '$station' δεν εκδίδει έντυπο αντικαταβολών. Επιτρέπονται: ATH, LCA, NIC, QLI."], 400);
}

// Ο χρήστης πρέπει να ανήκει στον σταθμό
$user_stations = $session['permissions']['stations'] ?? [];
if (!in_array($station, $user_stations, true)) {
    respond(['error' => "Δεν έχετε πρόσβαση στον σταθμό $station. Οι σταθμοί σας: "
                        . (implode(', ', $user_stations) ?: '—') . '.'], 403);
}

// ── Πελάτης ───────────────────────────────────────────────────────────────
$stmt = db()->prepare("SELECT id, name, account, afm, email, status, cod FROM `4a_clients` WHERE id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    respond(['error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
}
if (($client['status'] ?? '') !== 'accepted') {
    respond(['error' => 'Το έντυπο εκδίδεται μόνο σε πελάτη με κατάσταση «Αποδεκτός» (τρέχουσα: '
                        . ($client['status'] ?: '—') . ').'], 400);
}

$cod = json_decode((string)($client['cod'] ?? ''), true);
if (!is_array($cod) || empty($cod['cod_enabled'])) {
    respond(['error' => 'Ο πελάτης δεν έχει ενεργή αντικαταβολή (COD). Ενεργοποιήστε την COD στην καρτέλα του πελάτη.'], 400);
}

$account = trim((string)($client['account'] ?? ''));
if ($account === '' || $account === '—' || $account === '-') {
    respond(['error' => 'Ο πελάτης δεν έχει κωδικό πελάτη (account). Καταχωρήστε τον κωδικό στην καρτέλα του πελάτη πριν εκδοθεί το έντυπο.'], 400);
}

// ── Νομικά στοιχεία + γραφείο ─────────────────────────────────────────────
$country = $STATION_COUNTRY[$station];

$stmt = db()->prepare("SELECT * FROM `4a_legal` WHERE country = ?");
$stmt->execute([$country]);
$legal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$legal) {
    respond(['error' => "Δεν βρέθηκαν νομικά στοιχεία για $country. Συμπληρώστε τα στη σελίδα «Νομικά Στοιχεία» (legal.html)."], 400);
}

$office = null;
$stmt = db()->prepare("SELECT city, addr, tel FROM `4a_offices` WHERE prefix = ? LIMIT 1");
$stmt->execute([$STATION_PREFIX[$station]]);
if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $office = ['city' => $row['city'], 'addr' => $row['addr'], 'tel' => $row['tel']];
}

// ── Python (proc_open — το JSON πάει από stdin, χωρίς shell escaping) ─────
$script = $DOCS_SRC . '/generate_cod_bank_form.py';
if (!file_exists($script)) {
    respond(['error' => 'Δεν βρέθηκε το generate_cod_bank_form.py στον server.'], 500);
}

$payload = [
    'account' => $account,
    'company' => $client['name'],
    'vat'     => (string)($client['afm'] ?? ''),
    'station' => $station,
    'date'    => date('d/m/Y'),
    'legal'   => $legal,
    'office'  => $office ?: (object)[],
];

$desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
$proc = proc_open('python3 ' . escapeshellarg($script), $desc, $pipes, $DOCS_SRC);
if (!is_resource($proc)) {
    respond(['error' => 'Δεν ήταν δυνατή η εκκίνηση της python3 στον server.'], 500);
}
fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE));
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
proc_close($proc);

$res = json_decode($stdout, true);
if (is_array($res) && !empty($res['error'])) {
    respond(['error' => $res['error']], 400);   // ελεγχόμενο σφάλμα από το python
}
if (!is_array($res) || empty($res['pdf'])) {
    respond([
        'error'  => 'Η παραγωγή του PDF απέτυχε.',
        'detail' => trim(substr($stderr !== '' ? $stderr : $stdout, 0, 300)),
    ], 500);
}

respond([
    'ok'              => true,
    'pdf'             => $res['pdf'],
    'filename'        => '4A-FORM-001_' . $account . '.pdf',
    'suggested_email' => trim((string)($client['email'] ?? '')),
    'company'         => $client['name'],
    'account'         => $account,
    'country'         => $country,
    'station'         => $station,
    'legal_name'      => $legal['legal_name'],
    'regulator'       => trim(($legal['regulator_name'] ?? '') . ' ' . ($legal['regulator_number'] ?? '')),
    'return_email'    => $legal['email_accounts'],
]);
