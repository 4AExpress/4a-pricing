/* tasks-badge.js | v1.2 | 15-10-2026
 * v1.2: το tooltip των παρακάμψεων από τη βάση (4a_ui_texts common
 *       badge_override_tip, μέσω του ui-texts.js, Π1). Αν τα κείμενα δεν
 *       φορτωθούν: μόνο ο αριθμός, χωρίς tooltip παράκαμψης (απόφαση 14/10).
 * v1.1: το κόκκινο μετρά ΚΑΙ τις παρακάμψεις admin που περιμένουν «Το είδα»
 *       από αυτόν τον διαχειριστή (overrides, Φάση 5). 0 = όπως πριν.
 *
 * Κρεμάει δύο αριθμούς στον σύνδεσμο «Εργασίες» κάθε σελίδας που τον έχει:
 *   κόκκινο  = εργασίες με needs_attention (κανένας δικαιούχος — χρειάζονται άνθρωπο)
 *              + παρακάμψεις admin χωρίς «Το είδα»
 *   ουδέτερο = δικές μου σε εξέλιξη
 *
 * ΣΙΩΠΗΛΗ ΑΠΟΤΥΧΙΑ, ΡΗΤΗ ΑΠΟΦΑΣΗ: αν το αίτημα σκάσει, αργήσει, γυρίσει 401
 * ή 403, ή ο χρήστης δεν έχει δικαίωμα στο module «tasks», ΔΕΝ εμφανίζεται
 * τίποτα και ΔΕΝ γράφεται τίποτα στην οθόνη. Το σήμα είναι στολίδι σε ξένες
 * σελίδες — ο τιμοκατάλογος δεν πρέπει ποτέ να χαλάσει επειδή έσπασε το
 * σύστημα εργασιών. Ίδια αρχή με τη Φάση 2 (clients.php): το νέο σύστημα
 * δεν ρίχνει ποτέ το παλιό.
 */
(function () {
  if (window.__tasksBadgeLoaded) return;
  window.__tasksBadgeLoaded = true;

  var API = 'https://4aexpress.com/api';

  function links() {
    try { return document.querySelectorAll('a[href="tasks.html"]'); }
    catch (e) { return []; }
  }

  // texts: φορτώθηκε το badge_override_tip από τη βάση.
  function paint(attention, mine, overrides, texts) {
    overrides = overrides || 0;
    var red = attention + overrides;
    var a = links();
    for (var i = 0; i < a.length; i++) {
      var old = a[i].querySelector('.tasks-badge-wrap');
      if (old) old.remove();
      if (!red && !mine) continue;

      var w = document.createElement('span');
      w.className = 'tasks-badge-wrap';
      w.style.cssText = 'display:inline-flex;gap:4px;margin-left:6px;vertical-align:middle;';

      if (red) {
        var r = document.createElement('span');
        r.textContent = red;
        var why = [];
        if (attention) why.push(attention + ' εργασίες χωρίς δικαιούχο — χρειάζονται άνθρωπο');
        if (overrides && texts) why.push(UiTexts.t('badge_override_tip', { n: overrides }));
        r.title = why.join(' · ');
        r.style.cssText = 'background:#cc0000;color:#fff;font-size:10px;font-weight:700;' +
                          'line-height:1;padding:3px 6px;border-radius:9px;';
        w.appendChild(r);
      }
      if (mine) {
        var m = document.createElement('span');
        m.textContent = mine;
        m.title = mine + ' δικές μου εργασίες σε εξέλιξη';
        m.style.cssText = 'background:#eceff1;color:#546e7a;font-size:10px;font-weight:700;' +
                          'line-height:1;padding:3px 6px;border-radius:9px;';
        w.appendChild(m);
      }
      a[i].appendChild(w);
    }
  }

  function refresh() {
    if (!links().length) return;
    var token = '';
    try {
      var u = sessionStorage.getItem('4a_current_user');
      if (!u) return;                       // χωρίς συνεδρία, κανένα αίτημα
      token = JSON.parse(u)._token || '';
    } catch (e) { return; }
    if (!token) return;

    // ΠΡΟΣΟΧΗ: κανένα .catch() δεν λείπει και κανένα δεν εμφανίζει σφάλμα.
    // Το 401 ΔΕΝ κάνει logout εδώ — αυτό ανήκει στο apiFetch της κάθε
    // σελίδας, που ξέρει αν το αίτημα ήταν του χρήστη ή δικό μας.
    fetch(API + '/tasks.php?action=badge&v=' + Date.now(),
          { headers: { 'Authorization': 'Bearer ' + token } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || d.ok !== true) return;
        var o = Number(d.overrides) || 0;
        // Τα κείμενα ΜΟΝΟ όταν υπάρχει παράκαμψη να εξηγηθεί.
        var texts = !o || typeof UiTexts === 'undefined' ? Promise.resolve(false)
                  : UiTexts.load(['common']).then(function () { return UiTexts.has('badge_override_tip'); });
        return texts.then(function (ok) { paint(Number(d.attention) || 0, Number(d.mine) || 0, o, ok); });
      })
      .catch(function () { /* σιωπή, εσκεμμένα */ });
  }

  window.tasksBadgeRefresh = refresh;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh);
  } else {
    refresh();
  }
})();
