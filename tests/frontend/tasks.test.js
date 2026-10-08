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
const DB_FUEL_TYPES = { AIR:    { code: 'AIR',    label_el: 'Αεροπορικός' },
                        ROAD:   { code: 'ROAD',   label_el: 'Οδικός' },
                        AIR_CY: { code: 'AIR_CY', label_el: 'Air Cyprus' },
                        NONE:   { code: 'NONE',   label_el: 'Χωρίς επίναυλο' } };

function env(opts){
  opts = opts || {};
  const els = {};
  const doc = { getElementById: id => (els[id] = els[id] || { innerHTML: '', style: {} }) };
  const store = { getItem: () => null };
  const code = [
    'let currentUser = { id: 1 }, isAdmin = true, view = "all", lastTasks = [];',
    'let STATUSES = ' + JSON.stringify(opts.statuses === undefined ? DB_STATUSES : opts.statuses) + ';',
    'let FUEL_TYPES = ' + JSON.stringify(opts.fuelTypes === undefined ? DB_FUEL_TYPES : opts.fuelTypes) + ';',
    'const histOpen = new Map();',
    grabConst('TIPS'), grabConst('ACTION_URL_OK'), grabConst('FUEL_CMS_HEADER'), grabConst('GR_DAY3'),
    grab('escHtml'), grab('textOn'), grab('stBadge'), grab('whoCell'), grab('histHtml'), grab('histWhat'),
    grab('canOpen'), grab('actionBtn'), grab('summaryHtml'),
    grab('fmtWeekRange'), grab('taskTitle'), grab('closedGroupStyle'),
    grab('taskPayload'), grab('fuelCmsAoa'), grab('fuelCmsWorkbook'), grab('fmtDue'), grab('fuelFactsHtml'),
    grab('taskRow'), grab('render'),
    'return { render, taskRow, fuelCmsAoa, fuelCmsWorkbook, fmtDue, fuelFactsHtml, taskPayload, fmtWeekRange, taskTitle };'
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
  const h = api.fuelFactsHtml({ due_at: '2026-10-09 17:00:00' }, PAYLOAD);
  ck('εβδομάδα 12–18/10', h.includes('εβδομάδα <b>12–18/10</b>'), h);
  ck('τιμές με ετικέτες από τη βάση: Αεροπορικός 49.00 · Οδικός 40.75 · Air Cyprus 51.94',
     h.includes('Αεροπορικός 49.00%') && h.includes('Οδικός 40.75%') && h.includes('Air Cyprus 51.94%'), h);
  ck('κανένας κωδικός τύπου (AIR_CY, AIR, ROAD) στο HTML', !/AIR_CY|\bAIR\b|\bROAD\b/.test(h), h);
  ck('ο τύπος NONE (null) δεν εμφανίζεται', !h.includes('NONE') && !h.includes('Χωρίς επίναυλο'));
  ck('ισχύς 12-Oct-2026', h.includes('12-Oct-2026'));
  ck('προθεσμία Παρ 09/10/2026 17:00', h.includes('Παρ 09/10/2026 17:00'));
  ck('γραμμές 69', h.includes('γραμμές <b>69</b>'));
  const hist = api.fuelFactsHtml({}, HIST_PAYLOAD);
  ck('ιστορικό 2026-10-05: ισχύς 01-Oct-2026 και απόδειξη 69/69', hist.includes('01-Oct-2026') && hist.includes('69/69'), hist);
  ck('payload πελάτη: κανένα στοιχείο επίναυλου', api.fuelFactsHtml({}, { account: 'X' }) === '');
  const noLbl = env({ fuelTypes: {} }).api.fuelFactsHtml({}, PAYLOAD);
  ck('χωρίς ετικέτες από τη βάση: ΟΥΤΕ κωδικοί, μόνο σημείωση', !/AIR_CY|\bAIR\b|\bROAD\b/.test(noLbl) && noLbl.includes('οι ετικέτες δεν φορτώθηκαν'), noLbl);
}

console.log('\n═══ Εβδομάδα: ίδιος τρόπος παντού ═══');
{
  const { api } = env();
  ck('12–18/10', api.fmtWeekRange('2026-10-12', '2026-10-18') === '12–18/10');
  ck('05–11/10', api.fmtWeekRange('2026-10-05', '2026-10-11') === '05–11/10');
  ck('αλλαγή μήνα: 28/09–04/10', api.fmtWeekRange('2026-09-28', '2026-10-04') === '28/09–04/10');
  const h12 = api.fuelFactsHtml({}, PAYLOAD), h05 = api.fuelFactsHtml({}, HIST_PAYLOAD);
  ck('η 12/10 (με ετικέτα DHL στο payload) και η 05/10 (χωρίς) γράφονται ίδια',
     h12.includes('εβδομάδα <b>12–18/10</b>') && h05.includes('εβδομάδα <b>05–11/10</b>'), h05);
  ck('η ετικέτα του DHL («Οκτώβριος 12-18, 2026») ΔΕΝ εμφανίζεται', !h12.includes(PAYLOAD.week));
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
