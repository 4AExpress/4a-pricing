<?php
// ─────────────────────────────────────────────────────────────────────
// time_lib.php — ΖΩΝΗ ΩΡΑΣ, ΜΙΑ ΠΗΓΗ (απόφαση 21/10, εύρημα #109)
//
// Σύμβαση:
//   · στιγμές γεγονότων (4a_task_events.created_at, 4a_tasks.created_at,
//     closed_at, ό,τι γράφει η MySQL με NOW() / CURRENT_TIMESTAMP)
//     = UTC στη βάση (η σύνδεση τρέχει σε SYSTEM = UTC, έλεγχος 21/10)
//   · προθεσμίες και ημερολόγιο (due_at, «Δευ 00:00» του week_start)
//     = ώρα Αθήνας
//   · ΚΑΘΕ σύγκριση ανάμεσα στα δύο, και κάθε «τώρα», περνά από εδώ
//     (DateTimeZone με όνομα: σωστό και μετά την αλλαγή ώρας 25/10 03:00).
//   · προς την οθόνη: οι στιγμές φεύγουν ΗΔΗ σε ώρα Αθήνας
//     (time_db_to_local)· το due_at φεύγει όπως είναι.
//
// Καμία εξάρτηση από τη date.timezone της PHP ή την time_zone της MySQL:
// κάθε DateTime εδώ έχει ρητή ζώνη.
// ─────────────────────────────────────────────────────────────────────

if (!defined('TIME_DB_TZ'))    define('TIME_DB_TZ', 'UTC');
if (!defined('TIME_LOCAL_TZ')) define('TIME_LOCAL_TZ', 'Europe/Athens');

if (!function_exists('time_instant')) {

/**
 * «Y-m-d H:i:s» (ή «Y-m-d H:i») -> στιγμή, στη ζώνη $where: 'db' (UTC) ή
 * 'local' (Αθήνα). null αν το κείμενο δεν είναι τέτοια ημερομηνία.
 */
function time_instant($s, $where)
{
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $s)) return null;
    $tz = new DateTimeZone($where === 'db' ? TIME_DB_TZ : TIME_LOCAL_TZ);
    $d = DateTimeImmutable::createFromFormat(strlen($s) === 16 ? '!Y-m-d H:i' : '!Y-m-d H:i:s', $s, $tz);
    $e = DateTimeImmutable::getLastErrors();
    if (!$d || ($e && ($e['warning_count'] || $e['error_count']))) return null;
    return $d;
}

/** «Τώρα» ως στιγμή. $nowDb: «Y-m-d H:i:s» σε UTC, μόνο για τα τεστ. */
function time_now($nowDb = null)
{
    if ($nowDb === null) return new DateTimeImmutable('now', new DateTimeZone(TIME_DB_TZ));
    $d = time_instant((string)$nowDb, 'db');
    if ($d === null) throw new InvalidArgumentException('time_now: άκυρη ώρα ' . $nowDb);
    return $d;
}

/** -1 / 0 / 1: η στιγμή $a πριν / ίδια / μετά από τη $b. */
function time_cmp(DateTimeInterface $a, DateTimeInterface $b)
{
    return $a->getTimestamp() <=> $b->getTimestamp();
}

/**
 * Στιγμή της βάσης (UTC) -> ώρα Αθήνας «Y-m-d H:i:s», για την οθόνη.
 * null / κενό -> null. Κείμενο που δεν είναι ημερομηνία: όπως ήρθε
 * (καμία σιωπηλή αλλοίωση δεδομένων).
 */
function time_db_to_local($s)
{
    if ($s === null || $s === '') return null;
    $d = time_instant((string)$s, 'db');
    if ($d === null) return $s;
    return $d->setTimezone(new DateTimeZone(TIME_LOCAL_TZ))->format('Y-m-d H:i:s');
}

/** Η σημερινή ημερομηνία στην Αθήνα, «Y-m-d». */
function time_local_today($nowDb = null)
{
    return time_now($nowDb)->setTimezone(new DateTimeZone(TIME_LOCAL_TZ))->format('Y-m-d');
}

/**
 * Ήρθε (ή πέρασε) η ώρα Αθήνας $local; Π.χ. Δ-Α8: «Δευ 00:00» του
 * week_start. $nowDb όπως στο time_now. null αν το $local είναι άκυρο.
 */
function time_reached_local($local, $nowDb = null)
{
    $at = time_instant((string)$local, 'local');
    if ($at === null) return null;
    return time_cmp(time_now($nowDb), $at) >= 0;
}

/**
 * Έγινε η στιγμή της βάσης $db ΜΕΤΑ από την προθεσμία $local (ώρα Αθήνας);
 * Εκπρόθεσμο: κλείσιμο 17:30 UTC (= 20:30 Αθήνα) με προθεσμία 19:00 -> true.
 * null αν κάποιο από τα δύο είναι άκυρο.
 */
function time_db_after_local($db, $local)
{
    $a = time_instant((string)$db, 'db');
    $b = time_instant((string)$local, 'local');
    if ($a === null || $b === null) return null;
    return time_cmp($a, $b) > 0;
}

} // function_exists
