// tests/frontend/ui-texts.test.js
//
// Το ΠΡΑΓΜΑΤΙΚΟ frontend/ui-texts.js (Π1, 15-10-2026) και το ΠΡΑΓΜΑΤΙΚΟ
// frontend/tasks-badge.js με ψεύτικο fetch:
//   · κείμενα από το api/ui_texts.php, {x} -> τιμή, κλειδί που λείπει -> [κλειδί]
//   · σιωπηλή αποτυχία (δίκτυο, 401, άκυρη απάντηση, χωρίς συνεδρία)
//   · αντίγραφο στο localStorage (v1.1): server OK -> γράφεται· server πέφτει
//     με αντίγραφο -> κείμενα από αυτό· χωρίς αντίγραφο -> [κλειδί]
//   · ένα αίτημα ανά scope, όσες φορές κι αν ζητηθεί
//   · σήμα: tooltip παράκαμψης από τη βάση, αλλιώς ΜΟΝΟ αριθμός (απόφαση 14/10)
const fs = require('fs');
const UI_SRC    = fs.readFileSync('frontend/ui-texts.js', 'utf8');
const BADGE_SRC = fs.readFileSync('frontend/tasks-badge.js', 'utf8');

let pass = 0, fail = 0;
function ck(label, ok, extra) {
  ok ? pass++ : fail++;
  console.log(`   ${ok ? 'OK   ' : 'ΛΑΘΟΣ'} ${label}${ok ? '' : '   → ' + (extra === undefined ? '' : extra)}`);
}

const SESSION = { getItem: k => k === '4a_current_user' ? JSON.stringify({ _token: 'T' }) : null };
const NO_SESSION = { getItem: () => null };
const TEXTS = { ok: true, texts: { common: { link_icon: '🔗', badge_override_tip: '{n} παρακάμψεις διαχειριστή περιμένουν «Το είδα»' },
                                   tasks: { fuel_older: 'Παλαιότερες εβδομάδες ({n})' } } };

// Ψεύτικο fetch: καταγράφει κάθε αίτημα, απαντά με το reply(url).
function fakeFetch(reply) {
  const calls = [];
  const f = (url, opts) => { calls.push({ url, opts });
    return Promise.resolve(reply(url)).then(r => r instanceof Error ? Promise.reject(r) : r); };
  f.calls = calls;
  return f;
}
const json = (status, body) => ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
// Ψεύτικο localStorage· broken = πετάει σε κάθε κλήση (private mode).
function fakeLS(init, broken) {
  const m = Object.assign({}, init || {});
  const no = () => { throw new Error('SecurityError'); };
  return broken ? { getItem: no, setItem: no, m } :
    { getItem: k => (k in m ? m[k] : null), setItem: (k, v) => { m[k] = String(v); }, m };
}
const load = (fetch, session, ls) => new Function('fetch', 'sessionStorage', 'localStorage', UI_SRC + '\nreturn UiTexts;')(
  fetch, session || SESSION, ls === undefined ? fakeLS() : ls);

