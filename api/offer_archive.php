<?php
/**
 * 4A Express — api/offer_archive.php
 * ----------------------------------------------------------------------------
 * Αρχειοθέτηση προσφοράς ΠΡΙΝ την αποστολή.
 *
 * ΑΡΧΗ: το PDF που φεύγει στον πελάτη είναι εμπορική δέσμευση (περιέχει
 * σύμβαση + έντυπο αποδοχής). Φυλάσσεται byte-for-byte. Δεν αναπαράγεται
 * ποτέ από ζωντανές ρυθμίσεις.
 *
 * Ροή στο frontend:
 *   1. previewPDF(true)          → Uint8Array
 *   2. POST offer_archive.php    → { offer_id, log_id, offer_ref }
 *   3. POST send_email.php
 *   4. POST offer_archive.php?action=status → sent | failed
 *
 * Actions:
 *   (default)  create  — νέα προσφορά ή αναθεώρηση + αρχειοθέτηση PDF
 *   status            — ενημέρωση αποτελέσματος αποστολής
 *   list              — ιστορικό (φίλτρα: client_id, doc_type, from, to)
 *   file              — λήψη αρχειοθετημένου PDF (μέσω proxy, όχι direct URL)
 * ----------------------------------------------------------------------------
 */

// Η σειρά είναι δεσμευτική: το auth.php καλεί db() σε top-level (DDL του
// 4a_sessions), οπότε το config.php πρέπει να έχει φορτωθεί πρώτο.
// Το require_permission() ορίζεται στο auth.php:134 — η αναφορά ήταν σωστή.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ── Ρυθμίσεις ───────────────────────────────────────────────────────────────
// ΕΚΤΟΣ webroot. Επιβεβαιώθηκε γράψιμο (WRITABLE) στις 06/09/2026.
const ARCHIVE_ROOT = '/home/customer/www/4aexpress.com/offers_archive';
const MAX_PDF_BYTES = 20 * 1024 * 1024;   // 20 MB

$action = $_GET['action'] ?? 'create';

try {
    switch ($action) {
        case 'create': handleCreate(); break;
        case 'status': handleStatus(); break;
        case 'list':   handleList();   break;
        case 'file':   handleFile();   break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Άγνωστη ενέργεια']);
    }
} catch (Throwable $e) {
    error_log('[offer_archive] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Σφάλμα διακομιστή']);
}


