<?php
/* tests/php/fuel_lib.test.php
 *
 *   php tests/php/fuel_lib.test.php        (από τη ρίζα του repo)
 *
 * Καμία βάση, κανένα δίκτυο, κανένα config. Δοκιμάζει τις ΠΡΑΓΜΑΤΙΚΕΣ
 * συναρτήσεις του api/fuel_lib.php. Έξοδος 1 αν έστω ένας έλεγχος πέσει.
 */

require_once __DIR__ . '/../../api/fuel_lib.php';

$pass = 0; $fail = 0;
function ck($label, $got, $want) {
    global $pass, $fail;
    $ok = ($got === $want);
    $ok ? $pass++ : $fail++;
    printf("   %s %-46s got=%-10s want=%s\n",
        $ok ? 'OK   ' : 'ΛΑΘΟΣ',
        $label,
        $got === null ? 'NULL' : "'$got'",
        $want === null ? 'NULL' : "'$want'");
}

echo "\nbcmath: " . (function_exists('bcmul') ? 'ΥΠΑΡΧΕΙ' : '*** ΛΕΙΠΕΙ ***') . "\n";

echo "\n═══ fuel_pct: AIR_CY = AIR x 1.06, half-up στα 2 δεκαδικά ═══\n";
ck('46.25 x 1.06  (49.025 -> half-up)', fuel_pct(46.25, '1.0600'), '49.03');
ck('43.75 x 1.06  (46.375 -> half-up)', fuel_pct(43.75, '1.0600'), '46.38');
ck('48    x 1.06',                      fuel_pct(48,    '1.0600'), '50.88');
ck('49    x 1.06',                      fuel_pct(49,    '1.0600'), '51.94');

echo "\n═══ fuel_pct: πολλαπλασιαστής 1.0000 δεν αλλάζει τιμή ═══\n";
ck('48    x 1.00', fuel_pct(48,    '1.0000'), '48.00');
ck('40.75 x 1.00', fuel_pct(40.75, '1.0000'), '40.75');
ck('46.25 x 1.00', fuel_pct(46.25, '1.0000'), '46.25');

echo "\n═══ fuel_pct: ο τύπος NONE δεν παίρνει επίναυλο ═══\n";
ck('source NULL',     fuel_pct(null, '1.0000'), null);
ck('multiplier NULL', fuel_pct(48,   null),     null);
ck('και τα δύο NULL', fuel_pct(null, null),     null);

echo "\n═══ fuel_round: half-up, όχι στρογγυλοποίηση τραπεζίτη ═══\n";
ck('49.025 -> 49.03', fuel_round('49.025'), '49.03');
ck('49.015 -> 49.02', fuel_round('49.015'), '49.02');
ck('49.024 -> 49.02', fuel_round('49.024'), '49.02');
ck('49.026 -> 49.03', fuel_round('49.026'), '49.03');
ck('0.001  -> 0.00',  fuel_round('0.001'),  '0.00');
ck('μη αριθμός',      fuel_round('abc'),    null);
ck('κενό',            fuel_round(''),       null);

echo "\n═══ fuel_num: μορφή για το αρχείο CMS ═══\n";
ck('48    -> "48"',    fuel_num(48),      '48');
ck('40.75 -> "40.75"', fuel_num(40.75),   '40.75');
ck('50.88 -> "50.88"', fuel_num('50.88'), '50.88');
ck('49.03 -> "49.03"', fuel_num('49.03'), '49.03');
ck('40.5  -> "40.5"',  fuel_num(40.5),    '40.5');
ck('40.00 -> "40"',    fuel_num('40.00'), '40');
ck('0     -> "0"',     fuel_num(0),       '0');
ck('NULL  -> NULL',    fuel_num(null),    null);

echo "\n═══ ΑΛΥΣΙΔΑ: υπολογισμός και μετά μορφοποίηση ═══\n";
ck('AIR 48 -> AIR_CY -> αρχείο', fuel_num(fuel_pct(48, '1.0600')), '50.88');
ck('ROAD 40 -> ROAD -> αρχείο',  fuel_num(fuel_pct(40, '1.0000')), '40');
ck('AIR 46.25 -> AIR_CY',        fuel_num(fuel_pct(46.25, '1.0600')), '49.03');

printf("\n═══ ΣΥΝΟΛΟ: %d OK, %d ΛΑΘΟΣ ═══\n\n", $pass, $fail);
exit($fail ? 1 : 0);