(async () => {

console.log('\n═══ UiTexts: φόρτωση και κείμενα ═══');
{
  const f = fakeFetch(url => json(200, url.includes('scope=tasks,common') ? TEXTS : { ok: true, texts: {} }));
  const U = load(f);
  ck('πριν τη φόρτωση: [κλειδί]', U.t('fuel_older') === '[fuel_older]' && !U.has('fuel_older'));
  const ok = await U.load(['tasks', 'common']);
  ck('load -> true', ok === true);
  ck('ΕΝΑ αίτημα στο ui_texts.php?scope=tasks,common με Bearer', f.calls.length === 1 &&
     /^https:\/\/4aexpress\.com\/api\/ui_texts\.php\?scope=tasks,common&v=\d+$/.test(f.calls[0].url) &&
     f.calls[0].opts.headers.Authorization === 'Bearer T', JSON.stringify(f.calls));
  ck('{n} -> τιμή', U.t('fuel_older', { n: 3 }) === 'Παλαιότερες εβδομάδες (3)');
  ck('{n} χωρίς τιμή -> μένει {n}', U.t('fuel_older') === 'Παλαιότερες εβδομάδες ({n})');
  ck('null ως τιμή -> μένει {n}', U.t('fuel_older', { n: null }) === 'Παλαιότερες εβδομάδες ({n})');
  ck('κλειδί που λείπει -> [κλειδί]', U.t('δεν_υπάρχει') === '[δεν_υπάρχει]' && !U.has('δεν_υπάρχει'));
  ck('has() για ό,τι ήρθε', U.has('link_icon') && U.has('badge_override_tip'));
  await U.load(['tasks', 'common']);
  await U.load(['common']);
  ck('ήδη φορτωμένα scope -> κανένα νέο αίτημα', f.calls.length === 1, f.calls.length);
  await U.load(['common', 'clients']);
  ck('μόνο το νέο scope ζητιέται (clients)', f.calls.length === 2 && /scope=clients&/.test(f.calls[1].url), f.calls.map(c => c.url).join(' | '));
}

console.log('\n═══ UiTexts: ένα αίτημα για ταυτόχρονες κλήσεις ═══');
{
  let release;
  const f = fakeFetch(() => new Promise(r => { release = () => r(json(200, TEXTS)); }));
  const U = load(f);
  const a = U.load(['common']), b = U.load(['common']);
  release();
  ck('δύο ταυτόχρονα load -> ΕΝΑ αίτημα, και τα δύο true', (await a) === true && (await b) === true && f.calls.length === 1);
}

console.log('\n═══ UiTexts: σιωπηλή αποτυχία -> false και [κλειδί] ═══');
for (const [label, reply] of [
  ['δίκτυο (reject)', () => new Error('net')],
  ['401', () => json(401, { error: 'Unauthorized' })],
  ['ok: false', () => json(200, { ok: false })],
  ['χωρίς texts', () => json(200, { ok: true })],
  ['μη JSON', () => ({ ok: true, status: 200, json: () => Promise.reject(new Error('x')) })],
]) {
  const f = fakeFetch(reply);
  const U = load(f);
  let threw = false, r;
  try { r = await U.load(['common']); } catch (e) { threw = true; }
  ck(`${label}: false, χωρίς εξαίρεση, [κλειδί]`, !threw && r === false && U.t('link_icon') === '[link_icon]');
}
{
  const f = fakeFetch(() => json(200, TEXTS));
  const U = load(f, NO_SESSION);
  ck('χωρίς συνεδρία: ΚΑΝΕΝΑ αίτημα, false', (await U.load(['common'])) === false && f.calls.length === 0);
}
{
  let n = 0;
  const f = fakeFetch(() => (++n === 1 ? new Error('net') : json(200, TEXTS)));
  const U = load(f);
  await U.load(['common']);
  ck('μετά από αποτυχία, νέο load ξαναδοκιμάζει', (await U.load(['common'])) === true && f.calls.length === 2 && U.has('link_icon'));
}
{
  const f = fakeFetch(() => json(200, TEXTS));
  const U = load(f);
  await U.load(['common', '../x', 'A']);
  ck('άκυρο scope δεν φτάνει στο αίτημα', f.calls.length === 1 && /scope=common&/.test(f.calls[0].url), f.calls[0] && f.calls[0].url);
}

console.log('\n═══ UiTexts: αντίγραφο στο localStorage ═══');
const COPY = (texts, v) => JSON.stringify({ v: v === undefined ? 1 : v, saved_at: '2026-10-15T10:00:00.000Z', texts });
{ // server OK -> αντίγραφο ανά scope, με έκδοση και χρόνο
  const ls = fakeLS();
  const U = load(fakeFetch(() => json(200, TEXTS)), SESSION, ls);
  const ok = await U.load(['tasks', 'common']);
  const c = JSON.parse(ls.m['4a_ui_texts:common'] || 'null'), t = JSON.parse(ls.m['4a_ui_texts:tasks'] || 'null');
  ck('server OK: true, πηγή server', ok === true && U.source('common') === 'server' && U.source('tasks') === 'server');
  ck('server OK: αντίγραφο ανά scope {v:1, saved_at ISO, texts}', c && c.v === 1 && /^\d{4}-\d\d-\d\dT/.test(c.saved_at) &&
     JSON.stringify(c.texts) === JSON.stringify(TEXTS.texts.common) && t && t.texts.fuel_older === 'Παλαιότερες εβδομάδες ({n})', JSON.stringify(ls.m));
}
{ // server OK αντικαθιστά το παλιό αντίγραφο ολόκληρο (κλειδί που σβήστηκε φεύγει)
  const ls = fakeLS({ '4a_ui_texts:common': COPY({ old_key: 'παλιό', link_icon: 'Χ' }) });
  const U = load(fakeFetch(() => json(200, TEXTS)), SESSION, ls);
  await U.load(['common']);
  const c = JSON.parse(ls.m['4a_ui_texts:common']);
  ck('server OK: το νέο αντίγραφο αντικαθιστά το παλιό, ΟΧΙ συγχώνευση', !('old_key' in c.texts) && c.texts.link_icon === '🔗');
  ck('server OK: το παλιό αντίγραφο ΔΕΝ διαβάζεται', !U.has('old_key') && U.t('link_icon') === '🔗');
}
for (const [label, reply, session] of [
  ['δίκτυο', () => new Error('net')],
  ['401', () => json(401, { error: 'Unauthorized' })],
  ['500', () => json(500, { ok: false })],
  ['ok: false', () => json(200, { ok: false })],
  ['χωρίς συνεδρία', () => json(200, TEXTS), NO_SESSION],
]) { // server πέφτει ΜΕ αντίγραφο
  const ls = fakeLS({ '4a_ui_texts:common': COPY(TEXTS.texts.common) });
  const before = ls.m['4a_ui_texts:common'];
  const U = load(fakeFetch(reply), session, ls);
  const ok = await U.load(['common']);
  ck(`server πέφτει (${label}) ΜΕ αντίγραφο: true, κείμενα από αντίγραφο, όχι [κλειδί]`,
     ok === true && U.source('common') === 'copy' &&
     U.t('badge_override_tip', { n: 2 }) === '2 παρακάμψεις διαχειριστή περιμένουν «Το είδα»' &&
     U.has('link_icon') && ls.m['4a_ui_texts:common'] === before, U.t('link_icon'));
}
for (const [label, ls] of [['κενό localStorage', fakeLS()], ['localStorage πετάει', fakeLS(null, true)],
                           ['localStorage ανύπαρκτο', null],
                           ['αντίγραφο άλλης έκδοσης', fakeLS({ '4a_ui_texts:common': COPY(TEXTS.texts.common, 2) })],
                           ['αντίγραφο χαλασμένο', fakeLS({ '4a_ui_texts:common': '{χαλασμένο' })],
                           ['αντίγραφο άλλου scope', fakeLS({ '4a_ui_texts:tasks': COPY(TEXTS.texts.tasks) })]]) {
  // server πέφτει ΧΩΡΙΣ αντίγραφο
  const U = load(fakeFetch(() => new Error('net')), SESSION, ls);
  let threw = false, ok;
  try { ok = await U.load(['common']); } catch (e) { threw = true; }
  ck(`server πέφτει ΧΩΡΙΣ αντίγραφο (${label}): false, [κλειδί], χωρίς εξαίρεση`,
     !threw && ok === false && U.source('common') === null && U.t('link_icon') === '[link_icon]');
}
{ // μικτό: ένα scope με αντίγραφο, ένα χωρίς
  const U = load(fakeFetch(() => new Error('net')), SESSION, fakeLS({ '4a_ui_texts:common': COPY(TEXTS.texts.common) }));
  const ok = await U.load(['tasks', 'common']);
  ck('μικτό: false, common από αντίγραφο, tasks [κλειδί]', ok === false && U.t('link_icon') === '🔗' && U.t('fuel_older') === '[fuel_older]');
}
{ // αντίγραφο -> το επόμενο load ξαναρωτά τον server και τον προτιμά
  let n = 0;
  const ls = fakeLS({ '4a_ui_texts:common': COPY({ link_icon: 'ΠΑΛΙΟ' }) });
  const f = fakeFetch(() => (++n === 1 ? new Error('net') : json(200, TEXTS)));
  const U = load(f, SESSION, ls);
  await U.load(['common']);
  ck('1ο load: από αντίγραφο', U.t('link_icon') === 'ΠΑΛΙΟ' && U.source('common') === 'copy');
  const ok = await U.load(['common']);
  ck('2ο load: ξαναρωτά τον server, παίρνει τα νέα, ανανεώνει το αντίγραφο',
     ok === true && f.calls.length === 2 && U.t('link_icon') === '🔗' && U.source('common') === 'server' &&
     JSON.parse(ls.m['4a_ui_texts:common']).texts.link_icon === '🔗');
  await U.load(['common']);
  ck('3ο load: ήδη από τη βάση -> κανένα νέο αίτημα', f.calls.length === 2);
}
{ // _merge (τεστ σελίδων) δεν γράφει αντίγραφο
  const ls = fakeLS();
  const U = load(fakeFetch(() => json(200, TEXTS)), SESSION, ls);
  U._merge(TEXTS);
  ck('_merge: κείμενα χωρίς εγγραφή αντιγράφου', U.has('link_icon') && Object.keys(ls.m).length === 0);
}
ck('κανένα κείμενο οθόνης στον κώδικα του loader (εκτός σχολίων μόνο ASCII)',
   !/[^\x00-\x7f]/.test(UI_SRC.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')));
{ // σήμα: server κειμένων πέφτει, αντίγραφο υπάρχει -> tooltip από το αντίγραφο
  const spans = [];
  const link = { querySelector: () => null, appendChild: w => spans.push(w) };
  const doc = { readyState: 'complete', querySelectorAll: () => [link],
                createElement: () => ({ style: {}, children: [], appendChild(c) { this.children.push(c); } }) };
  const f = fakeFetch(url => url.includes('/tasks.php?action=badge') ? json(200, { ok: true, attention: 0, mine: 0, overrides: 2 }) : new Error('net'));
  const U = load(f, SESSION, fakeLS({ '4a_ui_texts:common': COPY(TEXTS.texts.common) }));
  new Function('window', 'document', 'fetch', 'sessionStorage', 'UiTexts', BADGE_SRC)({}, doc, f, SESSION, U);
  for (let i = 0; i < 10; i++) await new Promise(r => setImmediate(r));
  const red = spans[0] && spans[0].children[0];
  ck('σήμα: server κειμένων πέφτει, αντίγραφο -> tooltip από το αντίγραφο',
     red && red.title === '2 παρακάμψεις διαχειριστή περιμένουν «Το είδα»', red && red.title);
}

console.log('\n═══ tasks-badge.js: tooltip παράκαμψης από τη βάση, αλλιώς μόνο αριθμός ═══');
async function badge(badgeBody, textsReply, withUi) {
  const spans = [];
  const link = { querySelector: () => null, appendChild: w => spans.push(w) };
  const doc = { readyState: 'complete', querySelectorAll: () => [link],
                createElement: () => { const el = { style: {}, children: [], appendChild(c) { this.children.push(c); } }; return el; } };
  const f = fakeFetch(url => url.includes('/tasks.php?action=badge') ? json(200, badgeBody) : textsReply());
  const win = {};
  const UiTexts = withUi === false ? undefined : load(f);
  new Function('window', 'document', 'fetch', 'sessionStorage', 'UiTexts', BADGE_SRC)(win, doc, f, SESSION, UiTexts);
  for (let i = 0; i < 10; i++) await new Promise(r => setImmediate(r));
  const red = spans[0] && spans[0].children[0];
  return { red, calls: f.calls };
}
{
  let b = await badge({ ok: true, attention: 0, mine: 0, overrides: 2 }, () => json(200, TEXTS));
  ck('2 παρακάμψεις: κόκκινο «2», tooltip από τη βάση με {n}',
     b.red && b.red.textContent === 2 && b.red.title === '2 παρακάμψεις διαχειριστή περιμένουν «Το είδα»', b.red && b.red.title);
  ck('τα κείμενα ζητήθηκαν από το scope common', b.calls.some(c => /ui_texts\.php\?scope=common&/.test(c.url)));
  b = await badge({ ok: true, attention: 0, mine: 0, overrides: 2 }, () => new Error('net'));
  ck('loader απέτυχε: ΜΟΝΟ ο αριθμός, χωρίς tooltip (απόφαση 14/10)', b.red && b.red.textContent === 2 && b.red.title === '', b.red && b.red.title);
  b = await badge({ ok: true, attention: 0, mine: 0, overrides: 2 }, () => json(200, { ok: true, texts: { common: {} } }));
  ck('κλειδί που λείπει από τη βάση: μόνο ο αριθμός, ΟΧΙ «[badge_override_tip]»', b.red && b.red.title === '', b.red && b.red.title);
  b = await badge({ ok: true, attention: 0, mine: 0, overrides: 2 }, () => json(200, TEXTS), false);
  ck('χωρίς ui-texts.js στη σελίδα: μόνο ο αριθμός, χωρίς σφάλμα', b.red && b.red.textContent === 2 && b.red.title === '');
  b = await badge({ ok: true, attention: 1, mine: 0, overrides: 0 }, () => json(200, TEXTS));
  ck('χωρίς παράκαμψη: ΚΑΝΕΝΑ αίτημα κειμένων', !b.calls.some(c => /ui_texts/.test(c.url)) && b.red.textContent === 1);
}
ck('οι σελίδες με σήμα φορτώνουν το ui-texts.js ΑΜΕΣΩΣ πριν από το tasks-badge.js',
   ['pricelist-editor.html', 'users.html'].every(p => fs.readFileSync('frontend/' + p, 'utf8')
     .includes('<script src="ui-texts.js?v=2026-10-15"></script>\n<script src="tasks-badge.js?v=2026-10-15"></script>')) &&
   (s => s.indexOf('<script src="ui-texts.js?v=2026-10-15"></script>') > 0 &&
         s.indexOf('<script src="ui-texts.js?v=2026-10-15"></script>') < s.indexOf('<script src="tasks-badge.js?v=2026-10-15"></script>'))(
     fs.readFileSync('frontend/pricelist-clients.html', 'utf8')));
ck('κανένα σταθερό κείμενο παράκαμψης στο tasks-badge.js', !BADGE_SRC.includes("' παρακάμψεις διαχειριστή"));

console.log(`\n═══ ΣΥΝΟΛΟ: ${pass} OK, ${fail} ΛΑΘΟΣ ═══`);
process.exit(fail ? 1 : 0);
})();
