/* =====================================================================
 *  4a-pricing · announce.js
 *  Ανακοινώσεις μετά τη σύνδεση.
 *
 *  ΕΝΣΩΜΑΤΩΣΗ: μία γραμμή, πριν το </body>, σε κάθε σελίδα:
 *      <script src="announce.js"></script>
 *
 *  Δεν χρειάζεται αλλαγή σε κανένα σημείο του υπάρχοντος κώδικα.
 *  Δεν καλείται από πουθενά — ξεκινά μόνο του και περιμένει τη
 *  συνεδρία. Αν δεν βρει token, σβήνει σιωπηλά.
 *
 *  ΑΡΧΕΣ
 *   1. ΠΟΤΕ δεν μπλοκάρει τη σύνδεση. Κάθε σφάλμα καταπίνεται.
 *   2. Τρέχει μία φορά ανά σελίδα, ακόμα κι αν φορτωθεί δύο φορές
 *      (το pricelist-editor.html καλεί το me.php σε δύο σημεία).
 *   3. Ένα modal για όλα. Ποτέ στοίβα παραθύρων.
 *   4. Ο χρόνος μετράει ΜΟΝΟ με ανοιχτό κείμενο και ενεργή καρτέλα.
 *   5. Δεν χρησιμοποιεί localStorage — μόνο το υπάρχον sessionStorage.
 * ===================================================================== */
