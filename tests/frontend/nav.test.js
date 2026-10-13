// Το μενού (<div class="nav">) είναι γραμμένο με το χέρι σε κάθε σελίδα.
// 13-10-2026: το fuel-surcharge-dashboard.html έδειχνε δύο φορές «Επίναυλος»
// και το services.html δύο φορές «Services» και «Επίναυλος». Το commit που
// πρόσθεσε τα links σε όλες τις σελίδες (1270b2b) δεν έσβησε το παλιό
// «active» link της ίδιας της σελίδας. Backlog: μενού από τη βάση (modules)
// με ένα κοινό script.
const fs = require('fs');
const path = require('path');

let pass = 0, fail = 0;
const ck = (l, c, extra) => { c ? pass++ : fail++;
  console.log(`   ${c ? 'OK  ' : 'ΛΑΘΟΣ'} ${l}${c ? '' : '   → ' + (extra === undefined ? '' : extra)}`); };

// Το μενού: από το <div class="nav"> ως το πρώτο </div>. Μέσα του υπάρχουν
// μόνο <a>, κανένα εσωτερικό <div>.
function navOf(src) {
  const i = src.indexOf('<div class="nav"');
  if (i < 0) return null;
  const j = src.indexOf('</div>', i);
  return src.slice(i, j);
}
const links = nav => [...nav.matchAll(/<a\b[^>]*>/g)].map(m => m[0]);
const hrefOf = a => (a.match(/href="([^"]*)"/) || [])[1] || '';

const pages = fs.readdirSync('frontend').filter(f => f.endsWith('.html')).sort();
console.log('\n═══ Κανένα μενού με διπλό link ═══');
let withNav = 0;
for (const f of pages) {
  const nav = navOf(fs.readFileSync(path.join('frontend', f), 'utf8'));
  if (nav === null) continue;
  withNav++;
  const as = links(nav);
  const hrefs = as.map(hrefOf);
  const dup = [...new Set(hrefs.filter((h, k) => hrefs.indexOf(h) !== k))];
  ck(`${f}: κάθε link μία φορά (${hrefs.length} links)`, dup.length === 0, dup.join(', '));
  const act = as.filter(a => /class="[^"]*\bactive\b/.test(a)).map(hrefOf);
  // Το «active» δεν είναι πάντα η ίδια η σελίδα: το roles.html φωτίζει τους
  // «Χρήστες». Γι' αυτό ελέγχεται μόνο το πλήθος.
  ck(`${f}: έως ένα «active»`, act.length <= 1, act.join(', '));
}
ck('βρέθηκαν σελίδες με μενού (ο έλεγχος δεν πέρασε «στο κενό»)', withNav >= 8, withNav);

console.log('\n═══ Οι δύο σελίδες που διορθώθηκαν ═══');
{
  const d = navOf(fs.readFileSync('frontend/fuel-surcharge-dashboard.html', 'utf8'));
  const s = navOf(fs.readFileSync('frontend/services.html', 'utf8'));
  const n = (nav, h) => links(nav).filter(a => hrefOf(a) === h).length;
  ck('dashboard: «Επίναυλος» μία φορά, active', n(d, 'fuel-surcharge-dashboard.html') === 1 &&
     /class="nav-btn active" href="fuel-surcharge-dashboard.html"/.test(d));
  ck('services: «Services» μία φορά, active', n(s, 'services.html') === 1 &&
     /class="nav-btn active" href="services.html"/.test(s));
  ck('services: «Επίναυλος» μία φορά', n(s, 'fuel-surcharge-dashboard.html') === 1);
  ck('η «Help» μένει τελευταία και στις δύο',
     hrefOf(links(d).pop()) === 'help.html' && hrefOf(links(s).pop()) === 'help.html');
}

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══\n`);
process.exit(fail ? 1 : 0);
