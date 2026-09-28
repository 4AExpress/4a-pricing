/* fuel-freshness.js | v1.0 | 27-09-2026
 *
 * Ένας κανόνας εγκυρότητας για τον επίναυλο καυσίμων, σε ένα αρχείο.
 * Προδιαγραφή: docs/fuel_freshness_spec.md
 *
 * ΤΟ ΚΡΙΤΗΡΙΟ ΕΙΝΑΙ Η ΕΒΔΟΜΑΔΑ, ΟΧΙ ΟΙ ΩΡΕΣ. Η τιμή είναι έγκυρη αν η
 * εβδομάδα της περιέχει τη σημερινή ημερομηνία. Αρχείο 40 ωρών αλλά
 * σωστής εβδομάδας = έγκυρο. Αρχείο 2 ωρών αλλά περασμένης = άκυρο.
 *
 * ΑΡΧΕΣ — κάθε μία υπάρχει για συγκεκριμένο λόγο:
 *
 *  1. ΠΟΤΕ ΔΕΝ ΜΠΛΟΚΑΡΕΙ. Άκυρη τιμή επιστρέφεται κανονικά, με σημαία.
 *     Ο πωλητής με παλιό νούμερο και κόκκινη προειδοποίηση είναι σε
 *     καλύτερη θέση από τον πωλητή με παύλα.
 *
 *  2. ΔΕΝ ΑΛΛΑΖΕΙ ΠΟΤΕ ΤΗΝ ΤΙΜΗ ΠΟΥ ΠΑΙΡΝΕΙ Ο ΚΑΛΩΝ. Επιστρέφει ό,τι θα
 *     έβρισκε και ο σημερινός κώδικας, ΣΥΝ την κατάσταση. Αν το banner()
 *     δεν κληθεί ποτέ, η σελίδα συμπεριφέρεται ακριβώς όπως πριν — γι'
 *     αυτό η ενσωμάτωση είναι αναστρέψιμη ανά σελίδα.
 *
 *  3. Ο ΕΛΕΓΧΟΣ ΤΡΕΧΕΙ ΣΕ ΚΑΘΕ ΧΡΗΣΗ, όχι μία φορά στη φόρτωση. Καρτέλα
 *     ανοιχτή από Παρασκευή σε Δευτέρα πρωί πρέπει να γίνει κόκκινη
 *     χωρίς refresh.
 *
 *  4. ΤΟ CACHE ΔΕΝ ΔΙΑΓΡΑΦΕΤΑΙ ΠΟΤΕ, ΣΗΜΑΔΕΥΕΤΑΙ. Χωρίς δίκτυο, το παλιό
 *     αντίγραφο είναι το μόνο που έχουμε.
 *
 *  5. ΤΟ ΔΙΚΤΥΟ ΔΟΚΙΜΑΖΕΤΑΙ ΟΤΑΝ ΤΟ CACHE ΔΕΝ ΕΙΝΑΙ ΕΓΚΥΡΟ — όχι μόνο
 *     όταν λείπει. Το αντίστροφο ήταν το πραγματικό σφάλμα: ένα cache
 *     που γέμισε μια φορά δεν ανανεωνόταν ποτέ.
 *
 *  6. ΤΙΠΟΤΑ ΑΠΟ ΕΔΩ ΔΕΝ ΠΑΕΙ ΣΤΟ PDF. Μόνο οθόνη.
 */
