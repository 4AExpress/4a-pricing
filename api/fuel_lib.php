<?php
/* api/fuel_lib.php | v1.0 | 07-10-2026
 *
 * ΕΝΑ ΣΗΜΕΙΟ ΥΠΟΛΟΓΙΣΜΟΥ ΕΠΙΝΑΥΛΟΥ, ΣΤΟΝ SERVER.
 *
 * Το frontend ΔΕΝ ξαναϋπολογίζει τίποτα. Παίρνει έτοιμες τιμές και τις
 * εμφανίζει. Όποιος προσθέσει δεύτερο υπολογισμό αλλού, θα δημιουργήσει
 * δεύτερη αλήθεια που θα αποκλίνει στην πρώτη αλλαγή πολλαπλασιαστή.
 *
 * ΓΙΑΤΙ bcmath ΚΑΙ ΟΧΙ float: ο κανόνας είναι half-up στα 2 δεκαδικά, και
 * τα γινόμενα πέφτουν συχνά σε .xx5. Το 46.25 x 1.06 δίνει 49.025, που ΔΕΝ
 * είναι ακριβώς αναπαραστάσιμο σε δυαδικό. Σε float η στρογγυλοποίηση
 * εξαρτάται από το αν το σφάλμα αναπαράστασης έπεσε πάνω ή κάτω από το
 * μισό, δηλαδή από την τύχη. Μετρήθηκε στην παραγωγή: το float έδωσε
 * 49.025000000000006, οπότε τύχαινε να βγει σωστό. Δεν βασιζόμαστε σε αυτό.
 *
 * Καμία εξάρτηση από config.php ή από βάση για τις συναρτήσεις
 * υπολογισμού, ώστε να δοκιμάζονται χωρίς σύνδεση.
 */

if (!function_exists('fuel_round')) {

/**
 * Στρογγυλοποίηση half-up σε σταθερά δεκαδικά, με ακριβή δεκαδική
 * αριθμητική. Επιστρέφει string, ποτέ float, ώστε να μη χαθεί η ακρίβεια
 * στο επόμενο βήμα.
 *
 * @return string|null  null όταν η είσοδος δεν είναι αριθμός
 */
function fuel_round($value, $dp = 2)
{
    if ($value === null || $value === '') return null;
    // Οι float περνούν από sprintf με περίσσεια δεκαδικών: έτσι η τιμή
    // μπαίνει στη bcmath ως δεκαδικό string και όχι ως δυαδικό κλάσμα.
    $s = is_string($value) ? trim($value) : sprintf('%.12F', (float)$value);
    if (!is_numeric($s)) return null;

    $half = '0.' . str_repeat('0', (int)$dp) . '5';
    $neg  = (bccomp($s, '0', 12) < 0);
    // Το bcadd με scale $dp ΑΠΟΚΟΠΤΕΙ. Πρόσθεση του μισού πριν την
    // αποκοπή ισοδυναμεί με half-up, μακριά από το μηδέν.
    return bcadd($s, $neg ? '-' . $half : $half, (int)$dp);
}

/**
 * Η τιμή ενός τύπου επίναυλου: ποσοστό πηγής επί πολλαπλασιαστή.
 * Το ΜΟΝΟ σημείο όπου εφαρμόζεται ο πολλαπλασιαστής.
 *
 * @param  mixed $sourcePct  το air ή road του cache
 * @param  mixed $multiplier το 4a_fuel_types.multiplier
 * @return string|null       null όταν ο τύπος δεν παίρνει επίναυλο
 */
function fuel_pct($sourcePct, $multiplier)
{
    if ($sourcePct === null || $multiplier === null) return null;
    $src = is_string($sourcePct) ? trim($sourcePct) : sprintf('%.12F', (float)$sourcePct);
    $mul = is_string($multiplier) ? trim($multiplier) : sprintf('%.12F', (float)$multiplier);
    if (!is_numeric($src) || !is_numeric($mul)) return null;
    return fuel_round(bcmul($src, $mul, 12), 2);
}

/**
 * Μορφή αριθμού για το αρχείο CMS: έως δύο δεκαδικά, χωρίς μηδενικά στο
 * τέλος, τελεία ως υποδιαστολή.
 *
 * 48 -> "48"   ·   40.5 -> "40.5"   ·   40.75 -> "40.75"
 *
 * @return string|null
 */
function fuel_num($value)
{
    $r = fuel_round($value, 2);
    if ($r === null) return null;
    if (strpos($r, '.') !== false) {
        $r = rtrim($r, '0');
        $r = rtrim($r, '.');
    }
    return $r === '' || $r === '-' ? '0' : $r;
}

} // function_exists
