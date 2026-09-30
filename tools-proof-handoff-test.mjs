// What docs/PROOFER_SINCE_HANDOFF.md asks of the proofer for the saddle, pinned.
//
// Between 2026-09-26 and 2026-09-30 the calculators changed under the proofer
// (one staple under 3.5", snapped page counts, unreadable-image refusal, mixed
// colour). The brief lists what the proofer must match. For the booklet it
// serves today:
//   §1.2  the staple count comes from the calculator, never worked out here:
//         the host sends staples from twoStaplesApplied, and twoStaplesApplied
//         still honours oneStapleOnly;
//   §1.1  a page count no calculator would send (not whole, or past any book)
//         is refused rather than laid out;
//   §1.3  a colour mode the proofer cannot show (a mixed book) is refused, not
//         simulated as all colour or all grey;
//   §3.3  a file the loader cannot decode is refused BY NAME, never shown as a
//         blank page — and a JPEG a phone handed over with no type still loads.
//
// Needs the harness: node tools-proof-serve.mjs &
// Run: node tools-proof-handoff-test.mjs

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const BASE = process.env.PPS_PROOF_BASE || 'http://127.0.0.1:8137';
const PAGE = BASE + '/proof-ui-draft.html';

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

console.log('\n── §1.2 the staple count comes from the calculator ──');
{
  const calc = readFileSync(path.join(HERE, 'calc-preview-test.html'), 'utf8');
  const a = calc.indexOf('const openStandaloneProof = async');
  const body = a >= 0 ? calc.slice(a, calc.indexOf('ppsOpenProof({', a)) : '';
  ok('the job carries staples from twoStaplesApplied', /\bstaples:\s*twoStaplesApplied\s*\?\s*2\s*:\s*1/.test(body));
  ok('and twoStaplesApplied still honours one-staple-only (under 3.5")',
     /twoStaplesApplied=\{!!\(r\.twoAuto \|\| \(twoStaple && !r\.oneStapleOnly\)\)\}/.test(calc));
  const proofer = readFileSync(path.join(HERE, 'proof-ui-draft.html'), 'utf8');
  ok('the proofer never works the count out itself (no staple rule of its own)',
     !/one_staple|oneStapleOnly|twoAuto|twoStaple/.test(proofer));
}

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});
const errors = [];
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
page.on('pageerror', e => errors.push(String(e && e.message || e)));
const dialogs = [];
page.on('dialog', d => { dialogs.push(d.message()); d.dismiss(); });
await page.addInitScript(() => {
  window.__posts = []; window.PPS_PROOF_HOST = m => window.__posts.push(m); window.PPS_LIB_BASE = '/vendor/';
  window.PPS_PROOF_JOB = { calc: 'saddle', trim: { w: 5.5, h: 8.5 }, bleed: 0.125, safety: 0.125, pages: 8 };
});
await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
await page.waitForFunction(() => window.__posts.some(m => m.type === 'pps-proof:ready'), null, { timeout: 20000 });

console.log('\n── §1.1 / §1.3 counts and colours no calculator would send ──');
{
  const v = (job) => page.evaluate((job) => validateJob({ calc: 'saddle', trim: { w: 5.5, h: 8.5 }, pages: 8, ...job }), job);
  const frac = await v({ pages: 8.5 });
  ok('8.5 pages is refused', frac.some(e => /whole number|even/.test(e)), JSON.stringify(frac));
  const huge = await v({ pages: 2000 });
  ok('2,000 pages is refused, not laid out', huge.some(e => /past any book/.test(e)), JSON.stringify(huge));
  const pb = await v({ calc: 'perfect', pages: 350 });
  ok('perfect bound\'s longest book (350) is accepted', pb.length === 0, JSON.stringify(pb));
  const sixtyFour = await v({ pages: 64 });
  ok('the saddle\'s longest book (64) is accepted', sixtyFour.length === 0, JSON.stringify(sixtyFour));
  const mixed = await v({ insideColor: 'mixed' });
  ok('a mixed-colour book is refused rather than proofed as all colour or all grey',
     mixed.some(e => /insideColor/.test(e)), JSON.stringify(mixed));
}

console.log('\n── §3.3 a file that cannot be read is refused by name ──');
{
  const tryLoad = (name, type, bytes) => page.evaluate(async ({ name, type, bytes }) => {
    const before = uploads.has(2);
    await loadArt(2, new File([new Uint8Array(bytes)], name, { type }));
    return { attached: uploads.has(2) && !before };
  }, { name, type, bytes });
  dialogs.length = 0;
  const junkPng = await tryLoad('holiday-cover.png', 'image/png', [1, 2, 3, 4, 5, 6, 7, 8]);
  ok('an image that will not decode is not attached', !junkPng.attached);
  ok('…and the customer is told which file, and that it was NOT added',
     dialogs.length === 1 && /"holiday-cover\.png"/.test(dialogs[0]) && /NOT added/.test(dialogs[0]), dialogs.join(' | '));
  dialogs.length = 0;
  const junkPdf = await tryLoad('inside-pages.pdf', 'application/pdf', [37, 80, 68, 70, 45, 0, 0, 0]);
  ok('a PDF that will not open is refused by name', !junkPdf.attached && dialogs.length === 1 && /"inside-pages\.pdf"/.test(dialogs[0]),
     dialogs.join(' | '));
  dialogs.length = 0;
  const odd = await tryLoad('notes.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', [80, 75, 3, 4]);
  ok('a file that is not artwork at all is refused by name', !odd.attached && dialogs.length === 1 && /"notes\.docx"/.test(dialogs[0]),
     dialogs.join(' | '));
  // A real JPEG with no MIME type, as phones sometimes hand one over.
  dialogs.length = 0;
  const jpeg = await page.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 60; c.height = 90;
    c.getContext('2d').fillRect(0, 0, 60, 90);
    const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.9));
    await loadArt(3, new File([blob], 'IMG_0412.JPG', { type: '' }));
    return uploads.has(3);
  });
  ok('an untyped .JPG still loads, as the calculators accept it', jpeg && dialogs.length === 0, dialogs.join(' | '));
}

ok('no page errors', errors.length === 0, errors.join('\n       '));
await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> PROOF HANDOFF FAILED' : '>>> PROOF HANDOFF OK');
process.exit(failed ? 1 : 0);
