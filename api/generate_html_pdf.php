<?php
require_once "config.php";
if ($_SERVER["REQUEST_METHOD"] !== "POST") respond(["error"=>"POST only"],405);
$b = body(); file_put_contents("/tmp/pdf_input.json", json_encode($b, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
if (empty($b["offer"]) || empty($b["dhl"]) || empty($b["fuel"])) respond(["error"=>"Missing data"],400);
$input = json_encode($b, JSON_UNESCAPED_UNICODE);
$body_script = __DIR__ . "/../docs-src/generate_body.py";
exec("python3 $body_script 2>&1", $body_out, $body_rc);
$body_pdf_path = __DIR__ . "/../docs-src/4A_Body.pdf";
$body_pdf = "";
if ($body_rc === 0 && file_exists($body_pdf_path)) { $body_pdf = base64_encode(file_get_contents($body_pdf_path)); }
$script2 = __DIR__ . "/generate_pdf.py";
$descriptors = [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]];
$process = proc_open("python3 $script2", $descriptors, $pipes);
if (!is_resource($process)) respond(["error"=>"Cannot run Python"],500);
fwrite($pipes[0], $input); fclose($pipes[0]);
$output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
if ($errors && !$output) respond(["error"=>"Python error: ".substr($errors,0,500)],500);
$result = json_decode($output, true);
if (!$result || !isset($result["pdf"])) respond(["error"=>"No PDF","details"=>substr($errors,0,500)],500);
$result["body_pdf"] = $body_pdf;
respond($result);