// ════════════════════════════════════════════════════════════════════════════
// CREATE — νέα προσφορά / αναθεώρηση + αρχειοθέτηση PDF
// ════════════════════════════════════════════════════════════════════════════
function handleCreate(): void
{
    // ΠΡΟΣΟΧΗ: δικό του δικαίωμα, ΟΧΙ pricelist-clients:view.
    // Το send_email.php σήμερα δέχεται 'view' — όποιος βλέπει πελάτες
    // μπορεί να δεσμεύσει την εταιρεία.
    // Το RBAC γνωρίζει ΜΟΝΟ view/add/edit/delete/export (auth.php:80-86).
    // Δεν υπάρχει action 'send' — θα γύριζε 403 σε όλους, πάντα.
    $actor = require_permission('offers', 'add');

    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    foreach (['client_name', 'pdf_base64', 'snapshot'] as $req) {
        if (empty($in[$req])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Λείπει το πεδίο: $req"]);
            return;
        }
    }

    // ── PDF ─────────────────────────────────────────────────────────────────
    $pdf = base64_decode($in['pdf_base64'], true);
    if ($pdf === false || strlen($pdf) < 100) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Άκυρο PDF']);
        return;
    }
    if (strlen($pdf) > MAX_PDF_BYTES) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'Το PDF είναι πολύ μεγάλο']);
        return;
    }
    if (substr($pdf, 0, 5) !== '%PDF-') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Το αρχείο δεν είναι PDF']);
        return;
    }

    // Το country πάει σε ENUM. Χωρίς έλεγχο, μια άκυρη τιμή (π.χ. '' ή πεζά)
    // σκάει στη MySQL με error 1265 και ο χρήστης βλέπει σκέτο «Σφάλμα
    // διακομιστή». Ίδιος έλεγχος με clients.php:52.
    $country = $in['country'] ?? 'GR';
    if (!in_array($country, ['GR', 'CY', 'EU', 'NONEU'], true)) {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => 'Άκυρη χώρα (country). Επιτρεπτές τιμές: GR, CY, EU, NONEU.',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $pdo = db();
    $actorName = actorName($pdo, (int)$actor['id']);
    $pdo->beginTransaction();

    try {
        // ── Αριθμός προσφοράς ───────────────────────────────────────────────
        $baseId   = isset($in['revision_of']) ? (int)$in['revision_of'] : 0;
        $revision = 0;

        if ($baseId > 0) {
            // Αναθεώρηση υπάρχουσας
            $st = $pdo->prepare(
                'SELECT offer_number, MAX(revision) AS maxrev
                 FROM `4a_offers` WHERE offer_number = (
                     SELECT offer_number FROM `4a_offers` WHERE id = ?
                 ) GROUP BY offer_number'
            );
            $st->execute([$baseId]);
            $prev = $st->fetch(PDO::FETCH_ASSOC);
            if (!$prev) throw new RuntimeException('Δεν βρέθηκε η αρχική προσφορά');

            $offerNumber = $prev['offer_number'];
            $revision    = (int)$prev['maxrev'] + 1;
        } else {
            $offerNumber = nextOfferNumber($pdo);
        }

        $offerRef = $revision > 0 ? "$offerNumber-R$revision" : $offerNumber;

        // ── Εγγραφή προσφοράς ───────────────────────────────────────────────
        $validity = (int)($in['validity_days'] ?? 30);

        $st = $pdo->prepare(
            'INSERT INTO `4a_offers`
             (offer_number, revision, offer_ref, client_id, client_name,
              client_afm, client_email, country, status, snapshot, fuel_pct,
              tariff_version, validity_days, valid_until,
              created_by, created_by_name)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(CURDATE(), INTERVAL ? DAY),?,?)'
        );
        $st->execute([
            $offerNumber,
            $revision,
            $offerRef,
            $in['client_id'] ?? null,
            $in['client_name'],
            $in['client_afm']   ?? null,
            $in['client_email'] ?? null,
            $country,
            'draft',
            json_encode($in['snapshot'], JSON_UNESCAPED_UNICODE),
            $in['fuel_pct']       ?? null,
            $in['tariff_version'] ?? null,
            $validity,
            $validity,
            $actor['id'],
            $actorName,
        ]);
        $offerId = (int)$pdo->lastInsertId();

        // ── Αποθήκευση αρχείου ──────────────────────────────────────────────
        $relDir = date('Y/m');
        $absDir = ARCHIVE_ROOT . '/' . $relDir;
        if (!is_dir($absDir) && !mkdir($absDir, 0750, true) && !is_dir($absDir)) {
            throw new RuntimeException('Αδυναμία δημιουργίας φακέλου αρχείου');
        }

        $safeRef  = preg_replace('/[^A-Za-z0-9\-]/', '_', $offerRef);
        $fileName = $safeRef . '_' . date('Ymd_His') . '.pdf';
        $absPath  = "$absDir/$fileName";
        $relPath  = "$relDir/$fileName";

        if (file_put_contents($absPath, $pdf, LOCK_EX) === false) {
            throw new RuntimeException('Αδυναμία εγγραφής PDF');
        }
        chmod($absPath, 0640);

        $sha = hash('sha256', $pdf);

        // ── Εγγραφή αποστολής (pending — το SMTP δεν έχει τρέξει ακόμη) ──────
        $st = $pdo->prepare(
            "INSERT INTO `4a_outbound_log`
             (direction, doc_type, offer_id, client_id, client_name, reference,
              sent_to, sent_bcc, subject, file_name, file_path, file_sha256,
              file_bytes, status, actor_id, actor_name, ip)
             VALUES ('out','offer',?,?,?,?,?,?,?,?,?,?,?,'pending',?,?,?)"
        );
        $st->execute([
            $offerId,
            $in['client_id'] ?? null,
            $in['client_name'],
            $offerRef,
            $in['to']      ?? null,
            'sales@4aexpress.com',
            $in['subject'] ?? null,
            $in['filename'] ?? $fileName,
            $relPath,
            $sha,
            strlen($pdf),
            $actor['id'],
            $actorName,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        $logId = (int)$pdo->lastInsertId();

        $pdo->commit();

        echo json_encode([
            'ok'        => true,
            'offer_id'  => $offerId,
            'log_id'    => $logId,
            'offer_ref' => $offerRef,
            'sha256'    => $sha,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        $pdo->rollBack();
        if (isset($absPath) && is_file($absPath)) @unlink($absPath);
        throw $e;
    }
}


// ════════════════════════════════════════════════════════════════════════════
// STATUS — αποτέλεσμα αποστολής
// ════════════════════════════════════════════════════════════════════════════
function handleStatus(): void
{
    $actor = require_permission('offers', 'add');   // βλ. handleCreate: όχι 'send'
    $in    = json_decode(file_get_contents('php://input'), true) ?: [];

    $logId  = (int)($in['log_id'] ?? 0);
    $ok     = !empty($in['success']);
    $errMsg = $in['error'] ?? null;

    if ($logId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Λείπει το log_id']);
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "UPDATE `4a_outbound_log`
             SET status = ?, error_message = ?, sent_at = ?
             WHERE id = ? AND status = 'pending'"
        );
        $st->execute([
            $ok ? 'sent' : 'failed',
            $ok ? null : $errMsg,
            $ok ? date('Y-m-d H:i:s') : null,
            $logId,
        ]);

        if ($ok) {
            // Η προσφορά γίνεται 'sent'
            $pdo->prepare(
                "UPDATE `4a_offers` o
                 JOIN `4a_outbound_log` l ON l.offer_id = o.id
                 SET o.status = 'sent', o.sent_at = NOW()
                 WHERE l.id = ? AND o.status = 'draft'"
            )->execute([$logId]);

            // Οι προηγούμενες αναθεωρήσεις γίνονται 'superseded'
            $pdo->prepare(
                "UPDATE `4a_offers` prev
                 JOIN `4a_outbound_log` l ON l.id = ?
                 JOIN `4a_offers` cur ON cur.id = l.offer_id
                 SET prev.status = 'superseded', prev.superseded_by = cur.id
                 WHERE prev.offer_number = cur.offer_number
                   AND prev.revision < cur.revision
                   AND prev.status IN ('draft','sent')"
            )->execute([$logId]);
        }

        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}


// ════════════════════════════════════════════════════════════════════════════
// LIST — ιστορικό
// ════════════════════════════════════════════════════════════════════════════
function handleList(): void
{
    // Ξεχωριστό δικαίωμα ανάγνωσης: το ιστορικό περιέχει τιμολόγηση πελατών.
    $actor = require_permission('offers', 'view');

    $where = ['1=1'];
    $args  = [];

    if (!empty($_GET['client_id'])) { $where[] = 'l.client_id = ?'; $args[] = (int)$_GET['client_id']; }
    if (!empty($_GET['doc_type']))  { $where[] = 'l.doc_type = ?';  $args[] = $_GET['doc_type']; }
    if (!empty($_GET['from']))      { $where[] = 'l.created_at >= ?'; $args[] = $_GET['from'] . ' 00:00:00'; }
    if (!empty($_GET['to']))        { $where[] = 'l.created_at <= ?'; $args[] = $_GET['to']   . ' 23:59:59'; }

    $limit  = min(200, max(1, (int)($_GET['limit']  ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $sql = 'SELECT l.id, l.doc_type, l.direction, l.reference, l.client_id,
                   l.client_name, l.sent_to, l.subject, l.file_name,
                   l.file_bytes, l.file_sha256, l.status, l.error_message,
                   l.actor_name, l.created_at, l.sent_at,
                   o.offer_ref, o.revision, o.status AS offer_status
            FROM `4a_outbound_log` l
            LEFT JOIN `4a_offers` o ON o.id = l.offer_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY l.created_at DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset;

    $st = db()->prepare($sql);
    $st->execute($args);

    echo json_encode([
        'ok'   => true,
        'rows' => $st->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_UNICODE);
}


// ════════════════════════════════════════════════════════════════════════════
// FILE — λήψη αρχειοθετημένου PDF
// ════════════════════════════════════════════════════════════════════════════
function handleFile(): void
{
    $actor = require_permission('offers', 'view');

    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT file_path, file_name FROM `4a_outbound_log` WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || !$row['file_path']) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Δεν βρέθηκε']);
        return;
    }

    // Άμυνα κατά path traversal: το file_path έρχεται από τη ΔΙΚΗ μας βάση,
    // αλλά επαληθεύουμε ούτως ή άλλως ότι μένει μέσα στο ARCHIVE_ROOT.
    $abs  = ARCHIVE_ROOT . '/' . $row['file_path'];
    $real = realpath($abs);
    if ($real === false || strpos($real, realpath(ARCHIVE_ROOT)) !== 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Δεν βρέθηκε']);
        return;
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: inline; filename="' . basename($row['file_name']) . '"');
    readfile($real);
    exit;
}


// ════════════════════════════════════════════════════════════════════════════
// Βοηθητικά
// ════════════════════════════════════════════════════════════════════════════

/**
 * Όνομα χρήστη για το audit trail.
 *
 * Το require_permission() επιστρέφει ['id','role','expires_at','permissions']
 * (auth.php:134-143 → require_user():56) — ΔΕΝ περιέχει 'name'. Το $actor['name']
 * θα ήταν πάντα null, οπότε το διαβάζουμε από το 4a_users.
 */
function actorName(PDO $pdo, int $userId): ?string
{
    $st = $pdo->prepare('SELECT name FROM `4a_users` WHERE id = ?');
    $st->execute([$userId]);
    $n = $st->fetchColumn();
    return $n === false ? null : (string)$n;
}

/**
 * Ατομική παραγωγή αριθμού προσφοράς.
 *
 * Το UPDATE ... LAST_INSERT_ID(expr) είναι το κλασικό MySQL sequence pattern:
 * κλειδώνει τη γραμμή, αυξάνει, και επιστρέφει τη νέα τιμή στο ίδιο
 * connection. Δύο ταυτόχρονοι χρήστες ΔΕΝ μπορούν να πάρουν τον ίδιο αριθμό —
 * σε αντίθεση με τον σημερινό client-side max+1.
 */
function nextOfferNumber(PDO $pdo): string
{
    $year = (int)date('Y');

    // Η γραμμή του έτους μπορεί να μην υπάρχει (πρώτη προσφορά νέου έτους).
    $pdo->prepare(
        'INSERT INTO `4a_offer_seq` (`year`, `last_num`) VALUES (?, 0)
         ON DUPLICATE KEY UPDATE `year` = `year`'
    )->execute([$year]);

    $pdo->prepare(
        'UPDATE `4a_offer_seq`
         SET `last_num` = LAST_INSERT_ID(`last_num` + 1)
         WHERE `year` = ?'
    )->execute([$year]);

    $num = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

    return sprintf('4A-%d-%04d', $year, $num);
}
