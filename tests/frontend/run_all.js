#!/usr/bin/env node
/* tests/frontend/run_all.js
 *
 * Τρέχει όλα τα harness του frontend. Έξοδος 1 αν έστω ένα αποτύχει.
 *
 * ΑΠΟ ΤΗ ΡΙΖΑ ΤΟΥ REPO:   node tests/frontend/run_all.js
 *
 * Τα harness διαβάζουν τα frontend/*.html με ΣΧΕΤΙΚΗ διαδρομή, γι' αυτό ο
 * τρέχων φάκελος πρέπει να είναι η ρίζα. Ο runner το επιβάλλει.
 */
const { execFileSync } = require('child_process');
const fs   = require('fs');
const path = require('path');

const HERE = __dirname;
const ROOT = path.resolve(HERE, '..', '..');
process.chdir(ROOT);

if (!fs.existsSync(path.join(ROOT, 'frontend', 'fuel-freshness.js'))) {
  console.error('Δεν βρέθηκε το frontend/ — τρέξε τον runner από τη ρίζα του repo.');
  process.exit(2);
}

const files = fs.readdirSync(HERE).filter(f => f.endsWith('.test.js')).sort();
let failed = [];

for (const f of files) {
  const full = path.join(HERE, f);
  let out = '', code = 0;
  try {
    out = execFileSync(process.execPath, [full], { encoding: 'utf8', stdio: ['ignore','pipe','pipe'] });
  } catch (e) {
    out  = (e.stdout || '') + (e.stderr || '');
    code = e.status === undefined ? 1 : e.status;
  }
  const sum = (out.match(/ΣΥΝΟΛΟ: \d+ OK, \d+ ΛΑΘΟΣ/g) || []).pop() || '(χωρίς σύνοψη)';
  console.log(`${code === 0 ? 'OK   ' : 'ΛΑΘΟΣ'} ${f.padEnd(22)} ${sum}`);
  if (code !== 0) { failed.push(f); console.log(out.split(/\r?\n/).filter(l => l.includes('ΛΑΘΟΣ')).map(l => '      ' + l).join('\n')); }
}

console.log(`\n${files.length} harness · ${failed.length} απέτυχαν`);
process.exit(failed.length ? 1 : 0);
