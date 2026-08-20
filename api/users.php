<?php
// users.php | v2.0 | 20-08-2026 — per-user permissions + φίλτρο ορατότητας ανά σταθμό
require_once 'config.php';
require_once 'auth.php';

const PERM_ACTIONS = ['view', 'add', 'edit', 'delete', 'export'];
const SCOPES       = ['GR', 'CY', 'BOTH', 'NONE'];

/** Το scope ως σύνολο χωρών — ώστε η οροφή να είναι απλή τομή. */
function scope_to_set(string $s): array {
    if ($s === 'BOTH') return ['GR', 'CY'];
    if ($s === 'NONE') return [];
    return [$s];
}
function set_to_scope(array $set): string {
    $gr = in_array('GR', $set, true);
    $cy = in_array('CY', $set, true);
    if ($gr && $cy) return 'BOTH';
    if ($gr) return 'GR';
    if ($cy) return 'CY';
    return 'NONE';
}

/** Επιτρεπτά module_id — whitelist από τον πίνακα modules. */
function module_whitelist(): array {
    try {
        return db()->query('SELECT id FROM modules WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

/** Τα δικαιώματα που δίνει ΚΑΘΑΡΑ ο ρόλος (χωρίς per-user εξαιρέσεις). */
function role_baseline(string $role): array {
    $stmt = db()->prepare(
        'SELECT rp.module_id, rp.can_view, rp.can_add, rp.can_edit, rp.can_delete, rp.can_export
         FROM roles r JOIN role_permissions rp ON rp.role_id = r.id
         WHERE r.name = ?'
    );
    $stmt->execute([$role]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['module_id']] = [
            'view'   => (int)$r['can_view'],   'add'    => (int)$r['can_add'],
            'edit'   => (int)$r['can_edit'],   'delete' => (int)$r['can_delete'],
            'export' => (int)$r['can_export'],
        ];
    }
    return $out;
}

/**
 * Γράφει ΜΟΝΟ τις εξαιρέσεις: ό,τι ταυτίζεται με τον ρόλο διαγράφεται, ώστε
 * η «επαναφορά στον ρόλο» να είναι απλή διαγραφή γραμμής.
 */
function save_user_permissions(int $target_id, string $target_role, array $mp, bool $is_admin, array $my_perms): void {
    $whitelist = module_whitelist();
    $baseline  = role_baseline($target_role);
    $ins = db()->prepare(
        'INSERT INTO user_permissions (user_id, module_id, can_view, can_add, can_edit, can_delete, can_export)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_add=VALUES(can_add),
                                 can_edit=VALUES(can_edit), can_delete=VALUES(can_delete),
                                 can_export=VALUES(can_export)'
    );
    $del = db()->prepare('DELETE FROM user_permissions WHERE user_id = ? AND module_id = ?');

    foreach ($mp as $mid => $p) {
        if (!in_array($mid, $whitelist, true)) continue;   // άγνωστο module → αγνοείται
        if (!is_array($p)) continue;

        $vals = [];
        foreach (PERM_ACTIONS as $a) {
            $want = !empty($p[$a]) ? 1 : 0;
            // ΚΑΝΟΝΑΣ ΟΡΟΦΗΣ: ο μη-admin δίνει μόνο δικαιώματα που έχει ο ίδιος.
            if (!$is_admin && $want && empty($my_perms[$mid][$a])) $want = 0;
            $vals[$a] = $want;
        }

        $base = $baseline[$mid] ?? ['view'=>0,'add'=>0,'edit'=>0,'delete'=>0,'export'=>0];
        if ($vals == $base) {
            $del->execute([$target_id, $mid]);             // ίδιο με τον ρόλο → όχι εξαίρεση
        } else {
            $ins->execute([$target_id, $mid, $vals['view'], $vals['add'],
                           $vals['edit'], $vals['delete'], $vals['export']]);
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// ─────────────────────────────────────────────────────────────────────────
// GET — λίστα χρηστών, φιλτραρισμένη ΑΝΑ ΣΤΑΘΜΟ (server-side)
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $session     = require_permission('users', 'view');
    $me          = (int)$session['id'];
    $my_role     = $session['permissions']['role'] ?? '';
    $my_stations = $session['permissions']['stations'] ?? [];

    $rows = db()->query('SELECT id, user_code, name, office, role, email, stations, active_station, default_station, pricelist_scope, can_view, can_add, can_edit, can_delete, can_export, can_all, active, created_at FROM 4a_users WHERE active=1 ORDER BY user_code')->fetchAll();

    // Κανόνας ΥΠΟΣΥΝΟΛΟΥ: ο non-admin βλέπει χρήστη μόνο αν ΟΛΟΙ οι σταθμοί
    // του άλλου περιέχονται στους δικούς του. Ο εαυτός του πάντα ορατός.
    if ($my_role !== 'administrator') {
        $rows = array_values(array_filter($rows, function ($u) use ($me, $my_stations) {
            if ((int)$u['id'] === $me) return true;
            $theirs = array_filter(array_map('trim', explode(',', $u['stations'] ?? '')));
            if (empty($theirs)) return false;
            foreach ($theirs as $s) {
                if (!in_array($s, $my_stations, true)) return false;
            }
            return true;
        }));
    }

    // Οι per-user εξαιρέσεις, ώστε το grid να τις σημάνει οπτικά.
    $exceptions = [];
    try {
        foreach (db()->query('SELECT * FROM user_permissions')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $exceptions[(int)$r['user_id']][$r['module_id']] = [
                'view'   => (int)$r['can_view'],   'add'    => (int)$r['can_add'],
                'edit'   => (int)$r['can_edit'],   'delete' => (int)$r['can_delete'],
                'export' => (int)$r['can_export'],
            ];
        }
    } catch (Exception $e) { /* χωρίς πίνακα → καμία εξαίρεση */ }

    foreach ($rows as &$u) {
        $u['perm_exceptions'] = (object)($exceptions[(int)$u['id']] ?? []);
    }
    unset($u);

    respond($rows);
}

// ─────────────────────────────────────────────────────────────────────────
// POST
// ─────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $session  = require_permission('users', 'edit');
    $me       = (int)$session['id'];
    $my_role  = $session['permissions']['role'] ?? '';
    $my_perms = $session['permissions']['permissions'] ?? [];
    $my_scope = $session['permissions']['pricelist_scope'] ?? 'GR';
    $is_admin = ($my_role === 'administrator');

    $b      = body();
    $action = $b['action'] ?? 'add';
    $mp     = (isset($b['module_permissions']) && is_array($b['module_permissions']))
              ? $b['module_permissions'] : null;

    function nextUserCode($pdo) {
        $row = $pdo->query("SELECT MAX(CAST(SUBSTRING(user_code,2) AS UNSIGNED)) as mx FROM 4a_users WHERE user_code LIKE 'E%'")->fetch();
        $next = ($row['mx'] ?? 1000) + 1;
        return 'E' . $next;
    }

    // Τα can_* του 4a_users είναι LEGACY: γράφονταν αλλά δεν τα διάβαζε ποτέ
    // κανένας έλεγχος πρόσβασης (η αλήθεια είναι role_permissions +
    // user_permissions). Δεν τα γράφουμε πια· οι στήλες μένουν για το session
    // object και τυχόν frontend που τις διαβάζει.

    if ($action === 'add') {
        $role = $b['role'] ?? 'user';
        if (!$is_admin && !in_array($role, ['staff', 'readonly'], true)) {
            respond(['error' => 'Μόνο ο Administrator δημιουργεί χρήστες με ρόλο ' . $role . '.'], 403);
        }
        // pricelist_scope: whitelist, καμία σιωπηλή εικασία (μάθημα από το country
        // του clients.php). Ο administrator βλέπει πάντα τα πάντα.
        $scope = strtoupper(trim((string)($b['pricelist_scope'] ?? 'GR')));
        if (!in_array($scope, SCOPES, true)) {
            respond(['error' => 'Άκυρη τιμή pricelist_scope: «' . $scope . '». Επιτρεπτές: GR, CY, BOTH, NONE.'], 400);
        }
        if ($role === 'administrator') {
            $scope = 'BOTH';
        } elseif (!$is_admin) {
            // ΟΡΟΦΗ: ο μη-admin δεν δίνει scope ευρύτερο από το δικό του.
            $scope = set_to_scope(array_intersect(scope_to_set($scope), scope_to_set($my_scope)));
        }

        $code = !empty($b['user_code']) ? strtoupper(trim($b['user_code'])) : nextUserCode(db());
        $stmt = db()->prepare('INSERT INTO 4a_users (user_code, name, office, role, pin, email, stations, active_station, default_station, pricelist_scope) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $code,
            $b['name'], $b['office'] ?? 'Αθήνα', $role,
            $b['pin'], $b['email'] ?? '',
            $b['stations'] ?? 'ATH',
            $b['default_station'] ?? 'ATH',
            $b['default_station'] ?? 'ATH',
            $scope
        ]);
        $new_id = (int)db()->lastInsertId();
        if ($mp !== null && $role !== 'administrator') {
            save_user_permissions($new_id, $role, $mp, $is_admin, $my_perms);
        }
        respond(['ok' => true, 'id' => $new_id, 'user_code' => $code]);
    }

    if ($action === 'edit') {
        $tid = (int)($b['id'] ?? 0);
        $st  = db()->prepare('SELECT id, role, pricelist_scope FROM 4a_users WHERE id = ?');
        $st->execute([$tid]);
        $target = $st->fetch(PDO::FETCH_ASSOC);
        if (!$target) respond(['error' => 'Ο χρήστης δεν βρέθηκε.'], 404);

        $target_role  = $target['role'];
        $target_scope = $target['pricelist_scope'] ?? 'GR';
        $role         = $b['role'] ?? $target_role;

        if (!$is_admin) {
            // Ο manager επεμβαίνει μόνο σε staff/readonly — ποτέ σε manager ή admin.
            if ($tid !== $me && !in_array($target_role, ['staff', 'readonly'], true)) {
                respond(['error' => 'Δεν έχετε δικαίωμα επεξεργασίας χρήστη με ρόλο ' . $target_role . '.'], 403);
            }
            // Η προαγωγή/υποβιβασμός ρόλου ανήκει αποκλειστικά στον administrator.
            $role = $target_role;
        }

        // Κανείς δεν αλλάζει τα ΔΙΚΑ ΤΟΥ δικαιώματα (ρόλος/σταθμοί ναι, permissions όχι).
        if ($tid === $me) $mp = null;

        $scope = strtoupper(trim((string)($b['pricelist_scope'] ?? $target_scope)));
        if (!in_array($scope, SCOPES, true)) {
            respond(['error' => 'Άκυρη τιμή pricelist_scope: «' . $scope . '». Επιτρεπτές: GR, CY, BOTH, NONE.'], 400);
        }
        if ($role === 'administrator') {
            $scope = 'BOTH';                       // οι admins βλέπουν πάντα τα πάντα
        } elseif (!$is_admin) {
            if ($tid === $me) {
                $scope = $target_scope;            // ποτέ αλλαγή του δικού του scope
            } else {
                $scope = set_to_scope(array_intersect(scope_to_set($scope), scope_to_set($my_scope)));
            }
        }

        $sql = 'UPDATE 4a_users SET user_code=?, name=?, office=?, role=?, email=?, stations=?, default_station=?, pricelist_scope=?';
        $params = [
            $b['user_code'] ?? '', $b['name'], $b['office'] ?? 'Αθήνα', $role,
            $b['email'] ?? '', $b['stations'] ?? 'ATH',
            $b['default_station'] ?? 'ATH',
            $scope
        ];
        if (!empty($b['pin']) && strlen($b['pin']) === 4) {
            $sql .= ', pin=?';
            $params[] = $b['pin'];
        }
        $sql .= ' WHERE id=?';
        $params[] = $tid;
        db()->prepare($sql)->execute($params);

        if ($role === 'administrator') {
            // Οι admins έχουν πάντα τα πάντα — οι εξαιρέσεις δεν έχουν πια νόημα.
            db()->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$tid]);
        } elseif ($mp !== null) {
            save_user_permissions($tid, $role, $mp, $is_admin, $my_perms);
        }

        respond(['ok' => true]);
    }

    if ($action === 'reset_permissions') {
        $tid = (int)($b['id'] ?? 0);
        $st  = db()->prepare('SELECT id, role FROM 4a_users WHERE id = ?');
        $st->execute([$tid]);
        $target = $st->fetch(PDO::FETCH_ASSOC);
        if (!$target) respond(['error' => 'Ο χρήστης δεν βρέθηκε.'], 404);
        if ($tid === $me) respond(['error' => 'Δεν μπορείτε να αλλάξετε τα δικά σας δικαιώματα.'], 403);
        if (!$is_admin && !in_array($target['role'], ['staff', 'readonly'], true)) {
            respond(['error' => 'Δεν έχετε δικαίωμα επεξεργασίας χρήστη με ρόλο ' . $target['role'] . '.'], 403);
        }
        db()->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$tid]);
        respond(['ok' => true, 'reset' => $tid]);
    }

    if ($action === 'delete') {
        $tid = (int)($b['id'] ?? 0);
        if (!$is_admin) respond(['error' => 'Μόνο ο Administrator διαγράφει χρήστες.'], 403);
        db()->prepare('UPDATE 4a_users SET active=0 WHERE id=?')->execute([$tid]);
        respond(['ok' => true]);
    }
}

respond(['error' => 'Bad request'], 400);
