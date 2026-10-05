// Τρέχει τις ΠΡΑΓΜΑΤΙΚΕΣ codForService / codRuleText / plLineHtml /
// renderSvcWarn του pricelist-clients.html.
const fs = require('fs');
const src = fs.readFileSync('frontend/pricelist-clients.html', 'utf8');

function grab(name){
  const i = src.indexOf('function ' + name + '(');
  if (i < 0) throw new Error('δεν βρέθηκε: ' + name);
  let d=0, st=false;
  for (let j=i;j<src.length;j++){
    if (src[j]==='{'){ d++; st=true; }
    else if (src[j]==='}'){ d--; if (st&&d===0) return src.slice(i,j+1); }
  }
  throw new Error(name);
}

const escHtml = s => String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const SVC_NAMES = { S1010:'🇬🇷 🚛 ↗️ 🇪🇺', S1003:'🇬🇷 ✈️ ↗️ 🌍' };

// Το πραγματικό μητρώο, όπως το επιστρέφει το services.php (has_* ως integer)
const REGISTRY = [
  {code:'S1010', has_cod:0, has_fuel:1},
  {code:'S1039', has_cod:1, has_fuel:0},
  {code:'S1003', has_cod:0, has_fuel:1},
  {code:'S1003_GR', has_cod:1, has_fuel:0},
];

function build(servicesCache){
  const fn = new Function('servicesCache','SVC_NAMES','escHtml','document',
    grab('codForService') + '\n' + grab('codRuleText') + '\n' +
    grab('plLineHtml')    + '\n' + grab('renderSvcWarn') + '\n' +
    'return {codForService, codRuleText, plLineHtml, renderSvcWarn};');
  const warnEl = { innerHTML:'' };
  const doc = { getElementById: id => id==='svc-warn' ? warnEl : null };
  return { api: fn(servicesCache, SVC_NAMES, escHtml, doc), warnEl };
}

const COD = { cod_enabled:true, cod_fee:2, snapshot:{flat_fee:2, tier_limit:500, tier_pct:0.60} };
const txt = h => h.replace(/<[^>]+>/g,'').replace(/\s+/g,' ').trim();

let pass=0, fail=0;
const ck=(l,c,extra)=>{ c?pass++:fail++; console.log(`   ${c?'OK  ':'ΛΑΘΟΣ'} ${l}${c?'':'   '+(extra||'')}`); };

const { api } = build(REGISTRY);

console.log('\n═══ 1. Πελάτης με S1010 + S1039 (το παράδειγμά σου) ═══');
const c1 = { name:'ΑΛΦΑ', cod:COD,
  pricelists:[ {service_id:'S1010', markup:140, fuel_enabled:1},
               {service_id:'S1039', markup:21.5, fuel_enabled:0} ] };
