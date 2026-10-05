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

function env(){
  const els = {};
  const el = id => (els[id] = els[id] || { innerHTML: '', textContent: '', style: {} });
  const doc = { getElementById: id => el(id),
                querySelectorAll: () => [], createElement: () => ({ style:{}, setAttribute(){}, appendChild(){} }) };
  const win = {};
  new Function('window','localStorage','fetch','document', mod)(
    win, { getItem: () => null, setItem(){} }, () => Promise.reject(new Error('χωρίς δίκτυο')), doc);

  const fn = new Function('document','VALUABLE_MULTIPLIER','FuelFreshness',
    grab('badge') + '\n' + grab('calcDiff') + '\n' + grab('pickByDate') + '\n' +
    grab('pickNext') + '\n' + grab('renderDashboard') + '\n' +
    'return {badge, calcDiff, pickByDate, pickNext, renderDashboard};');
  return { api: fn(doc, 1.06, win.FuelFreshness), els, doc, FF: win.FuelFreshness };
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
  api.renderDashboard(FIX, iso);
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
  // 1.75 × 1.06 = 1.855, αλλά (1.855).toFixed(2) === '1.85' στη JS: το 1.855
  // δεν είναι ακριβώς αναπαραστάσιμο σε δυαδικό. ΠΡΟΫΠΑΡΧΟΥΣΑ συμπεριφορά
  // του toFixed, έξω από το εγκεκριμένο πεδίο — καταγράφεται, δεν «διορθώνεται».
  const bv = badgeIn(nextCard(html, 'Valuable — επόμενη'));
  ck('Valuable +1.85 / up (1.855 → 1.85, δες σχόλιο)',
     bv && bv.val === '+1.85' && bv.cls === 'up', JSON.stringify(bv));
}

// ── 4. Δευτέρα 05/10: η εβδομάδα γυρίζει ───────────────────────────────────
console.log('\n═══ Δευ 05/10 00:01  (now=2026-10-05) ═══');
{
  const { api, els } = env();
  api.renderDashboard(FIX, '2026-10-05');
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
  api.renderDashboard(FIX, '2026-10-12');
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
console.log('\n═══ Δομικά ═══');
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
