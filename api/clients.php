<?php
// clients.php | v1.2 | 09-07-2026 — persist cod, address, notes
require_once 'config.php';
require_once 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method === 'GET')  { require_permission('pricelist-clients', 'view'); }
if ($method === 'POST') { require_permission('pricelist-clients', 'edit'); }

// GET — φόρτωση πελατών με φίλτρο country βάσει pricelist_scope
if ($method === 'GET') {
    $session = require_permission('pricelist-clients', 'view');
    $perms   = get_user_permissions($session['id']);
    $scope   = $perms['pricelist_scope'] ?? 'GR';

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
        // Αυτόματος υπολογισμός country από office αν δεν δοθεί
        $office  = $b['office'] ?? '';
        $country = in_array($b['country'] ?? '', ['GR','CY','EU','NONEU'], true)
                   ? $b['country']
                   : (in_array($office, ['LCA','NIC','QLI']) ? 'CY' : 'GR');

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
        respond(['ok' => true]);
    }

    if ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM 4a_clients WHERE id=?');
        $stmt->execute([$b['id']]);
        respond(['ok' => true]);
    }

    // sync — migration από localStorage
    if ($action === 'sync') {
        $pdo = db();
        $pdo->exec('DELETE FROM 4a_clients');
        $stmt = $pdo->prepare('INSERT INTO 4a_clients
            (id, name, afm, contact, email, phone, website, address, notes, account, status,
             pricelists, surcharges, managers, cod, payment, invoice, validity,
             offer_number, user, office, country, date, is_walkin, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($b['items'] as $c) {
            $off = $c['office'] ?? '';
            $cty = in_array($c['country'] ?? '', ['GR','CY','EU','NONEU'], true)
                   ? $c['country']
                   : (in_array($off, ['LCA','NIC','QLI']) ? 'CY' : 'GR');
            $stmt->execute([
                $c['id'], $c['name'], $c['afm'] ?? '', $c['contact'] ?? '',
                $c['email'] ?? '', $c['phone'] ?? '', $c['website'] ?? '',
                $c['address'] ?? '', $c['notes'] ?? '',
                $c['account'] ?? '—', $c['status'] ?? 'prospect',
                json_encode($c['pricelists'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($c['surcharges'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($c['managers']   ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($c['cod']        ?? null, JSON_UNESCAPED_UNICODE),
                $c['payment'] ?? '30', $c['invoice'] ?? 'monthly',
                $c['validity'] ?? '30', $c['offer_number'] ?? '',
                $c['user'] ?? '', $off, $cty,
                $c['date'] ?? '', (int)($c['is_walkin'] ?? 0),
                $c['created_at'] ?? date('Y-m-d H:i:s')
            ]);
        }
        respond(['ok' => true, 'synced' => count($b['items'])]);
    }
}

respond(['error' => 'Bad request'], 400);
