// Populate proof-vendor/ with the libraries the proofer pins.
//
// proof-ui-draft.html loads pdf.js 3.11.174 and pdf-lib 1.17.1. The calculators
// load pdf.js 4.10.38. npm cannot hold two majors of one package at once, so
// installing the proofer's copy evicts the calculators' and the calculator smoke
// test then fails on a missing file that has nothing to do with what you were
// working on. That has cost time more than once.
//
// So: install the proofer's versions, copy the three files out to their own
// directory, then put the calculators' pdf.js back. tools-proof-serve.mjs reads
// from that directory and never from node_modules.
//
// The files are deliberately NOT committed. They are ~1.9 MB of third-party
// minified builds in a public repo, and npm already pins them exactly.
//
//   node tools-proof-vendor.mjs            # into ../proof-vendor relative to deps
//   node tools-proof-vendor.mjs --check    # report only, install nothing
//
// PPS_DEPS_DIR points at the node_modules to install into (defaults to one
// beside this file); PPS_PROOF_VENDOR_DIR overrides the destination.

import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdir, copyFile, stat } from 'node:fs/promises';
import path from 'node:path';

const run = promisify(execFile);

const HERE = path.dirname(new URL(import.meta.url).pathname);
const DEPS = process.env.PPS_DEPS_DIR || path.join(HERE, 'node_modules');
const ROOT = path.dirname(DEPS);                     // where npm should run
const VENDOR_DIR = process.env.PPS_PROOF_VENDOR_DIR || path.join(ROOT, 'proof-vendor');

// The proofer's pins, and where npm puts each one.
const PROOFER_DEPS = ['pdfjs-dist@3.11.174', 'pdf-lib@1.17.1'];
const CALC_PDFJS   = 'pdfjs-dist@4.10.38';           // what the calculators need back
const WANTED = {
  'pdf.min.js':        'pdfjs-dist/build/pdf.min.js',
  'pdf.worker.min.js': 'pdfjs-dist/build/pdf.worker.min.js',
  'pdf-lib.min.js':    'pdf-lib/dist/pdf-lib.min.js',
};

const exists = async p => { try { await stat(p); return true; } catch { return false; } };

async function report() {
  const rows = [];
  for (const name of Object.keys(WANTED)) {
    rows.push([name, await exists(path.join(VENDOR_DIR, name))]);
  }
  const calcOk = await exists(path.join(DEPS, 'pdfjs-dist/legacy/build/pdf.min.mjs'));
  console.log('vendor dir: ' + VENDOR_DIR);
  for (const [n, ok] of rows) console.log('  ' + (ok ? 'ok      ' : 'MISSING ') + n);
  console.log("calculators' pdf.js 4.x in " + DEPS + ': ' + (calcOk ? 'ok' : 'MISSING'));
  return rows.every(r => r[1]) && calcOk;
}

if (process.argv.includes('--check')) {
  process.exit((await report()) ? 0 : 1);
}

const npm = async (...args) => {
  const { stdout, stderr } = await run('npm', args, { cwd: ROOT, maxBuffer: 32 * 1024 * 1024 });
  return (stdout || '') + (stderr || '');
};

await mkdir(VENDOR_DIR, { recursive: true });

console.log('installing the proofer pins: ' + PROOFER_DEPS.join(', '));
await npm('install', '--no-save', ...PROOFER_DEPS);

for (const [name, rel] of Object.entries(WANTED)) {
  const from = path.join(DEPS, rel);
  if (!(await exists(from))) throw new Error('npm did not provide ' + rel);
  await copyFile(from, path.join(VENDOR_DIR, name));
  console.log('  copied ' + name);
}

// Always restore, even though the copy above already succeeded — leaving the
// tree on pdf.js 3.x is the failure this script exists to prevent.
console.log("restoring the calculators' " + CALC_PDFJS);
await npm('install', '--no-save', CALC_PDFJS);

console.log('');
const ok = await report();
if (!ok) {
  console.error('\nsomething is still missing — see above');
  process.exit(1);
}
console.log('\nready: node tools-proof-serve.mjs &');
