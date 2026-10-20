// Τρέχει τις ΠΡΑΓΜΑΤΙΚΕΣ συναρτήσεις του frontend/tasks.html για τις
// εργασίες συστήματος (εβδομαδιαίος γενικός επίναυλος): ομάδα στην κορυφή,
// στοιχεία κάρτας, και το xlsx ΜΟΝΟ από το payload.
//
// Το payload είναι το tests/frontend/fuel_weekly_payload.fixture.json, που
// το παρήγαγε η fuel_weekly_payload() του api/fuel_lib.php πάνω στο fixture
// 099ce2e. Το tests/php/fuel_weekly.test.php ελέγχει ότι ταυτίζεται ακόμα.
const fs = require('fs');
const path = require('path');
const HERE = __dirname;
const src = fs.readFileSync('frontend/tasks.html', 'utf8');
const NAVCHIP_SRC = fs.readFileSync('frontend/nav-chip.js', 'utf8');
// Κείμενα οθόνης (Π1, 15/10): το ΠΡΑΓΜΑΤΙΚΟ ui-texts.js, γεμισμένο με το ΙΔΙΟ
// seed του migration 2026-10-15b. Έτσι κάθε κείμενο που ελέγχεται εδώ είναι
// αυτό που θα δώσει η βάση, και κάθε κλειδί του κώδικα πρέπει να υπάρχει εκεί.
const UITEXTS_SRC = fs.readFileSync('frontend/ui-texts.js', 'utf8');
// Α2 (16/10): και τα 9 κείμενα της εβδομάδας του migration 2026-10-19b.
const UI_MIGS = ['db/migrations/2026-10-15b_ui_texts.sql', 'db/migrations/2026-10-19b_ui_texts_fuel_week.sql',
                 'db/migrations/2026-10-21a_ui_texts_fuel_card.sql'];
const UI_MIG = UI_MIGS.join(' + ');
// MIG_KINDS: kind ανά κλειδί (text | color). Το τεστ διαρροής ελέγχει ΜΟΝΟ
// κείμενα: ένα χρώμα της βάσης μπορεί να συμπίπτει με το CSS της σελίδας
// (π.χ. #cc0000, το κόκκινο της μάρκας), χωρίς να είναι κείμενο οθόνης (19/10).
const MIG_KINDS = {};
const MIG_TEXTS = (() => {
  const out = {};
  for (const f of UI_MIGS)
    for (const m of fs.readFileSync(f, 'utf8').matchAll(/\(\s*'([a-z_]+)',\s*'(\w+)',\s*'(text|color)',\s*'([^']*)'\)/g)) {
      (out[m[1]] = out[m[1]] || {})[m[2]] = m[4];
      MIG_KINDS[m[2]] = m[3];
    }
  return out;
})();
// texts === false: ο loader απέτυχε (καμία απάντηση από τη βάση).
const uiTextsCode = texts => UITEXTS_SRC + '\n' +
  (texts === false ? '' : 'UiTexts._merge(' + JSON.stringify({ ok: true, texts: texts || MIG_TEXTS }) + ');');
const PAYLOAD = JSON.parse(fs.readFileSync(path.join(HERE, 'fuel_weekly_payload.fixture.json'), 'utf8'));

function grab(name){
  const needle = 'function ' + name + '(';
  const i = src.indexOf(needle);
  if (i < 0) throw new Error('δεν βρέθηκε: ' + name);
  let d = 0, st = false;
  for (let j = i; j < src.length; j++){
    if (src[j] === '{'){ d++; st = true; }
    else if (src[j] === '}'){ d--; if (st && d === 0) return src.slice(i, j + 1); }
  }
  throw new Error(name);
}
function grabConst(name){
  const i = src.indexOf('const ' + name + ' =');
  if (i < 0) throw new Error('δεν βρέθηκε: ' + name);
  const j = src.indexOf(';\n', i);
  return src.slice(i, j + 1);
}

// Ό,τι στέλνει η βάση: καταστάσεις (4a_task_statuses) και τύποι επίναυλου
// (4a_fuel_types μέσω api/fuel_catalog.php). Το χρώμα του done είναι αυτό
// του migration 2026-09-23f.
const DB_STATUSES = { done: { code: 'done', label: 'Ολοκληρωμένη', color: '#9e9e9e' },
                      na:   { code: 'na',   label: 'Δεν εφαρμόζεται', color: '#cfcfcf' } };
// Σειρά εισαγωγής ΣΚΟΠΙΜΑ διαφορετική από το sort_order (10/20/30/40 του
// migration 2026-10-07a): οι στήλες πρέπει να ακολουθούν το sort_order.
// Εικονίδια όπως τα γράφει το migration 2026-10-08c.
const DB_FUEL_TYPES = { AIR_CY: { code: 'AIR_CY', label_el: 'Air Cyprus',     icon: '✈️', sort_order: 30 },
                        NONE:   { code: 'NONE',   label_el: 'Χωρίς επίναυλο', icon: null,  sort_order: 40 },
                        ROAD:   { code: 'ROAD',   label_el: 'Οδικός',         icon: '🚚', sort_order: 20 },
                        AIR:    { code: 'AIR',    label_el: 'Αεροπορικός',    icon: '✈️', sort_order: 10 } };

function env(opts){
  opts = opts || {};
  const els = {};
  const doc = { getElementById: id => (els[id] = els[id] || { innerHTML: '', style: {} }),
                baseURI: 'https://4aexpress.github.io/4a-pricing/frontend/tasks.html',
                querySelectorAll: () => opts.nav || [] };
  // Η πραγματική athensNow() δεν τρέχει στα τεστ: σταθερό «τώρα» Αθήνας.
  const NOW = opts.now === undefined ? '2026-10-08 10:00:00' : opts.now;
  const store = opts.perms ? { getItem: k => k === '4a_perms' ? JSON.stringify(opts.perms) : null } : { getItem: () => null };
  const code = [
    NAVCHIP_SRC, uiTextsCode(opts.texts),
    'const counts = ' + JSON.stringify(opts.counts || { mine: null, queue: null, all: null }) + ';',
    'let currentUser = { id: ' + (opts.userId === undefined ? 1 : opts.userId) + ' }, isAdmin = ' + (opts.admin === false ? 'false' : 'true') + ', view = ' + JSON.stringify(opts.view || 'all') + ', lastTasks = [];',
    'let STATUSES = ' + JSON.stringify(opts.statuses === undefined ? DB_STATUSES : opts.statuses) + ';',
    'let FUEL_TYPES = ' + JSON.stringify(opts.fuelTypes === undefined ? DB_FUEL_TYPES : opts.fuelTypes) + ';',
    'const histOpen = new Map(); const grpOpen = new Map();',
    grabConst('TIPS'), grabConst('ACTION_URL_OK'), grabConst('FUEL_CMS_HEADER'), grabConst('GR_DAY3'),
    grab('escHtml'), grab('textOn'), grab('stBadge'), grab('whoCell'), grab('histHtml'), grab('histWhat'),
    grab('canOpen'), grab('actionBtn'), grab('summaryHtml'),
    grab('fmtWeekRange'), grab('taskTitle'), grab('closedColor'), grab('closedGroupStyle'), grab('renderLegend'),
    grab('taskPayload'), grab('fuelCmsAoa'), grab('fuelCmsWorkbook'), grab('fmtDue'),
    'function athensNow(){ return ' + JSON.stringify(NOW) + '; }', grab('normDateTime'), grab('msLeft'), grab('fmtLeft'),
    grab('dueState'), grab('dueHtml'), grab('refreshDues'), grab('fuelColumns'), grab('fuelFactsHtml'),
    grabConst('STEP_UI_KEYS'), grab('uiText'),
    grab('stepTimelineHtml'), grab('stepMoreHtml'), grab('stepBtnHtml'), grab('stepCardHtml'), grab('uploadCheck'),
    grab('taskById'), grab('chainEntry'), grab('stripColorVar'), grabConst('FS_COLORS'), grab('fuelStripHtml'), grab('fuelStripMenu'),
    grabConst('UI_SCOPES'), grab('pageText'), grabConst('OVERDUE_COLOR'), grabConst('TONE_MIX'), grabConst('TONE_MIX_STRONG'),
    grab('tint'), grab('groupTone'), grab('addDaysISO'), grab('fuelActiveRoot'), grab('weekItems'), grab('dueClock'), grab('fuelWeekInfo'), grab('fuelStateHtml'),
    grab('grpIsOpen'), grab('grpTogHtml'), grab('groupHtml'),
    grab('taskRow'), grab('clientChipHtml'), grab('tabFromQuery'), grab('anchorFromHash'), grab('render'),
    'return { clientChipHtml, NavChip, tabFromQuery, anchorFromHash, render, renderLegend, closedGroupStyle, taskRow, fuelCmsAoa, fuelCmsWorkbook, fmtDue, fuelFactsHtml, fuelColumns,',
    '         stepCardHtml, stepTimelineHtml, stepMoreHtml, uploadCheck, uiText, STEP_UI_KEYS, fuelStripHtml, fuelStripMenu, taskById, chainEntry,',
    '         msLeft, fmtLeft, dueState, dueHtml, refreshDues, setTasks: ts => { lastTasks = ts; }, taskPayload, fmtWeekRange, taskTitle,',
    '         tint, groupTone, groupHtml, grpOpen, fuelWeekInfo, fuelStateHtml, fuelActiveRoot, weekItems, dueClock, pageText, UI_SCOPES, UiTexts };'
  ].join('\n');
  const api = new Function('document', 'sessionStorage', 'EV_LABELS', code)(doc, store, {});
  return { api, els };
}

// Γραμμές όπως τις δίνει η tasks_base_sql(): label, icon και title_template
// από τον 4a_task_types (migration 2026-10-08a).
const BASE = { assigned_to: null, assigned_name: null, status: 'open', needs_attention: 0, locked: 0,
               is_demo: 0, close_reason: null, offer_number: '', ready: true, ready_enforced: false };
// Όρια προθεσμίας του τύπου (migration 2026-10-08c): fuel_weekly 24 / 4,
// fuel_weekly_verify NULL μέχρι τη Φάση 5 (due_rule).
const FW  = { task_code: 'fuel_weekly', task_label: 'Γενικός επίναυλος εβδομάδας — αρχείο CMS',
              task_icon: '⛽', task_title_template: 'Γενικός επίναυλος {week} — αρχείο CMS', depends_on: null,
              due_warn_hours: 24, due_urgent_hours: 4 };
const FWV = { task_code: 'fuel_weekly_verify', task_label: 'Έλεγχος γενικού επίναυλου στο CMS',
              task_icon: '⛽', task_title_template: 'Έλεγχος γενικού επίναυλου {week} στο CMS', depends_on: 'fuel_weekly',
              due_warn_hours: null, due_urgent_hours: null };
const HIST_PAYLOAD = { kind: 'fuel_weekly', week_start: '2026-10-05', week_end: '2026-10-11',
  effective_date: '01-Oct-2026', retroactive: true, proof: { expected: 69, generated: 69, extra: 0, missing: 0 } };
const sysTask = (id, type, subject, payload, extra) => Object.assign({}, BASE, type, {
  id, client_id: null, client_name: null, client_country: 'BOTH', task_country: 'BOTH',
  subject_key: subject, due_at: '2026-10-09 17:00:00', payload: JSON.stringify(payload) }, extra || {});
const cliTask = (id, cid, name, extra) => Object.assign({}, BASE, {
  id, client_id: cid, client_name: name, client_country: 'GR', client_account: 'ACC', task_code: 'open_code',
  task_label: 'Άνοιγμα κωδικού', task_icon: null, task_title_template: null, depends_on: null,
  payload: JSON.stringify({ account: 'ACC' }) }, extra || {});
// Το HTML μιας ομάδας: από το άνοιγμά της μέχρι το επόμενο client-group.
const groupHtml = (html, marker) => {
  const m = html.indexOf(marker); if (m < 0) return '';
  const i = html.lastIndexOf('<div class="client-group', m);
  const j = html.indexOf('<div class="client-group', m);
  return html.slice(i, j < 0 ? html.length : j);
};

let pass = 0, fail = 0;
const ck = (l, c, extra) => { c ? pass++ : fail++;
  console.log(`   ${c ? 'OK  ' : 'ΛΑΘΟΣ'} ${l}${c ? '' : '   → ' + (extra === undefined ? '' : extra)}`); };
const clone = o => JSON.parse(JSON.stringify(o));

console.log('\npayload fixture: ' + PAYLOAD.week_start + ' · ' + PAYLOAD.effective_date + ' · ' + PAYLOAD.rows_count + ' γραμμές');

console.log('\n═══ fuelCmsAoa: οι γραμμές ΑΥΤΟΥΣΙΕΣ, ή null ═══');
{
  const { api } = env();
  const aoa = api.fuelCmsAoa(PAYLOAD);
  ck('έγκυρο payload -> 70 γραμμές (κεφαλίδα + 69)', aoa && aoa.length === 70, aoa && aoa.length);
  ck('ΙΔΙΟ αντικείμενο με το payload (καμία μετατροπή)', aoa === PAYLOAD.file.rows);
  const p1 = clone(PAYLOAD); p1.file.rows[5][7] = 49;
  ck('αριθμός αντί για κείμενο -> null', api.fuelCmsAoa(p1) === null);
  const p2 = clone(PAYLOAD); p2.file.rows[3].pop();
  ck('γραμμή 8 στηλών -> null', api.fuelCmsAoa(p2) === null);
  const p3 = clone(PAYLOAD); p3.file.rows[0][7] = 'Fuel';
  ck('λάθος κεφαλίδα -> null', api.fuelCmsAoa(p3) === null);
  ck('payload πελάτη -> null', api.fuelCmsAoa({ account: 'X', services: ['S1003'] }) === null);
  ck('null -> null', api.fuelCmsAoa(null) === null);
  ck('μόνο κεφαλίδα -> null', api.fuelCmsAoa(Object.assign(clone(PAYLOAD), { file: { rows: [PAYLOAD.file.rows[0]] } })) === null);
}

console.log('\n═══ fuelCmsWorkbook: φύλλο Table, κάθε κελί κείμενο ═══');
{
  const { api } = env();
  // Ψεύτικο XLSX με τη συμπεριφορά του SheetJS: οι αριθμοί γίνονται t:'n'.
  const XLSX = { utils: {
    aoa_to_sheet: aoa => { const ws = {}; aoa.forEach((r, i) => r.forEach((v, j) => {
      ws[String.fromCharCode(65 + j) + (i + 1)] = { v, t: typeof v === 'number' ? 'n' : 's' }; }));
      ws['!ref'] = 'A1:I' + aoa.length; return ws; },
    book_new: () => ({ SheetNames: [], Sheets: {} }),
    book_append_sheet: (wb, ws, n) => { wb.SheetNames.push(n); wb.Sheets[n] = ws; } } };
  const mixed = clone(PAYLOAD.file.rows); mixed[1][7] = 49;      // ακόμα κι αν ξέφευγε αριθμός
  const wb = api.fuelCmsWorkbook(XLSX, mixed);
  ck('ένα φύλλο, όνομα Table', wb.SheetNames.length === 1 && wb.SheetNames[0] === 'Table', JSON.stringify(wb.SheetNames));
  const cells = Object.keys(wb.Sheets.Table).filter(k => k[0] !== '!').map(k => wb.Sheets.Table[k]);
  // Όπως το αρχείο που δέχτηκε το CMS 07/10 (69/69/0/0): τα κενά κελιά ΔΕΝ γράφονται.
  ck('424 κελιά, όπως η αναφορά — τα κενά δεν γράφονται', cells.length === 424, cells.length);
  const nonEmpty = PAYLOAD.file.rows.reduce((n, r) => n + r.filter(v => v !== '').length, 0);
  ck('κελιά = μη κενές τιμές του payload', cells.length === nonEmpty, `${cells.length} / ${nonEmpty}`);
  ck('κανένα κελί με κενό κείμενο', cells.every(c => c.v !== ''));
  ck('A2 (ClientCode κενό) ΔΕΝ υπάρχει', wb.Sheets.Table.A2 === undefined);
  ck('ΟΛΑ t:"s", z:"@", v string', cells.every(c => c.t === 's' && c.z === '@' && typeof c.v === 'string'));
  ck('το H2 είναι "49" ως κείμενο', wb.Sheets.Table.H2.v === '49' && wb.Sheets.Table.H2.t === 's');
}

console.log('\n═══ Στοιχεία κάρτας ═══');
{
  const { api } = env();
  ck('fmtDue 2026-10-09 17:00:00 -> Παρ 09/10/2026 17:00', api.fmtDue('2026-10-09 17:00:00') === 'Παρ 09/10/2026 17:00', api.fmtDue('2026-10-09 17:00:00'));
  ck('fmtDue κενό -> —', api.fmtDue(null) === '—');
  ck('fmtDue short -> Παρ 09/10 17:00', api.fmtDue('2026-10-09 17:00:00', true) === 'Παρ 09/10 17:00', api.fmtDue('2026-10-09 17:00:00', true));
  const T = { id: 76, due_at: '2026-10-09 17:00:00', status: 'in_progress', locked: 0, due_warn_hours: 24, due_urgent_hours: 4 };
  const h = api.fuelFactsHtml(T, PAYLOAD);
  const ths = [...h.matchAll(/<th>([^<]*)<\/th>/g)].map(m => m[1]);
  const tds = [...h.matchAll(/<td>([^<]*)<\/td>/g)].map(m => m[1]);
  ck('πίνακας: μία γραμμή κεφαλίδας, μία γραμμή τιμών', (h.match(/<tr>/g) || []).length === 2 && h.includes('<table class="fuel-tbl">'), h);
  ck('στήλες με σειρά sort_order και εικονίδιο από τη βάση: ✈️ Αεροπορικός · 🚚 Οδικός · ✈️ Air Cyprus',
     JSON.stringify(ths) === JSON.stringify(['✈️ Αεροπορικός', '🚚 Οδικός', '✈️ Air Cyprus']), JSON.stringify(ths));
  const otherIco = env({ fuelTypes: Object.assign({}, DB_FUEL_TYPES, { ROAD: Object.assign({}, DB_FUEL_TYPES.ROAD, { icon: '🛻' }) }) }).api.fuelFactsHtml(T, PAYLOAD);
  ck('άλλο icon στη βάση -> άλλο εικονίδιο στην κεφαλίδα', otherIco.includes('<th>🛻 Οδικός</th>') && !otherIco.includes('🚚'), otherIco);
  const noIco = env({ fuelTypes: Object.assign({}, DB_FUEL_TYPES, { AIR: Object.assign({}, DB_FUEL_TYPES.AIR, { icon: null }) }) }).api.fuelFactsHtml(T, PAYLOAD);
  ck('icon NULL -> μόνο η ετικέτα, χωρίς κενό μπροστά', noIco.includes('<th>Αεροπορικός</th>'), noIco);
  ck('στο tasks.html: κανένα εικονίδιο τύπου (✈ / 🚚) γραμμένο σταθερά', !/✈|🚚/.test(src));
  ck('τιμές «49.00 %» · «40.75 %» · «51.94 %», κάτω από τη στήλη τους', JSON.stringify(tds) === JSON.stringify(['49.00 %', '40.75 %', '51.94 %']), JSON.stringify(tds));
  const swapped = env({ fuelTypes: { AIR: { code: 'AIR', label_el: 'Αεροπορικός', sort_order: 30 },
                                     ROAD: { code: 'ROAD', label_el: 'Οδικός', sort_order: 10 },
                                     AIR_CY: { code: 'AIR_CY', label_el: 'Air Cyprus', sort_order: 20 } } }).api.fuelFactsHtml(T, PAYLOAD);
  ck('άλλο sort_order στη βάση -> άλλη σειρά στηλών', [...swapped.matchAll(/<th>([^<]*)<\/th>/g)].map(m => m[1]).join('|') === 'Οδικός|Air Cyprus|Αεροπορικός', swapped);
  const plus = env({ fuelTypes: Object.assign({}, DB_FUEL_TYPES, { SEA: { code: 'SEA', label_el: 'Θαλάσσιος', sort_order: 25 } }) })
                 .api.fuelFactsHtml(T, Object.assign(clone(PAYLOAD), { prices: Object.assign({}, PAYLOAD.prices, { SEA: '12.50' }) }));
  ck('νέος τύπος στη βάση (με τιμή στο payload) -> νέα στήλη στη θέση του, χωρίς αλλαγή κώδικα',
     [...plus.matchAll(/<th>([^<]*)<\/th>/g)].map(m => m[1]).join('|') === '✈️ Αεροπορικός|🚚 Οδικός|Θαλάσσιος|✈️ Air Cyprus' && plus.includes('<td>12.50 %</td>'), plus);
  ck('κανένας κωδικός τύπου (AIR_CY, AIR, ROAD) στο HTML', !/AIR_CY|\bAIR\b|\bROAD\b/.test(h), h);
  ck('ο τύπος NONE (null) δεν εμφανίζεται', !h.includes('NONE') && !h.includes('Χωρίς επίναυλο'));
  ck('γραμμή: «Ισχύς από 12-Oct-2026 · Προθεσμία Παρ 09/10 17:00 (σε 1η 7ω) · 69 γραμμές»',
     h.replace(/<[^>]+>/g, '').includes('Ισχύς από 12-Oct-2026 · Προθεσμία Παρ 09/10 17:00 (σε 1η 7ω) · 69 γραμμές'), h.replace(/<[^>]+>/g, ''));
}

