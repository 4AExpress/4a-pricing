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

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══`);
process.exit(fail?1:0);
})();
