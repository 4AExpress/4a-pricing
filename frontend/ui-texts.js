/* ui-texts.js | v1.1 | 15-10-2026
 *
 * Φάση Α, Α1 (Π1): τα κείμενα οθόνης από τη βάση (4a_ui_texts, migration
 * 2026-10-15b), μέσω του api/ui_texts.php. Κοινό για όλες τις σελίδες.
 *
 *   UiTexts.load(['tasks', 'common'])  -> Promise<bool>, ΠΟΤΕ δεν απορρίπτεται
 *   UiTexts.t('κλειδί', {n: 3})        -> κείμενο με {x} -> vars.x
 *   UiTexts.has('κλειδί')              -> αν υπάρχει κείμενο (βάση ή αντίγραφο)
 *   UiTexts.source('tasks')            -> 'server' | 'copy' | null
 *
 * ΑΝΤΙΓΡΑΦΟ (v1.1, 15/10): κάθε επιτυχημένη απάντηση του server γράφεται στο
 * localStorage ανά scope ('4a_ui_texts:<scope>' = {v, saved_at, texts}).
 * Αν ο server αποτύχει (δίκτυο, 401, άκυρη απάντηση, χωρίς συνεδρία), το
 * scope παίρνει το τελευταίο αντίγραφο. Το αντίγραφο ΔΕΝ μετρά ως φόρτωση:
 * το επόμενο load() ξαναρωτά τον server. Το αντίγραφο είναι ό,τι έδωσε η
 * βάση, όχι κείμενο του κώδικα.
 *
 * ΚΛΕΙΔΙ ΠΟΥ ΛΕΙΠΕΙ -> «[κλειδί]» (απόφαση 14/10), ΜΟΝΟ όταν δεν υπάρχει ούτε
 * αντίγραφο. Κανένα κείμενο εφεδρείας εδώ ή στις σελίδες: ελλιπές seed
 * πρέπει να φαίνεται, όχι να κρύβεται. Όποια σελίδα θέλει άλλη συμπεριφορά
 * (π.χ. το σήμα: μόνο αριθμός) ρωτά πρώτα το has().
 *
 * ΣΙΩΠΗΛΗ ΑΠΟΤΥΧΙΑ: χωρίς μήνυμα και χωρίς logout. Το logout ανήκει στο
 * apiFetch της σελίδας. localStorage που πετάει (private mode, μπλοκαρισμένα
 * site data) = κανένα αντίγραφο.
 *
 * Τα κλειδιά είναι μοναδικά σε ΟΛΑ τα scope (το ελέγχει τεστ πάνω στο
 * migration), άρα αποθηκεύονται σε ένα επίπεδο.
 */
var UiTexts = (function () {
  var API = 'https://4aexpress.com/api';
  var COPY_PREFIX = '4a_ui_texts:';
  var COPY_V = 1;
  var texts = {};
  var loaded = {};    // scope -> true, όταν ήρθε από τη βάση
  var copied = {};    // scope -> true, όταν ήρθε από το αντίγραφο
  var pending = {};   // scope -> Promise σε εξέλιξη

  function token() {
    try {
      var u = sessionStorage.getItem('4a_current_user');
      return u ? (JSON.parse(u)._token || '') : '';
    } catch (e) { return ''; }
  }

  function fill(s, vars) {
    return String(s).replace(/\{(\w+)\}/g, function (m, k) {
      return (vars && vars[k] !== undefined && vars[k] !== null) ? String(vars[k]) : m;
    });
  }

  function put(kv) {
    Object.keys(kv).forEach(function (k) { if (typeof kv[k] === 'string') texts[k] = kv[k]; });
  }

  function saveCopy(s, kv) {
    try {
      localStorage.setItem(COPY_PREFIX + s,
        JSON.stringify({ v: COPY_V, saved_at: new Date().toISOString(), texts: kv }));
    } catch (e) {}
  }

  function readCopy(s) {
    try {
      var c = JSON.parse(localStorage.getItem(COPY_PREFIX + s) || 'null');
      return c && c.v === COPY_V && c.texts && typeof c.texts === 'object' ? c.texts : null;
    } catch (e) { return null; }
  }

  /** Απάντηση του endpoint -> κείμενα. save: γράψε και αντίγραφο. */
  function merge(d, save) {
    if (!d || d.ok !== true || !d.texts || typeof d.texts !== 'object') return false;
    Object.keys(d.texts).forEach(function (s) {
      var kv = d.texts[s];
      if (!kv || typeof kv !== 'object') return;
      put(kv);
      loaded[s] = true;
      delete copied[s];
      if (save === true) saveCopy(s, kv);
    });
    return true;
  }

  /** Ο server απέτυχε: όσα scope έχουν αντίγραφο το παίρνουν. */
  function fallback(scopes) {
    scopes.forEach(function (s) {
      if (loaded[s]) return;
      var kv = readCopy(s);
      if (kv) { put(kv); copied[s] = true; }
    });
  }

  /** Φορτώνει όσα scope δεν έχουν φορτωθεί ήδη από τη βάση. true αν όλα
   *  έχουν κείμενα (από τη βάση ή από αντίγραφο). */
  function load(scopes) {
    scopes = (scopes || []).filter(function (s) { return /^[a-z_]{1,20}$/.test(s); });
    var need = scopes.filter(function (s) { return !loaded[s] && !pending[s]; });
    if (need.length) {
      var tok = token();
      var p = (!tok || typeof fetch !== 'function' ? Promise.resolve(false)
        : fetch(API + '/ui_texts.php?scope=' + need.join(',') + '&v=' + Date.now(),
                { headers: { 'Authorization': 'Bearer ' + tok } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { return merge(d, true); })
            .catch(function () { return false; }))
        .then(function (ok) { if (!ok) fallback(need); });
      need.forEach(function (s) {
        pending[s] = p.then(function () { delete pending[s]; });
      });
    }
    return Promise.all(scopes.map(function (s) { return pending[s]; }))
      .then(function () { return scopes.every(function (s) { return loaded[s] || copied[s]; }); });
  }

  function has(key) { return typeof texts[key] === 'string'; }

  function t(key, vars) { return has(key) ? fill(texts[key], vars) : '[' + key + ']'; }

  function source(s) { return loaded[s] ? 'server' : copied[s] ? 'copy' : null; }

  return { load: load, t: t, has: has, source: source,
           // Μόνο για τα τεστ: κείμενα όπως θα τα έδινε το endpoint (χωρίς αντίγραφο).
           _merge: merge };
})();
