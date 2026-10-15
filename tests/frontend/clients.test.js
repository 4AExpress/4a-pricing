// Τρέχει τις ΠΡΑΓΜΑΤΙΚΕΣ fuelState()/fuelWarn() του pricelist-clients.html
// με το ΠΡΑΓΜΑΤΙΚΟ fuel-freshness.js και τα σημερινά δεδομένα.
const fs = require('fs');
const modSrc  = fs.readFileSync('frontend/fuel-freshness.js', 'utf8');
const pageSrc = fs.readFileSync('frontend/pricelist-clients.html', 'utf8');
// ΠΑΓΩΜΕΝΟ fixture (commit 099ce2e). ΟΧΙ ανάγνωση του ζωντανού cache:
// οι προσδοκίες δεν επιτρέπεται να αλλάζουν μαζί με τα δεδομένα.
const path = require('path');
const data   = JSON.parse(fs.readFileSync(path.join(__dirname,'fuel.fixture.099ce2e.json'),'utf8'));

function grab(src, name, kw){
  const needle = (kw || 'function ') + name + '(';
  const i = src.indexOf(needle);
  if (i < 0) throw new Error('δεν βρέθηκε: ' + name);
  let d=0, st=false;
  for (let j=i;j<src.length;j++){
    if (src[j]==='{'){ d++; st=true; }
    else if (src[j]==='}'){ d--; if (st&&d===0) return src.slice(i,j+1); }
  }
  throw new Error(name);
}
const makeEl = () => ({ innerHTML:'', textContent:'', style:{}, firstChild:null,
  querySelector(){ return null; },
  insertBefore(n){ this.innerHTML += n.innerHTML; return n; } });

// ΚΑΡΦΩΜΕΝΟ «σήμερα». Το fuel-freshness.js το παίρνει ΜΟΝΟ από new Date()
// (γρ. 51), οπότε σκιάζουμε το Date μέσα στο scope του module. Χωρίς αυτό
// η προσδοκία θα άλλαζε μόνη της κάθε Δευτέρα.
const NOW = '2026-09-28';
const RealDate = Date;
function FixedDate(){ return arguments.length ? new RealDate(...arguments)
                                              : new RealDate(NOW + 'T12:00:00'); }
FixedDate.now = () => new RealDate(NOW + 'T12:00:00').getTime();
FixedDate.prototype = RealDate.prototype;

function env(opts){
  opts = opts || {};
  const warn = makeEl();
  const doc = { getElementById: id => id==='fuel-warn' ? warn : null,
                createElement: () => ({ style:{}, setAttribute(){}, innerHTML:'',
                                        appendChild(){}, querySelector(){return null;} }) };
  const win = {};
  if (opts.withModule !== false)
    new Function('window','localStorage','fetch','document','Date',modSrc)(
      win, {getItem:()=>null,setItem:()=>{}},
      () => opts.net === false ? Promise.reject(new Error('offline'))
                               : Promise.resolve({ok:true, json:()=>Promise.resolve(opts.data===undefined?data:opts.data)}),
      doc, FixedDate);

  const store = {};
  const ls = { getItem:k=>store[k]||null, setItem:(k,v)=>{store[k]=v;} };
  if (opts.legacyCache) store['4a_fuel_cache'] = JSON.stringify(opts.legacyCache);

  const fn = new Function('window','document','localStorage','fetch','FuelFreshness',
    grab(pageSrc,'fuelState','async function ') + '\n' +
    grab(pageSrc,'fuelWarn') + '\n' +
    'return {fuelState, fuelWarn};');
  const api = fn(win, doc, ls,
    () => opts.net === false ? Promise.reject(new Error('offline'))
                             : Promise.resolve({json:()=>Promise.resolve(opts.data===undefined?data:opts.data)}),
    win.FuelFreshness);
  return { api, warn, win };
}

let pass=0, fail=0;
const ck=(l,c)=>{ c?pass++:fail++; console.log(`   ${c?'OK  ':'ΛΑΘΟΣ'} ${l}`); };

