// Τρέχει την ΠΡΑΓΜΑΤΙΚΗ renderFuelState() του pricelist-table.html
// μαζί με το ΠΡΑΓΜΑΤΙΚΟ fuel-freshness.js και τα ΠΡΑΓΜΑΤΙΚΑ δεδομένα.
const fs = require('fs');

const modSrc = fs.readFileSync('frontend/fuel-freshness.js', 'utf8');
const pageSrc = fs.readFileSync('frontend/pricelist-table.html', 'utf8');
// ΠΑΓΩΜΕΝΟ fixture (commit 099ce2e). ΟΧΙ ανάγνωση του ζωντανού cache:
// οι προσδοκίες δεν επιτρέπεται να αλλάζουν μαζί με τα δεδομένα.
const path = require('path');
const data   = JSON.parse(fs.readFileSync(path.join(__dirname,'fuel.fixture.099ce2e.json'),'utf8'));

function grab(src, name){
  const i = src.indexOf('function ' + name + '(');
  if (i < 0) throw new Error('δεν βρέθηκε: ' + name);
  let d = 0, st = false;
  for (let j = i; j < src.length; j++){
    if (src[j] === '{'){ d++; st = true; }
    else if (src[j] === '}'){ d--; if (st && d === 0) return src.slice(i, j+1); }
  }
  throw new Error(name);
}

function makeEl(id){
  return { id, textContent:'', title:'', innerHTML:'', style:{},
           children:[], firstChild:null,
           querySelector(){ return null; },
           insertBefore(n){ this.children.push(n); this.innerHTML += n.innerHTML; return n; } };
}

function run(scenario){
  const badge = makeEl('fc-badge');
  const warn  = makeEl('fuel-warn');
  const doc = { getElementById: id => id==='fc-badge'?badge:(id==='fuel-warn'?warn:null),
                querySelector: () => null,
                createElement: () => ({ style:{}, setAttribute(){}, innerHTML:'',
                                        appendChild(){}, querySelector(){return null;} }) };

  // Το module κρατά το `document` της στιγμής φόρτωσης — του δίνουμε το ΙΔΙΟ.
  const win = {};
  new Function('window','localStorage','fetch','document', modSrc)(
    win, {getItem:()=>null, setItem:()=>{}}, ()=>Promise.reject(), doc);

  const fn = new Function('document','window','FuelFreshness','SVC_MODE','FC_DEFAULTS',
    'fuelFc','fuelIsDefault','fuelRes',
    grab(pageSrc,'renderFuelState') + '\nreturn renderFuelState;');
  fn(doc, win, win.FuelFreshness, scenario.mode, {AIR:47.0, ROAD:35.25},
     scenario.fc, scenario.isDefault, scenario.res)();

  return { badge: badge.textContent, title: badge.title,
           bg: badge.style.background || '(κανένα)',
           banner: warn.innerHTML.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim() };
}

const FF = (()=>{ const w={}; new Function('window','localStorage','fetch','document',modSrc)(
  w,{getItem:()=>null,setItem:()=>{}},()=>Promise.reject(),{}); return w.FuelFreshness; })();

let pass=0, fail=0;
const ck=(l,c)=>{ c?pass++:fail++; console.log(`   ${c?'OK  ':'ΛΑΘΟΣ'} ${l}`); };

console.log('\n═══ 1. ΣΗΜΕΡΑ 28/09, πραγματικά δεδομένα → όλα εντάξει ═══');
let res = FF.check(data, '2026-09-28');
let out = run({mode:'AIR', fc:res.air, isDefault:false, res});
console.log('      badge   : ' + out.badge);
console.log('      title   : ' + out.title);
console.log('      λωρίδα  : ' + (out.banner || '(καμία)'));
ck('badge με την πραγματική τιμή', out.badge === 'FC AIR: 46.25%');
ck('ΧΩΡΙΣ «ενδεικτικό»', !out.badge.includes('ενδεικτικό'));
ck('καμία λωρίδα', out.banner === '');
ck('tooltip με την εβδομάδα', out.title.includes('Σεπτέμβριος 28-Οκτώβριος 4'));

