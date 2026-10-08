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
const DB_FUEL_TYPES = { AIR_CY: { code: 'AIR_CY', label_el: 'Air Cyprus',     sort_order: 30 },
                        NONE:   { code: 'NONE',   label_el: 'Χωρίς επίναυλο', sort_order: 40 },
                        ROAD:   { code: 'ROAD',   label_el: 'Οδικός',         sort_order: 20 },
                        AIR:    { code: 'AIR',    label_el: 'Αεροπορικός',    sort_order: 10 } };

function env(opts){
  opts = opts || {};
  const els = {};
  const doc = { getElementById: id => (els[id] = els[id] || { innerHTML: '', style: {} }) };
  // Η πραγματική athensNow() δεν τρέχει στα τεστ: σταθερό «τώρα» Αθήνας.
  const NOW = opts.now === undefined ? '2026-10-08 10:00:00' : opts.now;
  const store = { getItem: () => null };
  const code = [
    'let currentUser = { id: 1 }, isAdmin = true, view = "all", lastTasks = [];',
    'let STATUSES = ' + JSON.stringify(opts.statuses === undefined ? DB_STATUSES : opts.statuses) + ';',
    'let FUEL_TYPES = ' + JSON.stringify(opts.fuelTypes === undefined ? DB_FUEL_TYPES : opts.fuelTypes) + ';',
    'const histOpen = new Map();',
    grabConst('TIPS'), grabConst('ACTION_URL_OK'), grabConst('FUEL_CMS_HEADER'), grabConst('GR_DAY3'),
    grab('escHtml'), grab('textOn'), grab('stBadge'), grab('whoCell'), grab('histHtml'), grab('histWhat'),
    grab('canOpen'), grab('actionBtn'), grab('summaryHtml'),
    grab('fmtWeekRange'), grab('taskTitle'), grab('closedColor'), grab('closedGroupStyle'), grab('renderLegend'),
    grab('taskPayload'), grab('fuelCmsAoa'), grab('fuelCmsWorkbook'), grab('fmtDue'),
    'function athensNow(){ return ' + JSON.stringify(NOW) + '; }', grab('normDateTime'), grab('isPastDue'), grab('fuelColumns'), grab('fuelFactsHtml'),
    grab('taskRow'), grab('render'),
    'return { render, renderLegend, closedGroupStyle, taskRow, fuelCmsAoa, fuelCmsWorkbook, fmtDue, fuelFactsHtml, fuelColumns, isPastDue, taskPayload, fmtWeekRange, taskTitle };'
  ].join('\n');
  const api = new Function('document', 'sessionStorage', 'EV_LABELS', code)(doc, store, {});
  return { api, els };
}

// Γραμμές όπως τις δίνει η tasks_base_sql(): label, icon και title_template
// από τον 4a_task_types (migration 2026-10-08a).
const BASE = { assigned_to: null, assigned_name: null, status: 'open', needs_attention: 0, locked: 0,
               is_demo: 0, close_reason: null, offer_number: '', ready: true, ready_enforced: false };
const FW  = { task_code: 'fuel_weekly', task_label: 'Γενικός επίναυλος εβδομάδας — αρχείο CMS',
              task_icon: '⛽', task_title_template: 'Γενικός επίναυλος {week} — αρχείο CMS', depends_on: null };