(async () => {

console.log('\n═══ 1. ΔΟΜΙΚΟ: οι δύο διαδρομές PDF καλούν ΤΟΝ ΙΔΙΟ κώδικα ═══');
const block = pageSrc.match(/const _f = await fuelState\(\);/g) || [];
ck('η κοινή κλήση εμφανίζεται 2 φορές (μία ανά PDF)', block.length === 2, block.length);
ck('κανένα υπόλειμμα του παλιού αντιγραμμένου μπλοκ',
   !/fd\.air\|\|\[\]\)\.find\(x=>x\.is_current\)/.test(pageSrc));
const directReads = (pageSrc.match(/await fetch\('https:\/\/raw\.githubusercontent[^)]*fuel_surcharge_cache/g)||[]).length;
ck('μία μόνο άμεση ανάγνωση (η σιωπηλή υποχώρηση)', directReads === 1, directReads);

console.log('\n═══ 2. ΣΗΜΕΡΑ — τιμές από ημερομηνία ═══');
let e = env();
let f = await e.api.fuelState();
console.log('      air=' + f.air + ' road=' + f.road + ' week=' + f.week);
console.log('      fetched_at=' + f.fetched_at + ' week_start=' + f.week_start + ' state=' + f.state);
ck('air 46.25',  f.air === 46.25);
ck('road 38.25', f.road === 38.25);
ck('week', f.week === 'Σεπτέμβριος 28-Οκτώβριος 4, 2026');
ck('fetched_at για το snapshot', typeof f.fetched_at === 'string');
ck('week_start για το snapshot', f.week_start === '2026-09-28');
ck('week_end για το snapshot',   f.week_end === '2026-10-04');
ck('state', f.state === 'ok');
ck('data = ολόκληρο το αρχείο (για generate_pdf)', !!(f.data && f.data.air));

console.log('\n═══ 3. ΣΥΜΦΩΝΙΑ: δύο διαδοχικές κλήσεις δίνουν ΤΟ ΙΔΙΟ ═══');
const f1 = await e.api.fuelState();
const f2 = await e.api.fuelState();
ck('air ίδιο',  f1.air === f2.air);
ck('road ίδιο', f1.road === f2.road);
ck('week ίδιο', f1.week === f2.week);
console.log('      → προεπισκόπηση και τελικό PDF δεν μπορούν να διαφωνήσουν');

console.log('\n═══ 4. Η προειδοποίηση ΜΟΝΟ στην οθόνη ═══');
e = env();
f = await e.api.fuelState();
e.api.fuelWarn(f);
ck('με φρέσκα δεδομένα: καμία λωρίδα', e.warn.innerHTML === '');
// παλιό αρχείο
const old = JSON.parse(JSON.stringify(data));
old.air.forEach(r => { r.week_start='2026-01-05'; r.week_end='2026-01-11'; });
old.road.forEach(r => { r.week_start='2026-01-05'; r.week_end='2026-01-11'; });
e = env({data: old});
f = await e.api.fuelState();
e.api.fuelWarn(f);
console.log('      λωρίδα: ' + e.warn.innerHTML.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,120));
ck('με παλιό αρχείο: λωρίδα ΒΓΑΙΝΕΙ', e.warn.innerHTML.includes('περάσει'));
ck('η τιμή ΠΑΡΑΜΕΝΕΙ διαθέσιμη', typeof f.air === 'number');
ck('state=stale', f.state === 'stale');

console.log('\n═══ 5. ΤΟ PDF δεν μαθαίνει ΤΙΠΟΤΑ ═══');
// Τα πρότυπα PDF παίρνουν μόνο fuelAir/fuelRoad/fuelWeek — ποτέ res/state.
const pdfRegions = pageSrc.split('const _f = await fuelState();').slice(1)
  .map(x => x.slice(0, 9000));
let leak = false;
for (const r of pdfRegions)
  if (/\$\{\s*(_f\.state|_f\.res|res\.state|fuelWarn)/.test(r)) leak = true;
ck('καμία κατάσταση/λωρίδα μέσα σε πρότυπο PDF', !leak);
ck('το fuelWarn γράφει ΜΟΝΟ στο fuel-warn',
   /FuelFreshness\.banner\(res, 'fuel-warn'/.test(pageSrc));

console.log('\n═══ 6. Το snapshot (βήμα 5) ═══');
for (const k of ['fetched_at:_fsnap.fetched_at','week_start:_fsnap.week_start',
                 'week_end:  _fsnap.week_end','freshness: _fsnap.state'])
  ck('snapshot: ' + k.split(':')[0].trim(), pageSrc.includes(k));
ck('τα παλιά πεδία παραμένουν', /air_pct:airPct, road_pct:roadPct/.test(pageSrc));

console.log('\n═══ 7. Χωρίς module — σιωπηλή υποχώρηση ═══');
e = env({withModule:false, legacyCache:data});
f = await e.api.fuelState();
console.log('      air=' + f.air + ' road=' + f.road);
ck('βρίσκει τιμές από το παλιό cache', f.air === 46.25 && f.road === 38.25);
ck('state null (δεν ξέρει)', f.state === null);
e.api.fuelWarn(f);
ck('καμία λωρίδα χωρίς module', e.warn.innerHTML === '');

console.log('\n═══ 8. Καθόλου δεδομένα — καμία φραγή ═══');
e = env({data:null, net:false});
f = await e.api.fuelState();
e.api.fuelWarn(f);
ck('air null', f.air === null);
ck('road null', f.road === null);
ck('λωρίδα λέει «—» στην προσφορά', e.warn.innerHTML.includes('«—»'));
ck('η fuelState ΔΕΝ πετάει — η προσφορά βγαίνει', true);

// 14-10-2026: επιστροφή στις εργασίες ως chip «🔗✅ Εργασίες» (nav-chip.js),
// ΜΟΝΟ με from=tasks (λευκή λίστα).
console.log('\n═══ 9. Chip «🔗✅ Εργασίες» (from=tasks) ═══');
{
  // Το ΠΡΑΓΜΑΤΙΚΟ nav-chip.js, το ΠΡΑΓΜΑΤΙΚΟ ui-texts.js και τα κείμενα από
  // το ΙΔΙΟ seed του migration 2026-10-15b (Π1, 15/10). Το fetch του
  // ui-texts.js δεν τρέχει: τα κείμενα μπαίνουν όπως θα τα έδινε το endpoint.
  const navSrc = fs.readFileSync('frontend/nav-chip.js', 'utf8');
  const uiSrc  = fs.readFileSync('frontend/ui-texts.js', 'utf8');
  const MIG_TEXTS = {};
  for (const m of fs.readFileSync('db/migrations/2026-10-15b_ui_texts.sql', 'utf8').matchAll(/\(\s*'([a-z_]+)',\s*'(\w+)',\s*'(text|color)',\s*'([^']*)'\)/g))
    (MIG_TEXTS[m[1]] = MIG_TEXTS[m[1]] || {})[m[2]] = m[4];
  const scopesSrc = pageSrc.slice(pageSrc.indexOf('const UI_SCOPES = '), pageSrc.indexOf(';', pageSrc.indexOf('const UI_SCOPES = ')) + 1);
  const code = navSrc + '\n' + uiSrc + '\nUiTexts._merge(' + JSON.stringify({ ok: true, texts: MIG_TEXTS }) + ');\n' +
               scopesSrc + '\n' + grab(pageSrc, 'escHtml') + '\n' + grab(pageSrc, 'tasksBackHref') + '\n' +
               grab(pageSrc, 'showTasksBack') + '\nreturn { tasksBackHref, showTasksBack, UI_SCOPES, UiTexts };';
  const box = { innerHTML: '', style: { display: 'none' } };
  // Το μενού της σελίδας: link «✅ Εργασίες», με τα <span> του tasks-badge.js μετά το κείμενο.
  const tasksLink = { getAttribute: () => 'tasks.html',
                      firstChild: { nodeType: 3, nodeValue: '✅ Εργασίες', nextSibling: { nodeType: 1, nextSibling: null } } };
  let menu = [tasksLink];
  const api = new Function('document', code)({ getElementById: id => id === 'tasks-back' ? box : null,
                                                baseURI: 'https://4aexpress.github.io/4a-pricing/frontend/pricelist-clients.html',
                                                querySelectorAll: () => menu });
  const H = s => api.tasksBackHref(new URLSearchParams(s));
  ck('χωρίς from -> κανένας σύνδεσμος', H('client=7') === null && H('client=7&focus=account') === null);
  ck('from άλλο από tasks -> κανένας', H('client=7&from=evil') === null && H('client=7&from=https://x.y') === null);
  ck('from=tasks&tab=queue -> tasks.html?tab=queue#client-7', H('client=7&from=tasks&tab=queue') === 'tasks.html?tab=queue#client-7');
  ck('tab mine / all περνούν', H('client=7&from=tasks&tab=mine') === 'tasks.html?tab=mine#client-7' &&
                               H('client=7&from=tasks&tab=all') === 'tasks.html?tab=all#client-7');
  ck('tab άγνωστο ή κακόβουλο -> mine', H('client=7&from=tasks&tab=x') === 'tasks.html?tab=mine#client-7' &&
                                        H('client=7&from=tasks&tab=%22%3E%3Cimg') === 'tasks.html?tab=mine#client-7');
  ck('μη αριθμητικό client -> χωρίς #', H('client=7%22x&from=tasks&tab=mine') === 'tasks.html?tab=mine');
  await api.showTasksBack(null);
  ck('null -> το κουτί μένει κρυφό', box.style.display === 'none' && box.innerHTML === '');
  await api.showTasksBack('tasks.html?tab=queue#client-7');
  ck('chip «🔗✅ Εργασίες», εικονίδιο από το μενού, title «Πίσω στις εργασίες»', box.style.display === '' &&
     box.innerHTML === '<a class="nav-chip" href="tasks.html?tab=queue#client-7" title="Πίσω στις εργασίες">🔗✅ Εργασίες</a>', box.innerHTML);
  menu = [];
  await api.showTasksBack('tasks.html?tab=mine');
  ck('χωρίς link στο μενού: «🔗 Εργασίες», χωρίς σφάλμα', box.innerHTML.endsWith('>🔗 Εργασίες</a>'), box.innerHTML);
  ck('κείμενα από τη βάση (4a_ui_texts, scope clients + common), όχι στη showTasksBack',
     JSON.stringify(api.UI_SCOPES) === '["clients","common"]' &&
     MIG_TEXTS.common.link_icon === '🔗' && MIG_TEXTS.clients.clients_back_to_tasks === 'Εργασίες' &&
     MIG_TEXTS.clients.clients_back_to_tasks_title === 'Πίσω στις εργασίες' && !/Εργασίες|🔗|✅/.test(grab(pageSrc, 'showTasksBack').replace(/\/\/.*$/gm, '')));
  ck('το NAV_TEMP ΔΕΝ υπάρχει πια στη σελίδα', !/NAV_TEMP/.test(pageSrc));
  // Ο loader απέτυχε: [κλειδί] (απόφαση 14/10), όχι κενό chip ή σφάλμα.
  const failed = new Function('document', navSrc + '\n' + uiSrc + '\n' + scopesSrc + '\n' + grab(pageSrc, 'escHtml') + '\n' +
    grab(pageSrc, 'showTasksBack') + '\nreturn { showTasksBack };')({ getElementById: () => box, baseURI: 'https://x.invalid/', querySelectorAll: () => [] });
  box.innerHTML = '';
  await failed.showTasksBack('tasks.html?tab=mine');
  ck('χωρίς κείμενα από τη βάση -> «[link_icon] [clients_back_to_tasks]», χωρίς σφάλμα',
     box.innerHTML.includes('[clients_back_to_tasks]') && box.innerHTML.includes('[link_icon]'), box.innerHTML);
  ck('η σελίδα φορτώνει ui-texts.js και nav-chip.js?v=2026-10-14 πριν από το script της',
     pageSrc.indexOf('<script src="ui-texts.js?v=2026-10-15"></script>') > 0 &&
     pageSrc.indexOf('<script src="ui-texts.js?v=2026-10-15"></script>') < pageSrc.indexOf('<script src="nav-chip.js?v=2026-10-14"></script>') &&
     pageSrc.indexOf('<script src="nav-chip.js?v=2026-10-14"></script>') < pageSrc.indexOf('const UI_SCOPES = '));
  ck('το UI_SCOPES στην ΚΟΡΥΦΗ του script, πριν από την πρώτη εντολή του',
     pageSrc.indexOf('const UI_SCOPES = ') > 0 && pageSrc.indexOf('const UI_SCOPES = ') < pageSrc.indexOf('let _sessionExpiry = null;'));
  ck('κανένα «← Πίσω στις εργασίες» πια στη σελίδα', !pageSrc.includes('← Πίσω στις εργασίες'));
  const dl = grab(pageSrc, 'deepLink');
  ck('το deepLink το καλεί ΠΡΙΝ το replaceState και ΠΡΙΝ την αναζήτηση πελάτη',
     dl.indexOf('showTasksBack(tasksBackHref(q))') > 0 &&
     dl.indexOf('showTasksBack(tasksBackHref(q))') < dl.indexOf('history.replaceState') &&
     dl.indexOf('showTasksBack(tasksBackHref(q))') < dl.indexOf('clients.find('));
  ck('υπάρχει το #tasks-back στη σελίδα', pageSrc.includes('<div id="tasks-back" style="display:none;"></div>'));
}

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══`);
process.exit(fail?1:0);
})();
