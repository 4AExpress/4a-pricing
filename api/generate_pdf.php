<?php
// generate_pdf.php | v1.0 | 10-05-2026
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error'=>'POST only'],405);

$b = body(); file_put_contents('/tmp/pdf_input.json', json_encode($b, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
if (empty($b['offer']) || empty($b['dhl']) || empty($b['fuel']))
    respond(['error'=>'Missing data'],400);

$script = __DIR__ . '/generate_pdf.py';
$input  = json_encode($b, JSON_UNESCAPED_UNICODE);

// Εκτέλεση Python script
$descriptors = [
    0 => ['pipe','r'],
    1 => ['pipe','w'],
    2 => ['pipe','w'],
];
$process = proc_open("python3 $script", $descriptors, $pipes);
if (!is_resource($process)) respond(['error'=>'Cannot run Python'],500);

fwrite($pipes[0], $input);
fclose($pipes[0]);

$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);

if ($errors && !$output) {
    respond(['error'=>'Python error: '.substr($errors,0,500)],500);
}

$result = json_decode($output, true);
if (!$result || !isset($result['pdf'])) {
    respond(['error'=>'No PDF generated','details'=>substr($errors,0,500)],500);
}

respond($result);
