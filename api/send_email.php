<?php
require_once __DIR__ . '/config.php';
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';

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

$hasAttachments = !empty($input['attachments']) && is_array($input['attachments']);
$hasLegacyPdf = !empty($input['pdf_base64']);

if (empty($input['to']) || empty($input['subject']) || (!$hasAttachments && !$hasLegacyPdf)) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields: to, subject, attachments|pdf_base64']);
    exit;
}

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom('sales@4aexpress.com', '4A Express');
    $mail->addAddress($input['to']);

    $mail->Subject = $input['subject'];
    $mail->isHTML(false);
    $mail->Body = $input['body'] ?? '';

    if ($hasAttachments) {
        foreach ($input['attachments'] as $att) {
            if (empty($att['content_base64']) || empty($att['filename'])) continue;
            $data = base64_decode($att['content_base64']);
            $mime = $att['mime'] ?? 'application/octet-stream';
            $mail->addStringAttachment($data, $att['filename'], 'base64', $mime);
        }
    } else {
        // Legacy single PDF attachment
        $pdfData = base64_decode($input['pdf_base64']);
        $filename = $input['filename'] ?? 'offer.pdf';
        $mail->addStringAttachment($pdfData, $filename, 'base64', 'application/pdf');
    }

    $mail->send();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $mail->ErrorInfo]);
}