const FWV = { task_code: 'fuel_weekly_verify', task_label: 'Έλεγχος γενικού επίναυλου στο CMS',
              task_icon: '⛽', task_title_template: 'Έλεγχος γενικού επίναυλου {week} στο CMS', depends_on: 'fuel_weekly' };
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
  const T = { due_at: '2026-10-09 17:00:00', status: 'in_progress' };
  const h = api.fuelFactsHtml(T, PAYLOAD);
  const ths = [...h.matchAll(/<th>([^<]*)<\/th>/g)].map(m => m[1]);
  const tds = [...h.matchAll(/<td>([^<]*)<\/td>/g)].map(m => m[1]);
  ck('πίνακας: μία γραμμή κεφαλίδας, μία γραμμή τιμών', (h.match(/<tr>/g) || []).length === 2 && h.includes('<table class="fuel-tbl">'), h);
  ck('στήλες με σειρά sort_order: Αεροπορικός · Οδικός · Air Cyprus', JSON.stringify(ths) === JSON.stringify(['Αεροπορικός', 'Οδικός', 'Air Cyprus']), JSON.stringify(ths));
  ck('τιμές «49.00 %» · «40.75 %» · «51.94 %», κάτω από τη στήλη τους', JSON.stringify(tds) === JSON.stringify(['49.00 %', '40.75 %', '51.94 %']), JSON.stringify(tds));
  const swapped = env({ fuelTypes: { AIR: { code: 'AIR', label_el: 'Αεροπορικός', sort_order: 30 },
                                     ROAD: { code: 'ROAD', label_el: 'Οδικός', sort_order: 10 },
                                     AIR_CY: { code: 'AIR_CY', label_el: 'Air Cyprus', sort_order: 20 } } }).api.fuelFactsHtml(T, PAYLOAD);
  ck('άλλο sort_order στη βάση -> άλλη σειρά στηλών', [...swapped.matchAll(/<th>([^<]*)<\/th>/g)].map(m => m[1]).join('|') === 'Οδικός|Air Cyprus|Αεροπορικός', swapped);
  const plus = env({ fuelTypes: Object.assign({}, DB_FUEL_TYPES, { SEA: { code: 'SEA', label_el: 'Θαλάσσιος', sort_order: 25 } }) })
                 .api.fuelFactsHtml(T, Object.assign(clone(PAYLOAD), { prices: Object.assign({}, PAYLOAD.prices, { SEA: '12.50' }) }));
  ck('νέος τύπος στη βάση (με τιμή στο payload) -> νέα στήλη στη θέση του, χωρίς αλλαγή κώδικα',
     [...plus.matchAll(/<th>([^<]*)<\/th>/g)].map(m => m[1]).join('|') === 'Αεροπορικός|Οδικός|Θαλάσσιος|Air Cyprus' && plus.includes('<td>12.50 %</td>'), plus);
  ck('κανένας κωδικός τύπου (AIR_CY, AIR, ROAD) στο HTML', !/AIR_CY|\bAIR\b|\bROAD\b/.test(h), h);
  ck('ο τύπος NONE (null) δεν εμφανίζεται', !h.includes('NONE') && !h.includes('Χωρίς επίναυλο'));
  ck('γραμμή: «Ισχύς από 12-Oct-2026 · Προθεσμία Παρ 09/10 17:00 · 69 γραμμές»',
     h.replace(/<[^>]+>/g, '').includes('Ισχύς από 12-Oct-2026 · Προθεσμία Παρ 09/10 17:00 · 69 γραμμές'), h.replace(/<[^>]+>/g, ''));
  ck('η προθεσμία έντονη (fuel-due), όχι κόκκινη πριν το due_at', h.includes('<span class="fuel-due">Παρ 09/10 17:00</span>'), h);
  ck('isPastDue: 16:59 όχι, 17:00 όχι, 17:01 ναι',
     !api.isPastDue('2026-10-09 17:00:00', '2026-10-09 16:59:59') && !api.isPastDue('2026-10-09 17:00:00', '2026-10-09 17:00:00')
     && api.isPastDue('2026-10-09 17:00:00', '2026-10-09 17:01:00'));
  ck('κανονικοποίηση: «T» και χωρίς δευτερόλεπτα -> ίδια σύγκριση (17:00 == 17:00:00, όχι αργά)',
     !api.isPastDue('2026-10-09T17:00', '2026-10-09 17:00:00') && !api.isPastDue('2026-10-09 17:00:00', '2026-10-09T17:00')
     && api.isPastDue('2026-10-09T17:00', '2026-10-09 17:00:01'));
  ck('Z / offset (όχι ώρα Αθήνας) -> ΔΕΝ κρίνεται αργά',
     !api.isPastDue('2026-10-09T17:00:00Z', '2026-10-20 00:00:00') && !api.isPastDue('2026-10-09 17:00:00', '2026-10-20T00:00:00+03:00'));
  const late = env({ now: '2026-10-09 17:00:01' }).api.fuelFactsHtml(T, PAYLOAD);
  ck('μετά το due_at -> κόκκινη (fuel-due late)', late.includes('class="fuel-due late"'), late);
  ck('ακριβώς στο due_at -> όχι κόκκινη', !env({ now: '2026-10-09 17:00:00' }).api.fuelFactsHtml(T, PAYLOAD).includes('late'));
  const lateDone = env({ now: '2026-10-20 09:00:00' }).api.fuelFactsHtml(Object.assign({}, T, { status: 'done' }), PAYLOAD);
  ck('ολοκληρωμένη εργασία: ποτέ κόκκινη, ακόμα κι αν πέρασε η ώρα', !lateDone.includes('late'), lateDone);
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

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══\n`);
process.exit(fail ? 1 : 0);
