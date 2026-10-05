// Τρέχει τις ΠΡΑΓΜΑΤΙΚΕΣ renderFuelInfo() / getFcPct() του
// pricelist-editor.html με το ΠΡΑΓΜΑΤΙΚΟ fuel-freshness.js.
const fs = require('fs');
const modSrc  = fs.readFileSync('frontend/fuel-freshness.js', 'utf8');
const pageSrc = fs.readFileSync('frontend/pricelist-editor.html', 'utf8');
// ΠΑΓΩΜΕΝΟ fixture (commit 099ce2e). ΟΧΙ ανάγνωση του ζωντανού cache:
// οι προσδοκίες δεν επιτρέπεται να αλλάζουν μαζί με τα δεδομένα.
const path = require('path');
const data   = JSON.parse(fs.readFileSync(path.join(__dirname,'fuel.fixture.099ce2e.json'),'utf8'));

function grab(src, name){
  const i = src.indexOf('function ' + name + '(');
  if (i < 0) throw new Error('δεν βρέθηκε: ' + name);
  let d=0, st=false;
  for (let j=i;j<src.length;j++){
    if (src[j]==='{'){ d++; st=true; }
    else if (src[j]==='}'){ d--; if (st&&d===0) return src.slice(i,j+1); }
  }
  throw new Error(name);
}
const makeEl = () => ({ textContent:'', innerHTML:'', style:{}, firstChild:null,
  querySelector(){ return null; },
  insertBefore(n){ this.innerHTML += n.innerHTML; return n; } });

function run(sc){
  const info = makeEl(), warn = makeEl();
  const doc = { getElementById: id => id==='fc-info'?info:(id==='fuel-warn'?warn:null),
                createElement: () => ({ style:{}, setAttribute(){}, innerHTML:'',
                                        appendChild(){}, querySelector(){return null;} }) };
  const win = {};
  if (sc.withModule !== false)
    new Function('window','localStorage','fetch','document',modSrc)(
      win,{getItem:()=>null,setItem:()=>{}},()=>Promise.reject(),doc);

  const fn = new Function('document','window','FuelFreshness',
    'fuelRes','fuelAir','fuelRoad','fuelData',
    grab(pageSrc,'renderFuelInfo') + '\n' + grab(pageSrc,'getFcPct') +
    '\nreturn {renderFuelInfo, getFcPct};');
  const api = fn(doc, win, win.FuelFreshness, sc.res, sc.air, sc.road, sc.data || null);
  api.renderFuelInfo();
  return { info: info.innerHTML || info.textContent,
           bg: info.style.background || '(κανένα)',
           warn: warn.innerHTML.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim(),
           fc: m => api.getFcPct(m) };
}
const FF = (()=>{ const w={}; new Function('window','localStorage','fetch','document',modSrc)(
  w,{getItem:()=>null,setItem:()=>{}},()=>Promise.reject(),{}); return w.FuelFreshness; })();

let pass=0, fail=0;
const ck=(l,c)=>{ c?pass++:fail++; console.log(`   ${c?'OK  ':'ΛΑΘΟΣ'} ${l}`); };

console.log('\n═══ 1. ΣΗΜΕΡΑ 28/09 — όλα εντάξει ═══');
let res = FF.check(data,'2026-09-28');
let o = run({res, air:res.air, road:res.road, data});
console.log('      γραμμή : ' + o.info.replace(/<[^>]+>/g,''));
console.log('      λωρίδα : ' + (o.warn||'(καμία)'));
ck('λέει «τρέχουσας εβδομάδας»', o.info.includes('τρέχουσας εβδομάδας'));
ck('AIR 46.25 / ROAD 38.25', o.info.includes('46.25') && o.info.includes('38.25'));
ck('δείχνει την εβδομάδα', o.info.includes('Σεπτέμβριος 28-Οκτώβριος 4'));
ck('χωρίς κόκκινο', o.bg === '(κανένα)');
ck('καμία λωρίδα', o.warn === '');
ck('getFcPct AIR  = 46.25', o.fc({mode:'AIR'}) === 46.25);
ck('getFcPct ROAD = 38.25', o.fc({mode:'ROAD'}) === 38.25);
ck('getFcPct COMBI = ROAD', o.fc({combi:true, mode:'AIR'}) === 38.25);

console.log('\n═══ 2. ΠΑΛΙΑ δεδομένα (02/11, εκτός κάλυψης) — τιμή χρησιμοποιείται, σημαδεμένη ═══');
// Ημερομηνία ΕΚΤΟΣ της κάλυψης του fixture (14/09 - 18/10): μόνο τότε
// καμία εβδομάδα δεν περιέχει το «σήμερα» και η κατάσταση είναι stale.
res = FF.check(data,'2026-11-02');
o = run({res, air:res.air, road:res.road, data});
console.log('      γραμμή : ' + o.info.replace(/<[^>]+>/g,''));
console.log('      λωρίδα : ' + o.warn);
ck('ΔΕΝ λέει «τρέχουσας εβδομάδας»', !o.info.includes('τρέχουσας εβδομάδας'));
ck('η γραμμή κοκκινίζει', o.bg === '#fff4f4');
ck('οι τιμές παραμένουν', o.info.includes('46.25'));
ck('λωρίδα: «περάσει»', o.warn.includes('περάσει'));
ck('getFcPct δίνει την τιμή, όχι 0', o.fc({mode:'AIR'}) === 46.25);

console.log('\n═══ 3. ΚΑΘΟΛΟΥ δεδομένα — 0% και το λέει ═══');
res = FF.check(null,'2026-09-28');
o = run({res, air:null, road:null, data:null});
console.log('      γραμμή : ' + o.info);
console.log('      λωρίδα : ' + o.warn);
ck('γραμμή: «μη διαθέσιμος»', o.info.includes('μη διαθέσιμος'));
ck('γραμμή: λέει ΤΟ 0%', o.info.includes('0%'));
ck('κόκκινη', o.bg === '#fff4f4');
ck('λωρίδα: «0% επίναυλο»', o.warn.includes('0% επίναυλο'));
ck('λωρίδα: «φθηνότερα από την πραγματικότητα»', o.warn.includes('φθηνότερα'));
ck('ΟΧΙ υπόσχεση για παύλες', !o.warn.includes('εμφανίζονται ως «—»'));
ck('getFcPct = 0 (όπως πριν)', o.fc({mode:'AIR'}) === 0);
ck('getFcPct COMBI = 0', o.fc({combi:true}) === 0);

console.log('\n═══ 4. Δεδομένα OK αλλά χωρίς ποσοστά ═══');
o = run({res: FF.check(data,'2026-09-28'), air:null, road:null, data});
ck('η λωρίδα ΒΓΑΙΝΕΙ', o.warn.includes('0% επίναυλο'));
ck('με τον λόγο', o.warn.includes('δεν βρέθηκαν ποσοστά'));

console.log('\n═══ 5. Χωρίς το module — δεν σπάει ═══');
o = run({withModule:false, res:null, air:46.25, road:38.25, data:{fetched_at:'2026-09-28T06:31'}});
console.log('      γραμμή : ' + o.info.replace(/<[^>]+>/g,''));
ck('δεν πετάει', true);
ck('η γραμμή γράφεται', o.info.includes('46.25'));
ck('καμία λωρίδα', o.warn === '');
ck('getFcPct δουλεύει', o.fc({mode:'ROAD'}) === 38.25);

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══`);
process.exit(fail?1:0);