(function () {
  'use strict';

  /* Φρουρός διπλής φόρτωσης. */
  if (window.__ANNOUNCE_4A__) return;
  window.__ANNOUNCE_4A__ = true;

  /* --- Ρυθμίσεις ---------------------------------------------------- */
  // Το frontend τρέχει σε GitHub Pages, το API στη SiteGround:
  // το location.origin ΔΕΝ δίνει το σωστό. Χρησιμοποιούμε το window.API
  // που ορίζουν ήδη οι σελίδες, με σταθερό fallback.
  var API      = window.API || 'https://4aexpress.com/api';
  var BEAT_MS  = 10000;   // παλμός «διαβάζω» — ο server μετράει, όχι εμείς
  var IDLE_MS  = 60000;   // 60΄΄ χωρίς κίνηση = ο άνθρωπος έφυγε
  // (v1 είχε εδώ WAIT_MS/WAIT_MAX — αφαιρέθηκαν, βλ. watch() στο τέλος)

  /* --- Κατάσταση ---------------------------------------------------- */
  var items = [], openId = null, tickTimer = null, box = null;

  /* --- Βοηθητικά ---------------------------------------------------- */
  function token() {
    try {
      var u = JSON.parse(sessionStorage.getItem('4a_current_user') || 'null');
      return (u && u._token) || null;
    } catch (e) { return null; }
  }

  function call(action, payload) {
    var t = token();
    if (!t) return Promise.reject(new Error('no session'));
    var opt = { headers: { 'Authorization': 'Bearer ' + t } };
    var url = API + '/announcements.php';
    if (payload) {
      opt.method = 'POST';
      opt.headers['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(Object.assign({ action: action }, payload));
    } else {
      url += '?action=' + encodeURIComponent(action);
    }
    return fetch(url, opt).then(function (r) { return r.json(); });
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /* Ο server γράφει UTC. Χωρίς το 'Z' ο browser θα το διάβαζε ως τοπική
     ώρα και θα έδειχνε 3 ώρες πίσω. */
  function greekDate(mysql) {
    if (!mysql) return '';
    var d = new Date(mysql.replace(' ', 'T') + 'Z');
    if (isNaN(d)) return '';
    return ('0' + d.getDate()).slice(-2) + '/' +
           ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear();
  }

  function unacked() {
    return items.filter(function (a) { return a.require_ack && !a.acked; });
  }

  /* ==================================================================
   *  Χρόνος ανάγνωσης — v3
   *
   *  Ο πελάτης ΔΕΝ μετράει πια. Στέλνει «είμαι εδώ και διαβάζω» κάθε
   *  10 δευτερόλεπτα, και ο server υπολογίζει πόση ώρα πέρασε στ'
   *  αλήθεια. Η οθόνη δείχνει ό,τι απαντά ο server.
   *
   *  Γιατί άλλαξε: στη δοκιμή καταγράφηκαν 1800΄΄ σε 388΄΄ πραγματικού
   *  χρόνου. Πολλές ανοιχτές καρτέλες μετρούσαν η καθεμία ξεχωριστά
   *  και έγραφαν στην ίδια γραμμή.
   *
   *  Ο παλμός σταματά σε τέσσερα σημεία: κλειστό κείμενο, κρυφή
   *  καρτέλα, 60΄΄ χωρίς καμία ανθρώπινη κίνηση, κλείσιμο σελίδας.
   * ================================================================== */

  var lastAct = Date.now();
  ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel']
    .forEach(function (ev) {
      document.addEventListener(ev, function () { lastAct = Date.now(); },
                                { passive: true, capture: true });
    });

  function reading() {
    return openId !== null
        && !document.hidden
        && (Date.now() - lastAct) < IDLE_MS;
  }

  function beat() {
    if (!reading()) return;
    var id = openId;
    call('tick', { id: id })
      .then(function (r) {
        if (!r || !r.ok || typeof r.dwell !== 'number') return;
        var it = items.filter(function (a) { return a.id === id; })[0];
        if (it) it.dwell = r.dwell;
        var el = box && box.querySelector('[data-dwell="' + id + '"]');
        if (el) el.textContent = fmt(r.dwell);
      })['catch'](function () {});
  }

  function startTimer() { stopTimer(); tickTimer = setInterval(beat, BEAT_MS); }
  function stopTimer()  { if (tickTimer) { clearInterval(tickTimer); tickTimer = null; } }

  function fmt(s) {
    s = Math.round(s || 0);
    return s < 60 ? s + '\u2033'
                  : Math.floor(s / 60) + '\u2032' + ('0' + (s % 60)).slice(-2) + '\u2033';
  }

  /* Δεν υπάρχει πια «χρόνος που δεν στάλθηκε» — ο server τον ξέρει ήδη.
     Το flush() μένει ως κενό, για να μη σπάσουν οι κλήσεις παρακάτω. */
  function flush() {}

  /* ================================================================== */
  /*  Στυλ                                                               */
  /* ================================================================== */
  function styles() {
    if (document.getElementById('an4a-css')) return;
    var s = document.createElement('style');
    s.id = 'an4a-css';
    s.textContent = [
      '.an4a-veil{position:fixed;inset:0;background:rgba(21,34,50,.55);z-index:99999;',
      '  display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto}',
      '.an4a-sheet{background:#F2F1EC;border-radius:4px;width:100%;max-width:680px;',
      '  box-shadow:0 18px 50px rgba(9,16,25,.4);font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;',
      '  color:#152232;animation:an4a-lift .26s cubic-bezier(.2,.7,.3,1)}',
      '@keyframes an4a-lift{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}',
      '.an4a-sheet header{padding:20px 22px 15px;border-bottom:1px solid #DCDED8;background:#fff;border-radius:4px 4px 0 0}',
      '.an4a-sheet h3{margin:0;font-size:19px;font-weight:600}',
      '.an4a-sheet header p{margin:5px 0 0;color:#6B7A86;font-size:13.5px}',
      '.an4a-list{padding:16px 22px 6px}',
      '.an4a-i{background:#fff;border:1px solid #DCDED8;border-left:4px solid #41616F;border-radius:3px;margin-bottom:10px}',
      '.an4a-i.sev-important{border-left-color:#A9640D}',
      '.an4a-i.sev-critical{border-left-color:#8C2F39}',
      '.an4a-i.done{border-left-color:#2C6B50}',
      '.an4a-h{display:flex;gap:12px;align-items:flex-start;width:100%;background:none;border:0;',
      '  text-align:left;padding:13px 15px;cursor:pointer;font:inherit;color:inherit}',
      '.an4a-h:hover{background:#FAFAF7}',
      '.an4a-ic{font-size:17px;flex:0 0 auto;line-height:1.3}',
      '.an4a-t{flex:1;min-width:0}',
      '.an4a-ttl{font-weight:600;display:block}',
      '.an4a-sum{color:#6B7A86;font-size:13.5px;display:block;margin-top:2px}',
      '.an4a-tags{display:flex;gap:8px;margin-top:7px;flex-wrap:wrap}',
      '.an4a-tag{font-size:11.5px;font-family:ui-monospace,Menlo,Consolas,monospace;',
      '  border:1px solid #B9BDB5;color:#6B7A86;padding:1px 5px;border-radius:2px}',
      '.an4a-tag.c{color:#8C2F39;border-color:currentColor}',
      '.an4a-tag.p{color:#A9640D;border-color:currentColor}',
      '.an4a-tag.k{color:#2C6B50;border-color:currentColor}',
      '.an4a-chev{color:#B9BDB5;font-size:12px;padding-top:3px;transition:transform .18s}',
      '.an4a-i.open .an4a-chev{transform:rotate(90deg)}',
      '.an4a-b{display:none;padding:0 15px 15px 44px;border-top:1px solid #EDEEE9}',
      '.an4a-i.open .an4a-b{display:block}',
      '.an4a-b dl{margin:14px 0 0;display:grid;grid-template-columns:auto 1fr;gap:5px 14px}',
      '.an4a-b dt{font-weight:600;font-size:13px;white-space:nowrap;padding-top:1px}',
      '.an4a-b dd{margin:0;font-size:14px;color:#2A3B4D;max-width:62ch}',
      '.an4a-track{margin:14px 0 0;font-size:12.5px;color:#6B7A86;border-top:1px dotted #B9BDB5;padding-top:9px}',
      '.an4a-track b{font-family:ui-monospace,Menlo,Consolas,monospace;color:#152232;font-weight:500}',
      '.an4a-row{display:flex;gap:10px;align-items:center;margin-top:14px;flex-wrap:wrap}',
      '.an4a-btn{background:#152232;color:#fff;border:1px solid #152232;padding:7px 13px;',
      '  border-radius:3px;font:500 13.5px/1.4 inherit;cursor:pointer}',
      '.an4a-btn:hover{background:#2A3B4D}',
      '.an4a-btn.ghost{background:none;color:#152232;border-color:#B9BDB5}',
      '.an4a-btn.sign{background:#8C2F39;border-color:#8C2F39}',
      '.an4a-btn[disabled]{opacity:.4;cursor:not-allowed}',
      '.an4a-stamp{display:inline-flex;align-items:center;gap:7px;font-family:ui-monospace,Menlo,Consolas,monospace;',
      '  font-size:11.5px;color:#2C6B50;border:1px dashed #2C6B50;padding:4px 9px;border-radius:2px;transform:rotate(-1.2deg)}',
      '.an4a-sheet footer{padding:14px 22px 18px;display:flex;gap:14px;align-items:center;',
      '  border-top:1px solid #DCDED8;background:#fff;border-radius:0 0 4px 4px;flex-wrap:wrap}',
      '.an4a-note{font-size:13px;color:#8C2F39;flex:1;min-width:170px}',
      '.an4a-note.ok{color:#2C6B50}',
      '.an4a-bell{position:relative;background:none;border:1px solid #DCDED8;width:34px;height:32px;',
      '  border-radius:3px;font-size:15px;line-height:1;cursor:pointer;margin-left:8px;vertical-align:middle}',
      '.an4a-dot{position:absolute;top:-6px;right:-6px;min-width:17px;height:17px;background:#8C2F39;',
      '  color:#fff;border-radius:9px;font:600 11px/17px ui-monospace,monospace;border:2px solid #fff}',
      '.an4a-float{position:fixed;top:12px;right:14px;z-index:9998;background:#fff}',
      '@media (prefers-reduced-motion:reduce){.an4a-sheet{animation:none}.an4a-chev{transition:none}}'
    ].join('');
    document.head.appendChild(s);
  }

  /* ================================================================== */
  /*  Καμπανάκι                                                          */
  /* ================================================================== */
  function bell(n) {
    var b = document.getElementById('an4a-bell');
    if (!b) {
      b = document.createElement('button');
      b.id = 'an4a-bell';
      b.className = 'an4a-bell';
      b.type = 'button';
      b.title = 'Ανακοινώσεις';
      b.setAttribute('aria-label', 'Ανακοινώσεις');
      b.innerHTML = '\uD83D\uDD14<span class="an4a-dot" hidden></span>';
      b.onclick = function () { if (items.length) modal(); };
      // Δίπλα στο υπάρχον όνομα χρήστη αν υπάρχει· αλλιώς πάνω δεξιά.
      var host = document.getElementById('topbar-user');
      if (host && host.parentNode) host.parentNode.insertBefore(b, host.nextSibling);
      else { b.classList.add('an4a-float'); document.body.appendChild(b); }
    }
    var dot = b.querySelector('.an4a-dot');
    dot.hidden = !n;
    dot.textContent = n;
  }

  /* ================================================================== */
  /*  Modal                                                              */
  /* ================================================================== */
  function itemHTML(a) {
    var sev = a.severity === 'critical' ? 'c' : (a.severity === 'important' ? 'p' : '');
    var lbl = a.severity === 'critical' ? 'κρίσιμο'
            : (a.severity === 'important' ? 'σημαντικό' : 'πληροφορία');
    return '' +
    '<article class="an4a-i sev-' + a.severity + (a.acked ? ' done' : '') + '" data-id="' + a.id + '">' +
      '<button class="an4a-h" type="button" data-open="' + a.id + '">' +
        '<span class="an4a-ic">' + esc(a.icon) + '</span>' +
        '<span class="an4a-t">' +
          '<span class="an4a-ttl">' + esc(a.title) + '</span>' +
          '<span class="an4a-sum">' + esc(a.summary) + '</span>' +
          '<span class="an4a-tags">' +
            '<span class="an4a-tag ' + sev + '">' + lbl + '</span>' +
            '<span class="an4a-tag">' + esc(a.code) + '</span>' +
            (a.date ? '<span class="an4a-tag">' + greekDate(a.date) + '</span>' : '') +
            (a.acked ? '<span class="an4a-tag k">παραλήφθηκε</span>' : '') +
          '</span>' +
        '</span>' +
        '<span class="an4a-chev">\u25B6</span>' +
      '</button>' +
      '<div class="an4a-b">' +
        '<dl>' +
          '<dt>Τι άλλαξε</dt><dd>' + a.changed + '</dd>' +
          '<dt>Γιατί σε αφορά</dt><dd>' + a.why + '</dd>' +
          '<dt>Τι κάνεις αλλιώς</dt><dd>' + a.todo + '</dd>' +
        '</dl>' +
        (a.tracked
          ? '<p class="an4a-track">\u23F1 Χρόνος ανάγνωσης: <b data-dwell="' + a.id + '">' +
            fmt(a.dwell) + '</b> · καταγράφεται ότι το διάβασες, γιατί η αλλαγή είναι ' + lbl + '</p>'
          : '') +
        '<div class="an4a-row">' +
          (a.cta_url && a.cta_label
            ? '<a class="an4a-btn ghost" href="' + esc(a.cta_url) + '">' + esc(a.cta_label) + '</a>' : '') +
          (a.require_ack && !a.acked
            ? '<button class="an4a-btn sign" type="button" data-ack="' + a.id + '">Βεβαιώνω παραλαβή</button>' +
              '<span style="font-size:12.5px;color:#6B7A86">Απαιτείται — αλλάζει τον τρόπο δουλειάς σου.</span>'
            : '') +
          (a.acked ? '<span class="an4a-stamp">\u2714 ΠΑΡΑΛΗΦΘΗΚΕ</span>' : '') +
        '</div>' +
      '</div>' +
    '</article>';
  }

  function refreshFooter() {
    if (!box) return;
    var n = unacked().length;
    var note = box.querySelector('.an4a-note');
    var btn  = box.querySelector('[data-close]');
    note.className = 'an4a-note' + (n ? '' : ' ok');
    note.textContent = n
      ? 'Εκκρεμεί βεβαίωση παραλαβής σε ' + n + (n === 1 ? ' ανακοίνωση.' : ' ανακοινώσεις.')
      : 'Όλα εντάξει.';
    if (n) btn.setAttribute('disabled', 'disabled'); else btn.removeAttribute('disabled');
  }

  function modal() {
    if (box) return;
    styles();
    var oldest = items[items.length - 1];
    box = document.createElement('div');
    box.className = 'an4a-veil';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.innerHTML =
      '<div class="an4a-sheet">' +
        '<header>' +
          '<h3>Τι άλλαξε' + (oldest && oldest.date ? ' από τις ' + greekDate(oldest.date) : '') + '</h3>' +
          '<p>' + items.length + (items.length === 1 ? ' αλλαγή' : ' αλλαγές') +
          ' που σε αφορούν. Πάτησε για λεπτομέρειες.</p>' +
        '</header>' +
        '<div class="an4a-list">' + items.map(itemHTML).join('') + '</div>' +
        '<footer><span class="an4a-note"></span>' +
        '<button class="an4a-btn" type="button" data-close="1">Συνέχεια στην πλατφόρμα</button></footer>' +
      '</div>';
    document.body.appendChild(box);
    document.body.style.overflow = 'hidden';
    refreshFooter();
    startTimer();

    box.addEventListener('click', function (ev) {
      var o = ev.target.closest('[data-open]');
      if (o) { toggle(+o.getAttribute('data-open')); return; }
      var k = ev.target.closest('[data-ack]');
      if (k) { ack(+k.getAttribute('data-ack')); return; }
      if (ev.target.closest('[data-close]')) { close(); }
    });
  }

  function toggle(id) {
    flush();
    var el = box.querySelector('.an4a-i[data-id="' + id + '"]');
    if (openId === id) { openId = null; el.classList.remove('open'); return; }
    var prev = box.querySelector('.an4a-i.open');
    if (prev) prev.classList.remove('open');
    openId = id;
    el.classList.add('open');
    call('open', { id: id })['catch'](function () {});
  }

  function ack(id) {
    call('ack', { id: id }).then(function (r) {
      if (!r || !r.ok) return;
      var it = items.filter(function (a) { return a.id === id; })[0];
      if (it) it.acked = true;
      var el = box.querySelector('.an4a-i[data-id="' + id + '"]');
      el.classList.add('done');
      var row = el.querySelector('.an4a-row');
      var btn = row.querySelector('[data-ack]');
      if (btn) { btn.nextElementSibling && btn.nextElementSibling.remove(); btn.remove(); }
      var st = document.createElement('span');
      st.className = 'an4a-stamp';
      st.textContent = '\u2714 ΠΑΡΑΛΗΦΘΗΚΕ';
      row.appendChild(st);
      refreshFooter();
    })['catch'](function () {});
  }

  function close() {
    if (unacked().length) return;
    flush();
    stopTimer();
    call('seen', { ids: items.map(function (a) { return a.id; }) })['catch'](function () {});
    box.remove(); box = null; openId = null;
    document.body.style.overflow = '';
    items = [];
    bell(0);
  }

  /* ================================================================== */
  /*  Εκκίνηση                                                           */
  /* ================================================================== */
  var lastRunToken = null;   // για ποιο token τρέξαμε ήδη

  function run(force) {
    var t = token();
    if (!t) return Promise.resolve('no-session');
    if (!force && t === lastRunToken) return Promise.resolve('already-run');
    lastRunToken = t;

    return call('pending').then(function (r) {
      if (!r || !r.ok || !r.items || !r.items.length) { bell(0); return 'empty'; }
      items = r.items;
      bell(items.length);
      styles();
      modal();
      return 'shown:' + items.length;
    })['catch'](function (e) {
      // Σιωπηλά προς τον χρήστη — ποτέ δεν χαλάει τη σελίδα.
      lastRunToken = null;          // επόμενος κύκλος ξαναδοκιμάζει
      return 'error:' + (e && e.message);
    });
  }

  /* --------------------------------------------------------------
   *  Παρακολούθηση συνεδρίας.
   *
   *  ΔΙΟΡΘΩΣΗ v2: η v1 έψαχνε token για 12 δευτερόλεπτα και μετά
   *  παραιτούνταν. Δούλευε μόνο σε ήδη ανοιχτή συνεδρία — στο πρώτο
   *  login ο χρήστης περιμένει το OTP στο email, που παίρνει πολύ
   *  περισσότερο. Το script είχε ήδη σβήσει όταν συνδεόταν.
   *
   *  Τώρα κοιτάζει όσο ζει η σελίδα. Το sessionStorage δεν εκπέμπει
   *  event στην ίδια καρτέλα, οπότε polling είναι ο μόνος τρόπος.
   *  Παύση σε κρυφή καρτέλα, και αραιώνει μετά το πρώτο λεπτό.
   * -------------------------------------------------------------- */
  var ticks = 0;
  function watch() {
    ticks++;
    if (!document.hidden && !box) run();
    // Πρώτο λεπτό: κάθε 600ms. Μετά: κάθε 3 δευτερόλεπτα.
    setTimeout(watch, ticks < 100 ? 600 : 3000);
  }
  watch();

  /* --------------------------------------------------------------
   *  Εργαλεία ελέγχου — για την κονσόλα, όχι για την πλατφόρμα.
   *      Announce.status()   τι βλέπει αυτή τη στιγμή
   *      Announce.check()    ξαναρώτα τον server τώρα
   *      Announce.reset()    κλείσε το modal και ξεκίνα από την αρχή
   * -------------------------------------------------------------- */
  window.Announce = {
    status: function () {
      return {
        loaded:  true,
        api:     API,
        token:   token() ? 'ΝΑΙ' : 'ΟΧΙ',
        ranFor:  lastRunToken ? 'ναι' : 'όχι',
        items:   items.length,
        modal:   !!box,
        bell:    !!document.getElementById('an4a-bell'),
        openId:  openId
      };
    },
    check: function () { return run(true); },
    reset: function () {
      if (box) { stopTimer(); box.remove(); box = null; }
      document.body.style.overflow = '';
      openId = null; items = []; lastRunToken = null;
      return run(true);
    }
  };

})();
