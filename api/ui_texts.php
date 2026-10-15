<?php
// ui_texts.php | v1.1 | 15-10-2026
// Φάση Α, Α1 (Π1). Τα κείμενα οθόνης από τον 4a_ui_texts.
//
// GET ?scope=tasks,common  -> {ok, texts: {tasks: {k: text}, common: {…}}}
//
// Κάθε συνδεδεμένος χρήστης (require_user): τα κείμενα δεν είναι μυστικά,
// αλλά οι σελίδες που τα ζητούν είναι πίσω από login. Κανένα scope δεν
// χρειάζεται πριν το login. Χωρίς token -> 401 ΠΡΙΝ από κάθε άλλο έλεγχο
// (μέθοδος, scope), όπως τα υπόλοιπα (v1.1). Λεπτό endpoint: η
// λογική ζει στο ui_texts_lib.php. ΚΑΝΕΝΑ DDL: ο πίνακας έρχεται από το
// migration 2026-10-15b.
error_reporting(E_ALL);
ini_set('log_errors', 1);
require_once 'config.php';
require_once 'api_errors.php';
api_errors_install('ui_texts');
require_once 'auth.php';
require_once 'ui_texts_lib.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }

require_user();

if ($method !== 'GET') respond(['ok' => false, 'error' => 'μόνο GET'], 405);

$scopes = ui_texts_scopes(isset($_GET['scope']) ? $_GET['scope'] : null);
if ($scopes === null) {
    respond(['ok' => false, 'error' => 'άκυρο scope: έως ' . UI_TEXTS_MAX_SCOPES . ', μόνο [a-z_]'], 400);
}

$texts = ui_texts_fetch(db(), $scopes);
// Άδειο scope ως {} και όχι [], ώστε η σελίδα να βλέπει πάντα αντικείμενο.
foreach ($texts as $s => $kv) $texts[$s] = (object)$kv;
respond(['ok' => true, 'texts' => (object)$texts]);