console.log('\n═══ Προθεσμία: χρώμα από τον χρόνο που μένει (παγωμένο now, ώρα Αθήνας) ═══');
{
  const { api } = env();
  const T = { id: 76, due_at: '2026-10-09 17:00:00', status: 'in_progress', locked: 0, due_warn_hours: 24, due_urgent_hours: 4 };
  const st = (now, t) => api.dueState(t || T, now);
  const cls = (now, t) => (st(now, t) || { cls: null }).cls;
  // Τα όρια: > 24ω πράσινο · 24ω έως 4ω (με τα όρια) πορτοκαλί · < 4ω κόκκινο + ΕΠΕΙΓΟΝ · μετά το due αναβοσβήνει.
  ck('due − 24ω − 1λ (Πεμ 16:59) -> πράσινο d-ok',         cls('2026-10-08 16:59:00') === 'd-ok', cls('2026-10-08 16:59:00'));
  ck('due − 24ω ακριβώς (Πεμ 17:00) -> πορτοκαλί d-warn',  cls('2026-10-08 17:00:00') === 'd-warn', cls('2026-10-08 17:00:00'));
  ck('due − 4ω − 1λ (Παρ 12:59) -> πορτοκαλί d-warn',      cls('2026-10-09 12:59:00') === 'd-warn', cls('2026-10-09 12:59:00'));
  ck('due − 4ω ακριβώς (Παρ 13:00) -> πορτοκαλί d-warn',   cls('2026-10-09 13:00:00') === 'd-warn', cls('2026-10-09 13:00:00'));
  ck('due − 4ω + 1λ (Παρ 13:01) -> κόκκινο d-urgent',      cls('2026-10-09 13:01:00') === 'd-urgent', cls('2026-10-09 13:01:00'));
  ck('due ακριβώς (Παρ 17:00) -> κόκκινο d-urgent, ΟΧΙ ακόμα καθυστέρηση', cls('2026-10-09 17:00:00') === 'd-urgent', cls('2026-10-09 17:00:00'));
  ck('due + 1λ (Παρ 17:01) -> d-over (αναβοσβήνει)',        cls('2026-10-09 17:01:00') === 'd-over', cls('2026-10-09 17:01:00'));
  ck('κείμενο: «σε 1η 7ω» / «σε 1η» (24ω) / «σε 4ω» / «σε 3ω 59λ» / «σε 0λ»',
     st('2026-10-08 10:00:00').left === 'σε 1η 7ω' && st('2026-10-08 17:00:00').left === 'σε 1η' && st('2026-10-09 13:00:00').left === 'σε 4ω'
     && st('2026-10-09 13:01:00').left === 'σε 3ω 59λ' && st('2026-10-09 17:00:00').left === 'σε 0λ',
     ['2026-10-08 10:00:00', '2026-10-08 17:00:00', '2026-10-09 13:00:00', '2026-10-09 13:01:00', '2026-10-09 17:00:00'].map(n => st(n).left).join(' | '));
  ck('κείμενο: «καθυστέρηση 1λ» / «καθυστέρηση 2ω» / «καθυστέρηση 1η 1ω»',
     st('2026-10-09 17:01:00').left === 'καθυστέρηση 1λ' && st('2026-10-09 19:00:00').left === 'καθυστέρηση 2ω' && st('2026-10-10 18:00:00').left === 'καθυστέρηση 1η 1ω',
     ['2026-10-09 17:01:00', '2026-10-09 19:00:00', '2026-10-10 18:00:00'].map(n => st(n).left).join(' | '));
  ck('μισό λεπτό μετά -> ήδη καθυστέρηση «1λ», όχι «0λ»', st('2026-10-09 17:00:30').cls === 'd-over' && st('2026-10-09 17:00:30').left === 'καθυστέρηση 1λ');
  const hu = api.dueHtml(T, '2026-10-09 15:00:00');
  ck('< 4ω: ετικέτα «ΕΠΕΙΓΟΝ»', hu.includes('<span class="due-tag">ΕΠΕΙΓΟΝ</span>') && hu.includes('class="fuel-due d-urgent"'), hu);
  ck('πορτοκαλί / πράσινο / καθυστέρηση: ΧΩΡΙΣ «ΕΠΕΙΓΟΝ»',
     !api.dueHtml(T, '2026-10-09 10:00:00').includes('ΕΠΕΙΓΟΝ') && !api.dueHtml(T, '2026-10-07 10:00:00').includes('ΕΠΕΙΓΟΝ')
     && !api.dueHtml(T, '2026-10-09 18:00:00').includes('ΕΠΕΙΓΟΝ'));
  ck('η ημερομηνία και δίπλα ο χρόνος: «Παρ 09/10 17:00 (σε 2ω)»', api.dueHtml(T, '2026-10-09 15:00:00').replace(/<[^>]+>/g, '') === 'Παρ 09/10 17:00 (σε 2ω)ΕΠΕΙΓΟΝ');
  // ΚΑΘΟΛΟΥ χρώμα: ολοκληρωμένη, «Δεν εφαρμόζεται», κλειδωμένη, τύπος χωρίς όρια.
  const late = '2026-10-20 09:00:00';
  const plain = t => api.dueHtml(t, late) === '<span class="fuel-due" id="due-76">Παρ 09/10 17:00</span>';
  ck('done, πολύ μετά το due -> ΚΑΘΟΛΟΥ χρώμα, κανένα κείμενο χρόνου', plain(Object.assign({}, T, { status: 'done' })), api.dueHtml(Object.assign({}, T, { status: 'done' }), late));
  ck('na -> ΚΑΘΟΛΟΥ χρώμα', plain(Object.assign({}, T, { status: 'na' })));
  ck('κλειδωμένη (η #77 «Περιμένει») -> ΚΑΘΟΛΟΥ χρώμα, δεν αναβοσβήνει', plain(Object.assign({}, T, { locked: 1 })) && plain(Object.assign({}, T, { locked: '1' })));
  ck('κλειδωμένη 2 λεπτά πριν το due -> επίσης χωρίς χρώμα', api.dueState(Object.assign({}, T, { locked: 1 }), '2026-10-09 16:58:00') === null);
  ck('τύπος χωρίς όρια στη βάση (NULL) -> ΚΑΘΟΛΟΥ χρώμα', plain(Object.assign({}, T, { due_warn_hours: null, due_urgent_hours: null })));
  // Η #77 (verify) ΞΕΚΛΕΙΔΩΜΕΝΗ, ανοιχτή, μετά το due: όρια NULL στη βάση -> τίποτα.
  const v77 = sysTask(77, FWV, 'fuel:2026-10-12', PAYLOAD, { status: 'open', locked: 0 });
  ck('verify ξεκλείδωτη, 1λ πριν / 1ω μετά το due: ΟΥΤΕ ΕΠΕΙΓΟΝ ΟΥΤΕ αναβοσβήνει (όρια NULL)',
     api.dueState(v77, '2026-10-09 16:59:00') === null && api.dueState(v77, '2026-10-09 18:00:00') === null
     && api.dueHtml(v77, '2026-10-09 18:00:00') === '<span class="fuel-due" id="due-77">Παρ 09/10 17:00</span>');
  ck('άκυρα όρια (warn < urgent) -> ΚΑΘΟΛΟΥ χρώμα', plain(Object.assign({}, T, { due_warn_hours: 2, due_urgent_hours: 4 })));
  // Όρια από τη βάση: άλλες τιμές -> άλλα σημεία αλλαγής. Από PDO έρχονται ως string.
  const T2 = Object.assign({}, T, { due_warn_hours: '48', due_urgent_hours: '12' });
  ck('όρια 48/12 από τη βάση (string): 30ω πριν -> πορτοκαλί, 11ω πριν -> κόκκινο',
     cls('2026-10-08 11:00:00', T2) === 'd-warn' && cls('2026-10-09 06:00:00', T2) === 'd-urgent' && cls('2026-10-07 16:00:00', T2) === 'd-ok');
  ck('στον κώδικα: κανένα 24 / 4 ως όριο (μόνο due_warn_hours / due_urgent_hours)',
     !/\b(24|4)\s*\*\s*3600000|3600000\s*\*\s*(24|4)\b/.test(grab('dueState')) && /due_warn_hours/.test(grab('dueState')) && /due_urgent_hours/.test(grab('dueState')));
  // Κανονικοποίηση της ώρας (T, χωρίς δευτερόλεπτα), και Z/offset = όχι Αθήνα.
  ck('«T» και χωρίς δευτερόλεπτα -> ίδιο αποτέλεσμα', cls('2026-10-09T17:00', Object.assign({}, T, { due_at: '2026-10-09T17:00' })) === 'd-urgent'
     && cls('2026-10-09T17:01', T) === 'd-over');
  ck('Z / offset -> null, ΚΑΘΟΛΟΥ χρώμα', api.dueState(Object.assign({}, T, { due_at: '2026-10-09T17:00:00Z' }), late) === null
     && api.dueState(T, '2026-10-20T00:00:00+03:00') === null);
  // CSS
  ck('d-over: CSS animation', /\.fuel-due\.d-over +\{[^}]*animation: due-blink/.test(src) && /@keyframes due-blink/.test(src));
  ck('prefers-reduced-motion -> σταθερό κόκκινο χωρίς αναβόσβημα',
     /@media \(prefers-reduced-motion: reduce\) \{ \.fuel-due\.d-over \{ animation: none; \} \}/.test(src) && /\.fuel-due\.d-over +\{ color: #cc0000;/.test(src));
  // Αντίθεση WCAG AA σε λευκό για τα τρία χρώματα.
  const lum = hx => { const v = [1, 3, 5].map(i => parseInt(hx.slice(i, i + 2), 16) / 255).map(c => c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
                      return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
  const ratio = hx => 1.05 / (lum(hx) + 0.05);
  for (const k of ['d-ok', 'd-warn', 'd-urgent']) {
    const m = new RegExp('\\.fuel-due\\.' + k + ' +\\{ color: (#[0-9a-f]{6}); \\}').exec(src);
    ck(`${k}: αντίθεση ≥ 4.5:1 σε λευκό`, m && ratio(m[1]) >= 4.5, m ? `${m[1]} ${ratio(m[1]).toFixed(2)}` : 'δεν βρέθηκε');
  }
}

console.log('\n═══ Προθεσμία: ανανέωση κάθε λεπτό χωρίς reload ═══');
{
  const { api, els } = env({ now: '2026-10-09 16:00:00' });
  const t76 = sysTask(76, FW, 'fuel:2026-10-12', PAYLOAD, { status: 'in_progress', assigned_to: 1 });
  api.setTasks([t76, cliTask(50, 5, 'Π')]);
  els['due-76'] = { outerHTML: 'ΠΑΛΙΟ' };
  api.refreshDues();
  ck('refreshDues() ξαναγράφει ΜΟΝΟ το due-76 με το τρέχον now', els['due-76'].outerHTML === api.dueHtml(t76, '2026-10-09 16:00:00') && els['due-76'].outerHTML.includes('σε 1ω'), els['due-76'].outerHTML);
  ck('δεν ξαναζωγραφίζει τη λίστα', els.list === undefined || els.list.innerHTML === '');
  ck('setInterval(refreshDues, 60000) στη σελίδα', /setInterval\(refreshDues, 60000\)/.test(src));
  ck('το στοιχείο της προθεσμίας έχει id due-<id>', api.dueHtml(t76, '2026-10-09 16:00:00').startsWith('<span class="fuel-due d-urgent" id="due-76">'));
}

console.log('\n═══ Στοιχεία κάρτας: ιστορικό, ετικέτες, κινητό, escape ═══');
{
  const { api } = env();
  const T = { id: 76, due_at: '2026-10-09 17:00:00', status: 'in_progress', locked: 0, due_warn_hours: 24, due_urgent_hours: 4 };
  const hist = api.fuelFactsHtml({}, HIST_PAYLOAD);
  ck('ιστορικό 2026-10-05: ισχύς 01-Oct-2026 και απόδειξη 69/69', hist.includes('01-Oct-2026') && hist.includes('69/69'), hist);
  ck('payload πελάτη: κανένα στοιχείο επίναυλου', api.fuelFactsHtml({}, { account: 'X' }) === '');
  const noLbl = env({ fuelTypes: {} }).api.fuelFactsHtml({}, PAYLOAD);
  ck('χωρίς ετικέτες από τη βάση: ΟΥΤΕ κωδικοί ΟΥΤΕ πίνακας, μόνο σημείωση',
     !/AIR_CY|\bAIR\b|\bROAD\b/.test(noLbl) && !noLbl.includes('<table') && noLbl.includes('οι ετικέτες δεν φορτώθηκαν'), noLbl);
  // Στατικά: καμία ετικέτα τύπου γραμμένη στο αρχείο, ο πίνακας χωράει στο κινητό.
  ck('στο tasks.html: καμία σταθερή ετικέτα (Αεροπορικός/Οδικός/Air Cyprus)', !/Αεροπορικ|Οδικ|Air Cyprus/.test(src));
  const tblCss = (src.match(/\.fuel-tbl \{[^}]*}/) || [''])[0];
  ck('κινητό: πίνακας width 100% + table-layout fixed (ίσες στήλες, χωρίς οριζόντια κύλιση)',
     /width: 100%/.test(tblCss) && /table-layout: fixed/.test(tblCss), tblCss);
  ck('desktop: max-width 480px, στοίχιση αριστερά (χωρίς margin auto)',
     /max-width: 480px/.test(tblCss) && !/margin[^;]*auto/.test(tblCss), tblCss);
  ck('ίσες στήλες: κανένα width ανά κελί/στήλη', !/<col|<th[^>]*(style|width)|<td[^>]*(style|width)/.test(grab('fuelFactsHtml')));
  ck('κινητό: κελιά με overflow-wrap και μπλοκ με min-width 0',
     /\.fuel-tbl th, \.fuel-tbl td \{[^}]*overflow-wrap: anywhere/.test(src) && /\.fuel-block \{[^}]*min-width: 0/.test(src));
  ck('τιμές στοιχισμένες στο κέντρο', /\.fuel-tbl th, \.fuel-tbl td \{[^}]*text-align: center/.test(src));
  const xss = env({ fuelTypes: { AIR: { code: 'AIR', label_el: '<img src=x onerror=alert(1)>', sort_order: 10 } } })
                .api.fuelFactsHtml(T, Object.assign(clone(PAYLOAD), { prices: { AIR: '"><script>x</script>' } }));
  ck('label_el και τιμή περνούν από escHtml (κανένα ωμό <img>/<script>)',
     !xss.includes('<img') && !xss.includes('<script') && xss.includes('&lt;img') && xss.includes('&lt;script&gt;'), xss);
  ck('Air Cyprus από p.prices: καμία πράξη ×1.06 / multiplier στο frontend', !/1\.06|multiplier/.test(grab('fuelColumns') + grab('fuelFactsHtml')));
  const athens = new Function(grab('athensNow') + '\nreturn athensNow();')();
  ck('athensNow() -> «YYYY-MM-DD HH:MM:SS» (ίδια μορφή με το due_at)', /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(athens), athens);
}

console.log('\n═══ Κάρτα επίναυλου μέσα στη λίστα ═══');
{
  const { api, els } = env();
  api.render([sysTask(76, FW, 'fuel:2026-10-12', PAYLOAD, { status: 'in_progress', assigned_to: 1, assigned_name: 'u1' }),
              sysTask(77, FWV, 'fuel:2026-10-12', PAYLOAD, { locked: 1 })]);
  const html = els.list.innerHTML;
  ck('ΚΑΙ οι δύο κάρτες (fuel_weekly, fuel_weekly_verify) έχουν πίνακα', (html.match(/<table class="fuel-tbl">/g) || []).length === 2);
  ck('η γραμμή εργασίας έχει has-fuel (το μπλοκ πέφτει σε όλο το πλάτος)', (html.match(/class="task[^"]*has-fuel/g) || []).length === 2);
  const i76 = html.indexOf('downloadFuelCms(76)'), iTbl = html.indexOf('fuel-block');
  ck('το μπλοκ είναι ΜΕΤΑ τα κουμπιά (ξεχωριστή σειρά), εκτός task-main', i76 > 0 && iTbl > i76, `${i76} / ${iTbl}`);
  ck('τα κουμπιά μένουν: Λήψη, Ολοκλήρωση', html.includes('⬇ Λήψη αρχείου CMS') && html.includes('>Ολοκλήρωση</button>'));
  ck('εργασία πελάτη: χωρίς has-fuel', !env().api.taskRow(cliTask(50, 5, 'Π')).includes('has-fuel'));
  // Διάταξη ΣΥΣΤΗΜΑ: τίτλος σε δική του σειρά, μετά ανάδοχος/κατάσταση/κουμπιά, μετά πίνακας.
  ck('και οι δύο εργασίες ΣΥΣΤΗΜΑ: sys-row', (html.match(/class="task[^"]*sys-row/g) || []).length === 2);
  const r76 = env({ statuses: Object.assign({ in_progress: { code: 'in_progress', label: 'Σε εξέλιξη', color: '#ef6c00' } }, DB_STATUSES) }).api.taskRow(sysTask(76, FW, 'fuel:2026-10-12', PAYLOAD, { status: 'in_progress', assigned_to: 1, assigned_name: 'u1' }));
  const pos = ['class="task-main"', 'class="who"', 'class="st"', 'class="acts"', 'class="fuel-block"'].map(s => r76.indexOf(s));
  ck('σειρά: τίτλος → ανάδοχος → κατάσταση → κουμπιά → πίνακας', pos.every((p, i) => p > 0 && (i === 0 || p > pos[i - 1])), pos.join(' < '));
  // 13-10-2026: ο κανόνας ισχύει πλέον για ΟΛΕΣ τις γραμμές (και πελάτη).
  ck('CSS: κάθε γραμμή αναδιπλώνεται και ο τίτλος (task-main) πιάνει όλο το πλάτος',
     /\n  \.task \{ flex-wrap: wrap; \}/.test(src) && /\n  \.task \.task-main \{ flex: 0 0 100%; \}/.test(src));
  ck('CSS: η κάρτα βημάτων κρατά τη δική της διάταξη (πιο ειδικός κανόνας)',
     /\.task\.has-steps \.task-main \{ flex: 1 1 auto; \}/.test(src));
  ck('εργασία πελάτη: χωρίς sys-row', !env().api.taskRow(cliTask(50, 5, 'Π')).includes('sys-row'));
}

// 13-10-2026: το φόντο ομάδας και το [+]/[−] αλλάζουν ΜΟΝΟ την εμφάνιση της
// λίστας. appearanceStrip() αφαιρεί ακριβώς αυτά, και τίποτε άλλο: τις κλάσεις
// tone open/late, το style με τα --tone-*, το κουμπί grp-tog και το
// <div class="grp-body"> γύρω από τις γραμμές. Ό,τι μένει πρέπει να είναι
// ΙΔΙΟ byte προς byte με το golden.
function appearanceStrip(html) {
  return html
    .replace(/ tone (open|late)"/g, '"')
    .replace(/ style="--tone-bg:[^"]*"/g, '')
    .replace(/\n {8}<button class="grp-tog"[^>]*>[^<]*<\/button>/g, '')
    .replace(/\n {6}<div class="grp-body"( hidden)?>/g, '')
    .replace(/\n {6}<\/div>\n {4}<\/div>/g, '\n    </div>')
    // 14-10-2026: μετά το όνομα πελάτη μπήκε το chip «🔗👥 Πελάτης», και η
    // ομάδα πήρε id (στόχος της επιστροφής). Τίποτε άλλο.
    .replace(/ <a class="nav-chip" href="[^"]*" title="[^"]*">[^<]*<\/a>/g, '')
    .replace(/ id="client-\d+"/g, '');
}

console.log('\n═══ Εργασίες ΠΕΛΑΤΗ: byte-προς-byte ίδιες με το 20588f5 (εκτός από την εμφάνιση της ομάδας) ═══');
{
  // tasks_client_rows.golden.json = η έξοδος του tasks.html στο 20588f5 (το
  // ζωντανό πριν από αυτή την αλλαγή) πάνω στις εργασίες του tasks_client_cases.js.
  const cases = require(path.join(HERE, 'tasks_client_cases.js'));
  const golden = JSON.parse(fs.readFileSync(path.join(HERE, 'tasks_client_rows.golden.json'), 'utf8'));
  let rows = 0, diff = [], lists = 0, toned = 0;
  for (const key of Object.keys(golden)) {
    const [v, who] = key.split(':');
    const els = {};
    const doc = { getElementById: id => (els[id] = els[id] || { innerHTML: '', style: {} }) };
    const code = [NAVCHIP_SRC, uiTextsCode(), 'let currentUser = { id: 1 }, isAdmin = ' + (who === 'admin') + ', view = "' + v + '", lastTasks = [];',
      'let STATUSES = ' + JSON.stringify(cases.STATUSES) + ';', 'let FUEL_TYPES = {};', 'const histOpen = new Map(); const grpOpen = new Map();',
      ...['UI_SCOPES', 'OVERDUE_COLOR', 'TONE_MIX', 'TONE_MIX_STRONG'].filter(c => src.includes('const ' + c + ' =')).map(grabConst),
      grabConst('TIPS'), grabConst('ACTION_URL_OK'), grabConst('FUEL_CMS_HEADER'), grabConst('GR_DAY3'),
      ...cases.FUNCS.filter(f => src.includes('function ' + f + '(')).map(grab),
      'return { render, taskRow };'].join('\n');
    const a = new Function('document', 'sessionStorage', 'EV_LABELS', code)(doc, { getItem: () => null }, {});
    cases.TASKS.forEach((t, i) => { rows++; const got = a.taskRow(t); if (got !== golden[key].rows[i]) diff.push(`${key} #${t.id}`); });
    a.render(cases.TASKS);
    lists++;
    if (els.list.innerHTML !== golden[key].list) toned++;
    if (appearanceStrip(els.list.innerHTML) !== golden[key].list) diff.push(`${key} λίστα`);
  }
  ck(`${rows} γραμμές πελάτη σε ${Object.keys(golden).length} συνδυασμούς προβολής/ρόλου: ΙΔΙΑ bytes, και οι ${lists} λίστες ΙΔΙΕΣ μετά το appearanceStrip()`,
     diff.length === 0, diff.join(', '));
  ck('η εμφάνιση ΟΝΤΩΣ άλλαξε (αλλιώς ο έλεγχος θα ήταν κενός)', toned === lists, `${toned}/${lists}`);
}

console.log('\n═══ Υπόμνημα: κουκκίδα «Ολοκληρωμένη» = φόντο κλειστής ομάδας ═══');
{
  const { api, els } = env({ statuses: Object.assign({}, DB_STATUSES, { done: Object.assign({}, DB_STATUSES.done, { icon: '✓', sort_order: 30 }) }) });
  api.renderLegend();
  const leg = els.legend.innerHTML;
  const dot = /<span class="legend-dot closed-dot" style="background:([^;]+);"><\/span><span class="legend-lbl">Ολοκληρωμένη</.exec(leg);
  const bg = /--closed-bg:([^;]+);/.exec(api.closedGroupStyle([{ status: 'done' }]) || '');
  ck('«Ολοκληρωμένη»: κουκκίδα (ακόμα κι αν η βάση δίνει εικονίδιο)', !!dot, leg);
  ck('ΙΔΙΟ χρώμα κουκκίδας και φόντου κλειστής ομάδας', dot && bg && dot[1] === bg[1], `${dot && dot[1]} / ${bg && bg[1]}`);
  ck('...και είναι το color του done από τη βάση (#9e9e9e)', bg && bg[1] === '#9e9e9e');
  const e2 = env({ statuses: Object.assign({}, DB_STATUSES, { done: Object.assign({}, DB_STATUSES.done, { color: '#123456' }) }) });
  e2.api.renderLegend();
  ck('άλλο χρώμα στη βάση -> αλλάζουν ΚΑΙ τα δύο μαζί',
     e2.els.legend.innerHTML.includes('closed-dot" style="background:#123456;"') && e2.api.closedGroupStyle([{ status: 'na' }]).includes('--closed-bg:#123456;'));
  ck('ένα σημείο: closedGroupStyle και renderLegend διαβάζουν closedColor()',
     /closedColor\(\)/.test(grab('closedGroupStyle')) && /closedColor\(\)/.test(grab('renderLegend')) && !/STATUSES\.done/.test(grab('closedGroupStyle')));
  ck('η κουκκίδα χωρίς σκίαση που θα άλλαζε την απόχρωση', /\.legend-dot\.closed-dot \{ box-shadow: none; \}/.test(src));
}

console.log('\n═══ Εβδομάδα: ίδιος τρόπος παντού ═══');
{
  const { api } = env();
  ck('12–18/10', api.fmtWeekRange('2026-10-12', '2026-10-18') === '12–18/10');
  ck('05–11/10', api.fmtWeekRange('2026-10-05', '2026-10-11') === '05–11/10');
  ck('αλλαγή μήνα: 28/09–04/10', api.fmtWeekRange('2026-09-28', '2026-10-04') === '28/09–04/10');
  // Η εβδομάδα γράφεται ΜΟΝΟ στον τίτλο (taskTitle), όχι ξανά στην κάρτα.
  const t12 = api.taskTitle(sysTask(76, FW, 'fuel:2026-10-12', PAYLOAD)), t05 = api.taskTitle(sysTask(75, FW, 'fuel:2026-10-05', HIST_PAYLOAD));
  ck('η 12/10 (με ετικέτα DHL στο payload) και η 05/10 (χωρίς) γράφονται ίδια στον τίτλο',
     t12.includes('12–18/10') && t05.includes('05–11/10'), t05);
  const h12 = api.fuelFactsHtml({}, PAYLOAD);
  ck('η ετικέτα του DHL («Οκτώβριος 12-18, 2026») ΔΕΝ εμφανίζεται', !h12.includes(PAYLOAD.week) && !t12.includes(PAYLOAD.week));
  ck('η κάρτα δεν ξαναγράφει την εβδομάδα (είναι στον τίτλο)', !h12.includes('12–18/10'));
}

console.log('\n═══ Τίτλος και εικονίδιο από τη βάση ═══');
{
  const { api } = env();
  const t76 = sysTask(76, FW, 'fuel:2026-10-12', PAYLOAD);
  ck('«⛽ Γενικός επίναυλος 12–18/10 — αρχείο CMS»',
     api.taskTitle(t76) === '<span class="task-icon">⛽</span>Γενικός επίναυλος 12–18/10 — αρχείο CMS', api.taskTitle(t76));
  ck('verify: «⛽ Έλεγχος γενικού επίναυλου 12–18/10 στο CMS»',
     api.taskTitle(sysTask(77, FWV, 'fuel:2026-10-12', PAYLOAD)).endsWith('Έλεγχος γενικού επίναυλου 12–18/10 στο CMS'));
  const other = Object.assign({}, t76, { task_icon: '🧾' });
  ck('το εικονίδιο έρχεται από τη γραμμή της βάσης (task_icon), όχι από τον κώδικα', api.taskTitle(other).includes('🧾') && !api.taskTitle(other).includes('⛽'));
  ck('τύπος χωρίς εικονίδιο (NULL): κανένα εικονίδιο, ο τίτλος = label', api.taskTitle(cliTask(50, 5, 'Π')) === 'Άνοιγμα κωδικού');
  const noWeek = Object.assign({}, t76, { payload: JSON.stringify({ kind: 'fuel_weekly' }) });
  ck('χωρίς εβδομάδα στο payload: το label, ποτέ ωμό {week}', !api.taskTitle(noWeek).includes('{week}') && api.taskTitle(noWeek).includes('Γενικός επίναυλος εβδομάδας'));
}

console.log('\n═══ Λίστα: εργασίες συστήματος στην ΚΟΡΥΦΗ ═══');
{
  const { api, els } = env();
  const tasks = [
    cliTask(50, 5, 'Πελάτης Α'),
    sysTask(90, FW, 'fuel:2026-10-12', PAYLOAD),
    sysTask(91, FWV, 'fuel:2026-10-12', PAYLOAD, { locked: 1, blocked_by_label: 'Γενικός επίναυλος εβδομάδας — αρχείο CMS' }),
  ];
  api.render(tasks);
  const html = els.list.innerHTML;
  const iSys = html.indexOf('client-group sys'), iCli = html.indexOf('Πελάτης Α');
  ck('ομάδα συστήματος ΠΡΙΝ από τους πελάτες', iSys >= 0 && iCli > iSys, `${iSys} / ${iCli}`);
  ck('ΜΙΑ ομάδα συστήματος για το fuel:2026-10-12', (html.match(/client-group sys/g) || []).length === 1);
  const head = html.slice(iSys, html.indexOf('</div>', html.indexOf('client-head', iSys)));
  ck('κεφαλίδα ΣΥΣΤΗΜΑ: «⛽ Γενικός επίναυλος 12–18/10 — αρχείο CMS»', head.includes('⛽') && head.includes('Γενικός επίναυλος 12–18/10 — αρχείο CMS'), head);
  ck('σήμανση ΣΥΣΤΗΜΑ και χώρα BOTH', head.includes('ΣΥΣΤΗΜΑ') && head.includes('flag BOTH'));
  ck('κουμπί «Λήψη αρχείου CMS»', html.includes('⬇ Λήψη αρχείου CMS') && html.includes('downloadFuelCms(90)'));
  ck('το verify (κλειδωμένο) με εικονίδιο και εβδομάδα', html.includes('🔒') && html.includes('Έλεγχος γενικού επίναυλου 12–18/10 στο CMS'));
  ck('η ομάδα του πελάτη έμεινε όπως ήταν', html.includes('AccountNo ACC') && html.includes('Άνοιγμα κωδικού'));
  ck('η εργασία πελάτη ΔΕΝ έχει κουμπί λήψης CMS ούτε εικονίδιο', !html.slice(iCli).includes('downloadFuelCms') && !html.slice(iCli).includes('⛽'));
  ck('κανένα «AIR_CY» σε ΟΛΟ το HTML της λίστας', !html.includes('AIR_CY'));
}

console.log('\n═══ Ολοκληρωμένη ομάδα: γκρι με το χρώμα του «done» από τη βάση ═══');
{
  const { api, els } = env();
  api.render([
    sysTask(75, FW, 'fuel:2026-10-05', HIST_PAYLOAD, { status: 'done', assigned_to: 1, assigned_name: 'u1' }),
    sysTask(76, FW, 'fuel:2026-10-12', PAYLOAD, { status: 'in_progress', assigned_to: 2, assigned_name: 'u2' }),
    sysTask(77, FWV, 'fuel:2026-10-12', PAYLOAD, { locked: 1 }),
    cliTask(50, 5, 'Πελάτης Κλειστός', { status: 'done' }),
    cliTask(51, 5, 'Πελάτης Κλειστός', { status: 'na', task_code: 'cms_cod', task_label: 'COD' }),
    cliTask(60, 6, 'Πελάτης Ανοιχτός', { status: 'done' }),
    cliTask(61, 6, 'Πελάτης Ανοιχτός', { status: 'open', task_code: 'cms_rates', task_label: 'Τιμές' }),
  ]);
  const html = els.list.innerHTML;
  const g05 = groupHtml(html, '05–11/10'), g12 = groupHtml(html, 'Γενικός επίναυλος 12–18/10');
  const gC = groupHtml(html, 'Πελάτης Κλειστός'), gO = groupHtml(html, 'Πελάτης Ανοιχτός');
  ck('ΣΥΣΤΗΜΑ 05/10 (όλες done): closed, φόντο #9e9e9e από τη βάση',
     /class="client-group sys closed"/.test(g05) && g05.includes('--closed-bg:#9e9e9e'), g05.slice(0, 160));
  ck('...με χρώμα κειμένου από την textOn() του ίδιου χρώματος', g05.includes('--closed-fg:#333'));
  ck('ΣΥΣΤΗΜΑ 12/10 (ενεργή): κανονική', !g12.includes('closed') && !g12.includes('--closed-bg'), g12.slice(0, 120));
  ck('πελάτης με done + na: closed', /class="client-group closed"/.test(gC) && gC.includes('--closed-bg:#9e9e9e'), gC.slice(0, 120));
  ck('πελάτης με done + open: κανονικός', !gO.includes('closed'), gO.slice(0, 120));
  // Χωρίς χρώμα «done» από τη βάση: καμία γκρι ομάδα — ΚΑΝΕΝΑ εφεδρικό χρώμα.
  const e2 = env({ statuses: {} });
  e2.api.render([sysTask(75, FW, 'fuel:2026-10-05', HIST_PAYLOAD, { status: 'done' })]);
  ck('χωρίς χρώμα «done» στη βάση: όχι closed, κανένα εφεδρικό χρώμα', !e2.els.list.innerHTML.includes('closed'));
}

console.log('\n═══ Υπότιτλος σελίδας από τη βάση (modules.description) ═══');
{
  const el = { textContent: 'παλιό', style: { display: '' } };
  const doc = { getElementById: id => id === 'page-sub' ? el : null };
  const renderPageSub = new Function('document', grab('renderPageSub') + '\nreturn renderPageSub;')(doc);
  const TXT = 'Εργασίες πελατών μετά την αποδοχή προσφοράς, και εβδομαδιαίες εργασίες συστήματος';
  renderPageSub(TXT);
  ck('κείμενο από τη βάση -> εμφανίζεται αυτούσιο', el.textContent === TXT && el.style.display === '', JSON.stringify(el));
  renderPageSub(null);
  ck('NULL -> κανένας υπότιτλος (κενό, κρυμμένο)', el.textContent === '' && el.style.display === 'none', JSON.stringify(el));
  renderPageSub('   ');
  ck('κενό κείμενο -> κανένας υπότιτλος', el.textContent === '' && el.style.display === 'none');
  ck('ο υπότιτλος γράφεται ως textContent (όχι innerHTML)', /el\.textContent = txt/.test(grab('renderPageSub')) && !/innerHTML/.test(grab('renderPageSub')));
  ck('στο αρχείο: ο υπότιτλος είναι ΑΔΕΙΟΣ, κανένα σταθερό κείμενο',
     /<p class="page-sub" id="page-sub" style="display:none;"><\/p>/.test(src) && !src.includes('Ό,τι προκύπτει όταν ένας πελάτης αποδεχτεί προσφορά'));
  ck('η load() καλεί renderPageSub(d.description)', /renderPageSub\(d\.description\)/.test(grab('load')));
}

// ═════════════════════════════════════════════════════════════════════
// ΚΑΡΤΑ ΒΗΜΑΤΩΝ (Φάση 5, Κ3α). Τα t.step / t.timeline / t.override είναι ό,τι
// στέλνει το api/tasks_steps.php. Τα κείμενα εδώ είναι ΔΟΚΙΜΑΣΤΙΚΑ.
const STEP = {
  download: { code: 'download', action: 'download', final: false, status_label: 'Αναμονή λήψης αρχείου',
              hint: 'Κατέβασε το αρχείο', button_label: 'Λήψη αρχείου CMS', button_icon: '⬇' },
  upload:   { code: 'upload', action: 'upload', final: false, status_label: 'Αναμονή ανεβάσματος',
              hint: 'Ανέβασε το αρχείο στο CMS (Import → Fuel Charge Import)', button_label: 'Επιβεβαίωση ανεβάσματος', button_icon: '⬆' },
  uploaded: { code: 'uploaded', action: null, final: true, status_label: 'Ανέβηκε · ισχύει από Δευ 12/10',
              hint: 'Τίποτα άλλο σήμερα. Η επαλήθευση τιμών γίνεται Δευ 12/10.', button_label: null, button_icon: null },
};
const UI = {
  h_do: 'ΤΙ ΠΡΕΠΕΙ ΝΑ ΚΑΝΕΙΣ', h_status: 'ΚΑΤΑΣΤΑΣΗ', who: 'Το κάνει: {name}',
  menu_override: 'Παράκαμψη (admin)…', tip_override: 'Override — Κλείνει την εργασία χωρίς τα βήματά της',
  tip_redownload: 'Download again — Κατεβάζει ξανά το αρχείο. Καταγράφεται, δεν αλλάζει το βήμα',
  tip_upload_wait: 'Complete — Πρώτα η λήψη του αρχείου', tip_upload_done: 'Completed — Ολοκληρώθηκε, δεν ξαναγίνεται',
  tip_more: 'More — Υπόλοιπες ενέργειες',
  ov_box: 'Παράκαμψη από {name} · {at}', ov_ack: 'Το είδα', tip_ack: 'Acknowledge — Σβήνει το κόκκινο σήμα',
  tl_late: '(εκ των υστέρων)', tip_late: 'Late entry — η ώρα είναι η πραγματική',
  up_title: 'Επιβεβαίωση ανεβάσματος', up_hint: 'Από το Merge Result του CMS (Import → Fuel Charge Import). Το αρχείο έχει {rows} γραμμές.',
  up_send: 'Επιβεβαίωση', up_err_fill: 'Συμπλήρωσε και τα 4 νούμερα (ακέραιοι ≥ 0)',
  up_err_total: 'Total {n} ≠ {rows} γραμμές του αρχείου', up_err_exc: 'Exception {n} ≠ 0',
  up_warn_prefix: 'Προσοχή:', up_warn_sum: 'Merge + Updated ≠ Total', up_warn_upd: 'Updated > 0: ενημερώθηκαν τιμές που υπήρχαν ήδη',
  ov_title: 'Παράκαμψη από διαχειριστή', ov_warn: 'Η εργασία κλείνει χωρίς τα βήματά της.', ov_reason: 'Λόγος',
  ov_short: 'Ο λόγος θέλει τουλάχιστον 10 χαρακτήρες.', ov_send: 'Παράκαμψη', already_closed: 'Η εργασία είναι ήδη ολοκληρωμένη',
};
// Φάση Α (Α2): ο server στέλνει και steps (όλα τα μη-τελικά βήματα με done)
// και allow (σημαίες του τύπου). Μετά το Α4 το fuel_weekly έχει allow = 0.
const STEPS = (d1, d2) => [
  { code: 'download', action: 'download', button_label: 'Λήψη',       button_icon: '⬇', done: d1 },
  { code: 'upload',   action: 'upload',   button_label: 'Ολοκλήρωση', button_icon: '✓', done: d2 }];
const ALLOW0 = { na: false, release: false, reject: false, steal: false };
const ALLOW1 = { na: true, release: true, reject: true, steal: true };
const stepTask = (step, extra) => sysTask(76, FW, 'fuel:2026-10-12', PAYLOAD,
  Object.assign({ status: 'in_progress', assigned_to: 1, assigned_name: 'u1', step, steps: STEPS(false, false), allow: ALLOW0,
                  timeline: [], override: null, ui: UI }, extra || {}));

console.log('\n═══ Α3 κάρτα: ΔΥΟ κουμπιά με τη σειρά, μόνο για τον ανάδοχο ═══');
{
  const own = env({ userId: 1, admin: false }).api;
  const btns = h => [...h.matchAll(/<button class="btn btn-go( step-done)?"([^>]*)>([^<]*)<\/button>/g)]
    .map(m => ({ done: !!m[1], attrs: m[2], label: m[3] }));
  let h = own.taskRow(stepTask(STEP.download));
  let b = btns(h);
  ck('taskRow με t.step -> κάρτα βημάτων, επικεφαλίδες από τη βάση', h.includes('class="task has-steps') &&
     h.includes('<div class="step-h">ΤΙ ΠΡΕΠΕΙ ΝΑ ΚΑΝΕΙΣ</div>') && h.includes('ΚΑΤΑΣΤΑΣΗ'));
  ck('πριν το ①: ΔΥΟ κουμπιά, με τη σειρά των βημάτων', b.length === 2 && b[0].label === '⬇ Λήψη' && b[1].label === '✓ Ολοκλήρωση', JSON.stringify(b));
  ck('...① ενεργό -> stepDownload', !b[0].done && /onclick="stepDownload\(76\)"/.test(b[0].attrs) && !/disabled/.test(b[0].attrs));
  ck('...② ανενεργό, χωρίς onclick, tooltip tip_upload_wait', !b[1].done && /disabled/.test(b[1].attrs) && !/onclick/.test(b[1].attrs)
     && b[1].attrs.includes('title="Complete — Πρώτα η λήψη του αρχείου"'), b[1].attrs);
  ck('...ετικέτα και οδηγία του βήματος από τη βάση', h.includes('<div class="step-lbl">Αναμονή λήψης αρχείου</div>') && h.includes('<div class="step-hint">Κατέβασε το αρχείο</div>'));
  ck('...κανένα badge κατάστασης (Ρ), ο πίνακας τιμών δεξιά (Υ5 α)', !h.includes('class="st"') && h.indexOf('ΚΑΤΑΣΤΑΣΗ') < h.indexOf('fuel-tbl'));

  h = own.taskRow(stepTask(STEP.upload, { steps: STEPS(true, false) })); b = btns(h);
  ck('μετά το ①: ① γκρι ΑΛΛΑ πατήσιμο, tooltip tip_redownload', b[0].done && /onclick="stepDownload\(76\)"/.test(b[0].attrs)
     && !/disabled/.test(b[0].attrs) && b[0].attrs.includes('title="Download again — Κατεβάζει ξανά το αρχείο. Καταγράφεται, δεν αλλάζει το βήμα"'), b[0].attrs);
  ck('...② ενεργό -> openUpload', !b[1].done && /onclick="openUpload\(76\)"/.test(b[1].attrs) && !/disabled/.test(b[1].attrs), b[1].attrs);

  h = own.taskRow(stepTask(STEP.uploaded, { status: 'done', steps: STEPS(true, true) })); b = btns(h);
  ck('μετά το ②: και τα δύο γκρι', b.length === 2 && b[0].done && b[1].done, JSON.stringify(b));
  ck('...το ② ΜΗ πατήσιμο, tooltip tip_upload_done', /disabled/.test(b[1].attrs) && !/onclick/.test(b[1].attrs)
     && b[1].attrs.includes('title="Completed — Ολοκληρώθηκε, δεν ξαναγίνεται"'), b[1].attrs);
  ck('...το ① ξαναπατιέται και σε κλειστή (file_viewed)', /onclick="stepDownload\(76\)"/.test(b[0].attrs) && !/disabled/.test(b[0].attrs));
  ck('...η οδηγία του τελικού βήματος από τη βάση', h.includes('Τίποτα άλλο σήμερα') && h.includes('Ανέβηκε · ισχύει από Δευ 12/10'));
  b = btns(own.taskRow(stepTask(STEP.uploaded, { status: 'done', steps: STEPS(true, false) })));
  ck('κλειστή με παράκαμψη πριν το ②: ② γκρι και μη πατήσιμο', b[1].done && /disabled/.test(b[1].attrs) && !/onclick/.test(b[1].attrs));
  ck('χωρίς έγκυρο αρχείο στο payload: κανένα κουμπί λήψης, το ② μένει', (() => {
    const x = btns(own.taskRow(stepTask(STEP.download, { payload: JSON.stringify({ kind: 'fuel_weekly', week_start: '2026-10-12' }) })));
    return x.length === 1 && x[0].label === '✓ Ολοκλήρωση'; })());
  ck('χωρίς t.steps (παλιός server): κανένα κουμπί, χωρίς σφάλμα', btns(own.taskRow(stepTask(STEP.download, { steps: undefined }))).length === 0);

  const adm9 = env({ userId: 9, admin: true }).api.taskRow(stepTask(STEP.download));
  ck('admin που ΔΕΝ είναι ανάδοχος: κανένα κουμπί βήματος, «Το κάνει: u1»', btns(adm9).length === 0 && adm9.includes('Το κάνει: u1'));
  const adm1 = env({ userId: 1, admin: true }).api.taskRow(stepTask(STEP.download));
  ck('admin που ΕΙΝΑΙ ανάδοχος: τα δύο κουμπιά', btns(adm1).length === 2);
  const u3 = env({ userId: 3, admin: false }).api.taskRow(stepTask(STEP.upload, { steps: STEPS(true, false) }));
  ck('συνάδελφος: κανένα κουμπί βήματος, «Το κάνει: u1»', btns(u3).length === 0 && u3.includes('Το κάνει: u1'));
  const q = own.taskRow(stepTask(STEP.download, { status: 'open', assigned_to: null, assigned_name: null }));
  ck('αδιάθετη: ΚΑΝΕΝΑ κουμπί, ούτε «Ανάληψη» (Δ-Α2: μόνο «Ανάθεση…» από admin)', btns(q).length === 0 && !q.includes('claim(76)'));
  const lk = own.taskRow(stepTask(STEP.download, { locked: 1 }));
  ck('κλειδωμένη: κανένα κουμπί', btns(lk).length === 0 && lk.includes('🔒'));
  const x = '<img src=x onerror=alert(1)>';
  const esc = own.taskRow(stepTask(STEP.download, { steps: [{ code: 'download', action: 'download', button_label: x, button_icon: '"><b>', done: false }] }));
  ck('ετικέτα και εικονίδιο κουμπιού από τη βάση: escaped', !esc.includes('<img') && !esc.includes('"><b>') && esc.includes('&lt;img'));
}

console.log('\n═══ Α3 κάρτα: ΠΟΤΕ Δεν εφαρμόζεται / Αφήνω / Αναλαμβάνω / Απόρριψη / Έτοιμη για ολοκλήρωση ═══');
{
  const NEVER = /Δεν εφαρμόζεται|Αφήνω|Αναλαμβάνω|Απόρριψη|Έτοιμη για ολοκλήρωση|>Ανάληψη<|Προβολή αρχείου|markNa\(|act\(76,'done'\)|act\(76,'release'\)|openReject\(|steal\(76\)|claim\(76\)/;
  const cases = [['πριν το ①', stepTask(STEP.download)], ['μετά το ①', stepTask(STEP.upload, { steps: STEPS(true, false) })],
                 ['κλειστή', stepTask(STEP.uploaded, { status: 'done', steps: STEPS(true, true) })],
                 ['αδιάθετη', stepTask(STEP.download, { status: 'open', assigned_to: null, assigned_name: null })]];
  const bad = [];
  for (const [lbl, t] of cases)
    for (const [u, adm] of [[1, false], [1, true], [9, true], [3, false]]) {
      const h = env({ userId: u, admin: adm }).api.taskRow(t);
      if (NEVER.test(h)) bad.push(`${lbl} / ${u}${adm ? ' admin' : ''}: ${h.match(NEVER)[0]}`);
    }
  ck('4 καταστάσεις × 4 χρήστες: κανένα από αυτά στην κάρτα (allow = 0, όπως μετά το Α4)', bad.length === 0, bad.join(' | '));
}

console.log('\n═══ Α3 «⋯»: admin Ανάθεση + Παράκαμψη + ιστορικό, μη-admin μόνο ιστορικό ═══');
{
  const menu = h => { const m = /<div class="step-menu">([\s\S]*?)<\/div><\/details>/.exec(h); return m ? m[1] : null; };
  const names = h => [...menu(h).matchAll(/onclick="(\w+)\(/g)].map(m => m[1]);
  const t = stepTask(STEP.download);
  ck('admin (όχι ανάδοχος): Ανάθεση…, Παράκαμψη, ιστορικό — με αυτή τη σειρά',
     JSON.stringify(names(env({ userId: 9, admin: true }).api.taskRow(t))) === '["assign","openOverride","toggleHist"]',
     names(env({ userId: 9, admin: true }).api.taskRow(t)).join(','));
  ck('admin ανάδοχος: Ανάθεση…, ιστορικό (ΟΧΙ Παράκαμψη)',
     JSON.stringify(names(env({ userId: 1, admin: true }).api.taskRow(t))) === '["assign","toggleHist"]');
  ck('μη-admin ανάδοχος: μόνο ιστορικό', JSON.stringify(names(env({ userId: 1, admin: false }).api.taskRow(t))) === '["toggleHist"]');
  ck('μη-admin συνάδελφος: μόνο ιστορικό', JSON.stringify(names(env({ userId: 3, admin: false }).api.taskRow(t))) === '["toggleHist"]');
  const un = stepTask(STEP.download, { status: 'open', assigned_to: null, assigned_name: null });
  ck('αδιάθετη, admin: «Ανάθεση…» (ο μόνος δρόμος, Δ-Α2)', names(env({ userId: 9, admin: true }).api.taskRow(un)).includes('assign'));
  ck('κλειστή, admin: μόνο ιστορικό', JSON.stringify(names(env({ userId: 9, admin: true }).api.taskRow(
     stepTask(STEP.uploaded, { status: 'done', steps: STEPS(true, true) })))) === '["toggleHist"]');
  // Ο τύπος τα επιτρέπει (allow = 1): τα γενικά κουμπιά επιστρέφουν, ίδια με πριν.
  const a1 = stepTask(STEP.download, { allow: ALLOW1 });
  ck('allow = 1: ανάδοχος -> Αφήνω και Απόρριψη…', JSON.stringify(names(env({ userId: 1, admin: false }).api.taskRow(a1))) === '["act","openReject","toggleHist"]');
  ck('allow = 1: συνάδελφος -> Αναλαμβάνω', JSON.stringify(names(env({ userId: 3, admin: false }).api.taskRow(a1))) === '["steal","toggleHist"]');
  ck('χωρίς t.allow: τα γενικά κουμπιά κρυφά', JSON.stringify(names(env({ userId: 1, admin: false }).api.taskRow(
     stepTask(STEP.download, { allow: undefined })))) === '["toggleHist"]');
  ck('ΚΑΜΙΑ «Προβολή αρχείου» στο «⋯» σε καμία περίπτωση (το ① ξαναπατιέται)', !/downloadFuelCms\(/.test(grab('stepMoreHtml')));
}

console.log('\n═══ Κάρτα βημάτων: χρονολόγιο ═══');
{
  const api = env({ userId: 1, admin: false }).api;
  const tl = [
    { event: 'downloaded', label: 'Λήψη αρχείου', mark: 'step', alert: false, at: '2026-10-09 10:15:00', late: false, actor: 'u1', note: 'λήψη', meta: null },
    { event: 'upload_failed', label: 'Ανέβασμα χωρίς επιβεβαίωση', mark: 'event', alert: false, at: '2026-10-09 10:40:00', late: false, actor: 'u1', note: 'x',
      meta: { total: 68, merge: 0, updated: 68, exception: 0, rows_count: 69 } },
    { event: 'uploaded', label: 'Ανέβηκε στο CMS', mark: 'step', alert: false, at: '2026-10-09 10:45:00', late: true, actor: 'u1', note: 'x',
      meta: { total: 69, merge: 69, updated: 0, exception: 0, rows_count: 69, occurred_at: '2026-10-09 10:45:00' } },
    { event: 'admin_override', label: 'Παράκαμψη από διαχειριστή', mark: 'event', alert: true, at: '2026-10-09 11:00:00', late: false, actor: 'adm',
      note: '<b>CMS</b> έπεσε', meta: { step: 'upload' } },
  ];
  const h = api.stepTimelineHtml({ timeline: tl, ui: UI });
  const rows = h.split('<div class="tl-row');
  ck('4 γραμμές, με τη σειρά του server', rows.length === 5, h);
  ck('βήμα: «✓ Λήψη αρχείου · Παρ 09/10 10:15 · u1»', rows[1].includes('<span class="tl-mark">✓</span>Λήψη αρχείου · Παρ 09/10 10:15 · u1'), rows[1]);
  ck('αποτυχία: χωρίς ✓, με τα 4 νούμερα του CMS', !rows[2].includes('✓') && rows[2].includes('Total 68 · Merge 0 · Updated 68 · Exception 0'), rows[2]);
  ck('εκ των υστέρων: σήμανση', rows[3].includes('(εκ των υστέρων)'));
  ck('παράκαμψη: κόκκινη γραμμή, ο λόγος escaped', rows[4].startsWith(' alert"') && rows[4].includes('&lt;b&gt;CMS&lt;/b&gt; έπεσε') && !rows[4].includes('<b>CMS'), rows[4]);
  ck('κενό χρονολόγιο -> τίποτα', api.stepTimelineHtml({ timeline: [], ui: UI }) === '' && api.stepTimelineHtml({ ui: UI }) === '');
}

console.log('\n═══ Κάρτα βημάτων: παράκαμψη και «Το είδα» ═══');
{
  const ov = { event_id: 9, actor_id: 9, actor_name: 'adm', at: '2026-10-09 11:00:00', acked: false, acked_by: null };
  const t = stepTask(STEP.uploaded, { status: 'done', override: ov });
  const other = env({ userId: 1, admin: true }).api.taskRow(t);
  ck('άλλος admin: κόκκινο πλαίσιο με «Το είδα»', other.includes('class="step-ov"') && other.includes('onclick="overrideAck(76)">Το είδα</button>'));
  const self = env({ userId: 9, admin: true }).api.taskRow(t);
  ck('ο admin της παράκαμψης: πλαίσιο ΧΩΡΙΣ «Το είδα»', self.includes('class="step-ov"') && !self.includes('overrideAck('));
  const user = env({ userId: 3, admin: false }).api.taskRow(t);
  ck('μη-admin: χωρίς «Το είδα»', !user.includes('overrideAck('));
  const acked = env({ userId: 1, admin: true }).api.taskRow(Object.assign({}, t, { override: Object.assign({}, ov, { acked: true }) }));
  ck('επιβεβαιωμένη: κανένα πλαίσιο', !acked.includes('class="step-ov"'));
}

console.log('\n═══ Κάρτα βημάτων: escape κειμένων της βάσης ═══');
{
  const x = '<img src=x onerror=alert(1)>';
  const h = env({ userId: 1, admin: false }).api.taskRow(stepTask({ code: 'download', action: 'download', final: false,
    status_label: x, hint: x, button_label: x, button_icon: '"><b>' }));
  ck('ετικέτα, οδηγία, κουμπί, εικονίδιο: κανένα ωμό HTML', !h.includes('<img') && !h.includes('"><b>') && h.includes('&lt;img'));
}

console.log('\n═══ Merge Result: ίδιος κανόνας με τον server (Π) ═══');
{
  const api = env().api;
  const c = (v) => api.uploadCheck(69, v, UI);
  ck('69 / 69 / 0 / 0: OK χωρίς προειδοποιήσεις', c({ total: '69', merge: '69', updated: '0', exception: '0' }).ok === true
     && c({ total: '69', merge: '69', updated: '0', exception: '0' }).warns.length === 0);
  const u = c({ total: '69', merge: '0', updated: '69', exception: '0' });
  ck('69 / 0 / 69 / 0: OK, προειδοποίηση Updated > 0 (όχι φραγή)', u.ok === true && u.warns.length === 1 && u.warns[0].startsWith('Updated > 0'), JSON.stringify(u));
  const s = c({ total: '69', merge: '60', updated: '0', exception: '0' });
  ck('Merge + Updated ≠ Total: OK με προειδοποίηση', s.ok === true && s.warns[0] === 'Merge + Updated ≠ Total', JSON.stringify(s));
  const t = c({ total: '68', merge: '68', updated: '0', exception: '0' });
  ck('Total 68 ≠ 69: ΟΧΙ', t.ok === false && t.errs[0] === 'Total 68 ≠ 69 γραμμές του αρχείου', JSON.stringify(t));
  const e = c({ total: '69', merge: '68', updated: '0', exception: '1' });
  ck('Exception 1: ΟΧΙ', e.ok === false && e.errs.includes('Exception 1 ≠ 0'), JSON.stringify(e));
  ck('κενό / αρνητικό / δεκαδικό: ΟΧΙ', [{ total: '', merge: '0', updated: '0', exception: '0' },
       { total: '69', merge: '-1', updated: '0', exception: '0' }, { total: '69.5', merge: '0', updated: '0', exception: '0' }]
       .every(v => c(v).ok === false));
}

console.log('\n═══ Κάρτα βημάτων: κείμενα από τη βάση (t.ui) ═══');
{
  const api = env({ userId: 9, admin: true }).api;
  const used = [...new Set([...src.matchAll(/uiText\([^,]+,\s*'(\w+)'/g)].map(m => m[1]))].sort();
  const keys = [...api.STEP_UI_KEYS].sort();
  ck('κάθε uiText(…, κλειδί) του κώδικα είναι στο STEP_UI_KEYS, και αντίστροφα', JSON.stringify(used) === JSON.stringify(keys),
     'κώδικας: ' + used.join(',') + ' | λίστα: ' + keys.join(','));
  ck('το UI των τεστ καλύπτει ΟΛΑ τα κλειδιά', keys.every(k => typeof UI[k] === 'string'), keys.filter(k => typeof UI[k] !== 'string').join(','));
  // Α4 (22/10): το migration ενεργοποίησης γράφει ΑΚΡΙΒΩΣ τα STEP_UI_KEYS (κανένα [κλειδί], κανένα περιττό).
  const m22 = fs.readFileSync('db/migrations/2026-10-22a_fuel_weekly_activate.sql', 'utf8');
  const ui22 = [...m22.matchAll(/\('fuel_weekly', 'ui', '(\w+)',\s*'([^']*)'\)/g)].map(m => m[1]).sort();
  ck(`22a: τα ${ui22.length} κείμενα kind = ui = STEP_UI_KEYS του κώδικα (ούτε λείπει ούτε περισσεύει)`, JSON.stringify(ui22) === JSON.stringify(keys),
     'λείπουν: ' + keys.filter(k => !ui22.includes(k)).join(',') + ' | περισσεύουν: ' + ui22.filter(k => !keys.includes(k)).join(','));
  const other = Object.assign({}, UI, { h_do: 'ΑΛΛΟ ΚΕΙΜΕΝΟ', menu_override: 'Άλλη παράκαμψη' });
  const h = api.taskRow(stepTask(STEP.download, { ui: other }));
  ck('το κείμενο αλλάζει ΜΟΝΟ από τη βάση', h.includes('ΑΛΛΟ ΚΕΙΜΕΝΟ') && h.includes('Άλλη παράκαμψη') && !h.includes('ΤΙ ΠΡΕΠΕΙ ΝΑ ΚΑΝΕΙΣ'));
  const miss = api.taskRow(stepTask(STEP.download, { ui: {} }));
  ck('κλειδί που λείπει: φαίνεται ως [κλειδί], όχι κενό', miss.includes('[h_do]') && miss.includes('[menu_override]'));
  ck('placeholders: {name}', api.uiText(UI, 'who', { name: 'u1' }) === 'Το κάνει: u1');
  ck('placeholder χωρίς τιμή μένει όπως είναι', api.uiText(UI, 'up_err_total', { n: 68 }) === 'Total 68 ≠ {rows} γραμμές του αρχείου');
  const xss = api.taskRow(stepTask(STEP.download, { ui: Object.assign({}, UI, { h_do: '<img src=x>', who: '<b>{name}</b>' }), assigned_to: 2 }));
  ck('κείμενα ui escaped', !xss.includes('<img') && !xss.includes('<b>u1') && xss.includes('&lt;img'));
  ck('κανένα σταθερό κείμενο της κάρτας στον κώδικα', !/ΤΙ ΠΡΕΠΕΙ ΝΑ ΚΑΝΕΙΣ|Το κάνει:|Παράκαμψη \(admin\)|Προβολή αρχείου|Το είδα|ήδη ολοκληρωμένη/.test(
     ['stepCardHtml','stepMoreHtml','stepTimelineHtml','uploadCheck','openUpload','openOverride','sendOverride','showAlreadyClosed']
       .map(grab).join('\n').replace(/^\s*\/\/.*$/gm, '')));   // τα σχόλια δεν είναι κείμενο οθόνης
}

console.log('\n═══ 409 «ήδη κλειστή»: πληροφορία, όχι σφάλμα ═══');
{
  ck('sendUpload: already_closed -> showAlreadyClosed, πριν από το μήνυμα σφάλματος',
     /already_closed\)\{[\s\S]*showAlreadyClosed\(t\)[\s\S]*up-msg/.test(grab('sendUpload')));
  ck('sendOverride: already_closed -> showAlreadyClosed', /already_closed\)\{[^}]*showAlreadyClosed\(t\)/.test(grab('sendOverride')));
  const sac = grab('showAlreadyClosed');
  ck('showAlreadyClosed: πράσινο μήνυμα (showOk), καθαρίζει το σφάλμα, ξαναφορτώνει',
     /showErr\(null\)/.test(sac) && /showOk\(/.test(sac) && /uiText\(t && t\.ui, 'already_closed'\)/.test(sac) && /load\(\)/.test(sac));
  ck('το act() (γενικό «Ολοκλήρωση») ΔΕΝ άλλαξε', !/already_closed/.test(grab('act')));
}

console.log('\n═══ Κάρτα βημάτων: η σημερινή εμπειρία χωρίς t.step ═══');
{
  const api = env().api;
  const plain = sysTask(76, FW, 'fuel:2026-10-12', PAYLOAD, { status: 'in_progress', assigned_to: 1, assigned_name: 'u1' });
  const h = api.taskRow(plain);
  ck('χωρίς t.step: η γνωστή κάρτα (Ολοκλήρωση, Δεν εφαρμόζεται, λήψη)', !h.includes('has-steps') && h.includes("act(76,'done')")
     && h.includes('markNa(76)') && h.includes('downloadFuelCms(76)'));
  ck('t.step = null (na): η γνωστή κάρτα', !api.taskRow(Object.assign({}, plain, { step: null })).includes('has-steps'));
  ck('η downloadFuelCms στέλνει download σιωπηλά (quiet) ΜΕΤΑ το writeFile',
     /XLSX\.writeFile[\s\S]*post\(\{action:'download', id\}, true\)/.test(grab('downloadFuelCms')));
}

console.log('\n═══ Δομικά ═══');
{
  const fns = ['fuelCmsAoa', 'fuelCmsWorkbook', 'fuelFactsHtml'].map(grab).join('\n');
  ck('καμία μορφοποίηση αριθμών στις συναρτήσεις επίναυλου (toFixed/parseFloat/Number)',
     !/toFixed|parseFloat|Number\(/.test(fns));
  ck('το xlsx γράφεται ΜΟΝΟ από το payload (downloadFuelCms -> fuelCmsAoa)', /fuelCmsAoa\(p\)/.test(grab('downloadFuelCms')));
  const closedCss = (src.match(/\.client-group\.closed[^}]*}/g) || []).join(' ');
  ck('οι κανόνες CSS της κλειστής ομάδας ΔΕΝ έχουν σταθερό χρώμα (μόνο var)', closedCss.length > 0 && !/#[0-9a-f]{3,6}/i.test(closedCss), closedCss);
  ck('όνομα αρχείου FuelChargeImport_GR_CY_<ισχύς>.xlsx', /'FuelChargeImport_GR_CY_' \+ p\.effective_date \+ '\.xlsx'/.test(src));
}

// ── Φόντο ομάδας ανά κατάσταση και [+]/[−] (13-10-2026) ─────────────────
// Χρώματα όπως στη βάση (migration 2026-09-23f): in_progress #f0a000, done #9e9e9e.
const ST_DB = Object.assign({}, DB_STATUSES, { in_progress: { code: 'in_progress', label: 'Σε εξέλιξη', color: '#f0a000' } });
// WCAG 2.x: σχετική φωτεινότητα και λόγος αντίθεσης.
const lumOf = hex => { const h = hex.length === 4 ? hex.slice(1).split('').map(c => c + c).join('') : hex.slice(1);
  const n = parseInt(h, 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); })
    .reduce((s, v, i) => s + v * [0.2126, 0.7152, 0.0722][i], 0); };
const ratio = (a, b) => { const x = lumOf(a), y = lumOf(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
const vars = style => Object.fromEntries([...String(style).matchAll(/--([\w-]+):(#(?:[0-9a-f]{6}|[0-9a-f]{3}))(?=;)/gi)].map(m => [m[1], m[2]]));

console.log('\n═══ Φόντο ομάδας: κίτρινο / κόκκινο / γκρι ═══');
{
  // «Τώρα» 08/10 10:00 (Αθήνα): προθεσμία 09/10 = σε χρόνο, 07/10 = καθυστέρηση.
  const { api, els } = env({ statuses: ST_DB });
  const open = api.groupTone([cliTask(1, 7, 'Α', { status: 'in_progress', due_at: '2026-10-09 17:00:00' })]);
  ck('ανοιχτή, σε χρόνο -> tone open', open && open.cls === 'open', JSON.stringify(open));
  const ov = vars(open.style);
  ck('κίτρινο: ΟΛΗ η ομάδα (κεφαλίδα και σώμα) γεμάτο λεμονί του CMS #FCFF29, μαύρα γράμματα',
     ov['tone-bg'] === '#fcff29' && ov['tone-fg'] === '#000' && ov['tone-fg2'] === '#000', open.style);
  ck('CSS: κεφαλίδα και γραμμές της ομάδας παίρνουν το ΙΔΙΟ --tone-bg',
     /\.client-group\.tone, \.client-group\.tone > \.client-head, \.client-group\.tone > \.grp-body > \.task \{\s*background: var\(--tone-bg\)/.test(src));
  ck('το λεμονί έρχεται από τη βάση (4a_ui_texts color_open_head), όχι γραμμένο στη groupTone',
     /pageText\('color_open_head'\)/.test(grab('groupTone')) && !/FCFF29/i.test(grab('groupTone')));
  ck('χωρίς κείμενα από τη βάση (loader απέτυχε) -> κανένα tone, η σημερινή εμφάνιση',
     env({ texts: false }).api.groupTone([cliTask(1, 7, 'Α', { status: 'in_progress' })]) === null);
  const late = api.groupTone([cliTask(1, 7, 'Α', { status: 'open', due_at: '2026-10-07 17:00:00' }),
                              cliTask(2, 7, 'Α', { status: 'done' })]);
  ck('έστω μία ανοιχτή σε καθυστέρηση -> tone late (και για πελάτη, χωρίς όρια προθεσμίας)', late && late.cls === 'late', JSON.stringify(late));
  ck('κόκκινο = απόχρωση του κόκκινου της καθυστέρησης (#cc0000)', late && vars(late.style)['tone-bg'] === api.tint('#cc0000', 0.14));
  ck('κλειδωμένη με περασμένη προθεσμία -> ΟΧΙ καθυστέρηση (δεν τρέχει ακόμα)',
     api.groupTone([cliTask(1, 7, 'Α', { status: 'open', locked: 1, due_at: '2026-10-07 17:00:00' })]).cls === 'open');
  ck('ολοκληρωμένη με περασμένη προθεσμία -> ΟΧΙ καθυστέρηση',
     api.groupTone([cliTask(1, 7, 'Α', { status: 'done', due_at: '2026-10-07 17:00:00' }), cliTask(2, 7, 'Α', { status: 'in_progress' })]).cls === 'open');
  ck('κλειστή ομάδα -> κανένα tone (μένει το σημερινό γκρι)',
     api.groupTone([cliTask(1, 7, 'Α', { status: 'done' }), cliTask(2, 7, 'Α', { status: 'na' })]) === null);
  ck('κίτρινο: ΔΕΝ εξαρτάται από το χρώμα in_progress των καταστάσεων',
     env({ statuses: DB_STATUSES }).api.groupTone([cliTask(1, 7, 'Α', { status: 'in_progress' })]).cls === 'open');
  ck('πορτοκαλί χωρίς χρώμα in_progress στη βάση -> κανένα tone, όχι επινοημένο χρώμα',
     env({ statuses: DB_STATUSES }).api.groupTone([cliTask(1, 7, 'Α', { status: 'in_progress' })], undefined, 'orange') === null);

  api.render([
    sysTask(76, FW, 'fuel:2026-10-12', PAYLOAD, { status: 'done' }), sysTask(77, FWV, 'fuel:2026-10-12', PAYLOAD, { status: 'done' }),
    sysTask(80, FW, 'fuel:2026-10-19', PAYLOAD, { status: 'in_progress', due_at: '2026-10-16 17:00:00' }),
    cliTask(1, 7, 'Κίτρινος', { status: 'in_progress', due_at: '2026-10-09 17:00:00' }),
    cliTask(2, 8, 'Κόκκινος', { status: 'open', due_at: '2026-10-07 17:00:00' }),
    cliTask(3, 9, 'Γκρι', { status: 'done' }),
  ]);
  const L = els.list.innerHTML;
  const heads = [...L.matchAll(/<div class="(client-group[^"]*)"( id="client-\d+")?( style="[^"]*")?>/g)].map(m => m[1]);
  ck('ΚΑΜΙΑ λευκή ομάδα: κάθε ομάδα είναι tone ή closed', heads.length === 5 && heads.every(c => / tone (open|late)$| closed$/.test(c)), heads.join(' | '));
  ck('ΣΥΣΤΗΜΑ κλειστή = γκρι · ΣΥΣΤΗΜΑ ανοιχτή = κίτρινο',
     heads[0] === 'client-group sys closed' && heads[1] === 'client-group sys tone open', heads.slice(0, 2).join(' | '));
  ck('πελάτες: κίτρινο / κόκκινο / γκρι', heads[2] === 'client-group tone open' && heads[3] === 'client-group tone late' && heads[4] === 'client-group closed',
     heads.slice(2).join(' | '));
  ck('το γκρι της κλειστής ΔΕΝ άλλαξε (closedGroupStyle όπως πριν)', L.includes('style="--closed-bg:#9e9e9e;--closed-fg:#333;"'));
}

console.log('\n═══ Αντίθεση κειμένου σε κάθε φόντο (WCAG, όριο 4.5:1) ═══');
{
  const { api } = env({ statuses: ST_DB });
  for (const [lbl, items] of [['κίτρινο', [cliTask(1, 7, 'Α', { status: 'in_progress', due_at: '2026-10-09 17:00:00' })]],
                              ['κόκκινο', [cliTask(1, 7, 'Α', { status: 'open', due_at: '2026-10-07 17:00:00' })]]]) {
    const v = vars(api.groupTone(items).style);
    ck(`${lbl}: κύριο κείμενο ${v['tone-fg']} σε ${v['tone-bg']} = ${ratio(v['tone-fg'], v['tone-bg']).toFixed(2)}:1 ≥ 4.5`, ratio(v['tone-fg'], v['tone-bg']) >= 4.5);
    ck(`${lbl}: δευτερεύον ${v['tone-fg2']} σε ${v['tone-bg']} = ${ratio(v['tone-fg2'], v['tone-bg']).toFixed(2)}:1 ≥ 4.5`, ratio(v['tone-fg2'], v['tone-bg']) >= 4.5);
  }
  const c = vars(api.closedGroupStyle([cliTask(1, 7, 'Α', { status: 'done' })]));
  ck(`γκρι (αμετάβλητο): ${c['closed-fg']} σε ${c['closed-bg']} = ${ratio(c['closed-fg'], c['closed-bg']).toFixed(2)}:1 ≥ 4.5`, ratio(c['closed-fg'], c['closed-bg']) >= 4.5);
}

console.log('\n═══ [+]/[−] ανά ομάδα, ΚΑΙ για πελάτες ═══');
{
  const { api, els } = env({ statuses: ST_DB });
  const ts = [cliTask(1, 7, 'Ανοιχτός', { status: 'in_progress' }), cliTask(3, 9, 'Κλειστός', { status: 'done' }),
              sysTask(76, FW, 'fuel:2026-10-12', PAYLOAD, { status: 'done' })];
  api.render(ts);
  let L = els.list.innerHTML;
  ck('ανοιχτή ομάδα πελάτη: [−], aria-expanded=true, γραμμές ορατές',
     L.includes('data-g="c:7" aria-expanded="true"') && /data-g="c:7"[^>]*>−<\/button>\s*<\/div>\s*<div class="grp-body">/.test(L));
  ck('κλειστή ομάδα πελάτη: [+], μαζεμένη (hidden)', /data-g="c:9" aria-expanded="false"[^>]*>\+<\/button>\s*<\/div>\s*<div class="grp-body" hidden>/.test(L));
  ck('κλειστή ομάδα ΣΥΣΤΗΜΑ: [+], μαζεμένη', /data-g="sys:fuel:2026-10-12" aria-expanded="false"[^>]*>\+<\/button>\s*<\/div>\s*<div class="grp-body" hidden>/.test(L));
  ck('οι γραμμές της μαζεμένης ομάδας ΥΠΑΡΧΟΥΝ στο HTML (κρύβονται, δεν αλλάζουν)', L.includes(api.taskRow(ts[1])));
  api.grpOpen.set('c:9', true); api.grpOpen.set('c:7', false);
  api.render(ts); L = els.list.innerHTML;
  ck('η επιλογή του χρήστη υπερισχύει: κλειστή ανοίγει, ανοιχτή μαζεύει',
     /data-g="c:9" aria-expanded="true"/.test(L) && /data-g="c:7" aria-expanded="false"/.test(L));
  ck('το κλειδί περνά από escHtml (subject_key από τη βάση)', /data-g="\$\{escHtml\(key\)\}"/.test(grab('grpTogHtml')));
  ck('toggleGroup ξαναζωγραφίζει από το lastTasks, χωρίς δίκτυο', /render\(lastTasks\)/.test(grab('toggleGroup')) && !/post\(|fetch\(|load\(/.test(grab('toggleGroup')));
}

// ── Εβδομάδες επίναυλου: σειρά, ένδειξη, κόκκινο/πορτοκαλί (13-10-2026) ──
// Ισχύς όπως τη γράφει ο server (fuel_lib.php fuel_effective_date): dd-Mon-yyyy από το week_start,
// και στο payload και στη στήλη EffectiveDate του αρχείου. week_end = '' όπως πριν (golden).
const effDate = ws => ws.slice(8, 10) + '-' + ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][Number(ws.slice(5, 7)) - 1] + '-' + ws.slice(0, 4);
// FROZEN_PAY: ΜΟΝΟ για το golden της πύλης Α3 (tasks_fuel_gate.golden.json, πριν το Α3): ισχύς του fixture όπως ήταν.
let FROZEN_PAY = false;
const wkPay = ws => FROZEN_PAY ? Object.assign({}, PAYLOAD, { week_start: ws, week_end: '' }) : Object.assign({}, PAYLOAD, { week_start: ws, week_end: '', effective_date: effDate(ws),
  file: Object.assign({}, PAYLOAD.file, { rows: PAYLOAD.file.rows.map((r, i) => i ? r.slice(0, 8).concat([effDate(ws)]) : r) }) });
// Μια εβδομάδα: ανέβασμα (ρίζα) + επαλήθευση, με προθεσμία Παρ πριν το week_start 17:00.
const fuelWeek = (id, ws, up, ver, extra) => {
  const due = addDays(ws, -3) + ' 17:00:00';
  return [sysTask(id, FW, 'fuel:' + ws, wkPay(ws), Object.assign({ status: up, due_at: due }, extra && extra.up)),
          sysTask(id + 1, FWV, 'fuel:' + ws, wkPay(ws), Object.assign({ status: ver, due_at: due }, extra && extra.ver))];
};
function addDays(iso, n) { const d = new Date(Date.UTC(+iso.slice(0, 4), +iso.slice(5, 7) - 1, +iso.slice(8, 10) + n)); return d.toISOString().slice(0, 10); }
const NOW13 = '2026-10-13 12:00:00';

console.log('\n═══ Εβδομάδα επίναυλου: φάση και ένδειξη ═══');
{
  const { api } = env({ statuses: ST_DB, now: NOW13 });
  const I = (ws, up, ver, now) => api.fuelWeekInfo(fuelWeek(1, ws, up, ver), now || NOW13);
  let i = I('2026-10-19', 'in_progress', 'open');
  ck('επόμενη, ανέβασμα ανοιχτό: «◷ ΠΡΟΣ ΕΚΤΕΛΕΣΗ · προθεσμία Παρ 16/10 17:00», χωρίς χρώμα',
     i.phase === 'next' && i.text === '◷ ΠΡΟΣ ΕΚΤΕΛΕΣΗ · προθεσμία Παρ 16/10 17:00' && i.alert === null, JSON.stringify(i));
  i = I('2026-10-19', 'done', 'in_progress');
  ck('επόμενη, ανέβηκε, επαλήθευση ανοιχτή: «⏳ Ανέβηκε · εκκρεμεί επαλήθευση» (14/10), πορτοκαλί',
     i.text === '⏳ Ανέβηκε · εκκρεμεί επαλήθευση' && i.alert === 'orange', JSON.stringify(i));
  i = I('2026-10-19', 'done', 'done');
  ck('επόμενη, όλα έτοιμα: «✓ Έτοιμη · ισχύει από Δευ 19/10»', i.text === '✓ Έτοιμη · ισχύει από Δευ 19/10' && i.alert === null, JSON.stringify(i));
  i = I('2026-10-12', 'in_progress', 'open');
  ck('σε ισχύ, ανέβασμα ΟΧΙ done: «⚠ Σε ισχύ · CMS ΜΗ ενημερωμένο», κόκκινο',
     i.phase === 'now' && i.text === '⚠ Σε ισχύ · CMS ΜΗ ενημερωμένο' && i.alert === 'red', JSON.stringify(i));
  i = I('2026-10-12', 'done', 'in_progress');
  ck('σε ισχύ, ανέβηκε, επαλήθευση ανοιχτή: πορτοκαλί, ΟΧΙ κόκκινο', i.text === '⏳ Ανέβηκε · εκκρεμεί επαλήθευση' && i.alert === 'orange');
  i = I('2026-10-12', 'done', 'done');
  ck('σε ισχύ, όλα done: «✓ Σε ισχύ · CMS ενημερωμένο»', i.text === '✓ Σε ισχύ · CMS ενημερωμένο' && i.alert === null);
  ck('«na» στο ανέβασμα ΔΕΝ μετρά ως ενημερωμένο CMS (κόκκινο)', I('2026-10-12', 'na', 'na').alert === 'red');
  i = I('2026-10-05', 'done', 'done');
  ck('παλαιότερη: φάση older, καμία ένδειξη', i.phase === 'older' && i.text === null);
  ck('όριο: Κυρ 18/10 23:59:59 -> η 19/10 είναι ακόμα «επόμενη»', I('2026-10-19', 'done', 'done', '2026-10-18 23:59:59').phase === 'next');
  ck('όριο: Δευ 19/10 00:00:00 -> η 19/10 «σε ισχύ», η 12/10 «παλαιότερη»',
     I('2026-10-19', 'done', 'done', '2026-10-19 00:00:00').phase === 'now' && I('2026-10-12', 'done', 'done', '2026-10-19 00:00:00').phase === 'older');
  ck('ομάδα που δεν είναι επίναυλος -> null', api.fuelWeekInfo([cliTask(1, 7, 'Α')], NOW13) === null);
}

console.log('\n═══ Εβδομάδα επίναυλου: χρώμα ομάδας ═══');
{
  const { api } = env({ statuses: ST_DB, now: NOW13 });
  const T = (items, now) => { const info = api.fuelWeekInfo(items, now || NOW13); return api.groupTone(items, now || NOW13, info.alert); };
  ck('σε ισχύ, ανέβασμα ανοιχτό -> κόκκινο', T(fuelWeek(1, '2026-10-12', 'in_progress', 'open')).cls === 'late');
  const o = T(fuelWeek(1, '2026-10-19', 'done', 'in_progress'));
  ck('ανέβηκε, επαλήθευση ανοιχτή, σε χρόνο -> πορτοκαλί (warn)', o.cls === 'warn', JSON.stringify(o));
  ck('πορτοκαλί = ΙΔΙΟ χρώμα in_progress της βάσης, πιο έντονο (35%)', vars(o.style)['tone-bg'] === api.tint('#f0a000', 0.35), o.style);
  ck('σε ισχύ, ανέβηκε, επαλήθευση ΠΕΡΑΣΕ την προθεσμία της -> κόκκινο (κανόνας καθυστέρησης)',
     T(fuelWeek(1, '2026-10-12', 'done', 'in_progress')).cls === 'late');
  ck('επόμενη, ανέβασμα ανοιχτό, προθεσμία ΟΧΙ περασμένη -> κίτρινο', T(fuelWeek(1, '2026-10-19', 'in_progress', 'open')).cls === 'open');
  ck('επόμενη, ανέβασμα ανοιχτό, προθεσμία ΠΕΡΑΣΜΕΝΗ (Σαβ 17/10) -> κόκκινο',
     T(fuelWeek(1, '2026-10-19', 'in_progress', 'open'), '2026-10-17 10:00:00').cls === 'late');
  ck('κανένα νέο χρώμα: μόνο in_progress της βάσης και το κόκκινο της καθυστέρησης',
     !/#ef6c00/i.test(grab('groupTone') + grab('fuelStateHtml')) && /OVERDUE_COLOR/.test(grab('fuelStateHtml')) && /STATUSES\.in_progress/.test(grab('fuelStateHtml')));
  const red = api.fuelStateHtml(api.fuelWeekInfo(fuelWeek(1, '2026-10-12', 'open', 'open'), NOW13));
  const org = api.fuelStateHtml(api.fuelWeekInfo(fuelWeek(1, '2026-10-19', 'done', 'open'), NOW13));
  ck('ένδειξη κόκκινη: πλακίδιο #cc0000 με λευκό κείμενο', red.includes('background:#cc0000;color:#fff;'), red);
  ck('ένδειξη πορτοκαλί: πλακίδιο in_progress (#f0a000) με σκούρο κείμενο', org.includes('background:#f0a000;color:#333;'), org);
  console.log('\n═══ Αντίθεση: πορτοκαλί φόντο και πλακίδια ═══');
  const v = vars(o.style);
  ck(`πορτοκαλί: κύριο ${v['tone-fg']} σε ${v['tone-bg']} = ${ratio(v['tone-fg'], v['tone-bg']).toFixed(2)}:1 ≥ 4.5`, ratio(v['tone-fg'], v['tone-bg']) >= 4.5);
  ck(`πορτοκαλί: δευτερεύον ${v['tone-fg2']} σε ${v['tone-bg']} = ${ratio(v['tone-fg2'], v['tone-bg']).toFixed(2)}:1 ≥ 4.5`, ratio(v['tone-fg2'], v['tone-bg']) >= 4.5);
  ck(`πλακίδιο κόκκινο: #fff σε #cc0000 = ${ratio('#ffffff', '#cc0000').toFixed(2)}:1 ≥ 4.5`, ratio('#ffffff', '#cc0000') >= 4.5);
  ck(`πλακίδιο πορτοκαλί: #333 σε #f0a000 = ${ratio('#333', '#f0a000').toFixed(2)}:1 ≥ 4.5`, ratio('#333', '#f0a000') >= 4.5);
}

console.log('\n═══ Σειρά: επόμενη -> σε ισχύ -> «Παλαιότερες εβδομάδες (N)» ═══');
{
  const { api, els } = env({ statuses: ST_DB, now: NOW13 });
  api.render([...fuelWeek(70, '2026-09-28', 'done', 'done'), ...fuelWeek(75, '2026-10-05', 'done', 'done'),
              ...fuelWeek(76, '2026-10-12', 'done', 'done'), ...fuelWeek(80, '2026-10-19', 'in_progress', 'open'),
              cliTask(1, 7, 'Πελάτης', { status: 'in_progress', due_at: '2026-10-20 17:00:00' })]);
  const L = els.list.innerHTML;
  const keys = [...L.matchAll(/data-g="([^"]+)"/g)].map(m => m[1]);
  ck('σειρά: 19/10, 12/10, Παλαιότερες (05/10, 28/09), μετά οι πελάτες',
     JSON.stringify(keys) === JSON.stringify(['sys:fuel:2026-10-19', 'sys:fuel:2026-10-12', 'sys:older', 'sys:fuel:2026-10-05', 'sys:fuel:2026-09-28', 'c:7']),
     keys.join(' '));
  ck('«Παλαιότερες εβδομάδες (2)», μαζεμένη με [+]',
     L.includes('Παλαιότερες εβδομάδες (2)') && /data-g="sys:older" aria-expanded="false"[^>]*>\+<\/button>\s*<\/div>\s*<div class="grp-body" hidden>/.test(L));
  ck('οι εβδομάδες 05/10 και 28/09 είναι ΜΕΣΑ στις «Παλαιότερες»',
     L.indexOf('data-g="sys:older"') < L.indexOf('data-g="sys:fuel:2026-10-05"') && L.indexOf('data-g="sys:fuel:2026-09-28"') < L.indexOf('data-g="c:7"'));
  ck('ένδειξη στις κεφαλίδες: 19/10 «ΠΡΟΣ ΕΚΤΕΛΕΣΗ», 12/10 «Σε ισχύ · CMS ενημερωμένο»',
     L.includes('◷ ΠΡΟΣ ΕΚΤΕΛΕΣΗ · προθεσμία Παρ 16/10 17:00') && L.includes('✓ Σε ισχύ · CMS ενημερωμένο'));
  ck('κανένα «Παλαιότερες» όταν δεν υπάρχουν παλαιότερες', (() => {
    const e = env({ statuses: ST_DB, now: NOW13 }); e.api.render(fuelWeek(80, '2026-10-19', 'in_progress', 'open'));
    return !e.els.list.innerHTML.includes('sys:older'); })());
}

console.log('\n═══ Κείμενα οθόνης από τη βάση (4a_ui_texts, Π1 15/10) ═══');
{
  const { api } = env();
  ck('το UI_TEMP και το tempText ΔΕΝ υπάρχουν πια στον κώδικα', !/const UI_TEMP\b/.test(src) && !/tempText\(/.test(src));
  ck('UI_SCOPES = tasks, common', JSON.stringify(api.UI_SCOPES) === '["tasks","common"]');
  // Κάθε pageText('…') του κώδικα (και τα δύο σκέλη ενός «? 'α' : 'β'»).
  const used = [...new Set([...src.matchAll(/pageText\(\s*(?:[^'()]*\?\s*)?'(\w+)'(?:\s*:\s*'(\w+)')?/g)].flatMap(m => [m[1], m[2]]).filter(Boolean))].sort();
  const inScopes = k => api.UI_SCOPES.some(s => MIG_TEXTS[s] && k in MIG_TEXTS[s]);
  ck(`κάθε pageText(…) του κώδικα (${used.length}) έχει κλειδί στο seed του ${UI_MIG}, σε scope της σελίδας`,
     used.length >= 10 && used.every(inScopes), used.filter(k => !inScopes(k)).join(',') || String(used.length));
  // Σχόλια εξαιρούνται: περιγράφουν τα κείμενα, δεν τα εμφανίζουν.
  const outside = src.split(/\r?\n/).filter(l => !/^\s*(\/\/|\/\*|\*)/.test(l)).join('\n');
  // Τιμή μίας λέξης (π.χ. «Πελάτης», 14/10): διαρροή μόνο ως ΟΛΟΚΛΗΡΗ
  // συμβολοσειρά ('…', `…`, >…<), αλλιώς θα έπιανε το «Πελάτης επίδειξης».
  const isLeak = t => /\s/.test(t) ? outside.includes(t)
                                   : ["'" + t + "'", '`' + t + '`', '>' + t + '<'].some(q => outside.includes(q));
  const pageTexts = Object.assign({}, MIG_TEXTS.tasks, MIG_TEXTS.common);
  // ΚΑΜΙΑ εξαίρεση (απόφαση 16/10).
  const leaked = Object.entries(pageTexts).filter(([k]) => MIG_KINDS[k] === 'text')
    .map(([, t]) => t.replace(/\{\w+\}.*$/, '').trim()).filter(t => t && isLeak(t));
  ck('κανένα κείμενο του seed γραμμένο στον κώδικα', leaked.length === 0, leaked.join(' | '));
  ck('κλειδί που λείπει -> [κλειδί], όχι κενό', api.pageText('δεν_υπάρχει') === '[δεν_υπάρχει]');
  ck('{n} -> τιμή', api.pageText('fuel_older', { n: 3 }) === 'Παλαιότερες εβδομάδες (3)');
  ck('fuel_uploaded με ⏳ (αλλαγή 14/10)', api.pageText('fuel_uploaded') === '⏳ Ανέβηκε · εκκρεμεί επαλήθευση');

  // Ο loader απέτυχε: [κλειδί] (απόφαση 14/10), χωρίς σφάλμα.
  const f = env({ texts: false, view: 'mine' });
  f.api.render([cliTask(1, 7, 'Π', { client_account: '', offer_number: '' })]);
  ck('loader απέτυχε -> «[client_no_account]», όχι κενό ή σφάλμα', f.els.list.innerHTML.includes('[client_no_account]'), f.els.list.innerHTML);
  ck('χωρίς ui-texts.js (UiTexts undefined) -> [κλειδί]', new Function(grab('pageText') + '\nreturn pageText;')()('x') === '[x]');

  const ld = grab('load');
  ck('το load() ζητά τα κείμενα ΠΑΡΑΛΛΗΛΑ με τη λίστα και τα περιμένει ΠΡΙΝ το render',
     ld.indexOf('UiTexts.load(UI_SCOPES)') > 0 && ld.indexOf('UiTexts.load(UI_SCOPES)') < ld.indexOf('apiFetch(') &&
     ld.indexOf('await texts;') > ld.indexOf('apiFetch(') && ld.indexOf('await texts;') < ld.indexOf('render(d.tasks)'));
  ck('η σελίδα φορτώνει το ui-texts.js πριν από το script της',
     src.indexOf('<script src="ui-texts.js?v=2026-10-15"></script>') > 0 &&
     src.indexOf('<script src="ui-texts.js?v=2026-10-15"></script>') < src.indexOf('const API = '));

  // ΧΩΡΙΣ ΗΜΕΡΟΜΗΝΙΑ (απόφαση 13/10): το τεστ ΑΠΟΤΥΓΧΑΝΕΙ μόνο όταν υπάρχει
  // ΤΑΥΤΟΧΡΟΝΑ (α) migration που δημιουργεί τον πίνακα κειμένων οθόνης
  // `4a_ui_texts` στο db/migrations/ ΚΑΙ (β) μη άδειο UI_TEMP στον κώδικα.
  // Δηλαδή: μόλις μπει ο πίνακας, τα προσωρινά κείμενα πρέπει να φύγουν.
  const UI_TEXTS_TABLE = '4a_ui_texts';
  const createsTable = sql => new RegExp('CREATE TABLE IF NOT EXISTS `' + UI_TEXTS_TABLE + '`').test(sql);
  const tempKeys = source => { const m = /const UI_TEMP = \{([\s\S]*?)\n\};/.exec(source);
    return m ? (m[1].match(/^\s*\w+\s*:/gm) || []).length : 0; };
  const tempOk = (migrations, source) => !(migrations.some(createsTable) && tempKeys(source) > 0);
  const migs = fs.readdirSync('db/migrations').filter(f => f.endsWith('.sql')).map(f => fs.readFileSync(path.join('db/migrations', f), 'utf8'));
  const hasTable = migs.some(createsTable);
  ck(`UI_TEMP: ${tempKeys(src)} κλειδιά · migration του ${UI_TEXTS_TABLE}: ${hasTable ? 'ΥΠΑΡΧΕΙ' : 'όχι ακόμα'} -> ` +
     (hasTable ? 'το UI_TEMP πρέπει να είναι άδειο' : 'επιτρέπεται'),
     tempOk(migs, src), 'ο πίνακας κειμένων υπάρχει: τα κείμενα του UI_TEMP πρέπει να μεταφερθούν στη βάση');
  const MIG = 'CREATE TABLE IF NOT EXISTS `4a_ui_texts` (`k` VARCHAR(40))';
  ck('πίνακας ΚΑΙ μη άδειο UI_TEMP -> ΑΠΟΤΥΓΧΑΝΕΙ', !tempOk([MIG], 'const UI_TEMP = {\n  a: \'x\',\n};'));
  ck('πίνακας ΚΑΙ άδειο UI_TEMP -> περνά', tempOk([MIG], 'const UI_TEMP = {\n};'));
  ck('πίνακας ΚΑΙ κανένα UI_TEMP -> περνά', tempOk([MIG], '// καθαρό'));
  ck('χωρίς πίνακα, με UI_TEMP -> περνά (καμία ημερομηνία)', tempOk(['CREATE TABLE IF NOT EXISTS `άλλος` (x INT)'], 'const UI_TEMP = {\n  a: \'x\',\n};'));
  ck('ο πίνακας υπάρχει σε migration ΚΑΙ το UI_TEMP έφυγε', hasTable);
}

// ── Επίδειξη: ρίγες ΚΑΙ χρώμα κατάστασης (13-10-2026) ──────────────────────
// Λευκό, όπως στο CSS: rgba(255,255,255,.45) πάνω στο φόντο.
const blendWhite = (hex, a) => { const h = hex.length === 4 ? hex.slice(1).split('').map(c => c + c).join('') : hex.slice(1);
  const n = parseInt(h, 16); return '#' + [(n >> 16) & 255, (n >> 8) & 255, n & 255]
    .map(v => Math.round(v * (1 - a) + 255 * a).toString(16).padStart(2, '0')).join(''); };
console.log('\n═══ Επίδειξη: ρίγες ΚΑΙ χρώμα κατάστασης ═══');
{
  const demoCss = (src.match(/\.client-group\.demo > \.client-head \{[^}]*\}/) || [''])[0];
  ck('οι ρίγες είναι ΜΟΝΟ background-image (δεν σκεπάζουν το background-color)',
     /background-image:\s*repeating-linear-gradient/.test(demoCss) && !/\bbackground:/.test(demoCss), demoCss);
  ck('ρίγες = διάφανο λευκό 45% (και διάφανο ανάμεσα)', /transparent 0 10px/.test(demoCss) && /rgba\(255,255,255,\.45\) 10px 20px/.test(demoCss));
  ck('κανένα άλλο φόντο για την κεφαλίδα επίδειξης', (src.match(/\.client-group\.demo[^{]*\.client-head \{/g) || []).length === 1);
  const { api, els } = env({ statuses: ST_DB, now: NOW13 });
  const d = (id, cid, extra) => cliTask(id, cid, 'Επίδειξη ' + cid, Object.assign({ is_demo: 1 }, extra));
  api.render([d(1, 31, { status: 'done' }), d(2, 32, { status: 'in_progress', due_at: '2026-10-20 17:00:00' }),
              d(3, 33, { status: 'open', due_at: '2026-10-09 17:00:00' })]);
  const heads = [...els.list.innerHTML.matchAll(/<div class="(client-group[^"]*)"/g)].map(m => m[1]);
  ck('επίδειξη + γκρι / + λεμονί / + κόκκινο: η ομάδα κρατά ΚΑΙ demo ΚΑΙ το χρώμα',
     JSON.stringify(heads) === JSON.stringify(['client-group demo closed', 'client-group demo tone open', 'client-group demo tone late']), heads.join(' | '));
  for (const [lbl, bg, fg] of [['γκρι', '#9e9e9e', '#333'], ['λεμονί', '#fcff29', '#000'], ['κόκκινο', api.tint('#cc0000', 0.14), '#333']]) {
    const stripe = blendWhite(bg, 0.45);
    ck(`επίδειξη ${lbl}: κείμενο ${fg} σε ρίγα ${stripe} = ${ratio(fg, stripe).toFixed(2)}:1 και σε φόντο ${bg} = ${ratio(fg, bg).toFixed(2)}:1, ≥ 4.5`,
       ratio(fg, stripe) >= 4.5 && ratio(fg, bg) >= 4.5);
  }
}

// ── Κλειδωμένη κάρτα: αντίθεση (13-10-2026) ─────────────────────────────────
console.log('\n═══ Κλειδωμένη κάρτα: αντίθεση ≥ 4.5 σε κάθε φόντο ═══');
{
  const lockedRules = [...src.matchAll(/([^{}]*\.task\.locked[^{}]*)\{([^}]*)\}/g)];
  const op = lockedRules.map(m => /opacity:\s*([\d.]+)/.exec(m[2])).filter(Boolean).map(m => Number(m[1]));
  const opacity = op.length ? Math.min(...op) : 1;
  ck(`καμία opacity < 1 στην κλειδωμένη κάρτα (βρέθηκε ${opacity})`, opacity === 1);
  // Χρώμα κειμένου μετά την opacity: ανάμειξη με το φόντο της ομάδας.
  const mixOn = (fg, bg, a) => { const p = h => { h = h.length === 4 ? h.slice(1).split('').map(c => c + c).join('') : h.slice(1);
      const n = parseInt(h, 16); return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; };
    const F = p(fg), B = p(bg); return '#' + F.map((v, i) => Math.round(v * a + B[i] * (1 - a)).toString(16).padStart(2, '0')).join(''); };
  const { api } = env({ statuses: ST_DB, now: NOW13 });
  for (const [lbl, items, alert] of [
      ['λεμονί', [cliTask(1, 7, 'Α', { locked: 1, display_status: 'locked' })]],
      ['κόκκινο', [cliTask(1, 7, 'Α', { status: 'open', due_at: '2026-10-09 17:00:00' }), cliTask(2, 7, 'Α', { locked: 1 })]],
      ['πορτοκαλί', [cliTask(1, 7, 'Α', { locked: 1 })], 'orange']]) {
    const v = vars(api.groupTone(items, NOW13, alert).style);
    for (const k of ['tone-fg', 'tone-fg2']) {
      const eff = mixOn(v[k], v['tone-bg'], opacity);
      ck(`κλειδωμένη σε ${lbl}: ${k === 'tone-fg' ? 'τίτλος' : 'δευτερεύον'} ${eff} σε ${v['tone-bg']} = ${ratio(eff, v['tone-bg']).toFixed(2)}:1 ≥ 4.5`,
         ratio(eff, v['tone-bg']) >= 4.5);
    }
  }
  ck('η κλειδωμένη ξεχωρίζει χωρίς opacity: πλάγιος τίτλος', /\.task\.locked \.task-label \{ font-style: italic;/.test(src));
  ck('το «Περιμένει» το λέει το badge locked (ψευδο-κατάσταση από τη βάση)', /display_status/.test(grab('taskRow')));
}

// ── «Οι εργασίες μου»: αριθμός στην καρτέλα (13-10-2026, έρευνα) ──────────────
// Αναφέρθηκε ότι «Οι εργασίες μου» έχασε τον αριθμό. ΔΕΝ είναι παλινδρόμηση:
// από το 76f88b9 (24/09) ο αριθμός μπαίνει ΜΟΝΟ στην καρτέλα που φορτώθηκε στη
// συνεδρία (counts[view] = d.tasks.length), και η σελίδα ανοίγει στην «Ουρά».
// Μετά από Ctrl+Shift+R η «Οι εργασίες μου» δεν έχει αριθμό μέχρι να πατηθεί.
// Το τεστ καρφώνει τη σημερινή συμπεριφορά· αν αλλάξει (απόφαση), αλλάζει κι αυτό.
console.log('\n═══ Καρτέλες: αριθμός μόνο στην καρτέλα που φορτώθηκε (από 24/09) ═══');
{
  // 14/10: η προεπιλογή έγινε «Οι εργασίες μου» (βλ. ενότητα «?tab=» παρακάτω).
  ck('η σελίδα ανοίγει στην «Οι εργασίες μου»', /let view = 'mine';/.test(src));
  ck('ο αριθμός γράφεται μόνο για την τρέχουσα καρτέλα', /counts\[view\] = d\.tasks\.length;/.test(src));
  const tabsCode = [grabConst('VIEW_LABELS'), grabConst('VIEW_TIPS'), grab('escHtml'), grab('renderTabs')].join('\n');
  const tab = (counts, view) => { const els = {};
    new Function('document', 'counts', 'view', 'isAdmin', tabsCode + '\nrenderTabs();')(
      { getElementById: id => (els[id] = els[id] || { innerHTML: '' }) }, counts, view, true);
    return els.tabs.innerHTML; };
  const fresh = tab({ mine: null, queue: 7, all: null }, 'queue');
  ck('μετά από φόρτωση στην «Ουρά»: «Οι εργασίες μου» ΧΩΡΙΣ αριθμό, «Ουρά» με 7',
     !/Οι εργασίες μου<span class="cnt">/.test(fresh) && /Ουρά αδιάθετων<span class="cnt">7<\/span>/.test(fresh), fresh);
  const visited = tab({ mine: 4, queue: 7, all: null }, 'queue');
  ck('αφού ανοίξει η «Οι εργασίες μου»: κρατά τον αριθμό της (4)', /Οι εργασίες μου<span class="cnt">4<\/span>/.test(visited), visited);
  ck('ο αριθμός 0 φαίνεται ως 0, δεν κρύβεται', /<span class="cnt">0<\/span>/.test(tab({ mine: 0, queue: null, all: null }, 'mine')));
}

// ── 14-10-2026: (Α) ?tab= και άδεια «Οι εργασίες μου» · (Β) σύνδεσμος πελάτη
//    και επιστροφή · (Γ) «χωρίς προσφορά» / «χωρίς AccountNo» από τη βάση ──
console.log('\n═══ (Α) ?tab=mine|queue, προεπιλογή «Οι εργασίες μου» ═══');
{
  const { api } = env();
  const T = api.tabFromQuery;
  ck('χωρίς ?tab -> mine', T('', false) === 'mine' && T('', true) === 'mine');
  ck('?tab=queue -> queue', T('?tab=queue', false) === 'queue');
  ck('?tab=mine -> mine', T('?tab=mine', true) === 'mine');
  ck('?tab=all: διαχειριστής -> all, άλλος -> mine', T('?tab=all', true) === 'all' && T('?tab=all', false) === 'mine');
  ck('άγνωστο / κακόβουλο -> mine', T('?tab=xyz', true) === 'mine' && T('?tab=<script>', true) === 'mine' && T('?tab=', true) === 'mine');
  ck('με άλλες παραμέτρους -> διαβάζει το tab', T('?x=1&tab=queue', false) === 'queue');
  ck('το boot διαβάζει το ?tab ΜΕΤΑ το isAdmin', (() => { const b = src.slice(src.indexOf('/* --- boot'));
     return b.indexOf('isAdmin = (') > 0 && b.indexOf('isAdmin = (') < b.indexOf('view = tabFromQuery(location.search, isAdmin);'); })());

  const empty = v => { const e = env({ view: v }); e.api.render([]); return e.els.list.innerHTML; };
  const m = empty('mine');
  ck('άδεια «Οι εργασίες μου»: μήνυμα + σύνδεσμος στην Ουρά', m.includes('Δεν έχετε εργασίες σε εξέλιξη.') &&
     m.includes('href="tasks.html?tab=queue"') && m.includes("switchView('queue')") && m.includes('→ Δείτε την Ουρά αδιάθετων'), m);
  const withN = q => { const e = env({ view: 'mine', counts: { mine: 0, queue: q, all: null } }); e.api.render([]); return e.els.list.innerHTML; };
  ck('«→ Δείτε την Ουρά αδιάθετων (7)»: το πλήθος της Ουράς', withN(7).includes('→ Δείτε την Ουρά αδιάθετων (7)'), withN(7));
  ck('Ουρά 0 -> «(0)»', withN(0).includes('αδιάθετων (0)'));
  ck('πλήθος άγνωστο -> «(–)», όχι «{n}»', withN(null).includes('αδιάθετων (–)') && !withN(null).includes('{n}'));
  ck('το load() φέρνει το πλήθος της Ουράς ΜΟΝΟ όταν η «Οι εργασίες μου» είναι άδεια',
     /if\(view==='mine' && !lastTasks\.length\) await loadQueueCount\(\);/.test(grab('load')) &&
     /view=queue/.test(grab('loadQueueCount')) && /counts\.queue = d\.tasks\.length/.test(grab('loadQueueCount')));
  ck('άδεια «Ουρά»: ΧΩΡΙΣ σύνδεσμο', !empty('queue').includes('to-queue'));
  ck('άδεια «Όλες»: ΧΩΡΙΣ σύνδεσμο', !empty('all').includes('to-queue'));
}

// Ψεύτικο link μενού: πρώτος κόμβος κειμένου, και προαιρετικά <span> μετά
// (όπως τα βάζει το tasks-badge.js).
const navLink = (href, text, lead) => {
  const t = { nodeType: 3, nodeValue: text, nextSibling: { nodeType: 1, nextSibling: null } };
  return { getAttribute: () => href, firstChild: lead ? { nodeType: 1, nextSibling: t } : t };
};
const MENU = [navLink('pricelist-editor.html', '+ Νέος Τιμοκατάλογος'), navLink('pricelist-clients.html', '👥 Πελάτες'),
              navLink('tasks.html', '✅ Εργασίες'), navLink('users.html', '⚙️ Χρήστες')];

console.log('\n═══ (Β) chip «🔗👥 Πελάτης» -> pricelist-clients.html?client=…&from=tasks · #client-<id> ═══');
{
  const { api } = env({ view: 'queue', nav: MENU });
  const a = api.clientChipHtml(1791540547308);
  ck('chip με το εικονίδιο του μενού: « <a class="nav-chip" …>🔗👥 Πελάτης</a>»',
     a === ' <a class="nav-chip" href="pricelist-clients.html?client=1791540547308&amp;from=tasks&amp;tab=queue"' +
           ' title="Άνοιγμα πελάτη">🔗👥 Πελάτης</a>', a);
  ck('το URL περνά το ACTION_URL_OK', /^[a-z0-9][a-z0-9_-]*\.html(\?[A-Za-z0-9_=&%.-]*)?$/.test('pricelist-clients.html?client=1791540547308&from=tasks&tab=queue'));
  const no = env({ view: 'mine', nav: MENU, perms: { 'pricelist-clients': { view: 0 } } }).api.clientChipHtml(5);
  ck('χωρίς δικαίωμα στο pricelist-clients: ΚΑΝΕΝΑ chip', no === '', no);
  const yes = env({ view: 'mine', nav: MENU, perms: { 'pricelist-clients': { view: 1 } } }).api.clientChipHtml(5);
  ck('με δικαίωμα: chip', yes.includes('<a class="nav-chip"'));
  const bare = env({ view: 'mine' }).api.clientChipHtml(5);
  ck('χωρίς μενού: «🔗 Πελάτης», χωρίς σφάλμα', bare.endsWith('>🔗 Πελάτης</a>'), bare);

  // Το όνομα: ΞΑΝΑ σκέτο <span>, πριν από το chip και πριν από τη χώρα.
  const eN = env({ view: 'mine', nav: MENU });
  eN.api.render([cliTask(1, 7, 'Α&Β <ΑΕ>'), sysTask(80, FW, 'fuel:2026-10-19', PAYLOAD, { status: 'in_progress' })]);
  const LN = eN.els.list.innerHTML;
  ck('το όνομα είναι <span class="client-name">, escaped, χωρίς σύνδεσμο',
     LN.includes('<span class="client-name">Α&amp;Β &lt;ΑΕ&gt;</span> <a class="nav-chip"') && !LN.includes('client-link'), LN);
  ck('σειρά: όνομα -> chip -> χώρα', LN.indexOf('class="client-name"') < LN.indexOf('class="nav-chip"') &&
     LN.indexOf('class="nav-chip"') < LN.indexOf('<span class="flag GR">'));
  ck('η διακεκομμένη υπογράμμιση του ονόματος έφυγε από το CSS', !/client-link/.test(src));
  ck('το chip έχει hover (πιο σκούρο περίγραμμα) και cursor pointer',
     /\.nav-chip \{[^}]*cursor: pointer;/.test(src) && /\.nav-chip:hover \{ border-color: #37474f; \}/.test(src));
  ck('chip ίδιου μεγέθους με το .flag (11px, 700, border-radius 10px)',
     /\.flag \{ font-size: 11px; font-weight: 700;[^}]*border-radius: 10px; \}/.test(src) &&
     /\.nav-chip \{ font-size: 11px; font-weight: 700;[^}]*border-radius: 10px;/.test(src));
  ck('το tasks.html φορτώνει το nav-chip.js?v=2026-10-14 πριν από το script του', src.indexOf('<script src="nav-chip.js?v=2026-10-14"></script>') > 0 &&
     src.indexOf('<script src="nav-chip.js?v=2026-10-14"></script>') < src.indexOf("const API = 'https://4aexpress.com/api';"));

  const e = env({ view: 'mine' });
  e.api.render([cliTask(1, 7, 'Πελάτης'), sysTask(80, FW, 'fuel:2026-10-19', PAYLOAD, { status: 'in_progress' })]);
  const L = e.els.list.innerHTML;
  ck('η ομάδα πελάτη έχει id="client-7"', L.includes(' id="client-7"'));
  ck('η ομάδα ΣΥΣΤΗΜΑ ΔΕΝ έχει id client-', (L.match(/ id="client-/g) || []).length === 1);
  ck('το #client-<id> γίνεται δεκτό, οτιδήποτε άλλο -> null',
     api.anchorFromHash('#client-7') === 'client-7' && api.anchorFromHash('#client-x') === null &&
     api.anchorFromHash('#client-7"><img') === null && api.anchorFromHash('') === null && api.anchorFromHash('#x') === null);
  ck('μετά την πρώτη απόδοση: revealAnchor() (ανοίγει την ομάδα, μία φορά)', /render\(d\.tasks\);\n\s*revealAnchor\(\);/.test(src) &&
     /pendingAnchor = null;/.test(grab('revealAnchor')) && /grpOpen\.set\(key, true\)/.test(grab('revealAnchor')));
}

console.log('\n═══ (Γ) «χωρίς AccountNo» / «χωρίς προσφορά» από τη βάση, ίδιο κείμενο ═══');
{
  const { api, els } = env({ view: 'mine' });
  ck('seed: ίδια κείμενα', api.pageText('client_no_account') === 'χωρίς AccountNo' && api.pageText('client_no_offer') === 'χωρίς προσφορά');
  const t = cliTask(1, 7, 'Π', { client_account: '', offer_number: '' });
  api.render([t]);
  ck('η κεφαλίδα λέει «χωρίς AccountNo · χωρίς προσφορά» όπως πριν',
     els.list.innerHTML.includes('χωρίς AccountNo') && els.list.innerHTML.includes(' · χωρίς προσφορά'), els.list.innerHTML);
  ck('στο render: pageText, όχι γραμμένα κείμενα', /pageText\('client_no_account'\)/.test(grab('render')) && /pageText\('client_no_offer'\)/.test(grab('render')) &&
     !/'χωρίς AccountNo'|' · χωρίς προσφορά'/.test(grab('render')));
}

// ═════════════════════════════════════════════════════════════════════
// ΦΑΣΗ Α, Α3: ΠΥΛΗ. Χωρίς `step` στη ρίζα της εβδομάδας η οθόνη είναι ΙΔΙΑ
// byte-προς-byte με πριν από το Α3. tasks_fuel_gate.golden.json = η έξοδος
// του tasks.html στο 1e5279d (πριν από το Α3), για τα ίδια σενάρια. Τα
// δεδομένα έχουν ήδη ό,τι στέλνει ο server του Α2 (blocked_by_assignee_name),
// που ΔΕΝ πρέπει να αλλάζει τίποτα όσο η εβδομάδα δεν είναι ενεργή.
// Ξαναγράφεται ΜΟΝΟ ρητά: GEN_FUEL_GATE_GOLDEN=1 node tests/frontend/tasks.test.js
const GATE_GOLDEN = path.join(HERE, 'tasks_fuel_gate.golden.json');
// sha256 ανά λίστα: έλεγχος byte-προς-byte χωρίς 1,3 MB HTML στο repo.
const sha = s => require('crypto').createHash('sha256').update(s, 'utf8').digest('hex');
const gateWeeks = () => {
  const lockV = { ver: { locked: 1, blocked_by_label: 'Γενικός επίναυλος εβδομάδας — αρχείο CMS', blocked_by_assignee_name: 'u1' } };
  const lockN = { up: { assigned_to: null, assigned_name: null }, ver: { locked: 1, blocked_by_label: 'Γενικός επίναυλος εβδομάδας — αρχείο CMS', blocked_by_assignee_name: null } };
  const own = { up: { assigned_to: 1, assigned_name: 'u1' }, ver: { assigned_to: 2, assigned_name: 'u2' } };
  const merge = (a, b) => ({ up: Object.assign({}, a.up, b && b.up), ver: Object.assign({}, a.ver, b && b.ver) });
  return [
    ...fuelWeek(90, '2026-10-26', 'in_progress', 'open', merge(own, lockV)),
    ...fuelWeek(80, '2026-10-19', 'done', 'in_progress', own),
    ...fuelWeek(76, '2026-10-12', 'done', 'done', own),
    ...fuelWeek(70, '2026-10-05', 'done', 'done', own),
    ...fuelWeek(60, '2026-11-02', 'open', 'open', lockN),
    cliTask(1, 7, 'Πελάτης', { status: 'in_progress', assigned_to: 1, assigned_name: 'u1', due_at: '2026-10-20 17:00:00' }),
  ];
};
const GATE_RUNS = [];
for (const now of ['2026-10-13 12:00:00', '2026-10-23 10:00:00', '2026-10-24 10:00:00', '2026-10-26 09:00:00', '2026-10-26 18:00:00'])
  for (const [who, userId, admin] of [['admin1', 1, true], ['admin9', 9, true], ['user2', 2, false], ['user3', 3, false]])
    for (const view of ['all', 'mine', 'queue']) GATE_RUNS.push({ now, who, userId, admin, view });
const gateHtml = withStep => {
  const out = {};
  FROZEN_PAY = true;
  for (const g of GATE_RUNS) {
    const { api, els } = env({ statuses: ST_DB, now: g.now, userId: g.userId, admin: g.admin, view: g.view });
    const ts = gateWeeks();
    if (withStep) ts.filter(t => t.task_code === 'fuel_weekly').forEach(t => Object.assign(t, {
      step: STEP.download, steps: [], allow: { na: false, release: false, reject: false, steal: false }, timeline: [], override: null, ui: UI }));
    api.setTasks(ts);
    api.render(ts);
    out[`${g.now} ${g.who} ${g.view}`] = els.list.innerHTML;
  }
  FROZEN_PAY = false;
  return out;
};
console.log('\n═══ Α3 πύλη: χωρίς step στη ρίζα, η λίστα ΙΔΙΑ με πριν από το Α3 (golden) ═══');
if (process.env.GEN_FUEL_GATE_GOLDEN === '1') {
  const h = gateHtml(false), o = {};
  for (const k of Object.keys(h)) o[k] = sha(h[k]);
  fs.writeFileSync(GATE_GOLDEN, JSON.stringify(o, null, 1) + '\n');
  console.log('   γράφτηκε ' + GATE_GOLDEN);
}
{
  const golden = JSON.parse(fs.readFileSync(GATE_GOLDEN, 'utf8'));
  const got = gateHtml(false);
  const diff = Object.keys(golden).filter(k => got[k] === undefined || sha(got[k]) !== golden[k]);
  ck(`${Object.keys(golden).length} λίστες (5 στιγμές × 4 χρήστες × 3 καρτέλες): ΙΔΙΕΣ bytes`,
     Object.keys(golden).length === GATE_RUNS.length && diff.length === 0, diff.slice(0, 3).join(' | '));
}

// ── Α3: ενεργή εβδομάδα (η ρίζα έχει step, όπως μετά το Α4) ───────────────
// Εβδομάδα 26/10: ① προθεσμία Παρ 23/10 19:00, ② (verify) Δευ 26/10 17:00,
// όπως οι ζωντανοί κανόνες. Οι ώρες ΜΟΝΟ από τα due_at.
const activeWeek = (up, ver, o) => {
  o = o || {};
  const w = fuelWeek(90, '2026-10-26', up, ver, {
    up:  Object.assign({ due_at: o.upDue || '2026-10-23 19:00:00', assigned_to: 1, assigned_name: 'u1' }, o.up),
    ver: Object.assign({ due_at: o.verDue || '2026-10-26 17:00:00', assigned_to: 2, assigned_name: 'u2' }, o.ver) });
  Object.assign(w[0], { step: up === 'done' ? STEP.uploaded : STEP.download, steps: STEPS(up === 'done', up === 'done'),
                        allow: ALLOW0, timeline: [], override: null, ui: UI });
  return w;
};

console.log('\n═══ Α3 ενδείξεις: ΤΙ · ΑΠΟ ΠΟΤΕ · ΕΩΣ ΠΟΤΕ, 6 καταστάσεις ═══');
{
  const { api } = env({ statuses: ST_DB });
  const I = (w, now) => api.fuelWeekInfo(w, now);
  // Η λήψη έγινε: η ρίζα ανοιχτή στο ②.
  const dlDone = w => { w[0] = Object.assign({}, w[0], { steps: STEPS(true, false) }); return w; };
  const cases = [
    ['① ανοιχτό, πριν τη λήξη',  activeWeek('in_progress', 'open'), '2026-10-22 10:00:00', 'Τώρα: ① Λήψη αρχείου · έως Παρ 23/10 19:00', null],
    ['② ανοιχτό (λήψη έγινε)',   dlDone(activeWeek('in_progress', 'open')), '2026-10-22 10:00:00', 'Τώρα: ② Ανέβασμα στο CMS · έως Παρ 23/10 19:00', null],
    ['② ανοιχτό, μετά τη λήξη',  activeWeek('in_progress', 'open'), '2026-10-24 10:00:00', '⚠ ② Ανέβασμα στο CMS · εκπρόθεσμο από Παρ 23/10 19:00', 'red'],
    ['③ πριν τη Δευτέρα',        activeWeek('done', 'in_progress'), '2026-10-24 10:00:00', 'Επόμενο: ③ Επαλήθευση · ανοίγει Δευ 26/10 · έως 17:00', null],
    ['③ Δευτέρα, πριν τη λήξη',  activeWeek('done', 'in_progress'), '2026-10-26 09:00:00', 'Τώρα: ③ Επαλήθευση · έως 17:00', null],
    ['③ μετά τη λήξη',           activeWeek('done', 'in_progress'), '2026-10-26 18:00:00', '⚠ ③ Επαλήθευση · εκπρόθεσμη από Δευ 26/10 17:00', 'red'],
    ['όλα κλειστά',              activeWeek('done', 'done'),        '2026-10-26 18:00:00', '✓ Ολοκληρώθηκαν και τα 3 βήματα', null],
  ];
  for (const [lbl, w, now, text, alert] of cases) {
    const i = I(w, now);
    ck(`${lbl}: «${text}»${alert ? ', ' + alert : ', χωρίς πλακίδιο'}`, i && i.text === text && i.alert === alert, JSON.stringify(i));
  }
  ck('οι ώρες ΜΟΝΟ από τα δεδομένα: ② 18:45 και ③ 15:30',
     I(activeWeek('in_progress', 'open', { upDue: '2026-10-23 18:45:00' }), '2026-10-22 10:00:00').text === 'Τώρα: ① Λήψη αρχείου · έως Παρ 23/10 18:45'
     && I(activeWeek('done', 'in_progress', { verDue: '2026-10-26 15:30:00' }), '2026-10-24 10:00:00').text === 'Επόμενο: ③ Επαλήθευση · ανοίγει Δευ 26/10 · έως 15:30');
  const dlWeek = activeWeek('in_progress', 'open');
  dlWeek[0] = Object.assign({}, dlWeek[0], { steps: [{ code: 'download', action: 'download', done: false }, { code: 'upload', action: 'upload', done: false }] });
  ck('① ανοιχτό (λήψη όχι ακόμα): «Τώρα: ① Λήψη αρχείου · έως Παρ 23/10 19:00», λεμονί',
     I(dlWeek, '2026-10-22 10:00:00').text === 'Τώρα: ① Λήψη αρχείου · έως Παρ 23/10 19:00' && I(dlWeek, '2026-10-22 10:00:00').alert === null,
     JSON.stringify(I(dlWeek, '2026-10-22 10:00:00')));
  ck('όριο: Κυρ 25/10 23:59:59 ακόμα «από Δευ», Δευ 26/10 00:00:00 «τώρα»',
     I(activeWeek('done', 'in_progress'), '2026-10-25 23:59:59').key === 'fuel_w_verify_wait'
     && I(activeWeek('done', 'in_progress'), '2026-10-26 00:00:00').key === 'fuel_w_verify_now');
  ck('παλαιότερη ενεργή εβδομάδα: καμία ένδειξη, όπως πριν', I(activeWeek('done', 'done'), '2026-11-03 10:00:00').text === null);
  const pill = api.fuelStateHtml(I(activeWeek('in_progress', 'open'), '2026-10-24 10:00:00'));
  ck('κεφαλίδα ΛΕΜΟΝΙ: κόκκινο πλακίδιο ΜΟΝΟ μετά τη λήξη (OVERDUE_COLOR), αλλιώς κείμενο, ποτέ πορτοκαλί',
     pill.includes('background:#cc0000;') && api.fuelStateHtml(I(activeWeek('done', 'in_progress'), '2026-10-24 10:00:00')).includes('class="grp-state plain"')
     && api.fuelStateHtml(I(activeWeek('done', 'in_progress'), '2026-10-26 09:00:00')).includes('class="grp-state plain"')
     && api.fuelStateHtml(I(activeWeek('done', 'done'), '2026-10-26 18:00:00')).includes('class="grp-state plain"'));
  const keys = [...grab('fuelWeekInfo').matchAll(/'(fuel_w_\w+)'/g)].map(m => m[1]);
  ck(`κάθε κλειδί fuel_w_* της fuelWeekInfo (${new Set(keys).size}) υπάρχει στο seed (19b + 21a)`,
     new Set(keys).size === 7 && keys.every(k => MIG_TEXTS.tasks && k in MIG_TEXTS.tasks), keys.join(','));
  const code = ['fuelWeekInfo', 'fuelActiveRoot', 'dueClock', 'taskRow', 'stepCardHtml', 'stepBtnHtml', 'stepMoreHtml', 'fuelStripHtml', 'fuelStripMenu']
    .map(grab).join('\n').replace(/^\s*\/\/.*$/gm, '');
  ck('ΚΑΝΕΝΑ «19:00» ή «17:00» γραμμένο στον κώδικα (φρουρός)', !/\b(19|17):00\b/.test(code));
}

console.log('\n═══ Α3 πύλη: ΜΕ step στη ρίζα η λίστα ΑΛΛΑΖΕΙ (ο έλεγχος δεν είναι κενός) ═══');
{
  const golden = JSON.parse(fs.readFileSync(GATE_GOLDEN, 'utf8'));
  const withStep = gateHtml(true);
  const changed = Object.keys(golden).filter(k => sha(withStep[k]) !== golden[k]);
  ck(`με step: άλλαξαν ${changed.length} από ${Object.keys(golden).length} λίστες (όσες δείχνουν ενεργή εβδομάδα)`, changed.length >= 40, String(changed.length));
  const L = withStep['2026-10-24 10:00:00 admin9 all'];
  ck('...π.χ. Σαβ 24/10, admin: «⚠ ② Ανέβασμα στο CMS · εκπρόθεσμο από …» και κάρτα αντί για γραμμές', L.includes('⚠ ② Ανέβασμα στο CMS · εκπρόθεσμο από')
     && L.includes('<div class="fstrip"') && !L.includes('class="task has-steps'));
}

// ═════════════════════════════════════════════════════════════════════
// Α3, ΕΥΡΗΜΑ 16/10: η κεφαλίδα της εβδομάδας = κατάσταση ΟΛΗΣ της εβδομάδας
// (t.chain από τον server), ΙΔΙΑ σε «Οι εργασίες μου», «Ουρά» και «Όλες».
// Οι λίστες φιλτράρονται ΟΠΩΣ στο tasks_list (mine: δικές μου in_progress/
// paused, queue: αδιάθετες open, all: όλες), και κάθε εργασία συστήματος
// παίρνει chain ΟΠΩΣ στο tasks_chain.
const withChain = ts => {
  const byKey = new Map();
  ts.filter(t => t.client_id === null).forEach(t => { const k = t.subject_key; if (!byKey.has(k)) byKey.set(k, []); byKey.get(k).push(t); });
  return ts.map(t => {
    if (t.client_id !== null) return t;
    const sib = byKey.get(t.subject_key);
    const closed = code => sib.some(x => x.task_code === code && (x.status === 'done' || x.status === 'na'));
    return Object.assign({}, t, { chain: sib.map(x => Object.assign({ id: x.id, task_code: x.task_code, depends_on: x.depends_on, status: x.status,
      due_at: x.due_at, closed_at: x.closed_at || null, locked: x.depends_on !== null && !closed(x.depends_on) ? 1 : 0,
      assigned_name: x.assigned_name, closed_by_name: x.closed_by_name || null, has_steps: !!x.step,
      task_label: x.task_label, task_icon: x.task_icon, task_title_template: x.task_title_template,
      assigned_to: x.assigned_to === undefined ? null : x.assigned_to, notices: x.notices || [] },
      x.step ? { step: x.step, steps: x.steps, allow: x.allow, timeline: x.timeline, override: x.override, ui: x.ui } : {})) });
  });
};
const tabOf = (ts, view, me) => ts.filter(t => view === 'all' ? true
  : view === 'mine' ? Number(t.assigned_to) === me && (t.status === 'in_progress' || t.status === 'paused')
  : t.assigned_to === null && t.status === 'open');
// Οι κεφαλίδες ανά ομάδα: από το <div class="client-group…> ως το grp-body (χρώμα, τίτλος, ένδειξη, [+]/[−]).
const heads = html => {
  const out = {};
  for (const m of html.matchAll(/<div class="client-group[\s\S]*?<div class="grp-body"/g)) {
    const k = /data-g="([^"]*)"/.exec(m[0]); if (k) out[k[1]] = m[0];
  }
  return out;
};
// Κάθε κατάσταση εβδομάδας, με τα δύο μισά σε διαφορετικούς ανθρώπους.
// u1 = ανάδοχος του ①, u2 = του ②.
const UP_DONE = { status: 'done', assigned_to: 1, assigned_name: 'u1', closed_by_name: 'u1', closed_at: '2026-10-23 18:12:00' };
const VER_U2 = { status: 'in_progress', assigned_to: 2, assigned_name: 'u2' };
const WEEK_STATES = [
  ['① σε εξέλιξη (u1), ② κλειδωμένο αδιάθετο', { status: 'in_progress', assigned_to: 1, assigned_name: 'u1' }, { status: 'open' }],
  ['① αδιάθετο, ② κλειδωμένο αδιάθετο',         { status: 'open' }, { status: 'open' }],
  ['① done (u1), ② σε εξέλιξη (u2)',            UP_DONE, VER_U2],
  ['① done (u1), ② αδιάθετο',                   UP_DONE, { status: 'open' }],
  ['① done, ② done',                            { status: 'done', assigned_to: 1, assigned_name: 'u1' }, { status: 'done', assigned_to: 2, assigned_name: 'u2' }],
  ['① na, ② σε εξέλιξη (u2)',                   { status: 'na', assigned_to: 1, assigned_name: 'u1' }, VER_U2],
];
const NOWS = ['2026-10-22 10:00:00', '2026-10-24 10:00:00', '2026-10-26 09:00:00', '2026-10-26 18:00:00', '2026-10-28 10:00:00', '2026-11-03 10:00:00'];
const stateWeek = (up, ver, step) => {
  const w = fuelWeek(90, '2026-10-26', up.status, ver.status, {
    up:  Object.assign({ due_at: '2026-10-23 19:00:00', assigned_to: null, assigned_name: null }, up),
    ver: Object.assign({ due_at: '2026-10-26 17:00:00', assigned_to: null, assigned_name: null }, ver) });
  if (step) Object.assign(w[0], { step: up.status === 'done' ? STEP.uploaded : STEP.download, steps: STEPS(up.status === 'done', up.status === 'done'),
                                  allow: ALLOW0, timeline: [], override: null, ui: UI });
  // locked όπως η tasks_base_sql
  w[1].locked = w[0].status === 'done' || w[0].status === 'na' ? 0 : 1;
  return w;
};
const listOf = (ts, now, view, me, admin) => {
  const { api, els } = env({ statuses: ST_DB, now, userId: me, admin, view });
  const shown = tabOf(ts, view, me);
  api.setTasks(shown); api.render(shown);
  return { html: els.list.innerHTML, api };
};

console.log('\n═══ Α3 εύρημα 16/10: ΙΔΙΑ κεφαλίδα εβδομάδας σε mine / queue / all, για κάθε κατάσταση ═══');
for (const step of [false, true]) {
  const keys = new Set(), bad = [];
  let compared = 0;
  for (const now of NOWS) for (const [lbl, up, ver] of WEEK_STATES) {
    const ts = withChain([...stateWeek(up, ver, step), cliTask(1, 7, 'Πελάτης', { status: 'in_progress', assigned_to: 2, assigned_name: 'u2' })]);
    const all = listOf(ts, now, 'all', 9, true);
    const ref = heads(all.html);
    const info = all.api.fuelWeekInfo(all.api.weekItems(ts.filter(t => t.client_id === null)), now);
    keys.add(info.key || (info.phase === 'older' ? 'older' : 'null'));
    for (const [me, admin, view] of [[1, false, 'mine'], [2, false, 'mine'], [2, false, 'queue'], [9, true, 'queue'], [9, true, 'mine']]) {
      const got = heads(listOf(ts, now, view, me, admin).html);
      for (const k of Object.keys(got)) {
        if (k.startsWith('c:')) continue;
        compared++;
        if (got[k] !== ref[k]) bad.push(`${now} · ${lbl} · u${me} ${view} · ${k}`);
      }
    }
  }
  const want = step ? ['fuel_w_download', 'fuel_w_upload_late', 'fuel_w_verify_wait', 'fuel_w_verify_now', 'fuel_w_verify_late', 'fuel_w_done', 'older']
                    : ['fuel_next_todo', 'fuel_uploaded', 'fuel_next_ready', 'fuel_now_missing', 'fuel_now_ok', 'older'];
  ck(`${step ? 'ΜΕ' : 'χωρίς'} step: όλες οι καταστάσεις καλύπτονται (${want.join(', ')})`, want.every(k => keys.has(k)), [...keys].join(','));
  ck(`${step ? 'ΜΕ' : 'χωρίς'} step: ${compared} κεφαλίδες σε mine/queue: ΙΔΙΕΣ byte-προς-byte με την «Όλες»`, compared >= 60 && bad.length === 0, bad.slice(0, 3).join(' | '));
}
{
  // Το εύρημα αυτούσιο: εβδομάδα 19/10, Παρ 16/10, ① done από u1, ② της u2.
  const now = '2026-10-16 12:00:00';
  const wk = fuelWeek(80, '2026-10-19', 'done', 'in_progress', { up: { assigned_to: 1, assigned_name: 'u1' }, ver: { assigned_to: 2, assigned_name: 'u2' } });
  const mineH = listOf(withChain(wk), now, 'mine', 2, false).html;
  ck('εύρημα: «Οι εργασίες μου» της u2 -> «⏳ Ανέβηκε · εκκρεμεί επαλήθευση», πορτοκαλί (όχι «ΠΡΟΣ ΕΚΤΕΛΕΣΗ», όχι λεμονί)',
     mineH.includes('⏳ Ανέβηκε · εκκρεμεί επαλήθευση') && !mineH.includes('ΠΡΟΣ ΕΚΤΕΛΕΣΗ') && /class="client-group sys tone warn"/.test(mineH), heads(mineH)['sys:fuel:2026-10-19']);
  ck('...και οι γραμμές μένουν όσες φαίνονται: μόνο το ② (καμία γραμμή για το ①)', !/act\(80,|downloadFuelCms\(80\)|toggleHist\(80\)/.test(mineH) && /toggleHist\(81\)/.test(mineH));
  const oldH = listOf(wk, now, 'mine', 2, false).html;
  ck('χωρίς chain (παλιός server): όπως πριν, από όσες φαίνονται', oldH.includes('ΠΡΟΣ ΕΚΤΕΛΕΣΗ'));
  ck('weekItems χωρίς chain: τα ΙΔΙΑ αντικείμενα (εργασίες πελάτη, golden)', (() => {
    const { api } = env({}); const xs = [cliTask(1, 7, 'Π'), cliTask(2, 7, 'Π')]; const r = api.weekItems(xs);
    return r.length === 2 && r[0] === xs[0] && r[1] === xs[1]; })());
  ck('weekItems: κάθε εργασία μία φορά, ακόμα κι όταν φαίνονται και οι δύο', (() => {
    const { api } = env({}); return api.weekItems(withChain(wk)).length === 2; })());
}

// ═════════════════════════════════════════════════════════════════════
// Α3, ΛΩΡΙΔΑ ΒΗΜΑΤΩΝ (απόφαση 16/10, Πρόταση Β). Βήματα με ώρα και όνομα,
// όπως τα στέλνει ο server (steps[].done_at / done_by, chain).
const STEPS_AT = (d1, d2) => [
  { code: 'download', action: 'download', button_label: 'Λήψη αρχείου CMS', button_icon: '⬇', done: d1,
    done_at: d1 ? '2026-10-22 09:05:00' : null, done_by: d1 ? 'u1' : null },
  { code: 'upload', action: 'upload', button_label: 'Ολοκλήρωση', button_icon: '✓', done: d2,
    done_at: d2 ? '2026-10-23 18:12:00' : null, done_by: d2 ? 'u1' : null }];
// Μια ενεργή εβδομάδα σε στάδιο: 0 ① τώρα · 1 ② τώρα · 2 ③ τώρα · 3 όλα έγιναν.
// rootWho / verWho: ανάδοχοι (null = αδιάθετη).
const stripWeek = (stage, o) => {
  o = o || {};
  const rootWho = o.rootWho === undefined ? 1 : o.rootWho, verWho = o.verWho === undefined ? 2 : o.verWho;
  const up = stage >= 2 ? { status: 'done', closed_at: '2026-10-23 18:12:00', closed_by_name: 'u1' }
                        : { status: rootWho ? 'in_progress' : 'open' };
  const ver = stage === 3 ? { status: 'done', closed_at: '2026-10-26 10:30:00', closed_by_name: 'u2' }
            : { status: stage === 2 && verWho ? 'in_progress' : 'open' };
  const w = fuelWeek(90, '2026-10-26', up.status, ver.status, {
    up:  Object.assign({ due_at: '2026-10-23 19:00:00', assigned_to: rootWho, assigned_name: rootWho ? 'u' + rootWho : null }, up),
    ver: Object.assign({ due_at: '2026-10-26 17:00:00', assigned_to: stage >= 2 ? verWho : null,
                         assigned_name: stage >= 2 && verWho ? 'u' + verWho : null }, ver) });
  Object.assign(w[0], { step: stage >= 2 ? STEP.uploaded : stage === 1 ? STEP.upload : STEP.download,
                        steps: STEPS_AT(stage >= 1, stage >= 2), allow: ALLOW0, timeline: [], override: o.override || null, ui: UI,
                        notices: o.rootNotices });
  w[1].notices = o.verNotices;
  w[1].locked = stage >= 2 ? 0 : 1;
  return withChain(w);
};
// Η λωρίδα όπως τη βλέπει ο χρήστης me στην καρτέλα view.
const stripOf = (ts, now, view, me, admin, texts) => {
  const { api, els } = env({ statuses: ST_DB, now, userId: me, admin, view, texts });
  const shown = tabOf(ts, view, me);
  api.setTasks(shown); api.render(shown);
  const h = els.list.innerHTML;
  const i = h.indexOf('<div class="fstrip"');
  return i < 0 ? null : h.slice(i, h.indexOf('</div><!--fs-end-->', i) >= 0 ? h.indexOf('</div><!--fs-end-->', i) : h.length);
};
// Οι ΓΡΑΜΜΕΣ της κάρτας με τη σειρά της οθόνης (③ ② ①) και οι ΚΟΜΒΟΙ της μπάρας (① ② ③).
// n = 0, 1, 2 για ①, ②, ③ (από την ετικέτα του βήματος).
const partsOf = (h, cls, end) => { const a = h.indexOf(`<div class="${cls} `); if (a < 0) return [];
  const b = h.indexOf(end, a); return h.slice(a, b < 0 ? h.length : b).split(`<div class="${cls} `).slice(1)
    .map(x => { const m = /^([a-z]+)">([\s\S]*)$/.exec(x); const l = /(①|②|③)/.exec(m[2].replace(/<span class="fs-dot[^>]*>[^<]*<\/span>/, ''));
                return { cls: m[1], html: m[2], n: l ? '①②③'.indexOf(l[1]) : -1 }; }); };
const rowsInDom = h => partsOf(h, 'fs-step', '<div class="fs-top">');
const rowsOf = h => rowsInDom(h).slice().sort((x, y) => x.n - y.n);        // r[0] = ①, r[1] = ②, r[2] = ③
const nodesOf = h => partsOf(h, 'fs-node', '<details class="step-more">');
const STAGE_NOW = { 0: '2026-10-22 08:00:00', 1: '2026-10-22 10:00:00', 2: '2026-10-24 10:00:00', 3: '2026-10-26 18:00:00' };

console.log('\n═══ Fixtures: ισχύς = week_start της εβδομάδας (όπως fuel_effective_date του server) ═══');
{
  const h = stripOf(stripWeek(2), STAGE_NOW[2], 'all', 9, true);
  const p = wkPay('2026-10-26');
  ck('εβδομάδα 26/10–01/11: «Ισχύς από 26-Oct-2026» στην κάρτα, ΟΧΙ 12-Oct-2026', h.includes('Ισχύς από <b>26-Oct-2026</b>') && !h.includes('12-Oct-2026'), (h.match(/Ισχύς από <b>[^<]*/) || [''])[0]);
  ck('...και στη στήλη EffectiveDate κάθε γραμμής του αρχείου (κεφαλίδα ίδια)',
     p.file.rows.slice(1).every(r => r[8] === '26-Oct-2026') && p.file.rows[0][8] === 'EffectiveDate' && PAYLOAD.file.rows[1][8] === '12-Oct-2026');
  ck('effDate: 2026-01-05 -> 05-Jan-2026, 2026-11-02 -> 02-Nov-2026', effDate('2026-01-05') === '05-Jan-2026' && effDate('2026-11-02') === '02-Nov-2026');
}

console.log('\n═══ Α3 λωρίδα: ΙΔΙΑ σε mine / queue / all, για κάθε στάδιο και κάθε χρήστη ═══');
{
  const bad = []; let compared = 0;
  const cases = [];
  for (const stage of [0, 1, 2, 3]) for (const o of [{}, { rootWho: null }, { verWho: null }])
    for (const now of [STAGE_NOW[stage], '2026-10-26 09:00:00']) cases.push([stage, o, now]);
  for (const [stage, o, now] of cases) {
    if (stage === 0 && o.rootWho === null && now !== STAGE_NOW[0]) continue;
    const ts = stripWeek(stage, o);
    for (const [me, admin] of [[1, false], [2, false], [3, false], [9, true]]) {
      const all = stripOf(ts, now, 'all', me, admin);
      for (const view of ['mine', 'queue']) {
        const got = stripOf(ts, now, view, me, admin);
        if (got === null) continue;              // η εβδομάδα δεν φαίνεται σε αυτή την καρτέλα
        compared++;
        if (got !== all) bad.push(`στάδιο ${stage} ${JSON.stringify(o)} ${now} u${me} ${view}`);
      }
    }
  }
  ck(`${compared} λωρίδες σε mine/queue: ΙΔΙΕΣ byte-προς-byte με την «Όλες» του ίδιου χρήστη`, compared >= 30 && bad.length === 0, bad.slice(0, 3).join(' | '));
  const mineU1 = stripOf(stripWeek(1), STAGE_NOW[1], 'mine', 1, false);
  ck('② τώρα, «Οι εργασίες μου» του u1 (μόνο η ρίζα στη λίστα): ολόκληρη η κάρτα, και το ③ «→ αδιάθετο»',
     mineU1 !== null && rowsOf(mineU1).length === 3 && rowsOf(mineU1)[2].html.includes('→ <b>αδιάθετο</b> · ανοίγει Δευ 26/10 00:00 · έως Δευ 26/10 17:00'),
     String(mineU1 && rowsOf(mineU1).length));
  const mineU2s2 = stripOf(stripWeek(2), '2026-10-26 09:00:00', 'mine', 2, false);
  ck('③ τώρα, «Οι εργασίες μου» της u2 (μόνο το verify στη λίστα): 3 βήματα, ① και ② «έγινε» με τι · πότε · ποιος',
     rowsOf(mineU2s2).length === 3 && mineU2s2.includes('Κατέβηκε · Πεμ 22/10 09:05 · u1')
     && mineU2s2.includes('Ανέβηκε στο CMS · Παρ 23/10 18:12 · u1'), mineU2s2);
}

console.log('\n═══ ΚΑΡΤΑ ΕΠΙΝΑΥΛΟΥ (19/10): γραμμές σε ΣΤΑΘΕΡΗ σειρά ③ ② ①, μπάρα ① ② ③ ═══');
{
  const H = (stage, me, admin, now, o) => stripOf(stripWeek(stage, o), now || STAGE_NOW[stage], 'all', me, admin);
  const seq = rs => rs.map(x => '①②③'[x.n] + ':' + x.cls).join(' ');
  const cases = [[0, 1, STAGE_NOW[0]], [1, 1, STAGE_NOW[1]], [2, 2, '2026-10-24 10:00:00'], [2, 2, '2026-10-26 09:00:00'], [3, 9, STAGE_NOW[3]]];
  const exp = ['③:next ②:next ①:now', '③:next ②:now ①:done', '③:next ②:done ①:done', '③:now ②:done ①:done', '③:done ②:done ①:done'];
  cases.forEach(([st, me, now], i) => ck(`γραμμές, στάδιο ${st} ${now.slice(5, 16)}: ${exp[i]} (ΠΟΤΕ δεν αλλάζουν θέση)`,
    seq(rowsInDom(H(st, me, me === 9, now))) === exp[i], seq(rowsInDom(H(st, me, me === 9, now)))));
  cases.forEach(([st, me, now]) => ck(`μπάρα, στάδιο ${st}: ① ② ③ από αριστερά`, nodesOf(H(st, me, me === 9, now)).map(x => x.n).join('') === '012'));
  const links = h => [...h.matchAll(/<span class="fs-link( done)?"><\/span>/g)].map(m => m[1] ? 'γεμάτη' : 'διακεκομμένη').join(' ');
  ck('γραμμή της μπάρας: γεμάτη μετά από «έγινε», αλλιώς διακεκομμένη', links(H(0, 1, false)) === 'διακεκομμένη διακεκομμένη'
     && links(H(1, 1, false)) === 'γεμάτη διακεκομμένη' && links(H(3, 9, true)) === 'γεμάτη γεμάτη', links(H(1, 1, false)));
  ck('η σειρά των γραμμών είναι ΣΤΑΘΕΡΗ στον κώδικα ([2, 1, 0]), όχι ταξινόμηση κατά κατάσταση', /\[2, 1, 0\]\.map\(row\)/.test(grab('fuelStripHtml')) && !/const rank =/.test(grab('fuelStripHtml')));

  let r = rowsOf(H(0, 1, false));
  ck('στάδιο 0, ανάδοχος ①: ① ▶ ΤΩΡΑ, οδηγία, κουμπί «⬇ Λήψη αρχείου» -> stepDownload',
     r[0].cls === 'now' && r[0].html.includes('<span class="fs-tag now">▶ ΤΩΡΑ</span><span class="fs-text">Κατέβασε το αρχείο για το CMS.</span>')
     && r[0].html.includes('<button class="btn fs-btn" onclick="stepDownload(90)">⬇ Λήψη αρχείου</button>'), r[0].html);
  ck('...② 🔒 ΠΕΡΙΜΕΝΕΙ «→ u1 · ανοίγει όταν γίνει το ① · έως Παρ 23/10 19:00» (όνομα έντονο), χωρίς κουμπί',
     r[1].cls === 'next' && r[1].html.includes('<span class="fs-tag next">🔒 ΠΕΡΙΜΕΝΕΙ</span>')
     && r[1].html.includes('→ <b>u1</b> · ανοίγει όταν γίνει το ① · έως Παρ 23/10 19:00') && !/<button/.test(r[1].html), r[1].html);
  ck('...③ «→ αδιάθετο · ανοίγει Δευ 26/10 00:00 · έως Δευ 26/10 17:00»', r[2].html.includes('→ <b>αδιάθετο</b> · ανοίγει Δευ 26/10 00:00 · έως Δευ 26/10 17:00'), r[2].html);
  r = rowsOf(H(0, 2, false));
  ck('στάδιο 0, άλλος χρήστης: ① ▶ ΤΩΡΑ «Το κάνει: u1», ΟΧΙ κουμπί', r[0].html.includes('Το κάνει: u1') && !/<button/.test(r[0].html), r[0].html);
  r = rowsOf(H(0, 9, true, STAGE_NOW[0], { rootWho: null }));
  ck('στάδιο 0, ① αδιάθετο: «Το κάνει: αδιάθετο», κανένα κουμπί ούτε για admin', r[0].html.includes('Το κάνει: αδιάθετο') && !/<button/.test(r[0].html), r[0].html);

  r = rowsOf(H(1, 1, false));
  ck('στάδιο 1: ① ✓ ΕΓΙΝΕ «Κατέβηκε · Πεμ 22/10 09:05 · u1» + μικρό «⬇ ξανά» (καταγράφεται)',
     r[0].cls === 'done' && r[0].html.includes('<span class="fs-tag done"><span class="fs-ok"></span>ΕΓΙΝΕ</span><span class="fs-text">Κατέβηκε · Πεμ 22/10 09:05 · u1</span>')
     && /<button class="btn fs-again" title="Download again[^"]*" onclick="stepDownload\(90\)">⬇ ξανά<\/button>/.test(r[0].html), r[0].html);
  ck('...② ▶ ΤΩΡΑ, οδηγία CMS, κουμπί «Δηλώνω: το ανέβασα στο CMS» -> openUpload',
     r[1].cls === 'now' && r[1].html.includes('Μπες στο CMS → Fuel Charge Import → ανέβασε το αρχείο. Μετά πάτα το κουμπί.')
     && r[1].html.includes('<button class="btn fs-btn" onclick="openUpload(90)">Δηλώνω: το ανέβασα στο CMS</button>'), r[1].html);
  const n1 = nodesOf(H(1, 1, false));
  ck('μπάρα στάδιο 1: ① έγινε «Πεμ 22/10 09:05», ② «ΤΩΡΑ · έως Παρ 23/10 19:00», ③ «→ αδιάθετο · Δευ 26/10 00:00»',
     n1[0].cls === 'done' && n1[0].html.includes('Πεμ 22/10 09:05') && n1[1].cls === 'now' && n1[1].html.includes('ΤΩΡΑ · έως Παρ 23/10 19:00')
     && n1[2].cls === 'next' && n1[2].html.includes('→ <b>αδιάθετο</b> · Δευ 26/10 00:00'), n1.map(x => x.html).join(' | '));
  r = rowsOf(H(1, 2, false));
  ck('στάδιο 1, άλλος χρήστης: ② «Το κάνει: u1», κανένα κουμπί', r[1].html.includes('Το κάνει: u1') && !/<button/.test(r[1].html));
  r = rowsOf(H(1, 3, false));
  ck('στάδιο 1, χρήστης εκτός εβδομάδας: ① έγινε ΧΩΡΙΣ «⬇ ξανά» (ο server θα αρνιόταν)', r[0].cls === 'done' && !/<button/.test(r[0].html));
  r = rowsOf(H(2, 2, false, '2026-10-24 10:00:00'));
  ck('στάδιο 2, ΠΡΙΝ τη Δευτέρα: ② ✓ ΕΓΙΝΕ «Ανέβηκε στο CMS · Παρ 23/10 18:12 · u1», ③ ΠΕΡΙΜΕΝΕΙ «→ u2 · ανοίγει Δευ 26/10 00:00 · έως Δευ 26/10 17:00»',
     r[1].html.includes('Ανέβηκε στο CMS · Παρ 23/10 18:12 · u1') && r[2].cls === 'next'
     && r[2].html.includes('→ <b>u2</b> · ανοίγει Δευ 26/10 00:00 · έως Δευ 26/10 17:00') && !/<button/.test(r[2].html), r[2].html);
  ck('...ο ανάδοχος ③ ξανακατεβάζει το ① (ο server δέχεται όποιον έχει εργασία της εβδομάδας)', /onclick="stepDownload\(90\)"/.test(r[0].html));
  ck('όριο: Κυρ 25/10 23:59:59 ③ ΠΕΡΙΜΕΝΕΙ, Δευ 26/10 00:00:00 ③ ΤΩΡΑ',
     rowsOf(H(2, 2, false, '2026-10-25 23:59:59'))[2].cls === 'next' && rowsOf(H(2, 2, false, '2026-10-26 00:00:00'))[2].cls === 'now');
  r = rowsOf(H(2, 2, false, '2026-10-26 09:00:00'));
  ck('στάδιο 2, Δευτέρα, ανάδοχος ③: «🔍 Άνοιξε τον επαληθευτή» -> act(91,\'done\'), οδηγία Price Calculator',
     r[2].cls === 'now' && r[2].html.includes('Έλεγξε τις τιμές στο Price Calculator του CMS και διάλεξέ τες στον επαληθευτή.')
     && r[2].html.includes(`<button class="btn fs-btn" onclick="act(91,'done')">🔍 Άνοιξε τον επαληθευτή</button>`), r[2].html);
  ck('...ανάδοχος ①: ③ «Το κάνει: u2»', rowsOf(H(2, 1, false, '2026-10-26 09:00:00'))[2].html.includes('Το κάνει: u2'));
  r = rowsOf(H(3, 9, true));
  ck('στάδιο 3: ③ ✓ ΕΓΙΝΕ «Επαληθεύτηκε · Δευ 26/10 10:30 · u2», όλα έγιναν', r.every(x => x.cls === 'done')
     && r[2].html.includes('Επαληθεύτηκε · Δευ 26/10 10:30 · u2'), r[2].html);
  ck('...το ① ξανακατεβαίνει και μετά (admin)', /onclick="stepDownload\(90\)"/.test(r[0].html));
  // Παλιά ροή (#76/#87): κλειστή ρίζα χωρίς γεγονός λήψης.
  const old = stripWeek(3); old[0] = Object.assign({}, old[0], { steps: STEPS_AT(false, false) });
  const ho = stripOf(withChain(old.map(t => { const x = Object.assign({}, t); delete x.chain; return x; })), STAGE_NOW[3], 'all', 9, true);
  const o1 = rowsOf(ho)[0];
  ck('παλιά ροή (κλειστή, χωρίς λήψη): ① ΕΓΙΝΕ «Κατέβηκε · — · —», ΟΧΙ μπλε κουμπί, μόνο «⬇ ξανά»',
     o1.cls === 'done' && o1.html.includes('Κατέβηκε · — · —') && !/fs-btn/.test(o1.html) && /fs-again/.test(o1.html), o1.html);

  // ΚΑΝΟΝΕΣ (phase5_plan.md #43) σε κάθε στάδιο / χρήστη.
  const NEVER = /Δεν εφαρμόζεται|Απόρριψη|Αφήνω|Αναλαμβάνω|Έτοιμη για ολοκλήρωση|markNa\(|openReject\(|steal\(|claim\(|act\(90,|class="st"/;
  const bad = [], tick = [], word = [], said = [];
  for (const stage of [0, 1, 2, 3]) for (const now of [STAGE_NOW[stage], '2026-10-26 09:00:00'])
    for (const [me, admin] of [[1, false], [1, true], [2, false], [9, true], [3, false]]) {
      const h = stripOf(stripWeek(stage), now, 'all', me, admin);
      if (NEVER.test(h)) bad.push(`στάδιο ${stage} u${me}: ${h.match(NEVER)[0]}`);
      if (/<button[^>]*>[^<]*✓/.test(h)) tick.push(`στάδιο ${stage} u${me}`);
      if (/Ολοκλήρωση/.test(h.slice(0, h.indexOf('<div class="fs-top">')))) word.push(`στάδιο ${stage} u${me}`);
      for (const x of rowsOf(h)) if (x.cls === 'done' && !/<span class="fs-text">[^<]+ · [^<]+ · [^<]+<\/span>/.test(x.html)) said.push(`στάδιο ${stage} ${'①②③'[x.n]}`);
    }
  ck('κανένα «Δεν εφαρμόζεται / Απόρριψη / Αφήνω / Αναλαμβάνω», κανένα badge, κανένα οβάλ (40 περιπτώσεις)', bad.length === 0, bad.slice(0, 3).join(' | '));
  ck('κανένα ✓ πάνω σε κουμπί', tick.length === 0, tick.join(' | '));
  ck('η λέξη «Ολοκλήρωση» δεν υπάρχει στην κάρτα', word.length === 0, word.join(' | '));
  ck('κάθε «έγινε» λέει τι · πότε · ποιος', said.length === 0, said.join(' | '));
}

console.log('\n═══ ΚΑΡΤΑ ΕΠΙΝΑΥΛΟΥ: «⋯», ειδοποιήσεις, χρώματα από τη βάση, αντίθεση, κινητό ═══');
{
  const menu = h => { const m = /<div class="step-menu">([\s\S]*?)<\/div><\/details>/.exec(h); return m ? m[1] : ''; };
  const calls = h => [...menu(h).matchAll(/onclick="(\w+)\((\d+)\)"/g)].map(m => m[1] + m[2]);
  let h = stripOf(stripWeek(1), STAGE_NOW[1], 'all', 9, true);
  ck('admin (όχι ανάδοχος): Ανάθεση ①, Παράκαμψη, Ανάθεση ③, ιστορικό ①, ιστορικό ③',
     JSON.stringify(calls(h)) === '["assign90","openOverride90","assign91","toggleHist90","toggleHist91"]', calls(h).join(','));
  ck('...«⋯» δίπλα στη μπάρα, ΠΑΝΩ από τις γραμμές και τον πίνακα ποσοστών',
     h.indexOf('<div class="fs-row"><div class="fs-bar">') >= 0 && h.indexOf('<details class="step-more">') < h.indexOf('<div class="fs-steps">')
     && h.indexOf('<div class="fs-steps">') < h.indexOf('<div class="fs-top">'));
  h = stripOf(stripWeek(1), STAGE_NOW[1], 'all', 1, true);
  ck('admin ανάδοχος ①: ΟΧΙ Παράκαμψη', !calls(h).includes('openOverride90') && calls(h).includes('assign90'));
  for (const me of [1, 2, 3])
    ck(`μη-admin u${me}: μόνο ιστορικό ① και ③`, JSON.stringify(calls(stripOf(stripWeek(1), STAGE_NOW[1], 'all', me, false))) === '["toggleHist90","toggleHist91"]');
  h = stripOf(stripWeek(3), STAGE_NOW[3], 'all', 9, true);
  ck('όλα κλειστά, admin: μόνο ιστορικό', JSON.stringify(calls(h)) === '["toggleHist90","toggleHist91"]');

  h = stripOf(stripWeek(1), STAGE_NOW[1], 'all', 2, false);
  ck('📧 Ειδοποιήσεις: ΚΕΝΗ ως τη Φάση Β («Καμία ακόμα»)', h.includes('<div class="fs-notes-h">📧 Ειδοποιήσεις που στάλθηκαν</div><div class="fs-note none">Καμία ακόμα</div>'));
  h = stripOf(stripWeek(2, { verNotices: [{ at: '2026-10-23 18:13:00', what: '📧 Ανέβηκε · σειρά σου για επαλήθευση', to: 'u2' }],
                             rootNotices: [{ at: '2026-10-22 19:00:00', what: '<b>x</b>', to: null }] }), '2026-10-24 10:00:00', 'mine', 2, false);
  ck('...με ειδοποιήσεις από το ιστορικό: ώρα · τι · προς ποιον, με τη σειρά της ώρας, escaped',
     h.includes('<div class="fs-note"><span class="fs-at">Πεμ 22/10 19:00</span><span>&lt;b&gt;x&lt;/b&gt;</span></div>')
     && h.includes('<div class="fs-note"><span class="fs-at">Παρ 23/10 18:13</span><span>📧 Ανέβηκε · σειρά σου για επαλήθευση</span><span class="fs-to">προς u2</span></div>')
     && h.indexOf('Πεμ 22/10 19:00') < h.indexOf('Παρ 23/10 18:13'), h);

  h = stripOf(stripWeek(1), STAGE_NOW[1], 'all', 1, false);
  const FS = new Function(grabConst('FS_COLORS') + '\nreturn FS_COLORS;')();
  ck(`χρώματα ΜΟΝΟ από τη βάση: ${FS.length} κλειδιά (FS_COLORS), όλα #rrggbb στο seed, όλα στο style της κάρτας`,
     FS.every(([v, k]) => /^#[0-9a-f]{6}$/i.test(MIG_TEXTS.tasks[k] || '') && h.includes(`${v}:${MIG_TEXTS.tasks[k]};`)), FS.filter(([, k]) => !MIG_TEXTS.tasks[k]).join(','));
  h = stripOf(stripWeek(1), STAGE_NOW[1], 'all', 1, false, false);
  ck('...κείμενα που δεν φορτώθηκαν: κανένα inline χρώμα (το CSS κρατά την εφεδρεία), [κλειδί] στα κείμενα',
     h.startsWith('<div class="fstrip">') && h.includes('[strip_l1]'), h.slice(0, 80));
  const lum = hex => { const c = [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16) / 255).map(v => v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
                       return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const C = k => MIG_TEXTS.tasks[k];
  const pairs = [['✓ σε κύκλο/ετικέτα «έγινε»', C('color_fs_mark'), C('color_fs_done_dot')], ['«πότε» στη μπάρα', C('color_fs_done_cap'), C('color_fs_bar_bg')],
    ['αριθμός «ακολουθεί»', C('color_fs_next_num'), '#ffffff'], ['κείμενο «έγινε»', C('color_fs_done_text'), C('color_fs_done_bg')],
    ['ετικέτα «ΕΓΙΝΕ»', C('color_fs_done_text'), C('color_fs_done_dot')], ['κείμενο «ακολουθεί»', C('color_fs_next_text'), C('color_fs_next_bg')],
    ['«→ ποιος» στη μπάρα', C('color_fs_next_text'), C('color_fs_bar_bg')], ['κείμενο «τώρα»', C('color_fs_ink'), C('color_open_head')],
    ['«▶ ΤΩΡΑ»', C('color_open_head'), C('color_fs_ink')], ['«✗ ΛΑΘΟΣ»', '#ffffff', C('color_fs_wrong')],
    ['κείμενο «λάθος»', C('color_fs_ink'), C('color_fs_wrong_bg')], ['κουμπί', '#ffffff', C('color_fs_btn')]];
  const low = pairs.filter(([, a, b]) => ratio(a, b) < 4.5);
  ck(`αντίθεση ≥ 4.5:1 σε ${pairs.length} ζεύγη κειμένου/✓ και φόντου`, low.length === 0, low.map(([n, a, b]) => `${n} ${ratio(a, b).toFixed(2)}`).join(' | '));
  const css = src.slice(src.indexOf('.fs-row {'), src.indexOf('.fs-notes {'));
  ck('κινητό (< 600px): οι γραμμές στοιβάζονται', /@media \(max-width: 600px\) \{\s*\.fs-step \{ grid-template-columns: minmax\(0,1fr\); \}/.test(css));
  ck('γραμμή: όνομα 150px | ετικέτα + κείμενο | κουμπί', /\.fs-step \{ display: grid; grid-template-columns: 150px minmax\(0,1fr\) auto;/.test(css));
  ck('✓ και ✗ των κύκλων/ετικέτας από το CSS, όχι κείμενο στον κώδικα', /\.fs-dot\.done::before, \.fs-ok::before \{ content: "\\2713"; \}/.test(css)
     && /\.fs-dot\.wrong::before \{ content: "\\2717"; \}/.test(css));
  const keys = [...new Set([...['fuelStripHtml', 'fuelStripMenu', 'stripColorVar'].map(grab).join('\n').matchAll(/pageText\('(\w+)'/g)].map(m => m[1]))];
  ck(`κάθε pageText της κάρτας (${keys.length}) έχει κλειδί στο seed (15b / 19b / 21a)`, keys.length >= 20 && keys.every(k => k in MIG_TEXTS.tasks), keys.filter(k => !(k in MIG_TEXTS.tasks)).join(','));
}

console.log('\n═══ Α3 λωρίδα: ρίζα ΕΚΤΟΣ λίστας (chain) — λήψη / ολοκλήρωση / παράκαμψη βρίσκουν την εργασία ═══');
{
  const ts = stripWeek(2);
  const { api } = env({ statuses: ST_DB, now: '2026-10-26 09:00:00', userId: 2, admin: false, view: 'mine' });
  const shown = tabOf(ts, 'mine', 2);
  api.setTasks(shown);
  const r = api.taskById(90);
  ck('taskById: η ρίζα (90) δεν είναι στη λίστα, τη βρίσκει στο chain, με payload, βήματα και ui',
     shown.every(t => t.id !== 90) && r && r.id === 90 && r.payload === shown[0].payload && Array.isArray(r.steps) && r.ui === UI, JSON.stringify(r && Object.keys(r)));
  ck('...και η downloadFuelCms / uploadTask / overrideTask την ψάχνουν με taskById',
     /const t = taskById\(id\);/.test(grab('downloadFuelCms')) && /taskById\(uploadId\)/.test(grab('uploadTask')) && /taskById\(overrideId\)/.test(grab('overrideTask')));
  ck('taskById: άγνωστο id -> null', api.taskById(12345) === null);
}

console.log('\n═══ Α3 λωρίδα: ΧΩΡΙΣ step (παλιά ροή) καμία λωρίδα · taskRow όπως πριν από το Α3 ═══');
{
  const old = fuelWeek(80, '2026-10-19', 'done', 'in_progress', { up: { assigned_to: 1, assigned_name: 'u1' }, ver: { assigned_to: 2, assigned_name: 'u2' } });
  ck('εβδομάδα χωρίς step: γραμμές, όχι λωρίδα', stripOf(withChain(old), '2026-10-16 12:00:00', 'all', 9, true) === null);
  ck('taskRow χωρίς τίποτα από την παλιά γραμμή verify του Α3 (η λωρίδα αντικαθιστά τις γραμμές)',
     /^function taskRow\(t\)\{/.test(grab('taskRow')) && !/verifyActive|fuel_verify_|fuelActiveRoot|weekItems/.test(grab('taskRow')));
}


console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══\n`);
process.exit(fail ? 1 : 0);
