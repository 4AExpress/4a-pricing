<?php
// shipments/waybill.php | v1.1 | 30-05-2026
// GET /api/shipments/{id}/waybill          — JSON waybill data with COD section
// GET /api/shipments/{id}/waybill?format=pdf — same data as PDF
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['error' => 'Method not allowed'], 405);
}

$actor       = require_user();
$shipment_id = (int)($_GET['id'] ?? 0);
if (!$shipment_id) respond(['error' => 'Μη έγκυρο ID αποστολής'], 400);

$stmt = db()->prepare(
    "SELECT id, cod_enabled, cod_amount, cod_fee, cod_declared_val,
            cod_override, cod_confirmed_by, cod_confirmed_at, updated_at
     FROM `4a_shipments` WHERE id = ?"
);
$stmt->execute([$shipment_id]);
$shipment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$shipment) respond(['error' => 'Η αποστολή δεν βρέθηκε'], 404);

$cod_enabled = (bool)$shipment['cod_enabled'];

$cod_section = [
    'cod_flag'   => $cod_enabled,
    'cod_amount' => $cod_enabled ? (float)$shipment['cod_amount'] : null,
    'cod_fee'    => $cod_enabled ? (float)$shipment['cod_fee']    : null,
];

if (($_GET['format'] ?? 'json') === 'pdf') {
    $input = json_encode([
        'shipment_id'     => $shipment_id,
        'cod_enabled'     => $cod_enabled,
        'cod_amount'      => $cod_section['cod_amount'],
        'cod_fee'         => $cod_section['cod_fee'],
        'cod_declared_val'=> $cod_enabled ? (float)$shipment['cod_declared_val'] : null,
        'cod_override'    => (bool)$shipment['cod_override'],
        'cod_confirmed_at'=> $shipment['cod_confirmed_at'],
    ], JSON_UNESCAPED_UNICODE);

    $script      = __DIR__ . '/waybill_pdf.py';
    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $process     = proc_open("python3 $script", $descriptors, $pipes);
    if (!is_resource($process)) respond(['error' => 'Cannot run PDF generator'], 500);

    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if ($errors && !$output) {
        respond(['error' => 'PDF error: ' . substr($errors, 0, 500)], 500);
    }
    $result = json_decode($output, true);
    if (!$result || !isset($result['pdf'])) {
        respond(['error' => 'No PDF generated', 'details' => substr($errors, 0, 500)], 500);
    }
    respond($result);
}

respond(array_merge(
    ['id' => (int)$shipment['id']],
    $cod_section,
    ['updated_at' => $shipment['updated_at']]
));
