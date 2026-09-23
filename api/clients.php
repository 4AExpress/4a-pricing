<?php
// clients.php | v1.2 | 09-07-2026 — persist cod, address, notes
require_once 'config.php';
require_once 'auth.php';
require_once 'tasks_create.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method === 'GET')  { require_permission('pricelist-clients', 'view'); }
// Το αποτέλεσμα κρατιέται πλέον: χρειάζεται το id του χρήστη για το
// 4a_task_events. Ο έλεγχος δικαιώματος είναι ο ίδιος με πριν.
if ($method === 'POST') { $session = require_permission('pricelist-clients', 'edit'); }

// GET — φόρτωση πελατών με φίλτρο country βάσει pricelist_scope
if ($method === 'GET') {
    $session = require_permission('pricelist-clients', 'view');
    $perms   = get_user_permissions($session['id']);
    $scope   = $perms['pricelist_scope'] ?? 'GR';

    // NONE: ρητά κανένας πελάτης. Μέχρι τώρα κατέληγε σε WHERE country='NONE'
    // πάνω σε ENUM που δεν έχει αυτή την τιμή — δούλευε κατά σύμπτωση.
    // Το σχήμα της απάντησης παραμένει σκέτος πίνακας, όπως παρακάτω.
    if ($scope === 'NONE') respond([]);

    if ($scope === 'BOTH') {
        $rows = db()->query('SELECT * FROM 4a_clients ORDER BY created_at DESC')->fetchAll();
    } else {
        $stmt = db()->prepare('SELECT * FROM 4a_clients WHERE country=? ORDER BY created_at DESC');
        $stmt->execute([$scope]);
        $rows = $stmt->fetchAll();
    }

    foreach ($rows as &$r) {
        $r['pricelists'] = json_decode($r['pricelists'] ?? '[]', true);
        $r['surcharges'] = json_decode($r['surcharges'] ?? '[]', true);
        $r['managers']   = json_decode($r['managers']   ?? '[]', true);
        // cod is an object-or-null, not a list — decode NULL to null, not []
        $r['cod']        = json_decode($r['cod']        ?? 'null', true);
    }
    respond($rows);
}

