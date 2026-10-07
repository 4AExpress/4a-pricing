// Τρέχει τις ΠΡΑΓΜΑΤΙΚΕΣ pickByDate/pickNext/calcDiff/badge/renderDashboard
// του fuel-surcharge-dashboard.html πάνω σε ΠΑΓΩΜΕΝΟ fixture (commit 099ce2e)
// και με ΡΗΤΟ now. Καμία ανάγνωση του ζωντανού cache — η προσδοκία δεν
// μπορεί να «συμφωνήσει με τον εαυτό της».
const fs = require('fs');
const path = require('path');
const HERE = __dirname;
const src = fs.readFileSync('frontend/fuel-surcharge-dashboard.html', 'utf8');
const mod = fs.readFileSync('frontend/fuel-freshness.js', 'utf8');
const FIX = JSON.parse(fs.readFileSync(path.join(HERE, 'fuel.fixture.099ce2e.json'), 'utf8'));
// Απάντηση του api/fuel_catalog.php πάνω στο ίδιο fixture. Τιμές ΓΡΑΜΜΕΝΕΣ·
// το tests/php/fuel_lib.test.php ελέγχει ότι ο server βγάζει ακριβώς αυτές.
const CATFIX = JSON.parse(fs.readFileSync(path.join(HERE, 'fuel_catalog.fixture.json'), 'utf8'));
const CAT_OK = { state: 'ok', data: CATFIX };

function grab(name, kw){
  const needle = (kw || 'function ') + name + '(';
  const i = src.indexOf(needle);
  if (i < 0) throw new Error('δεν βρέθηκε: ' + name);
  let d = 0, st = false;
  for (let j = i; j < src.length; j++){
    if (src[j] === '{'){ d++; st = true; }
    else if (src[j] === '}'){ d--; if (st && d === 0) return src.slice(i, j + 1); }
  }
  throw new Error(name);
}
// Σταθερά top-level (const X = ...;) αυτούσια από τη σελίδα.
function grabConst(name){
  const m = src.match(new RegExp('const ' + name + ' = [^;]+;'));
  if (!m) throw new Error('δεν βρέθηκε: ' + name);
  return m[0];
}

function env(){
  const els = {};
  const el = id => (els[id] = els[id] || { innerHTML: '', textContent: '', style: {} });
  const doc = { getElementById: id => el(id),
                querySelectorAll: () => [], createElement: () => ({ style:{}, setAttribute(){}, appendChild(){} }) };
  const win = {};
  new Function('window','localStorage','fetch','document', mod)(
    win, { getItem: () => null, setItem(){} }, () => Promise.reject(new Error('χωρίς δίκτυο')), doc);

  const GR_DAYS = ['Κυριακή','Δευτέρα','Τρίτη','Τετάρτη','Πέμπτη','Παρασκευή','Σάββατο'];
  const fn = new Function('document','FuelFreshness','GR_DAYS',
    grabConst('STATION_TITLES') + '\n' + grabConst('MISSING_NOTE') + '\n' +
    grab('esc') + '\n' + grab('catType') + '\n' + grab('catExtraTypes') + '\n' +
    grab('catPct') + '\n' + grab('catDelta') + '\n' + grab('catMsg') + '\n' +
    grab('servicesHtml') + '\n' +
    grab('badge') + '\n' + grab('calcDiff') + '\n' + grab('fmtViewDate') + '\n' +
    grab('pickByDate') + '\n' + grab('pickNext') + '\n' + grab('renderDashboard') + '\n' +
    'return {badge, calcDiff, fmtViewDate, pickByDate, pickNext, renderDashboard, servicesHtml, catPct};');
  return { api: fn(doc, win.FuelFreshness, GR_DAYS), els, doc, FF: win.FuelFreshness };
}

let pass = 0, fail = 0;
const ck = (l, c, extra) => { c ? pass++ : fail++;
  console.log(`   ${c ? 'OK  ' : 'ΛΑΘΟΣ'} ${l}${c ? '' : '   → ' + (extra === undefined ? '' : extra)}`); };

