// Έλεγχος WCAG AA πάνω στο ΠΡΑΓΜΑΤΙΚΟ frontend/pricelist-clients.html.
// Τα χρώματα ΔΕΝ είναι γραμμένα εδώ — εξάγονται από το αρχείο.
const fs = require('fs');
const src = fs.readFileSync('frontend/pricelist-clients.html', 'utf8');

const hex3to6 = h => h.length === 4 ? '#' + [1,2,3].map(i => h[i] + h[i]).join('') : h;
const lum = h => { const c = [1,3,5].map(i => parseInt(h.substr(i,2),16)/255)
  .map(v => v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4));
  return .2126*c[0] + .7152*c[1] + .0722*c[2]; };
const ratio = (a,b) => { const x = lum(a), y = lum(b);
  return (Math.max(x,y)+.05) / (Math.min(x,y)+.05); };
const hue = h => { const [r,g,b] = [1,3,5].map(i => parseInt(h.substr(i,2),16)/255);
  const mx = Math.max(r,g,b), mn = Math.min(r,g,b), d = mx-mn; if (!d) return 0;
  const x = mx===r ? ((g-b)/d)%6 : mx===g ? (b-r)/d+2 : (r-g)/d+4;
  return Math.round(((x*60)+360)%360); };

// --- εξαγωγή από το αρχείο -------------------------------------------------
function cssVar(name){
  const m = src.match(new RegExp('--' + name + '\\s*:\\s*(#[0-9a-fA-F]{3,6})'));
  if (!m) throw new Error('δεν βρέθηκε η μεταβλητή --' + name);
  return hex3to6(m[1].toLowerCase());
}
function ruleColor(sel){
  const i = src.indexOf('\n  ' + sel + ' ');
  if (i < 0) throw new Error('δεν βρέθηκε ο κανόνας ' + sel);
  const body = src.slice(i, src.indexOf('}', i));
  const m = body.match(/[^-]color\s*:\s*(#[0-9a-fA-F]{3,6})/);
  if (!m) throw new Error('ο ' + sel + ' δεν ορίζει color');
  return hex3to6(m[1].toLowerCase());
}
function usesVar(sel, v){
  const i = src.indexOf('\n  ' + sel + ' ');
  if (i < 0) throw new Error('δεν βρέθηκε ο κανόνας ' + sel);
  return src.slice(i, src.indexOf('}', i)).includes('var(--' + v + ')');
}

const BG = cssVar('demo-stripe');
const AA = 4.5;   // κανονικό κείμενο· ΟΛΑ τα παρακάτω είναι 11-13px

const CHECKS = [
  ['.client-name',    'όνομα πελάτη'],
  ['.client-meta',    'ΑΦΜ / επαφή / email / ημερομηνία'],
  ['.client-pls',     'περιοχή τιμοκαταλόγων'],
  ['.pl-line',        'γραμμή τιμοκαταλόγου'],
  ['.pl-code',        'κωδικός υπηρεσίας'],
  ['.pl-unknown',     'άγνωστη υπηρεσία ⚠'],
  ['.demo-box-label', 'ετικέτα «Πελάτης επίδειξης»'],
  ['.demo-box-sub',   'υποσημείωση πλαισίου'],
];

let fail = 0, pass = 0;
console.log(`\n--demo-stripe = ${BG}  (απόχρωση ${hue(BG)}°)\n`);

console.log('ΔΟΜΗ');
for (const [sel] of [['.client-item.demo'], ['.demo-box']]) {
  const ok = usesVar(sel, 'demo-stripe');
  console.log(`   ${ok ? 'OK   ' : 'ΛΑΘΟΣ'} ${sel} χρησιμοποιεί var(--demo-stripe)`);
  ok ? pass++ : fail++;
}
const hard = (src.match(/#fafafa/g) || []).length;
console.log(`   ${hard === 0 ? 'OK   ' : 'ΠΡΟΣΟΧΗ'} υπολείμματα #fafafa στο αρχείο: ${hard}`);

console.log('\nΑΝΤΙΘΕΣΗ ΠΑΝΩ ΣΤΟ ' + BG + '  (όριο AA = 4.5:1)');
const failed = [];
for (const [sel, what] of CHECKS) {
  const fg = ruleColor(sel);
  const r = ratio(fg, BG), ok = r >= AA;
  console.log(`   ${ok ? 'OK   ' : 'ΚΟΠΗΚΕ'} ${r.toFixed(2)}:1  ${fg}  ${sel.padEnd(16)} ${what}`);
  if (ok) pass++; else { fail++; failed.push({ sel, fg, r, what, onWhite: ratio(fg, '#ffffff') }); }
}

console.log('\nΔΙΑΚΡΙΣΗ ΑΠΟ ΤΑ ΚΟΚΚΙΝΑ ΤΗΣ ΟΘΟΝΗΣ ΕΡΓΑΣΙΩΝ');
for (const [rival, what] of [['#ffebee','.btn-rej Απόρριψη'], ['#fff5f5','.task.attn'], ['#ffffff','λευκή ρίγα']]) {
  const d = Math.abs(hue(BG) - hue(rival));
  const dd = Math.min(d, 360 - d);
  const ok = rival === '#ffffff' ? ratio('#666666', BG) >= AA : dd >= 30;
  console.log(`   ${ok ? 'OK   ' : 'ΛΑΘΟΣ'} ${rival} (${hue(rival)}°) — διαφορά απόχρωσης ${dd}°  ${what}`);
  ok ? pass++ : fail++;
}

if (failed.length) {
  console.log('\n*** ΑΠΟΤΥΧΙΕΣ — ΔΕΝ ΤΙΣ ΔΙΟΡΘΩΝΩ ΧΩΡΙΣ ΕΓΚΡΙΣΗ ***');
  for (const f of failed)
    console.log(`   ${f.sel} (${f.fg}) — ${f.r.toFixed(2)}:1 στο ροζ, ${f.onWhite.toFixed(2)}:1 στο λευκό`);
}
console.log(`\nΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ\n`);
process.exit(fail ? 1 : 0);