(function () {
  'use strict';

  if (window.FuelFreshness) return;

  var URL_JSON = 'https://raw.githubusercontent.com/4AExpress/4a-pricing/main/data/fuel_surcharge_cache.json';
  var LS_KEY   = '4a_fuel_cache';
  var DASH     = 'fuel-surcharge-dashboard.html';

  var OK = 'ok', STALE = 'stale', FALLBACK = 'fallback';
  var RANK = { ok: 0, stale: 1, fallback: 2 };

  /* ── Ημερομηνία ────────────────────────────────────────────────────
     Τοπική ώρα του browser, σε YYYY-MM-DD. Η σύγκριση γίνεται με
     αλφαβητική σύγκριση συμβολοσειρών: για ISO ημερομηνίες είναι
     ισοδύναμη με χρονολογική και δεν έχει ζώνες ώρας να χαλάσουν. */
  function todayISO(d) {
    d = d || new Date();
    var m = d.getMonth() + 1, day = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }

  function contains(row, iso) {
    return !!(row && row.week_start && row.week_end &&
              row.week_start <= iso && iso <= row.week_end);
  }

  /* ── Επιλογή γραμμής ανά τρόπο (air / road) ───────────────────────
     Σειρά προτίμησης:
       1. η εβδομάδα που ΠΕΡΙΕΧΕΙ το σήμερα          → ok
       2. η σημαδεμένη is_current (παλιό cache χωρίς
          ημερομηνίες, ή πίνακας που δεν καλύπτει
          το σήμερα)                                  → stale
       3. η θέση i==1, όπως έκανε ο παλιός κώδικας    → stale
     Το is_current ΔΕΝ είναι αυθεντικό: το γράφει ο scraper την ώρα της
     σάρωσης, εμείς ρωτάμε την ώρα της χρήσης. */
  function pick(rows, iso) {
    if (!rows || !rows.length) return { row: null, state: FALLBACK, reason: 'κενή λίστα' };

    var i, hits = [];
    for (i = 0; i < rows.length; i++) if (contains(rows[i], iso)) hits.push(rows[i]);
    if (hits.length === 1) return { row: hits[0], state: OK, reason: '' };
    if (hits.length > 1)
      return { row: hits[0], state: STALE, reason: hits.length + ' εβδομάδες περιέχουν τη σημερινή ημερομηνία' };

    var dated = false;
    for (i = 0; i < rows.length; i++) if (rows[i] && rows[i].week_start) { dated = true; break; }

    var marked = null;
    for (i = 0; i < rows.length; i++) if (rows[i] && rows[i].is_current) { marked = rows[i]; break; }
    var row = marked || rows[Math.min(1, rows.length - 1)];

    return {
      row: row,
      state: STALE,
      reason: dated
        ? 'καμία εβδομάδα του αρχείου δεν περιέχει τη σημερινή ημερομηνία'
        : 'το αρχείο δεν έχει ημερομηνίες εβδομάδας'
    };
  }

  function worse(a, b) { return RANK[a] >= RANK[b] ? a : b; }

  /* ── check(): καθαρή. Καμία παρενέργεια, κανένα δίκτυο. ──────────── */
  function check(data, now) {
    var iso = typeof now === 'string' ? now : todayISO(now);

    if (!data || !data.air || !data.air.length) {
      return {
        state: FALLBACK, reason: 'δεν υπάρχουν δεδομένα επίναυλου',
        air: null, road: null, week: null, week_start: null, week_end: null,
        fetched_at: (data && data.fetched_at) || null,
        parse_warnings: (data && data.parse_warnings) || [],
        today: iso,
        data: data || null
      };
    }

    var a = pick(data.air, iso);
    var r = (data.road && data.road.length) ? pick(data.road, iso)
                                            : { row: null, state: OK, reason: '' };

    var reasons = [];
    if (a.reason) reasons.push('AIR: ' + a.reason);
    if (r.reason) reasons.push('ROAD: ' + r.reason);

    return {
      state:      worse(a.state, r.state),
      reason:     reasons.join(' · '),
      air:        a.row ? a.row.pct : null,
      road:       r.row ? r.row.pct : null,
      week:       a.row ? a.row.week : null,          // η ετικέτα, αυτούσια
      week_start: a.row ? (a.row.week_start || null) : null,
      week_end:   a.row ? (a.row.week_end   || null) : null,
      fetched_at: data.fetched_at || null,
      parse_warnings: data.parse_warnings || [],
      today:      iso,
      airState:   a.state,
      roadState:  r.state,
      data:       data          // το ακέραιο αρχείο, για όποιον το χρειάζεται (dashboard)
    };
  }

  /* ── Cache — διαβάζεται και γράφεται, ποτέ δεν σβήνεται ───────────
     Κάθε πρόσβαση σε try/catch: σε ιδιωτικό παράθυρο ή με μπλοκαρισμένα
     site data, το localStorage πετάει αντί να επιστρέψει null. */
  function readCache() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || 'null'); }
    catch (e) { return null; }
  }
  function writeCache(data) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(data)); } catch (e) {}
  }

  /* Ένα αίτημα τη φορά: το pricelist-clients καλεί load() από τέσσερα
     σημεία και δύο από αυτά μπορεί να τρέξουν μαζί. Μοιράζονται το ίδιο
     αίτημα — αλλά ΟΧΙ το αποτέλεσμα του check(), που ξανατρέχει πάντα. */
  var inflight = null;
  function fetchRemote() {
    if (inflight) return inflight;
    inflight = fetch(URL_JSON, { cache: 'no-cache' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .catch(function () { return null; })
      .then(function (d) { inflight = null; return d; });
    return inflight;
  }

  /* ── load(): cache → έλεγχος → δίκτυο αν χρειαστεί ────────────────── */
  function load() {
    var cached = readCache();
    var res    = check(cached, todayISO());

    if (res.state === OK) { res.source = 'cache'; return Promise.resolve(res); }

    return fetchRemote().then(function (fresh) {
      if (fresh) {
        writeCache(fresh);
        var out = check(fresh, todayISO());
        out.source = 'network';
        return out;
      }
      // Το δίκτυο δεν απάντησε. Κρατάμε ό,τι έχουμε και το λέμε.
      res.source = cached ? 'cache' : 'none';
      res.reason = res.reason
        ? res.reason + ' · το δίκτυο δεν απάντησε'
        : 'το δίκτυο δεν απάντησε';
      return res;
    });
  }

  /* ── Οθόνη ────────────────────────────────────────────────────────
     Τα στυλ είναι inline: οι σελίδες δεν μοιράζονται stylesheet και ένα
     <style> εδώ θα έμπαινε σε ξένο έδαφος. */
  function el(target) {
    if (!target) return null;
    return typeof target === 'string' ? document.getElementById(target) : target;
  }

  function box(bg, border, color) {
    var d = document.createElement('div');
    d.style.cssText =
      'margin:10px 0;padding:10px 14px;border:1px solid ' + border + ';border-left:5px solid ' + border +
      ';border-radius:4px;background:' + bg + ';color:' + color +
      ';font-size:13px;line-height:1.5;';
    return d;
  }

  function wording(res, opts) {
    if (res.state === FALLBACK) {
      return opts.defaults
        ? 'Δεν φορτώθηκαν δεδομένα επίναυλου. Οι τιμές που βλέπετε (' + opts.defaults +
          ') είναι <strong>ενδεικτικές — ΟΧΙ ο πραγματικός επίναυλος</strong>. ' +
          'Μην τις χρησιμοποιήσετε σε προσφορά.'
        // opts.effect: τι κάνει η ΣΥΓΚΕΚΡΙΜΕΝΗ σελίδα όταν λείπει η τιμή.
        // Χωρίς αυτό η λωρίδα θα υποσχόταν παύλες σε σελίδα που βάζει 0%.
        : 'Δεν φορτώθηκαν δεδομένα επίναυλου. ' +
          (opts.effect || 'Τα ποσοστά εμφανίζονται ως «—».');
    }
    if (res.week && res.week_start) {
      return 'Ο επίναυλος που εμφανίζεται είναι της εβδομάδας «' + esc(res.week) +
             '» και έχει <strong>περάσει</strong>.';
    }
    return 'Δεν μπορεί να επιβεβαιωθεί η εβδομάδα του επίναυλου.';
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /* banner(): κόκκινη λωρίδα για stale/fallback. Σε ok, καθαρίζει. */
  function banner(res, target, opts) {
    opts = opts || {};
    var host = el(target);
    if (!host) return null;

    var old = host.querySelector('[data-fuel-freshness]');
    if (old) old.parentNode.removeChild(old);
    if (!res || res.state === OK) return null;

    var d = box('#fff4f4', '#c62828', '#7f1d1d');
    d.setAttribute('data-fuel-freshness', res.state);
    d.innerHTML =
      '<strong>⚠ ' + wording(res, opts) + '</strong>' +
      (opts.link === false ? '' :
        ' <a href="' + DASH + '" style="color:#c62828;font-weight:600;">Ελέγξτε τον επίναυλο</a>' +
        // Με προκαθορισμένες τιμές έχει ήδη ειπωθεί «μην τις χρησιμοποιήσετε».
        (opts.defaults ? '.' : ' πριν στείλετε προσφορά.')) +
      (res.reason ? '<div style="margin-top:4px;opacity:.75;font-size:12px;">' + esc(res.reason) + '</div>' : '');

    host.insertBefore(d, host.firstChild);
    return d;
  }

  /* warningsBox(): κίτρινο πλαίσιο με τα parse_warnings του scraper.
     Μόνο για το dashboard — είναι διαγνωστική πληροφορία, όχι μήνυμα
     προς πωλητή. Κενό → τίποτα στην οθόνη. */
  function warningsBox(res, target) {
    var host = el(target);
    if (!host) return null;

    var old = host.querySelector('[data-fuel-parse-warnings]');
    if (old) old.parentNode.removeChild(old);

    var w = (res && res.parse_warnings) || [];
    if (!w.length) return null;

    var d = box('#fffbe6', '#b8860b', '#6b4e00');
    d.setAttribute('data-fuel-parse-warnings', String(w.length));
    d.innerHTML =
      '<strong>⚠ Η ανάλυση των εβδομάδων της DHL εντόπισε προβλήματα</strong>' +
      '<ul style="margin:6px 0 0 18px;padding:0;">' +
      w.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') +
      '</ul>';

    host.insertBefore(d, host.firstChild);
    return d;
  }

  window.FuelFreshness = {
    VERSION: '1.0',
    OK: OK, STALE: STALE, FALLBACK: FALLBACK,
    URL: URL_JSON,
    todayISO: todayISO,
    check: check,
    load: load,
    banner: banner,
    warningsBox: warningsBox
  };
})();