// Βοηθητικά για ανάγνωση του παραχθέντος HTML
const nextCard = (html, label) => {
  const i = html.indexOf(label);
  return i < 0 ? '' : html.slice(i, i + 700);
};
const badgeIn = s => { const m = s.match(/<span class="badge (up|down)">([+\-−]?[\d.]+)%<\/span>/);
  return m ? { cls: m[1], val: m[2] } : null; };
const currentWeeks = html => {
  const out = [];
  const re = /<tr class="current">\s*<td>([^<]+?)\s*<span class="badge curr">/g;
  let m; while ((m = re.exec(html))) out.push(m[1].trim());
  return out;
};

console.log('\nfixture: commit 099ce2e · fetched_at=' + FIX.fetched_at);
console.log('air:  ' + FIX.air.map(r => `${r.week_start}..${r.week_end}=${r.pct}`).join('  '));
console.log('road: ' + FIX.road.map(r => `${r.week_start}..${r.week_end}=${r.pct}`).join('  '));

// ── 1-3. Πριν τη Δευτέρα: τρέχουσα = 28/09-04/10 ───────────────────────────
for (const [iso, lbl] of [['2026-10-02','Παρ 02/10 15:00'],
                          ['2026-10-03','Σαβ 03/10 12:00'],
                          ['2026-10-04','Κυρ 04/10 23:59']]) {
  console.log(`\n═══ ${lbl}  (now=${iso}) ═══`);
  const { api, els } = env();
  api.renderDashboard(FIX, iso, CAT_OK);
  const html = els['content'].innerHTML;

  ck('AIR τρέχουσα 46.25',  html.includes('46.25%'));
  ck('ROAD τρέχουσα 38.25', html.includes('38.25%'));
  ck('τρέχουσα εβδομάδα = Σεπτέμβριος 28-Οκτώβριος 4',
     currentWeeks(html).every(w => w.startsWith('Σεπτέμβριος 28')), JSON.stringify(currentWeeks(html)));

  const b = badgeIn(nextCard(html, 'AIR — επόμενη'));
  console.log('      κάρτα ΕΠΟΜΕΝΗ AIR: ' + JSON.stringify(b));
  ck('μεταβολή = επόμενη − τρέχουσα = +1.75', b && b.val === '+1.75', b && b.val);
  ck('αύξηση επίναυλου ΔΕΝ είναι πράσινη (cls=up)', b && b.cls === 'up', b && b.cls);

  const br = badgeIn(nextCard(html, 'ROAD — επόμενη'));
  ck('ROAD +1.75 / up', br && br.val === '+1.75' && br.cls === 'up', JSON.stringify(br));
  // Μεταβολή Air Cyprus = διαφορά δύο τιμών του SERVER: 50.88 − 49.03.
  // Όχι πια diffAir × 1.06 στρογγυλεμένο σε JS (απόφαση 07-10-2026).
  const bv = badgeIn(nextCard(html, 'Air Cyprus — επόμενη'));
  ck('Air Cyprus +1.85 / up (50.88 − 49.03, τιμές server)',
     bv && bv.val === '+1.85' && bv.cls === 'up', JSON.stringify(bv));
}

// ── 4. Δευτέρα 05/10: η εβδομάδα γυρίζει ───────────────────────────────────
console.log('\n═══ Δευ 05/10 00:01  (now=2026-10-05) ═══');
{
  const { api, els } = env();
  api.renderDashboard(FIX, '2026-10-05', CAT_OK);
  const html = els['content'].innerHTML;
  ck('AIR τρέχουσα 48.00',  html.includes('48.00%'));
  ck('ROAD τρέχουσα 40.00', html.includes('40.00%'));
  ck('ΔΕΝ δείχνει πια 46.25 ως τρέχουσα',
     !nextCard(html,'AIR — τρέχουσα').includes('46.25'));
  ck('τρέχουσα εβδομάδα = Οκτώβριος 5-11',
     currentWeeks(html).every(w => w.startsWith('Οκτώβριος 5-11')), JSON.stringify(currentWeeks(html)));
  const b = badgeIn(nextCard(html, 'AIR — επόμενη'));
  console.log('      κάρτα ΕΠΟΜΕΝΗ AIR: ' + JSON.stringify(b));
  ck('επόμενη = 49.00', nextCard(html,'AIR — επόμενη').includes('49.00%'));
  ck('μεταβολή +1.00 / up', b && b.val === '+1.00' && b.cls === 'up', JSON.stringify(b));
  const br = badgeIn(nextCard(html, 'ROAD — επόμενη'));
  ck('ROAD μεταβολή +0.75 / up', br && br.val === '+0.75' && br.cls === 'up', JSON.stringify(br));
  console.log('      → η σημαία is_current του αρχείου λέει 28/09-04/10· η οθόνη αγνοεί τη σημαία');
}

