<?php
/* tests/php/api_errors.test.php
 *
 *   php tests/php/api_errors.test.php        (από τη ρίζα του repo)
 *
 * api/api_errors.php: κανένα σκέτο 500. Ελέγχει τις καθαρές συναρτήσεις
 * (γραμμή log, σώμα απάντησης, θέση του log) και ότι το tasks.php
 * εγκαθιστά τον χειριστή ΠΡΙΝ από το auth.php και το tasks_lib.php.
 * Καμία βάση, κανένα δίκτυο, τίποτα δεν γράφεται.
 * Έξοδος 1 αν έστω ένας έλεγχος πέσει.
 */

require_once __DIR__ . '/../../api/api_errors.php';

$pass = 0; $fail = 0;
function ck($label, $ok, $extra = '')
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("   %s %s%s\n", $ok ? 'OK   ' : 'ΛΑΘΟΣ', $label, $ok ? '' : "   → $extra");
}

echo "\n═══ γραμμή log: αρχείο:γραμμή, μία γραμμή ═══\n";
$l = api_errors_line('tasks', 'R1', 'PDOException',
                     "SQLSTATE[HY000]: General error: 1267\n  Illegal mix of collations",
                     '/x/y/public_html/api/tasks_lib.php', 726);
ck('έχει tasks_lib.php:726', strpos($l, '@ tasks_lib.php:726 ') !== false, $l);
ck('ΧΩΡΙΣ πλήρη διαδρομή', strpos($l, 'public_html') === false, $l);
ck('μία γραμμή', strpos($l, "\n") === false, $l);
ck('endpoint, ref, κλάση', strpos($l, '[tasks] ref=R1 PDOException') !== false, $l);
ck('το μήνυμα του σφάλματος', strpos($l, '1267 Illegal mix of collations') !== false, $l);
$long = api_errors_line('t', 'R', 'E', str_repeat('x', 5000), 'f.php', 1);
ck('μήνυμα κομμένο στους 1000', strlen($long) < 1200, (string)strlen($long));

echo "\n═══ σώμα 500: μήνυμα που διαβάζεται, κωδικός, καμία λεπτομέρεια ═══\n";
$p = api_errors_payload('R2');
ck('ok = false', $p['ok'] === false);
ck('ref', $p['ref'] === 'R2');
ck('error μη κενό, με τον κωδικό', is_string($p['error']) && strpos($p['error'], 'R2') !== false, $p['error']);
ck('λέει ότι ΔΕΝ είναι θέμα δικαιωμάτων', strpos($p['error'], 'όχι θέμα δικαιωμάτων') !== false, $p['error']);
ck('ΧΩΡΙΣ SQL/διαδρομή', !preg_match('/SQLSTATE|\.php|public_html/', $p['error']), $p['error']);
ck('json_encode δουλεύει', json_encode($p, JSON_UNESCAPED_UNICODE) !== false);

echo "\n═══ ref ═══\n";
$r1 = api_errors_ref(); $r2 = api_errors_ref();
ck('μορφή yymmdd-HHMMSS-6hex', (bool)preg_match('/^\d{6}-\d{6}-[0-9a-f]{6}$/', $r1), $r1);
ck('δύο ref διαφέρουν', $r1 !== $r2);

echo "\n═══ το log ΕΚΤΟΣ webroot ═══\n";
$f      = api_errors_log_file();
$apiDir = dirname((new ReflectionFunction('api_errors_log_file'))->getFileName());
$web    = dirname($apiDir);
ck('= <api>/../../4a_logs/api_errors.log', $f === dirname($apiDir, 2) . '/4a_logs/api_errors.log', $f);
ck('όχι μέσα στο api/', strpos($f, $apiDir . '/') !== 0, $f);
ck('όχι μέσα στο webroot', strpos($f, $web . '/') !== 0, $f);

echo "\n═══ tasks.php: ο χειριστής ΠΡΙΝ από auth.php και tasks_lib.php ═══\n";
$src = file_get_contents(__DIR__ . '/../../api/tasks.php');
$iCfg  = strpos($src, "_once 'config.php';");
$iErr  = strpos($src, "_once 'api_errors.php';");
$iInst = strpos($src, "api_errors_install('tasks');");
$iAuth = strpos($src, "_once 'auth.php';");
$iLib  = strpos($src, "_once 'tasks_lib.php';");
ck('υπάρχουν όλα', $iCfg !== false && $iErr !== false && $iInst !== false && $iAuth !== false && $iLib !== false);
ck('config < api_errors < install < auth < tasks_lib',
   $iCfg < $iErr && $iErr < $iInst && $iInst < $iAuth && $iAuth < $iLib);

printf("\n═══ ΣΥΝΟΛΟ: %d OK, %d ΛΑΘΟΣ ═══\n", $pass, $fail);
exit($fail ? 1 : 0);
