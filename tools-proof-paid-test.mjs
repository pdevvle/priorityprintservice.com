// A customer who bought a staff proof must not be asked to approve their own.
//
// The saddle calculator sells three proof options: the free online proof (the
// customer approves, and takes responsibility — proof 0), a professional digital
// proof by prepress (0.01), and a mailed hardcopy (3.01 and up). Only the first
// is self-approved. The built-in modal has always shown the other two the art
// and no Approve button, and the add-to-cart gate applies to proof 0 only.
//
// Until 2026-09-28 the standalone proofer was never told which one was bought,
// so it demanded the same approval — with the sentence that makes errors the
// customer's responsibility — from someone who had just paid us to check.
//
// Pins:
//   - the calculator sends `proof` in the job;
//   - proof 0 approves as before;
//   - a paid proof is review-only: no Approve, no agreement, no liability line,
//     the matching explanation, and a Done that closes;
//   - approval cannot be forced through on a paid proof, even by a click on the
//     hidden button;
//   - a malformed proof value is refused rather than read as "self-approve".
//
// Needs the harness: node tools-proof-serve.mjs &
// Run: node tools-proof-paid-test.mjs

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

console.log('\n── the calculator says which proof was bought ──');
{
  const src = readFileSync(path.join(HERE, 'calc-preview-test.html'), 'utf8');
  const a = src.indexOf('const openStandaloneProof = async');
  const b = src.indexOf('ppsOpenProof({', a);
  const body = a >= 0 && b > a ? src.slice(a, b) : '';
  ok('openStandaloneProof puts proof in the job', /\bproof:\s*Number\(proof\)\s*\|\|\s*0/.test(body));
}

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});
const errors = [];
const JOB = { calc: 'saddle', trim: { w: 5.5, h: 8.5 }, bleed: 0.125, safety: 0.125, pages: 8,
              insideColor: 'color', coverColor: 'color' };

async function open(job) {
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  page.on('pageerror', e => errors.push(String(e && e.message || e)));
  page.on('dialog', d => d.dismiss());
  await page.route('**/*', route => {
    const u = route.request().url();
    if (u.startsWith(BASE) || u.startsWith('data:') || u.startsWith('blob:')) return route.continue();
    return route.fulfill({ status: 204, body: '' });
  });
  await page.addInitScript((job) => {
    window.__posts = [];
    window.PPS_PROOF_HOST = m => window.__posts.push(m);
    window.PPS_LIB_BASE = '/vendor/';
    window.PPS_PROOF_JOB = job;
  }, job);
  await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => window.__posts.some(m => /pps-proof:(ready|error)/.test(m.type)), null, { timeout: 20000 });
  // art on every page, so nothing else is flagged
  await page.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 1725; c.height = 2625;
    const x = c.getContext('2d'); x.fillStyle = '#4a7bd0'; x.fillRect(0, 0, c.width, c.height);
    const blob = await new Promise(r => c.toBlob(r, 'image/png'));
    for (let n = 1; n <= MODEL.pages.length; n++) await loadArt(n, new File([blob], 'p' + n + '.png', { type: 'image/png' }));
    renderAll();
  });
  return page;
}
const visible = (page, sel) => page.evaluate((sel) => {
  const el = document.querySelector(sel);
  if (!el) return false;
  const r = el.getBoundingClientRect(), cs = getComputedStyle(el);
  return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
}, sel);
const posted = (page, type) => page.evaluate((t) => window.__posts.filter(m => m.type === t).length, type);

console.log('\n── the free online proof approves as before ──');
{
  const page = await open({ ...JOB, proof: 0 });
  ok('Approve is on screen', await visible(page, '#approveBtn'));
  ok('the agreement is on screen', await visible(page, '#agree'));
  ok('the responsibility sentence is on screen', await visible(page, '#liability'));
  ok('Done is not', !(await visible(page, '#reviewDone')));
  await page.close();
}

for (const [proof, name, re] of [[0.01, 'professional digital proof', /prepress/i], [3.01, 'hardcopy proof', /hardcopy/i]]) {
  console.log('\n── a ' + name + ' (' + proof + ') is review-only ──');
  const page = await open({ ...JOB, proof });
  ok('no Approve button', !(await visible(page, '#approveBtn')));
  ok('no agreement to tick', !(await visible(page, '#agree')));
  ok('no responsibility sentence', !(await visible(page, '#liability')));
  ok('no acknowledgment box', !(await visible(page, '#ackBox')));
  ok('a Done button instead', await visible(page, '#reviewDone'));
  const note = await page.evaluate(() => document.getElementById('noteText').textContent);
  ok('the note explains who approves', re.test(note) && !/Online proof approval is best/.test(note), note);
  // Forcing it: tick everything and click the hidden button directly.
  const label = await page.evaluate(() => {
    for (const id of ['agree', 'ackIssues']) { const el = document.getElementById(id); if (el) { el.disabled = false; el.checked = true; } }
    const b = document.getElementById('approveBtn'); b.disabled = false; b.click();
    // The handler relabels the button synchronously when it starts building,
    // so this catches a run that has not had time to post anything yet.
    return b.textContent;
  });
  ok('a forced click on Approve does not start building', label === 'APPROVE ART', label);
  // Long enough for an 8-page package to finish if it had started.
  await page.waitForTimeout(8000);
  ok('…and approves nothing', (await posted(page, 'pps-proof:approved')) === 0
     && (await posted(page, 'pps-proof:approve-failed')) === 0);
  await page.click('#reviewDone');
  ok('Done closes the proof', (await posted(page, 'pps-proof:close')) === 1);
  // 3D mode must not bring Approve back
  await page.evaluate(() => { const b = document.querySelector('#modes button[data-mode="book"]'); if (b) b.click(); });
  await page.evaluate(() => { const b = document.querySelector('#modes button[data-mode="proof"]'); if (b) b.click(); });
  ok('switching views does not bring Approve back', !(await visible(page, '#approveBtn')));
  await page.close();
}

console.log('\n── a proof value that is not a number is refused ──');
{
  const page = await open(JOB);
  const errs = await page.evaluate(() => validateJob({ calc: 'saddle', trim: { w: 5.5, h: 8.5 }, pages: 8, proof: 'staff' }));
  ok('proof "staff" is an error, not a free proof', errs.some(e => /proof/.test(e)), JSON.stringify(errs));
  const neg = await page.evaluate(() => validateJob({ calc: 'saddle', trim: { w: 5.5, h: 8.5 }, pages: 8, proof: -1 }));
  ok('proof -1 is an error', neg.some(e => /proof/.test(e)), JSON.stringify(neg));
  await page.close();
}

ok('no page errors', errors.length === 0, errors.join('\n       '));
await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> PROOF PAID FAILED' : '>>> PROOF PAID OK');
process.exit(failed ? 1 : 0);
