/* ui-texts.js | v1.0 | 15-10-2026
 *
 * Φάση Α, Α1 (Π1): τα κείμενα οθόνης από τη βάση (4a_ui_texts, migration
 * 2026-10-15b), μέσω του api/ui_texts.php. Κοινό για όλες τις σελίδες.
 *
 *   UiTexts.load(['tasks', 'common'])  -> Promise<bool>, ΠΟΤΕ δεν απορρίπτεται
 *   UiTexts.t('κλειδί', {n: 3})        -> κείμενο με {x} -> vars.x
 *   UiTexts.has('κλειδί')              -> αν ήρθε από τη βάση
 *
 * ΚΛΕΙΔΙ ΠΟΥ ΛΕΙΠΕΙ -> «[κλειδί]» (απόφαση 14/10). Κανένα κείμενο εφεδρείας
 * εδώ ή στις σελίδες: ελλιπές seed πρέπει να φαίνεται, όχι να κρύβεται.
 * Όποια σελίδα θέλει άλλη συμπεριφορά (π.χ. το σήμα: μόνο αριθμός) ρωτά
 * πρώτα το has().
 *
 * ΣΙΩΠΗΛΗ ΑΠΟΤΥΧΙΑ: σφάλμα δικτύου, 401 ή άκυρη απάντηση δίνουν false,
 * χωρίς μήνυμα και χωρίς logout. Το logout ανήκει στο apiFetch της σελίδας.
 *
 * Τα κλειδιά είναι μοναδικά σε ΟΛΑ τα scope (το ελέγχει τεστ πάνω στο
 * migration), άρα αποθηκεύονται σε ένα επίπεδο.
 */
var UiTexts = (function () {
  var API = 'https://4aexpress.com/api';
  var texts = {};
  var loaded = {};    // scope -> true, όταν ήρθε από τη βάση
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

  function merge(d) {
    if (!d || d.ok !== true || !d.texts || typeof d.texts !== 'object') return false;
    Object.keys(d.texts).forEach(function (s) {
      var kv = d.texts[s];
      if (!kv || typeof kv !== 'object') return;
      Object.keys(kv).forEach(function (k) { if (typeof kv[k] === 'string') texts[k] = kv[k]; });
      loaded[s] = true;
    });
    return true;
  }

  /** Φορτώνει όσα scope δεν έχουν φορτωθεί ήδη. true αν φορτώθηκαν όλα. */
  function load(scopes) {
    scopes = (scopes || []).filter(function (s) { return /^[a-z_]{1,20}$/.test(s); });
    var need = scopes.filter(function (s) { return !loaded[s] && !pending[s]; });
    if (need.length) {
      var tok = token();
      var p = !tok || typeof fetch !== 'function' ? Promise.resolve(false)
        : fetch(API + '/ui_texts.php?scope=' + need.join(',') + '&v=' + Date.now(),
                { headers: { 'Authorization': 'Bearer ' + tok } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(merge)
            .catch(function () { return false; });
      need.forEach(function (s) {
        pending[s] = p.then(function (ok) { delete pending[s]; return ok; });
      });
    }
    return Promise.all(scopes.map(function (s) { return loaded[s] ? true : pending[s]; }))
      .then(function (r) { return r.every(Boolean); });
  }

  function has(key) { return typeof texts[key] === 'string'; }

  function t(key, vars) { return has(key) ? fill(texts[key], vars) : '[' + key + ']'; }

  return { load: load, t: t, has: has,
           // Μόνο για τα τεστ: κείμενα όπως θα τα έδινε το endpoint.
           _merge: merge };
})();
