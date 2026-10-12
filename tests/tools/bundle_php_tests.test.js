#!/usr/bin/env node
/* tests/tools/bundle_php_tests.test.js
 *
 *   node tests/tools/bundle_php_tests.test.js     (από τη ρίζα του repo)
 *
 * Τεστ του tools/bundle_php_tests.js:
 *   1. καθαρή συνάρτηση σε συνθετικό τεστ, χωρίς git
 *   2. όλα τα tests/php/*.test.php του HEAD: κανένα __DIR__, κάθε require
 *      του api δείχνει στο <API>, κάθε fixture ενσωματωμένο byte-προς-byte
 *   3. ΑΝ υπάρχει php τοπικά: το bundle (με API = το api/ του repo) τρέχει
 *      από stdin και δίνει την ίδια σύνοψη με το αρχικό τεστ. Αλλιώς SKIP.
 */
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
process.chdir(ROOT);
const { bundleSource, gitBlob, gitTestFiles } = require(path.join(ROOT, 'tools', 'bundle_php_tests.js'));

let ok = 0, bad = 0;
const t = (name, cond) => { if (cond) { ok++; console.log('OK    ' + name); } else { bad++; console.log('ΛΑΘΟΣ ' + name); } };

// ── 1. Συνθετικό ────────────────────────────────────────────────────────
{
  const fake = { 'tests/frontend/a.json': Buffer.from('{"x":1}'), 'tests/php/b.txt': Buffer.from('β') };
  const blob = p => { if (!fake[p]) throw new Error('άγνωστο ' + p); return fake[p]; };
  const src = [
    "require_once __DIR__ . '/../../api/tasks_lib.php';",
    "$FX = __DIR__ . '/../frontend/';",
    "$a = file_get_contents($FX . 'a.json');",
    "$b = file_get_contents(__DIR__ . '/b.txt');",
  ].join('\n');
  const r = bundleSource(src, '<API>', blob);
  t('συνθετικό: require -> <API>/tasks_lib.php', r.code.includes("require_once '<API>/tasks_lib.php';"));
  t('συνθετικό: $FX fixture ενσωματωμένο', r.code.includes(`base64_decode('${fake['tests/frontend/a.json'].toString('base64')}')`));
  t('συνθετικό: __DIR__ fixture ενσωματωμένο', r.code.includes(`base64_decode('${fake['tests/php/b.txt'].toString('base64')}')`));
  t('συνθετικό: $FX = null', r.code.includes('$FX = null;'));
  t('συνθετικό: κανένα __DIR__', r.left === 0);
  const r2 = bundleSource("$y = __DIR__ . '/κάτι';", '<API>', blob);
  t('συνθετικό: άγνωστο __DIR__ μετριέται', r2.left === 1);
}

// ── 2. Τα πραγματικά τεστ του HEAD ──────────────────────────────────────
const files = gitTestFiles();
t('HEAD: υπάρχουν αρχεία τεστ PHP', files.length > 0);
for (const f of files) {
  const name = path.basename(f);
  const r = bundleSource(gitBlob(f).toString('utf8'), '/X/api', gitBlob);
  t(`${name}: κανένα __DIR__`, r.left === 0);
  t(`${name}: κανένα ../../api`, !r.code.includes('../../api'));
  const reqs = r.code.match(/require(_once)?\s+'[^']+'/g) || [];
  t(`${name}: κάθε require στο /X/api/`, reqs.length > 0 && reqs.every(x => x.includes("'/X/api/")));
  const b64 = [...r.code.matchAll(/base64_decode\('([^']*)'\)/g)].map(m => m[1]);
  t(`${name}: base64 έγκυρα (round-trip)`, b64.every(s => Buffer.from(s, 'base64').toString('base64') === s));
}

// ── 3. Εκτέλεση, αν υπάρχει php ─────────────────────────────────────────
const php = spawnSync('php', ['-v'], { encoding: 'utf8' });
if (php.error || php.status !== 0) {
  console.log('SKIP  εκτέλεση bundles: δεν υπάρχει php τοπικά');
} else {
  const api = path.join(ROOT, 'api').split(path.sep).join('/');
  const sum = out => (out.match(/ΣΥΝΟΛΟ: \d+ OK, \d+ ΛΑΘΟΣ/g) || []).pop() || '';
  for (const f of files) {
    const r = bundleSource(gitBlob(f).toString('utf8'), api, gitBlob);
    const orig = spawnSync('php', [f], { encoding: 'utf8' });
    const bund = spawnSync('php', [], { input: r.code, encoding: 'utf8' });
    t(`${path.basename(f)}: ίδια σύνοψη με το αρχικό`, sum(orig.stdout) !== '' && sum(orig.stdout) === sum(bund.stdout));
  }
}

console.log(`\nΣΥΝΟΛΟ: ${ok} OK, ${bad} ΛΑΘΟΣ`);
process.exitCode = bad ? 1 : 0;