// POST — αποθήκευση / διαγραφή πελάτη
if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? 'save';

    if ($action === 'save') {
        // Το country είναι υποχρεωτικό — καμία εικασία.
        // v1.x μάντευε από το $office, αλλά το office κρατά όνομα πόλης («Αθήνα»)
        // και όχι κωδικό σταθμού, οπότε η εικασία κατέληγε ΠΑΝΤΑ 'GR' και ένας
        // κυπριακός πελάτης εξαφανιζόταν σιωπηλά από τους CY χρήστες.
        $office  = $b['office'] ?? '';
        $country = $b['country'] ?? '';
        if (!in_array($country, ['GR','CY','EU','NONEU'], true)) {
            respond(['error' => 'Λείπει ή είναι άκυρη η χώρα του πελάτη (country). Επιτρεπτές τιμές: GR, CY, EU, NONEU.'], 400);
        }

        // Η ΠΑΛΙΑ κατάσταση, ΠΡΙΝ το UPSERT — μετά θα έχει χαθεί. Χωρίς αυτό
        // δεν ξεχωρίζουμε «μόλις έγινε accepted» από «ήταν ήδη και πατήθηκε
        // ξανά Αποθήκευση». false => νέος πελάτης.
        $stOld = db()->prepare('SELECT `status` FROM `4a_clients` WHERE `id` = ?');
        $stOld->execute([$b['id']]);
        $oldStatus = $stOld->fetchColumn();
        if ($oldStatus === false) $oldStatus = null;

        $stmt = db()->prepare('INSERT INTO 4a_clients
            (id, name, afm, contact, email, phone, website, address, notes, account, status,
             pricelists, surcharges, managers, cod, payment, invoice, validity,
             offer_number, user, office, country, date, is_walkin, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            name=VALUES(name), afm=VALUES(afm), contact=VALUES(contact),
            email=VALUES(email), phone=VALUES(phone), website=VALUES(website),
            address=VALUES(address), notes=VALUES(notes),
            account=VALUES(account), status=VALUES(status),
            pricelists=VALUES(pricelists), surcharges=VALUES(surcharges),
            managers=VALUES(managers), cod=VALUES(cod), payment=VALUES(payment),
            invoice=VALUES(invoice), validity=VALUES(validity),
            offer_number=VALUES(offer_number), user=VALUES(user),
            office=VALUES(office), country=VALUES(country), date=VALUES(date),
            is_walkin=VALUES(is_walkin)');

        $stmt->execute([
            $b['id'], $b['name'], $b['afm'] ?? '', $b['contact'] ?? '',
            $b['email'], $b['phone'] ?? '', $b['website'] ?? '',
            $b['address'] ?? '', $b['notes'] ?? '',
            $b['account'] ?? '—', $b['status'] ?? 'prospect',
            json_encode($b['pricelists'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($b['surcharges'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($b['managers']   ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($b['cod']        ?? null, JSON_UNESCAPED_UNICODE),
            $b['payment'] ?? '30', $b['invoice'] ?? 'monthly',
            $b['validity'] ?? '30', $b['offer_number'] ?? '',
            $b['user'] ?? '', $office, $country,
            $b['date'] ?? '', (int)($b['is_walkin'] ?? 0),
            $b['created_at'] ?? date('Y-m-d H:i:s')
        ]);

        // ── Εργασίες ───────────────────────────────────────────────────
        // Ο πελάτης ΕΧΕΙ ΗΔΗ ΓΡΑΦΤΕΙ ΚΑΙ ΔΕΣΜΕΥΤΕΙ σε αυτό το σημείο: το
        // UPSERT παραπάνω τρέχει σε autocommit, χωρίς περιβάλλουσα
        // συναλλαγή. Η tasks_create_for_client() ανοίγει ΔΙΚΗ της
        // συναλλαγή και δεν πετάει ποτέ εξαίρεση — ό,τι κι αν συμβεί με τις
        // εργασίες, η αποθήκευση του πελάτη έχει ήδη πετύχει.
        //
        // ΑΠΟΚΛΙΣΗ ΑΠΟ ΤΟ docs/tasks_phase2_spec.md: το spec ζητούσε ΜΙΑ
        // κοινή συναλλαγή για UPSERT + εργασίες. Υπερισχύει η απαίτηση «η
        // αποθήκευση του πελάτη δεν χαλάει ποτέ». Το τίμημα — πελάτης
        // accepted χωρίς εργασίες — το καλύπτει το δίχτυ ασφαλείας μέσα
        // στην tasks_create_for_client().
        $tasks = tasks_create_for_client(
            db(), $b['id'],
            isset($session['id']) ? $session['id'] : null,
            $oldStatus, $b['status'] ?? 'prospect'
        );

        $out = ['ok' => true];
        if ($tasks['ran'])            $out['tasks_created'] = $tasks['created'];
        // Το κείμενο του σφάλματος μένει στο error_log, δεν φεύγει στον
        // browser. Ο client μαθαίνει μόνο ότι κάτι δεν πήγε καλά.
        if ($tasks['error'] !== null) $out['tasks_failed']  = true;
        respond($out);
    }

    if ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM 4a_clients WHERE id=?');
        $stmt->execute([$b['id']]);
        respond(['ok' => true]);
    }

    // Το action 'sync' (εφάπαξ migration από localStorage) αφαιρέθηκε 19-08-2026:
    // έκανε DELETE FROM 4a_clients χωρίς transaction, χωρίς έλεγχο του items,
    // και δεν το καλούσε κανένα frontend αρχείο.
}

respond(['error' => 'Bad request'], 400);
