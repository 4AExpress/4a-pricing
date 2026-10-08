<?php
// fuel_weekly.php | v1.0 | 08-10-2026
// ΕΒΔΟΜΑΔΙΑΙΑ ΕΡΓΑΣΙΑ ΓΕΝΙΚΟΥ ΕΠΙΝΑΥΛΟΥ: ποια εβδομάδα και δημιουργία.
// Το καλεί το cron/fuel_weekly_create.php, κάθε 6 ώρες.
//
// ΔΕΝ κάνει require config.php ή auth.php, και δεν εκτελεί τίποτα κατά το
// include. Καθαρές συναρτήσεις που δέχονται PDO, ώστε να δοκιμάζονται
// χωρίς πραγματική βάση.
//
// ΙΔΕΜΠΟΤΕΝΤΙΚΟ: δεύτερη εκτέλεση για την ίδια εβδομάδα δεν κάνει τίποτα
// (UNIQUE(task_code, dedupe_key), INSERT IGNORE). Γι' αυτό η συχνότητα και
// η ζώνη ώρας του cron δεν επηρεάζουν το αποτέλεσμα.
//
// Ο DHL δημοσιεύει την τιμή της επόμενης εβδομάδας το ΣΑΒΒΑΤΟ, και το
// GitHub Action την πιάνει περίπου 10:00–11:30 UTC. Η πρώτη εκτέλεση του
// cron μετά από αυτό δημιουργεί την εργασία, με προθεσμία την Παρασκευή
// 17:00 Αθήνας που ακολουθεί.
require_once __DIR__ . '/fuel_lib.php';
require_once __DIR__ . '/tasks_system.php';

if (!defined('FUEL_WEEKLY_CACHE_URL')) {
    define('FUEL_WEEKLY_CACHE_URL',
           'https://raw.githubusercontent.com/4AExpress/4a-pricing/main/data/fuel_surcharge_cache.json');
}

/** Η σημερινή ημερομηνία στην Αθήνα, Y-m-d. */
function fuel_weekly_today_athens($now = null)
{
    $d = new DateTime($now === null ? 'now' : $now, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('Europe/Athens'));
    return $d->format('Y-m-d');
}

/**
 * Οι εβδομάδες του cache: όλες, και όσες ξεκινούν ΜΕΤΑ το σήμερα (Αθήνα)
 * και έχουν τιμή air ΚΑΙ road. Μόνο αυτές παίρνουν εργασία. Η τρέχουσα
 * εβδομάδα δεν δημιουργείται εκ των υστέρων.
 *
 * @return array ['all' => string[], 'targets' => string[]]  ταξινομημένα
 */
function fuel_weekly_targets(array $cache, $todayAthens)
{
    $src = array();
    foreach (array('air', 'road') as $k) {
        foreach ((isset($cache[$k]) && is_array($cache[$k]) ? $cache[$k] : array()) as $r) {
            if (!empty($r['week_start']) && isset($r['pct']) && is_numeric($r['pct'])) {
                $src[$r['week_start']][$k] = true;
            }
        }
    }
    $all = array_keys($src);
    sort($all);
    $targets = array();
    foreach ($all as $ws) {
        if ($ws > $todayAthens && !empty($src[$ws]['air']) && !empty($src[$ws]['road'])) $targets[] = $ws;
    }
    return array('all' => $all, 'targets' => $targets);
}

