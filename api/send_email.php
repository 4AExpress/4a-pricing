<?php
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['to']) || empty($input['subject']) || empty($input['pdf_base64'])) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields: to, subject, pdf_base64']);
    exit;
}

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'mail.4aexpress.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'SMTP_USER';
    $mail->Password   = 'SMTP_PASS';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom('SMTP_USER', '4A Express');
    $mail->addAddress($input['to']);

    $mail->Subject = $input['subject'];
    $mail->isHTML(false);
    $mail->Body = $input['body'] ?? '';

    $pdfData = base64_decode($input['pdf_base64']);
    $filename = $input['filename'] ?? 'offer.pdf';
    $mail->addStringAttachment($pdfData, $filename, 'base64', 'application/pdf');

    $mail->send();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $mail->ErrorInfo]);
}
