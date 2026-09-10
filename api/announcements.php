<?php
/**
 * 4a-pricing · api/announcements.php
 *
 * Ενέργειες:
 *   GET  ?action=pending            όσα δεν έχει δει στην τρέχουσα αναθεώρηση
 *   GET  ?action=list               όλες οι ορατές (σελίδα «Τι νέο υπάρχει»)
 *   POST {action:'seen', ids:[]}    σήμανση ως ιδωμένες
 *   POST {action:'open', id}        άνοιξε το κείμενο (+1 opens)
 *   POST {action:'tick', id, sec}   ενεργός χρόνος ανάγνωσης
 *   POST {action:'ack',  id}        βεβαίωση παραλαβής
 *   GET  ?action=report             αναφορά ανάγνωσης  [δικαίωμα]
 *
 * ΔΙΚΑΙΩΜΑΤΑ
 *   Η ανάγνωση ανακοινώσεων ΔΕΝ περνάει από RBAC — require_user() σκέτο.
 *   Το user_permissions ΑΝΤΙΚΑΘΙΣΤΑ τον ρόλο (auth.php:110-118), οπότε
 *   μια κενή γραμμή θα έκοβε σιωπηλά κάποιον από ανακοινώσεις που
 *   ΟΦΕΙΛΕΙ να δει. Μόνο η αναφορά είναι RBAC-gated.
 *
 * ΡΟΛΟΣ
 *   Το require_user() διαβάζει ΜΟΝΟ το 4a_sessions — ο ρόλος εκεί είναι
 *   παγωμένος από τη στιγμή του login. Διαβάζουμε role/pricelist_scope
 *   ΖΩΝΤΑΝΑ από το 4a_users σε κάθε κλήση.
 */

require_once __DIR__ . '/config.php';   // db(), respond(), body()
require_once __DIR__ . '/auth.php';     // require_user(), require_permission()

header('Content-Type: application/json; charset=utf-8');

/* --- Όρια τηλεμετρίας --------------------------------------------- */
// Μέγιστα δευτερόλεπτα που πιστώνονται ανά παλμό. Ο client χτυπά κάθε
// 10΄΄· το 20 αφήνει περιθώριο για αργό δίκτυο αλλά κόβει την περίπτωση
// «άνοιξε, έφυγε για καφέ, γύρισε» — εκεί ο παλμός σταματά ούτως ή άλλως.
const ANN_TICK_MAX  = 20;
const ANN_TOTAL_MAX = 1800;  // 30 λεπτά ανά ανακοίνωση, απόλυτο ταβάνι

/* ==================================================================== */
/*  Βοηθητικά                                                            */
/* ==================================================================== */

/** Το κλειδί του χρήστη στο session δεν είναι επιβεβαιωμένο — ψάχνουμε. */
function ann_uid(array $s): int {
    foreach (['user_id', 'id', 'uid'] as $k) {
        if (!empty($s[$k])) return (int)$s[$k];
    }
    respond(['ok' => false, 'error' => 'Η συνεδρία δεν φέρει αναγνωριστικό χρήστη'], 500);
}

/** Ζωντανό προφίλ από το 4a_users. ΠΟΤΕ από το session. */
function ann_me(PDO $d, int $uid): array {
    $st = $d->prepare("SELECT `id`,`name`,`role`,`pricelist_scope`,`active`
                         FROM `4a_users` WHERE `id` = ? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int)$u['active'] !== 1) {
        respond(['ok' => false, 'error' => 'Ανενεργός χρήστης'], 403);
    }
    return $u;
}

/**
 * Συνθήκη ορατότητας.
 * pricelist_scope='BOTH'  -> βλέπει GR, CY και BOTH.
 * pricelist_scope='NONE'  -> βλέπει μόνο BOTH. Δεν βλέπει τιμοκαταλόγους,
 *                            αλλά ΕΙΝΑΙ εργαζόμενος: οι γενικές τον αφορούν.
 */
function ann_where(array $me, array &$p): string {
    $p[':role']  = $me['role'];
    $p[':scope'] = $me['pricelist_scope'];
    return "a.`active` = 1
        AND (a.`published_at` IS NULL OR a.`published_at` <= NOW())
        AND (a.`audience_roles` IS NULL OR FIND_IN_SET(:role, a.`audience_roles`))
        AND (a.`audience_scope` = 'BOTH' OR :scope = 'BOTH' OR a.`audience_scope` = :scope)";
}