/** Υπάρχει ήδη εργασία αυτού του τύπου για αυτό το subject; */
function fuel_weekly_existing_id(PDO $db, $code, $subjectKey)
{
    $st = $db->prepare('SELECT `id` FROM `4a_tasks` WHERE `task_code` = ? AND `dedupe_key` = ?');
    $st->execute(array($code, $subjectKey));
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * Δημιουργεί fuel_weekly και fuel_weekly_verify για κάθε μελλοντική
 * εβδομάδα του cache που δεν έχει ήδη εργασία.
 *
 * Με $dryRun = true ΔΕΝ γράφει τίποτα: λέει μόνο τι θα έκανε.
 *
 * @return array [
 *   'invalid' => ?string     λόγος απόρριψης του cache, null αν είναι εντάξει
 *   'all'     => string[]    εβδομάδες στο cache
 *   'targets' => string[]    μελλοντικές εβδομάδες με air και road
 *   'actions' => [ ['week','code','status' => created|exists|would_create|error,
 *                   'task_id','due_at','prices','rows','error'] ]
 * ]
 */
function fuel_weekly_ensure(PDO $db, $cache, array $types, $todayAthens, $dryRun = false)
{
    $out = array('invalid' => null, 'all' => array(), 'targets' => array(), 'actions' => array());
    $why = fuel_cache_invalid($cache);
    if ($why !== null) { $out['invalid'] = $why; return $out; }

    $t = fuel_weekly_targets($cache, $todayAthens);
    $out['all'] = $t['all'];
    $out['targets'] = $t['targets'];

    foreach ($t['targets'] as $ws) {
        $subject = 'fuel:' . $ws;
        $payload = fuel_weekly_payload($db, $cache, $types, $ws);
        $due     = fuel_weekly_due_at($ws);
        foreach (array('fuel_weekly', 'fuel_weekly_verify') as $code) {
            $a = array('week' => $ws, 'code' => $code, 'status' => null, 'task_id' => null,
                       'due_at' => $due, 'prices' => $payload ? $payload['prices'] : null,
                       'rows' => $payload ? $payload['rows_count'] : null, 'error' => null);
            $existing = fuel_weekly_existing_id($db, $code, $subject);
            if ($existing !== null) {
                $a['status'] = 'exists'; $a['task_id'] = $existing;
            } elseif ($payload === null) {
                $a['status'] = 'error'; $a['error'] = 'χωρίς payload';
            } elseif ($dryRun) {
                $a['status'] = 'would_create';
            } else {
                $r = tasks_create_system($db, $code, $subject, $payload, 'BOTH', $due);
                $a['status']  = $r['created'] ? 'created' : ($r['error'] === null ? 'exists' : 'error');
                $a['task_id'] = $r['task_id'] !== null ? $r['task_id'] : fuel_weekly_existing_id($db, $code, $subject);
                $a['error']   = $r['error'];
            }
            $out['actions'][] = $a;
        }
    }
    return $out;
}

/**
 * Μία γραμμή log: ώρα, εβδομάδες που βρέθηκαν, εργασίες που δημιουργήθηκαν
 * ή «καμία νέα». ΚΑΝΕΝΑ στοιχείο σύνδεσης, κανένα μήνυμα βάσης.
 */
function fuel_weekly_log_line(array $r, $nowUtc, $dryRun = false)
{
    $p = array(gmdate('Y-m-d\TH:i:s\Z', strtotime($nowUtc)) . ($dryRun ? ' ΔΟΚΙΜΗ' : ''));
    if ($r['invalid'] !== null) {
        $p[] = 'cache άκυρο: ' . $r['invalid'];
        return implode(' · ', $p);
    }
    $p[] = 'εβδομάδες: ' . ($r['all'] ? implode(',', $r['all']) : '—');
    if (!$r['targets']) {
        $p[] = 'καμία νέα · ΚΑΘΥΣΤΕΡΗΣΗ: καμία μελλοντική εβδομάδα στο cache';
        return implode(' · ', $p);
    }
    $made = array(); $would = array(); $err = array();
    foreach ($r['actions'] as $a) {
        $tag = $a['code'] . ' ' . $a['week'];
        if ($a['status'] === 'created')      $made[]  = $tag . ' #' . $a['task_id'];
        if ($a['status'] === 'would_create') $would[] = $tag;
        if ($a['status'] === 'error')        $err[]   = $tag;
    }
    if ($made)  $p[] = 'δημιουργήθηκαν: ' . implode(', ', $made);
    if ($would) $p[] = 'θα δημιουργούνταν: ' . implode(', ', $would);
    if ($err)   $p[] = 'ΣΦΑΛΜΑ: ' . implode(', ', $err);
    if (!$made && !$would && !$err) $p[] = 'καμία νέα';
    return implode(' · ', $p);
}
