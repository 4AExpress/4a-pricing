<?php
// shelf.php | v1.3 | 21-08-2026 — φίλτρο ανά pricelist_scope
// Πηγή αλήθειας για τη χώρα κάθε τιμοκαταλόγου είναι το 4a_services.country,
// ΠΟΤΕ το suffix του κωδικού (τα S1050/S1051 είναι CY χωρίς _CY) και ποτέ το
// 4a_shelf.office (είναι «Αθήνα» και στις 56 εγγραφές).
// ΠΡΟΣΟΧΗ: 4a_services.code = utf8mb4_unicode_ci ενώ 4a_shelf.service_id =
// utf8mb4_0900_ai_ci — χωρίς ρητό COLLATE το JOIN ρίχνει «Illegal mix of
// collations» (ERROR 1267), δεν επιστρέφει απλώς λάθος αποτέλεσμα.
error_reporting(E_ALL); ini_set("log_errors",1); ini_set("error_log","/tmp/shelf_errors.log");
require_once 'config.php';
require_once 'auth.php';
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
$session = null;
if ($method === 'GET')  { $session = require_permission('pricelist-editor', 'view'); }
if ($method === 'POST') { $session = require_permission('pricelist-editor', 'edit'); }

$scope    = $session['permissions']['pricelist_scope'] ?? 'GR';
$role     = $session['permissions']['role'] ?? '';
$sees_all = ($role === 'administrator' || $scope === 'BOTH');

/** Η χώρα ενός service από τη βάση, ή null αν δεν υπάρχει. */
function service_country(string $code): ?string {
    $st = db()->prepare('SELECT country FROM `4a_services` WHERE `code` = ?');
    $st->execute([$code]);
    $c = $st->fetchColumn();
    return ($c === false) ? null : $c;
}

if ($method === 'GET') {
    // NONE: ρητά κανένας τιμοκατάλογος — να μη στηριζόμαστε σε WHERE που
    // «τυχαίνει» να μη βρίσκει τίποτα.
    if (!$sees_all && $scope === 'NONE') respond((object)[]);

    if ($sees_all) {
        $rows = db()->query('SELECT * FROM 4a_shelf ORDER BY created_at DESC')->fetchAll();
    } else {
        $stmt = db()->prepare(
            'SELECT sh.* FROM 4a_shelf sh
             JOIN `4a_services` sv ON sv.code = sh.service_id COLLATE utf8mb4_unicode_ci
             WHERE sv.country = ?
             ORDER BY sh.created_at DESC'
        );
        $stmt->execute([$scope]);
        $rows = $stmt->fetchAll();
    }
    $shelf = [];
    foreach ($rows as $r) {
        $r['rows'] = array_map(function($row){
         $row['price'] = (float)sprintf('%.2f', (float)($row['price']??0));   
            return $row;
        }, json_decode($r['rows']??'[]',true));
        $shelf[$r['service_id']][] = $r;
    }
    respond((object)$shelf);
}
if ($method === 'POST') {
    $b = body();
    $action = $b['action'] ?? 'save';
    if ($action === 'save') {
        // Δεν αποθηκεύεται τιμοκατάλογος για service εκτός του scope του χρήστη.
        if (!$sees_all) {
            $svc_code = (string)($b['service_id'] ?? '');
            $svc_ctry = service_country($svc_code);
            if ($scope === 'NONE' || $svc_ctry === null || $svc_ctry !== $scope) {
                respond(['error' => 'Δεν έχετε πρόσβαση στην υπηρεσία ' . $svc_code
                                    . '. Οι τιμοκατάλογοί σας: ' . $scope . '.'], 403);
            }
        }
        $stmt = db()->prepare('INSERT INTO 4a_shelf
            (id, name, service_id, service_name, markup, global_markup, account, user, office, date, created_at, `rows`)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            name=VALUES(name), markup=VALUES(markup), global_markup=VALUES(global_markup), `rows`=VALUES(`rows`)');
        $stmt->execute([
            $b['id'], $b['name'], $b['service_id'], $b['service_name'] ?? '',
            $b['markup'], $b['global_markup'] ?? $b['markup'],
            $b['account'] ?? '—', $b['user'] ?? '', $b['office'] ?? '',
            $b['date'] ?? '', $b['created_at'] ?? date('Y-m-d H:i:s'),
            json_encode(array_map(function($r){$r['price']=(float)number_format((float)($r['price']??0),2,'.','');return $r;},$b['rows']??[]))
        ]);
        respond(['ok' => true]);
    }
    if ($action === 'delete') {
        // Ίδιος έλεγχος και στη διαγραφή: ό,τι δεν βλέπεις, δεν το σβήνεις.
        if (!$sees_all) {
            $st = db()->prepare('SELECT service_id FROM 4a_shelf WHERE id = ?');
            $st->execute([$b['id']]);
            $svc_code = (string)$st->fetchColumn();
            $svc_ctry = $svc_code === '' ? null : service_country($svc_code);
            if ($scope === 'NONE' || $svc_ctry === null || $svc_ctry !== $scope) {
                respond(['error' => 'Δεν έχετε πρόσβαση σε αυτόν τον τιμοκατάλογο.'], 403);
            }
        }
        $stmt = db()->prepare('DELETE FROM 4a_shelf WHERE id=?');
        $stmt->execute([$b['id']]);
        respond(['ok' => true]);
    }
    // Το action 'sync' (εφάπαξ migration από localStorage) αφαιρέθηκε 19-08-2026:
    // έκανε DELETE FROM 4a_shelf χωρίς transaction, χωρίς έλεγχο του items,
    // και δεν το καλούσε κανένα frontend αρχείο. Ίδια αφαίρεση με το clients.php.
}
respond(['error' => 'Bad request'], 400);