// ── 5. Δευτέρα 12/10: τελευταία εβδομάδα του αρχείου, καμία επόμενη ────────
console.log('\n═══ Δευ 12/10 10:00  (now=2026-10-12) ═══');
{
  const { api, els } = env();
  api.renderDashboard(FIX, '2026-10-12', CAT_OK);
  const html = els['content'].innerHTML;
  ck('AIR τρέχουσα 49.00',  html.includes('49.00%'));
  ck('ROAD τρέχουσα 40.75', html.includes('40.75%'));
  ck('τρέχουσα εβδομάδα = Οκτώβριος 12-18',
     currentWeeks(html).every(w => w.startsWith('Οκτώβριος 12-18')), JSON.stringify(currentWeeks(html)));
  const card = nextCard(html, 'AIR — επόμενη');
  ck('καμία επόμενη: τιμή «—»', /metric-value warn">—</.test(card), card.slice(0,160));
  ck('καμία επόμενη: κανένα badge μεταβολής', badgeIn(card) === null, JSON.stringify(badgeIn(card)));
}

// ── 6. Αρχείο χωρίς γραμμές ────────────────────────────────────────────────
console.log('\n═══ Αρχείο χωρίς γραμμές ═══');
{
  const { api, els } = env();
  let threw = null;
  try { api.renderDashboard({ fetched_at: FIX.fetched_at, air: [], road: [] }, '2026-10-05'); }
  catch (e) { threw = e; }
  ck('η renderDashboard ΔΕΝ πετάει', threw === null, threw && threw.message);
  const html = els['content'].innerHTML;
  ck('κάτι ζωγραφίστηκε', html.length > 0);
  ck('δείχνει «—» αντί NaN', html.includes('—') && !html.includes('NaN'));
  ck('κανένα badge μεταβολής', badgeIn(nextCard(html, 'AIR — επόμενη')) === null);
}

// ── 7. Δομικοί έλεγχοι: δεν επανήλθε το σφάλμα ─────────────────────────────
// ── 8. Η ένδειξη «Προβολή για» ─────────────────────────────────────────────
console.log('\n═══ Ένδειξη «Προβολή για» ═══');
{
  // Η ένδειξη πρέπει να δείχνει την ημερομηνία που ΠΡΑΓΜΑΤΙ χρησιμοποιήθηκε
  // για την επιλογή εβδομάδας — γι' αυτό γράφεται μέσα στη renderDashboard,
  // με το ίδιο viewISO, και δεν μπορεί να αποκλίνει.
  for (const [iso, want] of [['2026-10-02','Παρασκευή 02/10/2026'],
                             ['2026-10-03','Σάββατο 03/10/2026'],
                             ['2026-10-04','Κυριακή 04/10/2026'],
                             ['2026-10-05','Δευτέρα 05/10/2026'],
                             ['2026-10-12','Δευτέρα 12/10/2026']]) {
    const { api, els } = env();
    api.renderDashboard(FIX, iso, CAT_OK);
    const got = els['view-date-label'].textContent;
    ck(`${iso} → «${want}»`, got === want, got);
  }
  // Η ίδια ημερομηνία που πήγε στην επιλογή εβδομάδας
  const { api, els } = env();
  api.renderDashboard(FIX, '2026-10-05', CAT_OK);
  ck('η ένδειξη συμφωνεί με την εβδομάδα που επιλέχθηκε',
     els['view-date-label'].textContent.includes('05/10/2026') &&
     els['content'].innerHTML.includes('48.00%'));
}

// ── 9. Κάρτες ανά υπηρεσία από τον κατάλογο CMS (§5) ──────────────────────
// Βοηθητικά: η κάρτα μιας υπηρεσίας σε έναν σταθμό, και τα κομμάτια της.
const svcCard = (html, st, svc) => {
  const i = html.indexOf(`data-station="${st}" data-service="${svc}"`);
  if (i < 0) return '';
  const j = html.indexOf('data-service=', i + 20);
  return html.slice(i, j < 0 ? i + 800 : j);
};
const svcPct = card => (card.match(/svc-pct [a-z]+">([^<]*)</) || [])[1];
const section = (html, st) => {
  const i = html.indexOf(`class="station-title" data-station="${st}"`);
  if (i < 0) return '';
  const j = html.indexOf('class="station-title"', i + 10);
  return html.slice(i, j < 0 ? html.length : j);
};

console.log('\n═══ Κάρτες ανά υπηρεσία — Παρ 02/10 (AIR 46.25) ═══');
{
  const { api, els } = env();
  api.renderDashboard(FIX, '2026-10-02', CAT_OK);
  const html = els['content'].innerHTML;

  ck('πάνω κάρτα «Air Cyprus — τρέχουσα» = 49.03%',
     nextCard(html, 'Air Cyprus — τρέχουσα').includes('49.03%'), nextCard(html, 'Air Cyprus — τρέχουσα').slice(0, 200));
  ck('κάρτα S1027 (ΕΛΛΑΔΑ) = 49.03%', svcPct(svcCard(html, 'GR', 'S1027')) === '49.03%', svcPct(svcCard(html, 'GR', 'S1027')));
  ck('η S1027 έχει ετικέτα από τη βάση «Air Cyprus»', svcCard(html, 'GR', 'S1027').includes('svc-type">Air Cyprus<'));
  ck('η S1027 είναι ΜΟΝΟ στην ΕΛΛΑΔΑ',
     section(html, 'GR').includes('data-service="S1027"') && !section(html, 'CY').includes('data-service="S1027"'));
  ck('η S1003 σε ΔΥΟ ενότητες (ΕΛΛΑΔΑ + ΚΥΠΡΟΣ)',
     section(html, 'GR').includes('data-service="S1003"') && section(html, 'CY').includes('data-service="S1003"'));
  ck('η S1012 σε ΔΥΟ ενότητες (ΕΛΛΑΔΑ + ΚΥΠΡΟΣ)',
     section(html, 'GR').includes('data-service="S1012"') && section(html, 'CY').includes('data-service="S1012"'));
  ck('ενότητες με τίτλο ΕΛΛΑΔΑ και ΚΥΠΡΟΣ, με αυτή τη σειρά',
     html.indexOf('>ΕΛΛΑΔΑ<') > 0 && html.indexOf('>ΕΛΛΑΔΑ<') < html.indexOf('>ΚΥΠΡΟΣ<'));
  const nCards = (html.match(/data-service="/g) || []).length;
  ck('12 κάρτες, μία ανά υπηρεσία ανά σταθμό', nCards === 12, nCards);
  ck('S1003 ΕΛΛΑΔΑ = AIR 46.25%', svcPct(svcCard(html, 'GR', 'S1003')) === '46.25%', svcPct(svcCard(html, 'GR', 'S1003')));
  ck('ιστορικό: καρτέλα «Air Cyprus (S1027)»', html.includes('>Air Cyprus (S1027)<'));
  ck('ιστορικό Air Cyprus: 49.03% στην τρέχουσα γραμμή',
     /id="tab-t-AIR_CY"[\s\S]*?<strong>49\.03%<\/strong>/.test(html));
  ck('καμία λέξη «Valuable» στο HTML', !/valuable/i.test(html));
  ck('καμία σημείωση ασυμφωνίας όταν συμφωνούν', !html.includes('διαφέρει, στα δεδομένα του server'));
}

console.log('\n═══ COMBI — Δευ 05/10 (ROAD 40) ═══');
{
  const { api, els } = env();
  api.renderDashboard(FIX, '2026-10-05', CAT_OK);
  const html = els['content'].innerHTML;
  for (const svc of ['S1050', 'S1051']) {
    const c = svcCard(html, 'CY', svc);
    ck(`${svc} στην ΚΥΠΡΟ με ROAD 40.00%`, svcPct(c) === '40.00%', svcPct(c));
    ck(`${svc} ετικέτα «Οδικός» και κλάση road`, c.includes('svc-type">Οδικός<') && c.includes('svc-pct road'));
    ck(`${svc} σήμανση «επίναυλος στο σκέλος Ελλάδα↔κόσμος»`, c.includes('επίναυλος στο σκέλος Ελλάδα↔κόσμος'));
  }
  ck('μη-COMBI χωρίς σήμανση COMBI', !svcCard(html, 'GR', 'S1010').includes('COMBI'));
  ck('Air Cyprus τρέχουσα 50.88% (48 × 1.06, από server)', nextCard(html, 'Air Cyprus — τρέχουσα').includes('50.88%'));
  const bv = badgeIn(nextCard(html, 'Air Cyprus — επόμενη'));
  ck('Air Cyprus μεταβολή +1.06 = 51.94 − 50.88 (τιμές server)',
     bv && bv.val === '+1.06' && bv.cls === 'up', JSON.stringify(bv));
}

console.log('\n═══ Χωρίς σύνδεση ═══');
{
  const { api, els } = env();
  api.renderDashboard(FIX, '2026-10-05', { state: 'nologin' });
  const html = els['content'].innerHTML;
  ck('AIR/ROAD από το cache όπως σήμερα (48.00 / 40.00)',
     nextCard(html, 'AIR — τρέχουσα').includes('48.00%') && nextCard(html, 'ROAD — τρέχουσα').includes('40.00%'));
  ck('κάρτες υπηρεσιών: «Απαιτείται σύνδεση»',
     /Ανά Service[\s\S]*Απαιτείται σύνδεση για τις τιμές ανά υπηρεσία/.test(html));
  ck('ειδικός επίναυλος: «Απαιτείται σύνδεση», καμία τιμή',
     nextCard(html, 'Ειδικός επίναυλος — τρέχουσα').includes('Απαιτείται σύνδεση') &&
     !html.includes('50.88'));
  ck('καμία κάρτα υπηρεσίας', !html.includes('data-service='));
  ck('καμία λέξη «Valuable»', !/valuable/i.test(html));
}

console.log('\n═══ Ασυμφωνία εβδομάδας σελίδας / server ═══');
{
  // Ο server έχει παλιότερη έκδοση: λείπει η εβδομάδα 05/10.
  const older = JSON.parse(JSON.stringify(CATFIX));
  older.weeks = older.weeks.filter(w => w.week_start !== '2026-10-05');
  const { api, els } = env();
  api.renderDashboard(FIX, '2026-10-05', { state: 'ok', data: older });
  const html = els['content'].innerHTML;
  ck('λείπει η εβδομάδα: S1003 «—»', svcPct(svcCard(html, 'GR', 'S1003')) === '—', svcPct(svcCard(html, 'GR', 'S1003')));
  ck('λείπει η εβδομάδα: Air Cyprus «—», όχι 50.88', !html.includes('50.88') &&
     /Air Cyprus — τρέχουσα<\/div>\s*<div class="metric-value cy">—</.test(html));
  ck('λείπει η εβδομάδα: σημείωση', html.includes('διαφέρει, στα δεδομένα του server'));
  ck('AIR από το cache μένει 48.00', nextCard(html, 'AIR — τρέχουσα').includes('48.00%'));

  // Ίδια εβδομάδα, άλλη τιμή πηγής (το DHL διόρθωσε την τιμή).
  const revised = JSON.parse(JSON.stringify(CATFIX));
  revised.weeks.find(w => w.week_start === '2026-10-05').src.air = 47.5;
  const e2 = env();
  e2.api.renderDashboard(FIX, '2026-10-05', { state: 'ok', data: revised });
  const h2 = e2.els['content'].innerHTML;
  ck('άλλη τιμή πηγής: S1003 «—»', svcPct(svcCard(h2, 'GR', 'S1003')) === '—', svcPct(svcCard(h2, 'GR', 'S1003')));
  ck('άλλη τιμή πηγής: ROAD κάρτες κανονικά 40.00%', svcPct(svcCard(h2, 'GR', 'S1010')) === '40.00%');
  ck('άλλη τιμή πηγής: σημείωση', h2.includes('διαφέρει, στα δεδομένα του server'));
}

console.log('\n═══ Ασφάλεια εμφάνισης ═══');
{
  const evil = JSON.parse(JSON.stringify(CATFIX));
  evil.cards[0].service_name = '<img src=x onerror=alert(1)>';
  const { api, els } = env();
  api.renderDashboard(FIX, '2026-10-05', { state: 'ok', data: evil });
  ck('το service_name της βάσης γίνεται escape', !els['content'].innerHTML.includes('<img src=x'));
}

console.log('\n═══ Δομικά ═══');
ck('καμία λέξη «Valuable» ΠΟΥΘΕΝΑ στο αρχείο', !/valuable/i.test(src), (src.match(/.{0,30}valuable.{0,30}/i) || [])[0]);
ck('κανένας πολλαπλασιαστής 1.06 στη σελίδα', !src.includes('1.06'));
ck('κανένας πολλαπλασιασμός με multiplier σε JS', !/multiplier\)?\s*\*|\*\s*(Number\()?t\.multiplier/.test(src));
ck('οι κάρτες υπηρεσιών ΔΕΝ είναι γραμμένες στο αρχείο (S1027 μόνο από τη βάση)', !src.includes('S1027'));
ck('η σελίδα διαβάζει το api/fuel_catalog.php', src.includes("'/fuel_catalog.php'"));
ck('ΚΑΝΕΝΑ <input> ημερομηνίας στη σελίδα', !/<input[^>]*type="date"/i.test(src),
   (src.match(/<input[^>]*type="date"[^>]*>/i) || [])[0]);
ck('κανένα input/button «ημερομηνίας προβολής»',
   !src.includes('onViewDate') && !src.includes('onViewToday') && !src.includes('id="view-date"'));
ck('η ένδειξη είναι μόνο ανάγνωσης (span)', src.includes('id="view-date-label"'));
ck('η σελίδα παίρνει το σήμερα από το module',
   /const iso = FuelFreshness\.todayISO\(\);/.test(src));
// Τα σχόλια εξαιρούνται: το αρχείο ΠΕΡΙΓΡΑΦΕΙ το παλιό badge(-diff) σε σχόλιο.
const code = src.split(/\r?\n/).filter(l => !/^\s*(\/\/|\*|\/\*)/.test(l)).join('\n');
ck('κανένα badge(-diff...) σε ΚΩΔΙΚΑ', !/badge\(-\s*diff/.test(code));
ck('οι γραμμές ιστορικού δεν κρίνονται από r.is_current', !/r\.is_current \? '<strong>'/.test(src));
ck('καμία σήμανση από r.is_next', !src.includes('r.is_next'));
ck('η μεταβολή ορίζεται ως next.pct - curr.pct', /next\.pct - curr\.pct/.test(src));
// KNOWN BUG — ΡΑΦΙΑ 3 §5 #10, εκκρεμεί απόφαση ποιο κρατάμε.
// Δύο στοιχεία μοιράζονται το ίδιο id="last-update", που είναι άκυρη HTML:
// το document.getElementById() βρίσκει μόνο το πρώτο. ΔΕΝ είναι αναμενόμενη
// δομή. Ο έλεγχος καρφώνει το 2 ΜΟΝΟ ως φράχτη: αν γίνει 1, κάποιος το
// διόρθωσε και πρέπει να ενημερωθεί το #10· αν γίνει 3, το σφάλμα απλώνει.
ck('KNOWN BUG: ακόμα 2 στοιχεία με id="last-update" (ΡΑΦΙΑ 3 §5 #10)',
   (src.match(/id="last-update"/g) || []).length === 2,
   (src.match(/id="last-update"/g) || []).length);

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══\n`);
process.exit(fail ? 1 : 0);
