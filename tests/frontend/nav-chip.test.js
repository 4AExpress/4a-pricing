// tests/frontend/nav-chip.test.js
//
// Το ΠΡΑΓΜΑΤΙΚΟ frontend/nav-chip.js (14-10-2026): chip «🔗 + εικονίδιο
// της σελίδας-προορισμού + λέξη». Το εικονίδιο από το μενού:
//   · αντιστοίχιση με το ΟΝΟΜΑ ΑΡΧΕΙΟΥ του href (όρος 2)
//   · δεκτό ΜΟΝΟ αν περιέχει emoji, Extended_Pictographic (όρος 1)
//   · αλλιώς «🔗 + λέξη», χωρίς σφάλμα
const fs = require('fs');
const NavChip = new Function(fs.readFileSync('frontend/nav-chip.js', 'utf8') + '\nreturn NavChip;')();

let pass = 0, fail = 0;
function ck(label, ok, extra) {
  ok ? pass++ : fail++;
  console.log(`   ${ok ? 'OK   ' : 'ΛΑΘΟΣ'} ${label}${ok ? '' : '   → ' + (extra === undefined ? '' : extra)}`);
}

// Ψεύτικο link μενού: κόμβοι όπως στον browser. lead = στοιχείο ΠΡΙΝ από το
// κείμενο· μετά από αυτό πάντα ένα <span> (όπως τα βάζει το tasks-badge.js).
const link = (href, text, lead) => {
  const t = { nodeType: 3, nodeValue: text, nextSibling: { nodeType: 1, nodeValue: null, nextSibling: null } };
  return { getAttribute: () => href, firstChild: lead ? { nodeType: 1, nextSibling: t } : t };
};
const BASE = 'https://4aexpress.github.io/4a-pricing/frontend/pricelist-clients.html';
const doc = links => ({ baseURI: BASE, querySelectorAll: () => links });
const MENU = [link('pricelist-editor.html', '+ Νέος Τιμοκατάλογος'), link('pricelist-clients.html', '👥 Πελάτες'),
              link('tasks.html', '✅ Εργασίες'), link('users.html', '⚙️ Χρήστες')];

console.log('\n═══ Όρος 2: αντιστοίχιση με το ΟΝΟΜΑ ΑΡΧΕΙΟΥ του href ═══');
{
  const F = h => NavChip.fileOf(h, BASE);
  ck('tasks.html -> tasks.html', F('tasks.html') === 'tasks.html');
  ck('./tasks.html -> tasks.html', F('./tasks.html') === 'tasks.html');
  ck('tasks.html?x=1 -> tasks.html', F('tasks.html?x=1') === 'tasks.html');
  ck('tasks.html#y -> tasks.html', F('tasks.html#y') === 'tasks.html');
  ck('απόλυτο URL -> tasks.html', F('https://4aexpress.github.io/4a-pricing/frontend/tasks.html?tab=mine') === 'tasks.html');
  ck('my-tasks.html ΔΕΝ είναι tasks.html', F('my-tasks.html') !== 'tasks.html');
  ck('./tasks.html στο μενού -> ✅', NavChip.navIcon(doc([link('./tasks.html', '✅ Εργασίες')]), 'tasks.html') === '✅');
  ck('tasks.html?x=1 στο μενού -> ✅', NavChip.navIcon(doc([link('tasks.html?x=1', '✅ Εργασίες')]), 'tasks.html') === '✅');
  ck('pricelist-clients.html -> 👥', NavChip.navIcon(doc(MENU), 'pricelist-clients.html') === '👥');
}

console.log('\n═══ Όρος 1: εικονίδιο ΜΟΝΟ αν περιέχει emoji (Extended_Pictographic) ═══');
{
  ck('«+ Νέος Τιμοκατάλογος»: το «+» ΔΕΝ είναι εικονίδιο -> ""', NavChip.navIcon(doc(MENU), 'pricelist-editor.html') === '');
  ck('«⚙️ Χρήστες» (με VS16) -> ⚙️', NavChip.navIcon(doc(MENU), 'users.html') === '⚙️');
  ck('«- Κάτι» -> ""', NavChip.navIcon(doc([link('x.html', '- Κάτι')]), 'x.html') === '');
  ck('«Χωρίς εικονίδιο» -> ""', NavChip.navIcon(doc([link('x.html', 'Χωρίς εικονίδιο')]), 'x.html') === '');
  ck('«123 x» -> ""', NavChip.navIcon(doc([link('x.html', '123 x')]), 'x.html') === '');
  ck('πρώτος κόμβος κειμένου μετά από στοιχείο -> ✅', NavChip.navIcon(doc([link('tasks.html', '✅ Εργασίες', true)]), 'tasks.html') === '✅');
  const h = NavChip.html({ doc: doc([link('a.html', '+ Α')]), target: 'a.html', href: 'a.html', link: '🔗', word: 'Α', title: 't' });
  ck('chip με «+» στο μενού -> «🔗 Α» (fallback)', h.endsWith('>🔗 Α</a>'), h);
}

console.log('\n═══ Fallback: χωρίς μενού / χωρίς link / σφάλμα -> «🔗 + λέξη» ═══');
{
  const html = d => NavChip.html({ doc: d, target: 'tasks.html', href: 'tasks.html?tab=mine', link: '🔗', word: 'Εργασίες', title: 'Πίσω στις εργασίες' });
  ck('μενού χωρίς το link', html(doc([link('users.html', '⚙️ Χρήστες')])).endsWith('>🔗 Εργασίες</a>'));
  ck('άδειο μενού', html(doc([])).endsWith('>🔗 Εργασίες</a>'));
  ck('doc χωρίς querySelectorAll', html({}).endsWith('>🔗 Εργασίες</a>'));
  ck('doc = undefined', html(undefined).endsWith('>🔗 Εργασίες</a>'));
  ck('querySelectorAll πετάει', html({ querySelectorAll: () => { throw new Error('x'); } }).endsWith('>🔗 Εργασίες</a>'));
  ck('με μενού: «🔗✅ Εργασίες»', html(doc(MENU)) ===
     '<a class="nav-chip" href="tasks.html?tab=mine" title="Πίσω στις εργασίες">🔗✅ Εργασίες</a>', html(doc(MENU)));
}

console.log('\n═══ Escaping ═══');
{
  const h = NavChip.html({ doc: doc([]), target: 't.html', href: 'a.html?x="><img src=x>', link: '🔗', word: '<b>Λ&Ξ</b>', title: '"t\'' });
  ck('href escaped', h.includes('href="a.html?x=&quot;&gt;&lt;img src=x&gt;"'), h);
  ck('title escaped', h.includes('title="&quot;t&#39;"'), h);
  ck('λέξη escaped', h.includes('>🔗 &lt;b&gt;Λ&amp;Ξ&lt;/b&gt;</a>'), h);
  const evil = NavChip.html({ doc: doc([link('t.html', '✅<img> x')]), target: 't.html', href: 't.html', link: '🔗', word: 'W', title: 't' });
  ck('εικονίδιο από το μενού escaped', !evil.includes('<img>') && evil.includes('✅&lt;img&gt;'), evil);
}

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══`);
process.exit(fail ? 1 : 0);
