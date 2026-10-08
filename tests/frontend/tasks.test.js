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

function env(){
  const els = {};
  const doc = { getElementById: id => (els[id] = els[id] || { innerHTML: '', style: {} }) };
  const store = { getItem: () => null };
  const code = [
    'let currentUser = { id: 1 }, isAdmin = true, view = "all", lastTasks = [], STATUSES = {};',
    'const histOpen = new Map();',
    grabConst('TIPS'), grabConst('ACTION_URL_OK'), grabConst('FUEL_CMS_HEADER'), grabConst('GR_DAY3'),
    grab('escHtml'), grab('textOn'), grab('stBadge'), grab('whoCell'), grab('histHtml'), grab('histWhat'),
    grab('canOpen'), grab('actionBtn'), grab('summaryHtml'),
    grab('taskPayload'), grab('fuelCmsAoa'), grab('fuelCmsWorkbook'), grab('fmtDue'), grab('fuelFactsHtml'),
    grab('taskRow'), grab('render'),
    'return { render, taskRow, fuelCmsAoa, fuelCmsWorkbook, fmtDue, fuelFactsHtml, taskPayload };'
  ].join('\n');
  const api = new Function('document', 'sessionStorage', 'EV_LABELS', code)(doc, store, {});
  return { api, els };
}

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
  ck('εβδομάδα', h.includes(PAYLOAD.week));
  ck('τιμές AIR 49.00 · ROAD 40.75 · AIR_CY 51.94', h.includes('AIR 49.00%') && h.includes('ROAD 40.75%') && h.includes('AIR_CY 51.94%'), h);
  ck('ο τύπος NONE (null) δεν εμφανίζεται', !h.includes('NONE'));
  ck('ισχύς 12-Oct-2026', h.includes('12-Oct-2026'));
  ck('προθεσμία Παρ 09/10/2026 17:00', h.includes('Παρ 09/10/2026 17:00'));
  ck('γραμμές 69', h.includes('γραμμές <b>69</b>'));
  const hist = api.fuelFactsHtml({}, { kind: 'fuel_weekly', week_start: '2026-10-05', week_end: '2026-10-11',
    effective_date: '01-Oct-2026', retroactive: true, proof: { expected: 69, generated: 69, extra: 0, missing: 0 } });
  ck('ιστορικό 2026-10-05: ισχύς 01-Oct-2026 και απόδειξη 69/69', hist.includes('01-Oct-2026') && hist.includes('69/69'), hist);
  ck('payload πελάτη: κανένα στοιχείο επίναυλου', api.fuelFactsHtml({}, { account: 'X' }) === '');
}

console.log('\n═══ Λίστα: εργασίες συστήματος στην ΚΟΡΥΦΗ ═══');
{
  const { api, els } = env();
  const base = { assigned_to: null, assigned_name: null, status: 'open', needs_attention: 0, locked: 0,
                 is_demo: 0, close_reason: null, offer_number: '', ready: true, ready_enforced: false };
  const tasks = [
    Object.assign({}, base, { id: 50, client_id: 5, client_name: 'Πελάτης Α', client_country: 'GR',
      client_account: 'ACC', task_code: 'open_code', task_label: 'Άνοιγμα κωδικού', depends_on: null,
      payload: JSON.stringify({ account: 'ACC' }) }),
    Object.assign({}, base, { id: 90, client_id: null, client_name: null, client_country: 'BOTH', task_country: 'BOTH',
      subject_key: 'fuel:2026-10-12', task_code: 'fuel_weekly', task_label: 'Γενικός επίναυλος εβδομάδας — αρχείο CMS',
      depends_on: null, due_at: '2026-10-09 17:00:00', payload: JSON.stringify(PAYLOAD) }),
    Object.assign({}, base, { id: 91, client_id: null, client_name: null, client_country: 'BOTH', task_country: 'BOTH',
      subject_key: 'fuel:2026-10-12', task_code: 'fuel_weekly_verify', task_label: 'Έλεγχος γενικού επίναυλου στο CMS',
      depends_on: 'fuel_weekly', locked: 1, blocked_by_label: 'Γενικός επίναυλος εβδομάδας — αρχείο CMS',
      due_at: '2026-10-09 17:00:00', payload: JSON.stringify(PAYLOAD) }),
  ];
  api.render(tasks);
  const html = els.list.innerHTML;
  const iSys = html.indexOf('client-group sys'), iCli = html.indexOf('Πελάτης Α');
  ck('ομάδα συστήματος ΠΡΙΝ από τους πελάτες', iSys >= 0 && iCli > iSys, `${iSys} / ${iCli}`);
  ck('ΜΙΑ ομάδα συστήματος για το fuel:2026-10-12', (html.match(/client-group sys/g) || []).length === 1);
  const head = html.slice(iSys, html.indexOf('</div>', html.indexOf('client-head', iSys)));
  ck('τίτλος = ετικέτα του τύπου-ρίζας από τη βάση', head.includes('Γενικός επίναυλος εβδομάδας — αρχείο CMS'), head);
  ck('σήμανση ΣΥΣΤΗΜΑ και χώρα BOTH', head.includes('ΣΥΣΤΗΜΑ') && head.includes('flag BOTH'));
  ck('κουμπί «Λήψη αρχείου CMS»', html.includes('⬇ Λήψη αρχείου CMS') && html.includes('downloadFuelCms(90)'));
  ck('το verify (κλειδωμένο) δείχνει την εργασία που περιμένει', html.includes('🔒') && html.includes('Έλεγχος γενικού επίναυλου στο CMS'));
  ck('η ομάδα του πελάτη έμεινε όπως ήταν', html.includes('AccountNo ACC') && html.includes('Άνοιγμα κωδικού'));
  ck('η εργασία πελάτη ΔΕΝ έχει κουμπί λήψης CMS', !html.slice(iCli).includes('downloadFuelCms'));
}

console.log('\n═══ Δομικά ═══');
{
  const fns = ['fuelCmsAoa', 'fuelCmsWorkbook', 'fuelFactsHtml'].map(grab).join('\n');
  ck('καμία μορφοποίηση αριθμών στις συναρτήσεις επίναυλου (toFixed/parseFloat/Number)',
     !/toFixed|parseFloat|Number\(/.test(fns));
  ck('το xlsx γράφεται ΜΟΝΟ από το payload (downloadFuelCms -> fuelCmsAoa)', /fuelCmsAoa\(p\)/.test(grab('downloadFuelCms')));
  ck('όνομα αρχείου FuelChargeImport_GR_CY_<ισχύς>.xlsx', /'FuelChargeImport_GR_CY_' \+ p\.effective_date \+ '\.xlsx'/.test(src));
}

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══\n`);
process.exit(fail ? 1 : 0);