function ann_row(PDO $d, int $aid): ?array {
    $st = $d->prepare("SELECT * FROM `4a_announcements` WHERE `id` = ? LIMIT 1");
    $st->execute([$aid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Ο χρήστης δικαιούται να δει ΑΥΤΗ την ανακοίνωση; Έλεγχος πριν από κάθε γραφή. */
function ann_may(array $me, array $a): bool {
    if ((int)$a['active'] !== 1) return false;
    if ($a['published_at'] !== null && strtotime($a['published_at']) > time()) return false;
    if ($a['audience_roles'] !== null) {
        $roles = array_map('trim', explode(',', $a['audience_roles']));
        if (!in_array($me['role'], $roles, true)) return false;
    }
    return $a['audience_scope'] === 'BOTH'
        || $me['pricelist_scope'] === 'BOTH'
        || $a['audience_scope'] === $me['pricelist_scope'];
}

function ann_touch(PDO $d, int $aid, int $uid, int $rev): void {
    $d->prepare("INSERT INTO `4a_announcement_reads`
                   (`announcement_id`,`user_id`,`revision_seen`,`first_seen_at`,`last_seen_at`)
                 VALUES (?,?,?,NOW(),NOW())
                 ON DUPLICATE KEY UPDATE
                   `revision_seen` = GREATEST(`revision_seen`, VALUES(`revision_seen`)),
                   `first_seen_at` = COALESCE(`first_seen_at`, NOW()),
                   `last_seen_at`  = NOW()")
      ->execute([$aid, $uid, $rev]);
}

function ann_public(array $a): array {
    return [
        'id'          => (int)$a['id'],
        'code'        => $a['code'],
        'revision'    => (int)$a['revision'],
        'title'       => $a['title'],
        'summary'     => $a['summary'],
        'changed'     => $a['body_changed'],
        'why'         => $a['body_why'],
        'todo'        => $a['body_todo'],
        'icon'        => $a['icon'],
        'severity'    => $a['severity'],
        'category'    => $a['category'],
        'cta_label'   => $a['cta_label'],
        'cta_url'     => $a['cta_url'],
        'require_ack' => (int)$a['require_ack'] === 1,
        'tracked'     => (int)$a['track_reading'] === 1,
        'date'        => $a['published_at'],
    ];
}

/* ==================================================================== */
/*  Δρομολόγηση                                                          */
/* ==================================================================== */

$d      = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in     = $method === 'POST' ? (body() ?: []) : [];
$action = $_GET['action'] ?? ($in['action'] ?? '');

/* --- Η αναφορά είναι το ΜΟΝΟ σημείο με RBAC ------------------------- */
if ($action === 'report') {
    $actor = require_permission('announcements-report', 'view');
    $uid   = ann_uid($actor);
    $me    = ann_me($d, $uid);
    respond(['ok' => true] + ann_report($d, $me));
}

/* --- Όλα τα υπόλοιπα: απλός συνδεδεμένος χρήστης -------------------- */
$actor = require_user();
$uid   = ann_uid($actor);
$me    = ann_me($d, $uid);

switch ($action) {

    /* ---- Τι δεν έχει δει ακόμα ------------------------------------- */
    case 'pending':
    case 'list': {
        $p   = [':uid' => $uid];
        $w   = ann_where($me, $p);
        $new = $action === 'pending'
             ? " AND (r.`user_id` IS NULL OR r.`revision_seen` < a.`revision`)"
             : "";

        $st = $d->prepare(
            "SELECT a.*, r.`revision_seen`, r.`first_seen_at`,
                    r.`acked_at`, r.`opens`, r.`dwell_sec`
               FROM `4a_announcements` a
               LEFT JOIN `4a_announcement_reads` r
                      ON r.`announcement_id` = a.`id` AND r.`user_id` = :uid
              WHERE $w $new
              ORDER BY FIELD(a.`severity`,'critical','important','info'),
                       a.`published_at` DESC, a.`id` DESC"
        );
        $st->execute($p);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out[] = ann_public($a) + [
                'seen'  => $a['revision_seen'] !== null
                           && (int)$a['revision_seen'] >= (int)$a['revision'],
                'acked' => $a['acked_at'] !== null,
                'opens' => (int)($a['opens'] ?? 0),
                'dwell' => (int)($a['dwell_sec'] ?? 0),
            ];
        }
        respond(['ok' => true, 'items' => $out, 'count' => count($out)]);
    }

    /* ---- Σήμανση ως ιδωμένες --------------------------------------- */
    case 'seen': {
        $ids = array_filter(array_map('intval', (array)($in['ids'] ?? [])));
        if (!$ids) respond(['ok' => true, 'marked' => 0]);
        $n = 0;
        foreach ($ids as $aid) {
            $a = ann_row($d, $aid);
            if (!$a || !ann_may($me, $a)) continue;   // σιωπηλά, όχι 403
            ann_touch($d, $aid, $uid, (int)$a['revision']);
            $n++;
        }
        respond(['ok' => true, 'marked' => $n]);
    }

    /* ---- Άνοιγμα κειμένου ------------------------------------------ */
    case 'open': {
        $aid = (int)($in['id'] ?? 0);
        $a   = ann_row($d, $aid);
        if (!$a || !ann_may($me, $a)) respond(['ok' => false, 'error' => 'Άγνωστη ανακοίνωση'], 404);

        ann_touch($d, $aid, $uid, (int)$a['revision']);
        if ((int)$a['track_reading'] === 1) {
            $d->prepare("UPDATE `4a_announcement_reads` SET `opens` = `opens` + 1
                          WHERE `announcement_id` = ? AND `user_id` = ?")
              ->execute([$aid, $uid]);
        }
        respond(['ok' => true]);
    }

    /* ---- Ενεργός χρόνος ανάγνωσης ---------------------------------- */
    /* ---- Παλμός ανάγνωσης ------------------------------------------
       v2: ο client ΔΕΝ στέλνει πια δευτερόλεπτα. Στέλνει μόνο
       «είμαι εδώ και διαβάζω», και ο SERVER μετράει πόση ώρα πέρασε
       πραγματικά από τον προηγούμενο παλμό.

       Γιατί άλλαξε: στην v1 ο πελάτης έστελνε τον αριθμό. Δύο
       καρτέλες ανοιχτές έγραφαν στην ΙΔΙΑ γραμμή και ο χρόνος
       πολλαπλασιαζόταν. Στη δοκιμή καταγράφηκαν 1800΄΄ σε 388΄΄
       πραγματικού χρόνου.

       Τώρα είναι μαθηματικά αδύνατο: δέκα καρτέλες μαζί δεν μπορούν
       να προσθέσουν παραπάνω από όσο κύλησε το ρολόι, γιατί η πρώτη
       που θα γράψει μηδενίζει το last_seen_at για όλες. */
    case 'tick': {
        $aid = (int)($in['id'] ?? 0);
        $a   = ann_row($d, $aid);

        // Καταγραφή ΜΟΝΟ όπου έχει δηλωθεί ρητά. Αλλιώς σιωπηλά τίποτα.
        if (!$a || !ann_may($me, $a) || (int)$a['track_reading'] !== 1) {
            respond(['ok' => true, 'tracked' => false]);
        }

        /* ΠΡΟΣΟΧΗ — τα δύο όρια μπαίνουν ως ΚΥΡΙΟΛΕΚΤΙΚΟΙ αριθμοί,
           όχι ως δεσμευμένες παράμετροι.

           Το PDO στέλνει κάθε παράμετρο ως συμβολοσειρά. Η MySQL,
           αν έστω ένα όρισμα της LEAST() είναι κείμενο, συγκρίνει
           ΟΛΑ ως κείμενο:  LEAST(5, '1800') = '1800', γιατί
           αλφαβητικά το '1' προηγείται του '5'. Το ταβάνι γινόταν
           πάτωμα και κάθε παλμός έγραφε 1800.

           Οι δύο τιμές είναι σταθερές του αρχείου, όχι είσοδος
           χρήστη — δεν υπάρχει κίνδυνος injection. */
        $step  = (int) ANN_TICK_MAX;
        $total = (int) ANN_TOTAL_MAX;

        $d->prepare(
            "UPDATE `4a_announcement_reads`
                SET `dwell_sec` = LEAST(
                      `dwell_sec` + LEAST(
                          GREATEST(TIMESTAMPDIFF(SECOND, `last_seen_at`, NOW()), 0),
                          $step),
                      $total),
                    `last_seen_at` = NOW()
              WHERE `announcement_id` = :aid AND `user_id` = :uid"
        )->execute([
            ':aid' => $aid,
            ':uid' => $uid,
        ]);

        // Επιστρέφουμε την αλήθεια του server, ώστε η οθόνη να δείχνει
        // ό,τι είναι πράγματι γραμμένο — όχι δικό της μέτρημα.
        $st = $d->prepare("SELECT `dwell_sec` FROM `4a_announcement_reads`
                            WHERE `announcement_id` = ? AND `user_id` = ?");
        $st->execute([$aid, $uid]);

        respond(['ok' => true, 'tracked' => true, 'dwell' => (int)$st->fetchColumn()]);
    }

    /* ---- Βεβαίωση παραλαβής ---------------------------------------- */
    case 'ack': {
        $aid = (int)($in['id'] ?? 0);
        $a   = ann_row($d, $aid);
        if (!$a || !ann_may($me, $a)) respond(['ok' => false, 'error' => 'Άγνωστη ανακοίνωση'], 404);
        if ((int)$a['require_ack'] !== 1) {
            respond(['ok' => false, 'error' => 'Η ανακοίνωση δεν ζητά βεβαίωση'], 400);
        }
        ann_touch($d, $aid, $uid, (int)$a['revision']);
        // COALESCE: η πρώτη υπογραφή μένει. Δεύτερο πάτημα δεν την ξαναγράφει.
        $d->prepare("UPDATE `4a_announcement_reads`
                        SET `acked_at` = COALESCE(`acked_at`, NOW())
                      WHERE `announcement_id` = ? AND `user_id` = ?")
          ->execute([$aid, $uid]);
        respond(['ok' => true]);
    }

    default:
        respond(['ok' => false, 'error' => 'Άγνωστη ενέργεια'], 400);
}

/* ==================================================================== */
/*  Αναφορά ανάγνωσης                                                    */
/* ==================================================================== */
function ann_report(PDO $d, array $me): array {

    $isAdmin = $me['role'] === 'administrator';

    /* Ποιους βλέπει ο καθένας.
       Ο υπεύθυνος: μόνο τη δική του εμβέλεια, ποτέ administrators,
       ποτέ τον εαυτό του. Ο administrator: τους πάντες. */
    if ($isAdmin) {
        $us = $d->query("SELECT `id`,`name`,`role`,`pricelist_scope`,`office`
                           FROM `4a_users` WHERE `active` = 1 ORDER BY `name`")
                ->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $d->prepare("SELECT `id`,`name`,`role`,`pricelist_scope`,`office`
                             FROM `4a_users`
                            WHERE `active` = 1
                              AND `role` <> 'administrator'
                              AND `id` <> :me
                              AND (:scope = 'BOTH' OR `pricelist_scope` IN (:scope2,'BOTH'))
                            ORDER BY `name`");
        $st->execute([':me' => (int)$me['id'],
                      ':scope' => $me['pricelist_scope'],
                      ':scope2' => $me['pricelist_scope']]);
        $us = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $pool = array_column($us, null, 'id');

    /* Μόνο όσες έχουν ρητή καταγραφή. Οι υπόλοιπες δεν μετρώνται. */
    $anns = $d->query("SELECT * FROM `4a_announcements`
                        WHERE `active` = 1 AND `track_reading` = 1
                        ORDER BY `published_at` DESC, `id` DESC")
              ->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($anns as $a) {
        $aid = (int)$a['id'];

        $st = $d->prepare("SELECT * FROM `4a_announcement_reads`
                            WHERE `announcement_id` = ?");
        $st->execute([$aid]);
        $byUser = array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'user_id');

        $rows = [];
        $times = [];
        $done = 0;
        foreach ($pool as $u) {
            if (!ann_may($u, $a)) continue;          // εκτός κοινού
            $r    = $byUser[$u['id']] ?? null;
            $seen = $r && (int)$r['revision_seen'] >= (int)$a['revision'];
            $ok   = $seen && ((int)$a['require_ack'] !== 1 || $r['acked_at'] !== null);
            if ($ok) $done++;
            $dw = (int)($r['dwell_sec'] ?? 0);
            if ($dw > 0) $times[] = $dw;

            $rows[] = [
                'user_id' => (int)$u['id'],
                'name'    => $u['name'],
                'role'    => $u['role'],
                'scope'   => $u['pricelist_scope'],
                'office'  => $u['office'],
                'state'   => !$seen ? 'unseen' : ($ok ? 'done' : 'unacked'),
                'dwell'   => $dw,
                'opens'   => (int)($r['opens'] ?? 0),
                'seen_at' => $r['first_seen_at'] ?? null,
                'ack_at'  => $r['acked_at'] ?? null,
            ];
        }

        /* Εκτιμώμενος χρόνος ανάγνωσης: ~180 λέξεις/λεπτό, κατώφλι 12΄΄.
           Χωρίς μέτρο σύγκρισης, ένα «34 δευτερόλεπτα» δεν σημαίνει τίποτα. */
        $txt   = strip_tags($a['body_changed'].' '.$a['body_why'].' '.$a['body_todo']);
        $words = count(preg_split('/\s+/u', trim($txt), -1, PREG_SPLIT_NO_EMPTY));
        $est   = max(12, (int)round($words / 180 * 60));

        $out[] = [
            'id'        => $aid,
            'code'      => $a['code'],
            'title'     => $a['title'],
            'icon'      => $a['icon'],
            'severity'  => $a['severity'],
            'needs_ack' => (int)$a['require_ack'] === 1,
            'est_sec'   => $est,
            'avg_sec'   => $times ? (int)round(array_sum($times) / count($times)) : 0,
            'done'      => $done,
            'audience'  => count($rows),
            'rows'      => $rows,
        ];
    }

    return [
        'scope_note' => $isAdmin
            ? 'Όλοι οι χρήστες.'
            : 'Μόνο οι χρήστες της εμβέλειάς σου (' . $me['pricelist_scope'] . '). Οι διαχειριστές δεν εμφανίζονται.',
        'items' => $out,
    ];
}