console.log('\n═══ 2. ΠΑΛΙΑ δεδομένα (02/11, εκτός κάλυψης) → τιμή ΧΡΗΣΙΜΟΠΟΙΕΙΤΑΙ, σημαδεμένη ═══');
// Ημερομηνία ΕΚΤΟΣ της κάλυψης του fixture (14/09 - 18/10): μόνο τότε
// καμία εβδομάδα δεν περιέχει το «σήμερα» και η κατάσταση είναι stale.
res = FF.check(data, '2026-11-02');
out = run({mode:'ROAD', fc:res.road, isDefault:false, res});
console.log('      badge   : ' + out.badge);
console.log('      λωρίδα  : ' + out.banner);
ck('η τιμή ΔΕΝ κρύβεται', out.badge === 'FC ROAD: 38.25%');
ck('badge κόκκινο', out.bg === '#ffebee');
ck('λωρίδα λέει «περάσει»', out.banner.includes('περάσει'));
ck('λέει ΠΟΙΑ εβδομάδα', out.banner.includes('Σεπτέμβριος 28-Οκτώβριος 4'));
ck('ΟΧΙ «ενδεικτικές» — η τιμή είναι αληθινή, απλώς παλιά', !out.banner.includes('ενδεικτικές'));

console.log('\n═══ 3. ΚΑΘΟΛΟΥ δεδομένα → προκαθορισμένες, ρητά σημαδεμένες ═══');
res = FF.check(null, '2026-09-28');
out = run({mode:'AIR', fc:47.0, isDefault:true, res});
console.log('      badge   : ' + out.badge);
console.log('      λωρίδα  : ' + out.banner);
ck('badge λέει «(ενδεικτικό)»', out.badge === 'FC AIR: 47% (ενδεικτικό)');
ck('badge κόκκινο', out.bg === '#ffebee');
ck('λωρίδα: «ενδεικτικές»', out.banner.includes('ενδεικτικές'));
ck('λωρίδα: «ΟΧΙ ο πραγματικός επίναυλος»', out.banner.includes('ΟΧΙ ο πραγματικός επίναυλος'));
ck('αναφέρει τις ΤΙΜΕΣ', out.banner.includes('47') && out.banner.includes('35.25'));
ck('«Μην τις χρησιμοποιήσετε σε προσφορά»', out.banner.includes('Μην τις χρησιμοποιήσετε'));

console.log('\n═══ 4. Δεδομένα OK αλλά λείπει το ποσοστό του mode ═══');
out = run({mode:'AIR', fc:47.0, isDefault:true, res: FF.check(data,'2026-09-28')});
console.log('      badge   : ' + out.badge);
console.log('      λωρίδα  : ' + out.banner);
ck('η λωρίδα ΒΓΑΙΝΕΙ παρότι state=ok', out.banner.includes('ενδεικτικές'));
ck('με τον λόγο', out.banner.includes('δεν βρέθηκε ποσοστό AIR'));

console.log('\n═══ 5. Χωρίς το module → η σελίδα δεν σπάει ═══');
{
  const badge = makeEl('fc-badge'); const warn = makeEl('fuel-warn');
  const doc = { getElementById: id => id==='fc-badge'?badge:warn };
  const fn = new Function('document','window','FuelFreshness','SVC_MODE','FC_DEFAULTS',
    'fuelFc','fuelIsDefault','fuelRes',
    grab(pageSrc,'renderFuelState') + '\nreturn renderFuelState;');
  fn(doc, {}, undefined, 'AIR', {AIR:47.0,ROAD:35.25}, 47.0, true, null)();
  console.log('      badge   : ' + badge.textContent);
  ck('δεν πετάει', true);
  ck('το badge ενημερώνεται ούτως ή άλλως', badge.textContent.includes('ενδεικτικό'));
  ck('καμία λωρίδα', warn.innerHTML === '');
}

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══`);
process.exit(fail?1:0);
