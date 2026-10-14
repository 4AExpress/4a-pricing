<?php
// api_errors.php | v1.0 | 14-10-2026
// ΚΑΝΕΝΑ ΣΚΕΤΟ 500. Σφάλμα που ξεφεύγει από endpoint (exception ή fatal)
// απαντά JSON 500 με μήνυμα που διαβάζεται και κωδικό αναφοράς, και
// γράφεται σε ΔΙΚΟ ΜΑΣ log με αρχείο:γραμμή.
//
// Αφορμή (13/10): το tasks.php έριχνε PDOException (collation) πριν από το
// respond(). Ο browser έπαιρνε 500 με 0 bytes, και το log της PHP
// (error_log = php_errorlog, σχετική διαδρομή) δεν βρέθηκε πουθενά.
//
// Το log ζει ΕΚΤΟΣ webroot: δύο επίπεδα πάνω από το api/, στον φάκελο
// 4a_logs/. Αν δεν γράφεται, πέφτει στο error_log() της PHP.
//
// Η άρνηση δικαιώματος ΔΕΝ περνά από εδώ: μένει 403 με μήνυμα, από το
// auth.php και τα tasks_err(). Εδώ φτάνει μόνο ό,τι είναι σφάλμα κώδικα
// ή βάσης. Ο πελάτης ΔΕΝ βλέπει SQL ούτε διαδρομές: μόνο τον κωδικό.
//
// Χρήση, αμέσως μετά το config.php:
//   require_once 'api_errors.php';
//   api_errors_install('tasks');

if (!function_exists('api_errors_install')) {

/** Το αρχείο του log, εκτός webroot. */
function api_errors_log_file()
{
    return dirname(__DIR__, 2) . '/4a_logs/api_errors.log';
}

/** Κωδικός αναφοράς: ώρα UTC + 6 hex. Ίδιος στο μήνυμα και στο log. */
function api_errors_ref()
{
    return gmdate('ymd-His') . '-' . bin2hex(random_bytes(3));
}

/**
 * Μία γραμμή log. Καθαρή συνάρτηση.
 * Ώρα UTC · endpoint · ref · κλάση · αρχείο:γραμμή · μήνυμα σε ΜΙΑ γραμμή.
 */
function api_errors_line($tag, $ref, $class, $message, $file, $line)
{
    $msg = preg_replace('/\s+/', ' ', (string)$message);
    if (strlen($msg) > 1000) $msg = substr($msg, 0, 1000) . '…';
    return gmdate('Y-m-d H:i:s') . ' UTC'
         . ' [' . $tag . '] ref=' . $ref
         . ' ' . $class
         . ' @ ' . basename((string)$file) . ':' . (int)$line
         . ' ' . $msg;
}

/** Το σώμα της απάντησης 500. Καθαρή συνάρτηση: μόνο ο κωδικός, καμία λεπτομέρεια. */
function api_errors_payload($ref)
{
    return [
        'ok'    => false,
        'error' => 'Σφάλμα διακομιστή (όχι θέμα δικαιωμάτων). '
                 . 'Δοκίμασε ξανά σε λίγο· αν επιμένει, στείλε στον διαχειριστή τον κωδικό ' . $ref . '.',
        'ref'   => $ref,
    ];
}

// Όριο μεγέθους: πάνω από MAX το αρχείο ξαναγράφεται με τα τελευταία KEEP
// bytes (από αρχή γραμμής). Φάκελος 700, αρχείο 600.
if (!defined('API_ERRORS_MAX'))  define('API_ERRORS_MAX', 5 * 1024 * 1024);
if (!defined('API_ERRORS_KEEP')) define('API_ERRORS_KEEP', 1024 * 1024);

/**
 * Το τελευταίο κομμάτι ενός log, από αρχή γραμμής. Καθαρή συνάρτηση.
 * Αν χωρά ολόκληρο, επιστρέφεται αυτούσιο.
 */
function api_errors_tail($text, $keep)
{
    if (strlen($text) <= $keep) return $text;
    $t  = substr($text, -$keep);
    $nl = strpos($t, "\n");
    $t  = $nl === false ? '' : substr($t, $nl + 1);
    return gmdate('Y-m-d H:i:s') . " UTC [api_errors] -- κόπηκε, κρατήθηκαν τα τελευταία " . strlen($t) . " bytes --\n" . $t;
}

function api_errors_write($line)
{
    $f = api_errors_log_file();
    $d = dirname($f);
    if (!is_dir($d)) @mkdir($d, 0700, true);
    @chmod($d, 0700);
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > API_ERRORS_MAX) {
        $tail = @file_get_contents($f, false, null, max(0, filesize($f) - API_ERRORS_KEEP - 4096));
        if ($tail !== false) @file_put_contents($f, api_errors_tail($tail, API_ERRORS_KEEP), LOCK_EX);
    }
    $ok = @file_put_contents($f, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
    if ($ok) @chmod($f, 0600);
    else     error_log($line);
}

function api_errors_send($ref)
{
    if (headers_sent()) return;
    if (function_exists('respond')) respond(api_errors_payload($ref), 500);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(api_errors_payload($ref), JSON_UNESCAPED_UNICODE);
    exit;
}

function api_errors_install($tag)
{
    set_exception_handler(function ($e) use ($tag) {
        $ref = api_errors_ref();
        api_errors_write(api_errors_line($tag, $ref, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        api_errors_send($ref);
    });
    register_shutdown_function(function () use ($tag) {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        $ref = api_errors_ref();
        api_errors_write(api_errors_line($tag, $ref, 'Fatal', $e['message'], $e['file'], $e['line']));
        api_errors_send($ref);
    });
}

} // function_exists
