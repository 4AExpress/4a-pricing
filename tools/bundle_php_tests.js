#!/usr/bin/env node
/* tools/bundle_php_tests.js
 *
 * Φτιάχνει από κάθε tests/php/*.test.php ένα αυτόνομο αρχείο για το Β8 του
 * docs/DEPLOY_PROCEDURE.md (`ssh … php < bundle`):
 *   · require __DIR__ . '/../../api/X'  ->  require '<API>/X'   (τα ΑΝΕΒΑΣΜΕΝΑ αρχεία του server)
 *   · file_get_contents(fixture)        ->  base64_decode('…')   (fixtures ενσωματωμένα, από το git HEAD)
 * Τίποτα δεν γράφεται στον server. Τα τεστ τρέχουν σε SQLite στη μνήμη.
 * Εξαίρεση: tasks_roles_live.test.php, ζωντανή MySQL μόνο ανάγνωσης (Β8).
 * Τα ίδια τα τεστ ΔΕΝ αλλάζουν: η μετατροπή γίνεται μόνο εδώ.
 *
 * Πηγή: ΠΑΝΤΑ το git HEAD, ποτέ ο δίσκος (ίδιος κανόνας με το SCP του Β5).
 *
 * Χρήση (από τη ρίζα του repo):
 *   node tools/bundle_php_tests.js <outdir> <api path στον server>
 * Το <outdir> είναι τοπικός φάκελος ΕΚΤΟΣ repo. Το <api path> είναι
 * placeholder εδώ· η πραγματική τιμή δεν γράφεται σε κανένα αρχείο του repo.
 *
 * Έξοδος 1 αν σε κάποιο bundle έμεινε `__DIR__`.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const TESTS = 'tests/php';

/** Τα bytes ενός αρχείου όπως είναι στο git HEAD. */
function gitBlob(p) {
  return execFileSync('git', ['show', 'HEAD:' + p.split(path.sep).join('/')]);
}

/** Τα αρχεία τεστ PHP του HEAD. */
function gitTestFiles() {
  return execFileSync('git', ['ls-tree', '--name-only', 'HEAD', TESTS + '/'])
    .toString().trim().split('\n').filter(f => f.endsWith('.test.php'));
}

/**
 * Μετατρέπει ΕΝΑ αρχείο τεστ. Καθαρή συνάρτηση: το blob() δίνεται απ' έξω,
 * ώστε να δοκιμάζεται χωρίς git.
 *
 * @param {string}   src   το κείμενο του τεστ
 * @param {string}   api   η διαδρομή του api στον server
 * @param {function} blob  (repo path) -> Buffer
 * @return {{code: string, left: number}}  το bundle και πόσα __DIR__ έμειναν
 */
function bundleSource(src, api, blob) {
  let s = src;
  const resolve = rel => path.posix.normalize(path.posix.join(TESTS, rel));
  // $FX = __DIR__ . '/../frontend/';  …  file_get_contents($FX . 'x.json')
  const fxm = /\$FX\s*=\s*__DIR__\s*\.\s*'([^']+)'\s*;/.exec(s);
  if (fxm) {
    s = s.replace(/file_get_contents\(\$FX\s*\.\s*'([^']+)'\)/g,
      (_, n) => `base64_decode('${blob(resolve(fxm[1] + n)).toString('base64')}')`);
  }
  s = s.replace(/file_get_contents\(__DIR__\s*\.\s*'([^']+)'\)/g,
    (_, rel) => `base64_decode('${blob(resolve(rel)).toString('base64')}')`);
  s = s.replace(/__DIR__\s*\.\s*'\/\.\.\/\.\.\/api\/([^']+)'/g, (_, n) => `'${api}/${n}'`);
  if (fxm) s = s.replace(fxm[0], '$FX = null; // fixtures ενσωματωμένα');
  return { code: s, left: (s.match(/__DIR__/g) || []).length };
}

function main(argv) {
  const [OUT, API] = argv;
  if (!OUT || !API) {
    console.error('χρήση: node tools/bundle_php_tests.js <outdir> <api path>');
    return 2;
  }
  fs.mkdirSync(OUT, { recursive: true });
  let code = 0;
  for (const f of gitTestFiles()) {
    const r = bundleSource(gitBlob(f).toString('utf8'), API, gitBlob);
    fs.writeFileSync(path.join(OUT, path.basename(f)), r.code);
    console.log(`${path.basename(f).padEnd(30)} ${String(r.code.length).padStart(8)} bytes · __DIR__ που έμειναν: ${r.left}`);
    if (r.left) code = 1;
  }
  return code;
}

module.exports = { bundleSource, gitBlob, gitTestFiles };

if (require.main === module) process.exitCode = main(process.argv.slice(2));
