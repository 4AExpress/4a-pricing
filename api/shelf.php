<?php
// shelf.php | v1.5 | 18-09-2026 — POST action=meta (label/category/is_hot), GET action=categories (v1.4: soft delete)
// Το delete θέτει active=0, δεν σβήνει γραμμή. Το GET δείχνει μόνο active=1.
// Το σκληρό DELETE άφησε εννιά στοιχεία πελατών να δείχνουν σε ανύπαρκτους
// τιμοκαταλόγους, πέντε από αυτά χωρίς τιμές πουθενά.
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
    // Κατηγορίες ραφιού — λίστα επιλογών, όχι δεδομένα τιμοκαταλόγων: δεν
    // φιλτράρονται ανά χώρα, γι' αυτό πριν τον έλεγχο NONE.
    if (($_GET['action'] ?? '') === 'categories') {
        $cats = db()->query(
            'SELECT `code`, `label`, `kind`, `sort_order` FROM `4a_shelf_categories`
              WHERE `active` = 1 ORDER BY `sort_order`, `code`'
        )->fetchAll();
        respond($cats);
    }

    // NONE: ρητά κανένας τιμοκατάλογος — να μη στηριζόμαστε σε WHERE που
    // «τυχαίνει» να μη βρίσκει τίποτα.
    if (!$sees_all && $scope === 'NONE') respond((object)[]);

    if ($sees_all) {
        $rows = db()->query('SELECT * FROM 4a_shelf WHERE active = 1 ORDER BY created_at DESC')->fetchAll();
    } else {
        $stmt = db()->prepare(
            'SELECT sh.* FROM 4a_shelf sh
             JOIN `4a_services` sv ON sv.code = sh.service_id COLLATE utf8mb4_unicode_ci
             WHERE sv.country = ? AND sh.active = 1
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
        // active=1 στο ON DUPLICATE: χωρίς αυτό, αποθήκευση πάνω σε soft-deleted id
        // γράφει εγγραφή που υπάρχει στη βάση αλλά δεν επιστρέφεται ποτέ από το GET.
        $stmt = db()->prepare('INSERT INTO 4a_shelf
            (id, name, service_id, service_name, markup, global_markup, account, user, office, date, created_at, `rows`)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            name=VALUES(name), markup=VALUES(markup), global_markup=VALUES(global_markup), `rows`=VALUES(`rows`),
            active=1');
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
        // Soft delete: η γραμμή μένει, βγαίνει από το GET. Οι προσφορές που τη
        // δείχνουν κρατούν αντίγραφο τιμών, αλλά το ίχνος δεν χάνεται πια.
        $stmt = db()->prepare('UPDATE 4a_shelf SET active = 0 WHERE id = ?');
        $stmt->execute([$b['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'meta') {
        // ΜΟΝΟ label, category, is_hot. Ποτέ rows/markup/year/active: το save
        // με μερικό body γράφει rows=[] και σβήνει τις τιμές — γι' αυτό ξεχωριστό action.
        // Και τα τρία πεδία υποχρεωτικά: πεδίο που λείπει ΔΕΝ γίνεται σιωπηλά ''/0.
        foreach (['id', 'label', 'category', 'is_hot'] as $k) {
            if (!array_key_exists($k, $b)) respond(['error' => "Λείπει το πεδίο $k."], 400);
        }
        if (!preg_match('/^\d+$/', (string)$b['id'])) respond(['error' => 'Μη έγκυρο id.'], 400);
        if (!is_string($b['label']) || !is_string($b['category'])) {
            respond(['error' => 'Τα label και category πρέπει να είναι κείμενο.'], 400);
        }
        $label    = trim($b['label']);
        $category = trim($b['category']);
        // Όρια στηλών (VARCHAR 80 / 40): σφάλμα, όχι σιωπηλό κόψιμο.
        if (mb_strlen($label) > 80)    respond(['error' => 'Το label ξεπερνά τους 80 χαρακτήρες.'], 400);
        if (mb_strlen($category) > 40) respond(['error' => 'Η κατηγορία ξεπερνά τους 40 χαρακτήρες.'], 400);
        if (!in_array($b['is_hot'], [0, 1, '0', '1', true, false], true)) {
            respond(['error' => 'Το is_hot πρέπει να είναι 0 ή 1.'], 400);
        }
        $is_hot = (int)(bool)$b['is_hot'];

        // Ο τιμοκατάλογος πρέπει να υπάρχει και να μην είναι διαγραμμένος —
        // αλλιώς το UPDATE θα «πετύχαινε» χωρίς να αλλάξει τίποτα.
        $st = db()->prepare('SELECT service_id FROM 4a_shelf WHERE id = ? AND active = 1');
        $st->execute([$b['id']]);
        $svc_code = $st->fetchColumn();
        if ($svc_code === false) respond(['error' => 'Ο τιμοκατάλογος δεν βρέθηκε.'], 404);

        // Ίδιος έλεγχος scope με το delete: ό,τι δεν βλέπεις, δεν το αλλάζεις.
        if (!$sees_all) {
            $svc_code = (string)$svc_code;
            $svc_ctry = $svc_code === '' ? null : service_country($svc_code);
            if ($scope === 'NONE' || $svc_ctry === null || $svc_ctry !== $scope) {
                respond(['error' => 'Δεν έχετε πρόσβαση σε αυτόν τον τιμοκατάλογο.'], 403);
            }
        }

        // Κενή κατηγορία = «χωρίς κατηγορία». Οποιαδήποτε άλλη πρέπει να είναι ενεργή.
        // Αποθηκεύεται ο code ΟΠΩΣ είναι στον πίνακα: το utf8mb4_unicode_ci ταιριάζει
        // και το "eshops" με το "ESHOPS", και δεν θέλουμε δύο γραφές στο 4a_shelf.
        if ($category !== '') {
            $st = db()->prepare('SELECT `code` FROM `4a_shelf_categories` WHERE `code` = ? AND `active` = 1');
            $st->execute([$category]);
            $canon = $st->fetchColumn();
            if ($canon === false) {
                respond(['error' => 'Η κατηγορία «' . $category . '» δεν υπάρχει ή είναι ανενεργή.'], 400);
            }
            $category = $canon;
        }

        $stmt = db()->prepare('UPDATE 4a_shelf SET label = ?, category = ?, is_hot = ? WHERE id = ? AND active = 1');
        $stmt->execute([$label, $category, $is_hot, $b['id']]);
        respond(['ok' => true]);
    }
    // Το action 'sync' (εφάπαξ migration από localStorage) αφαιρέθηκε 19-08-2026:
    // έκανε DELETE FROM 4a_shelf χωρίς transaction, χωρίς έλεγχο του items,
    // και δεν το καλούσε κανένα frontend αρχείο. Ίδια αφαίρεση με το clients.php.
}
respond(['error' => 'Bad request'], 400);