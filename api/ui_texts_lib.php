<?php
// ui_texts_lib.php | v1.0 | 15-10-2026
// Φάση Α, Α1 (Π1): κείμενα οθόνης από τον 4a_ui_texts (migration
// 2026-10-15b). Καθαρές συναρτήσεις που δέχονται PDO: τίποτα δεν εκτελείται
// κατά το include, δοκιμάζονται σε SQLite (tests/php/ui_texts.test.php).
//
// Χρώμα (kind = color) που δεν είναι #rrggbb ΔΕΝ επιστρέφεται: η σελίδα το
// βλέπει σαν κλειδί που λείπει. Το CHECK του migration το φράζει ήδη στη
// βάση· εδώ είναι η δεύτερη γραμμή, για βάση χωρίς CHECK.

if (!function_exists('ui_texts_scopes')) {

if (!defined('UI_TEXTS_MAX_SCOPES')) define('UI_TEXTS_MAX_SCOPES', 5);

/**
 * «tasks,common» -> ['tasks', 'common']. null αν είναι άκυρο: κενό,
 * περισσότερα από UI_TEXTS_MAX_SCOPES, ή scope εκτός [a-z_]{1,20}.
 * Διπλά scope μετρούν μία φορά, η σειρά κρατιέται.
 */
function ui_texts_scopes($raw)
{
    if (!is_string($raw)) return null;
    $out = [];
    foreach (explode(',', $raw) as $s) {
        $s = trim($s);
        if (!preg_match('/^[a-z_]{1,20}$/', $s)) return null;
        if (!in_array($s, $out, true)) $out[] = $s;
    }
    if (!$out || count($out) > UI_TEXTS_MAX_SCOPES) return null;
    return $out;
}

/** Δεκτό χρώμα: ΜΟΝΟ #rrggbb. */
function ui_texts_color_ok($text)
{
    return is_string($text) && preg_match('/^#[0-9A-Fa-f]{6}\z/', $text) === 1;
}

/**
 * Τα κείμενα των $scopes: ['scope' => ['k' => text]]. Κάθε ζητούμενο scope
 * υπάρχει στο αποτέλεσμα, και άδειο.
 */
function ui_texts_fetch($db, array $scopes)
{
    $out = [];
    foreach ($scopes as $s) $out[$s] = [];
    if (!$scopes) return $out;
    $st = $db->prepare('SELECT `scope`, `k`, `kind`, `text` FROM `4a_ui_texts`
                         WHERE `scope` IN (' . implode(',', array_fill(0, count($scopes), '?')) . ')
                         ORDER BY `scope`, `k`');
    $st->execute(array_values($scopes));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['kind'] === 'color' && !ui_texts_color_ok($r['text'])) {
            error_log('ui_texts: άκυρο χρώμα ' . $r['scope'] . '.' . $r['k']);
            continue;
        }
        $out[$r['scope']][$r['k']] = $r['text'];
    }
    return $out;
}

} // function_exists