let l1 = api.plLineHtml(c1, c1.pricelists[0]);
let l2 = api.plLineHtml(c1, c1.pricelists[1]);
console.log('      ' + txt(l1));
console.log('      ' + txt(l2));
ck('S1010: emoji + ΚΩΔΙΚΟΣ', l1.includes('🇬🇷 🚛 ↗️ 🇪🇺') && l1.includes('>S1010<'));
ck('S1010: markup 140.00%', txt(l1).includes('140.00%'));
ck('S1010: ⛽ ΕΝΤΟΝΟ (fuel_enabled=1)', /pl-ico" title="Επίναυλος καυσίμων: ενεργός[^"]*">⛽/.test(l1));
ck('S1010: ΚΑΝΕΝΑ 💳 (has_cod=0)', !l1.includes('💳'));
ck('S1039: ΚΩΔΙΚΟΣ χωρίς emoji', l2.includes('>S1039<') && !l2.includes('pl-emoji'));
ck('S1039: 21.50%', txt(l2).includes('21.50%'));
ck('S1039: 💳 με κανόνα', l2.includes('💳') && txt(l2).includes('COD €2.00 έως €500 · 0.60% άνω'));
ck('S1039: ΚΑΝΕΝΑ ⛽ (has_fuel=0)', !l2.includes('⛽'));

console.log('\n═══ 2. Πελάτης ΜΟΝΟ με S1039 ═══');
const c2 = { name:'ΒΗΤΑ', cod:COD, pricelists:[{service_id:'S1039', markup:30}] };
let l = api.plLineHtml(c2, c2.pricelists[0]);
console.log('      ' + txt(l));
ck('κωδικός + markup + COD', txt(l).includes('S1039') && txt(l).includes('30.00%') && txt(l).includes('COD €2.00'));

console.log('\n═══ 3. Πελάτης ΧΩΡΙΣ COD (cod=null) — υπηρεσία ΜΕ has_cod=1 ═══');
const c3 = { name:'ΓΑΜΑ', cod:null, pricelists:[{service_id:'S1039', markup:15}] };
l = api.plLineHtml(c3, c3.pricelists[0]);
console.log('      ' + txt(l));
ck('codForService → null', api.codForService(c3,'S1039') === null);
ck('💳 ΞΕΘΩΡΙΑΣΜΕΝΟ', /pl-ico off" title="Αντικαταβολή: ανενεργή[^"]*">💳/.test(l));
ck('ΧΩΡΙΣ κανόνα', !txt(l).includes('COD €'));
ck('ο κωδικός και το markup παραμένουν', txt(l).includes('S1039') && txt(l).includes('15.00%'));

console.log('\n═══ 4. cod_enabled=false — ίδια συμπεριφορά με null ═══');
const c4 = { name:'ΔΕΛΤΑ', cod:{cod_enabled:false}, pricelists:[{service_id:'S1039', markup:10}] };
ck('codForService → null', api.codForService(c4,'S1039') === null);
ck('💳 ξεθωριασμένο', api.plLineHtml(c4,c4.pricelists[0]).includes('pl-ico off'));

console.log('\n═══ 5. ⛽ ξεθωριασμένο: has_fuel=1 αλλά fuel_enabled=0 ═══');
const c5 = { name:'ΕΨΙΛΟΝ', cod:null, pricelists:[{service_id:'S1010', markup:100, fuel_enabled:0}] };
l = api.plLineHtml(c5, c5.pricelists[0]);
console.log('      ' + txt(l));
ck('⛽ με κλάση off', /pl-ico off" title="Επίναυλος καυσίμων: ανενεργός[^"]*">⛽/.test(l));
ck('το εικονίδιο ΥΠΑΡΧΕΙ (δεν κρύβεται)', l.includes('⛽'));

console.log('\n═══ 6. Άγνωστη υπηρεσία → ΟΡΑΤΗ ένδειξη, όχι σιωπή ═══');
const c6 = { name:'ΖΗΤΑ', cod:COD, pricelists:[{service_id:'S9999', markup:55}] };
l = api.plLineHtml(c6, c6.pricelists[0]);
console.log('      ' + txt(l));
ck('ο κωδικός φαίνεται', l.includes('>S9999<'));
ck('ένδειξη ⚠ ;', l.includes('⚠ ;'));
ck('με επεξήγηση', l.includes('Δεν βρέθηκε στο μητρώο υπηρεσιών'));
ck('το markup παραμένει', txt(l).includes('55.00%'));
ck('κανένα ⛽/💳 (άγνωστες δυνατότητες)', !l.includes('⛽') && !l.includes('💳'));

console.log('\n═══ 7. ΑΠΟΤΥΧΙΑ loadServices (servicesCache=null) ═══');
{
  const { api:a2, warnEl } = build(null);
  const l7 = a2.plLineHtml(c1, c1.pricelists[0]);
  console.log('      γραμμή: ' + txt(l7));
  ck('ο κωδικός φαίνεται κανονικά', l7.includes('>S1010<'));
  ck('το markup φαίνεται', txt(l7).includes('140.00%'));
  ck('ΚΑΜΙΑ ένδειξη ⚠ (φταίει το δίκτυο, όχι η υπηρεσία)', !l7.includes('⚠'));
  ck('κανένα ⛽/💳', !l7.includes('⛽') && !l7.includes('💳'));
  a2.renderSvcWarn();
  console.log('      γραμμή σφάλματος: ' + txt(warnEl.innerHTML));
  ck('ΟΡΑΤΗ γραμμή πάνω από τη λίστα', warnEl.innerHTML.includes('Δεν φορτώθηκαν οι υπηρεσίες'));
  ck('λέει τι λείπει', warnEl.innerHTML.includes('⛽/💳'));
  ck('και τι ΔΕΝ επηρεάζεται', warnEl.innerHTML.includes('ποσοστά είναι σωστά'));
}
{
  const { api:a3, warnEl } = build(REGISTRY);
  a3.renderSvcWarn();
  ck('με φορτωμένο μητρώο: καμία γραμμή', warnEl.innerHTML === '');
}

console.log('\n═══ 8. Άμυνα ═══');
ck('χωρίς markup: δεν γράφει «NaN%»', !txt(api.plLineHtml(c3,{service_id:'S1039'})).includes('NaN'));
ck('global_markup προηγείται', txt(api.plLineHtml(c3,{service_id:'S1039',global_markup:77,markup:11})).includes('77.00%'));
ck('escHtml στον κωδικό', api.plLineHtml(c3,{service_id:'<img>'}).includes('&lt;img&gt;'));

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══`);
process.exit(fail?1:0);
