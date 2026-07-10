<?php
// generate_html_pdf.php | v2.1 | 10-07-2026 | gs merge + dynamic COD doc
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
require_once 'config.php';
$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if (!$auth) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
$body = json_decode(file_get_contents('php://input'), true);
$html = $body['html'] ?? '';
if (!$html) { http_response_code(400); echo json_encode(['error'=>'No HTML']); exit; }
$cod = $body['cod'] ?? null;
$docs_src = '/home/customer/www/4aexpress.com/public_html/docs-src';
$tmp_dir = '/tmp/4a_pdf_' . uniqid();
mkdir($tmp_dir, 0755, true);
$html_file = $tmp_dir . '/offer.html';
$offer_pdf = $tmp_dir . '/offer.pdf';
$final_pdf = $tmp_dir . '/final.pdf';
file_put_contents($html_file, $html);
$out = shell_exec("python3 -c \"import weasyprint; weasyprint.HTML(filename='" . addslashes($html_file) . "').write_pdf('" . addslashes($offer_pdf) . "')\" 2>&1");
if (!file_exists($offer_pdf)) {
    http_response_code(500);
    echo json_encode(['error'=>'WeasyPrint failed','detail'=>$out]);
    cleanup($tmp_dir); exit;
}
$cod_warn = null;
$rows = db()->query("SELECT id, pdf_url FROM 4a_docs WHERE active=1 AND id!='contract' ORDER BY sort_order ASC")->fetchAll();
$doc_files = [];
foreach ($rows as $r) {
    if ($r['id'] === 'cod') {
        if (!$cod) continue;
        $f = cod_pdf($docs_src, $tmp_dir, $cod, $cod_warn);
        if ($f) $doc_files[] = $f;
        continue;
    }
    if (preg_match('/\?f=([^&]+)$/', $r['pdf_url'], $m)) {
        $f = $docs_src . '/' . $m[1];
        if (file_exists($f)) $doc_files[] = $f;
    }
}
$contract = $docs_src . '/4A_Contract.pdf';
if (file_exists($contract)) $doc_files[] = $contract;
$resp = ['ok'=>true];
if ($cod_warn) $resp['cod_warn'] = $cod_warn;
if (empty($doc_files)) {
    $resp['pdf'] = base64_encode(file_get_contents($offer_pdf));
    echo json_encode($resp);
    cleanup($tmp_dir); exit;
}
$all_pdfs = array_merge([$offer_pdf], $doc_files);
$pdf_args = implode(' ', array_map('escapeshellarg', $all_pdfs));
$gs_cmd = "gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile=" . escapeshellarg($final_pdf) . " " . $pdf_args . " 2>&1";
$merge_out = shell_exec($gs_cmd);
if (file_exists($final_pdf)) {
    $resp['pdf'] = base64_encode(file_get_contents($final_pdf));
} else {
    $resp['pdf'] = base64_encode(file_get_contents($offer_pdf));
    $resp['merge_warn'] = trim($merge_out);
}
echo json_encode($resp);
cleanup($tmp_dir);
function cleanup($dir){array_map('unlink',glob($dir.'/*'));rmdir($dir);}
// Το 4A-EXPLAIN-003 έχει τιμές ανά πελάτη, οπότε δεν υπάρχει στατικό PDF στο docs-src.
// Το JSON πάει από stdin ώστε να μη χρειάζεται shell escaping.
function cod_pdf($docs_src, $tmp_dir, $cod, &$warn) {
    $script = $docs_src . '/generate_cod_doc.py';
    if (!file_exists($script)) { $warn = 'generate_cod_doc.py not found'; return null; }
    $desc = [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']];
    $proc = proc_open('python3 ' . escapeshellarg($script), $desc, $pipes, $docs_src);
    if (!is_resource($proc)) { $warn = 'cannot start python3'; return null; }
    fwrite($pipes[0], json_encode($cod, JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);
    $res = json_decode($stdout, true);
    if (!$res || empty($res['pdf'])) {
        $warn = trim(substr($stderr !== '' ? $stderr : 'no pdf in stdout', 0, 300));
        return null;
    }
    $path = $tmp_dir . '/cod.pdf';
    if (file_put_contents($path, base64_decode($res['pdf'])) === false) { $warn = 'cannot write cod.pdf'; return null; }
    return $path;
}
