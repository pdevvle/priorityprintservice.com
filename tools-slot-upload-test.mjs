// Building a booklet a page at a time has to accumulate.
//
// "Upload pages individually" opens a grid of page slots. Dropping a file on
// one is meant to be that page's artwork. For PDFs it was not: handleSlotFile
// sent any PDF straight to processFiles, which REPLACES the whole book — so the
// second single-page PDF wiped the first, and only ever one stuck. Reported
// from staging on 2026-09-13 as "it will allow 1 page to upload, but then not
// accept the others", and it is single-page PDFs people have, because that is
// what a design tool exports.
//
// The damage ran past the calculator: slotFilesRef was never written either, so
// ppsOpenProof forwarded no slots and the proofer had art for one page and
// nothing for the rest (see tools-proof-blank-pages-test.mjs for what it then
// drew on them).
//
// A multi-page PDF on a slot still means the whole book — that is a different
// intent and the old behaviour is right for it.
//
// Setup: parity-saddle.html served on :8137 (tools-harness-prep.mjs; see the
// header of tools-parity-saddle.mjs).
//
// Run: PPS_DEPS_DIR=<node_modules> node tools-slot-upload-test.mjs

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const { createRequire } = await import('node:module');
const require = createRequire(import.meta.url);
const DEPS = process.env.PPS_DEPS_DIR || new URL('./node_modules', import.meta.url).pathname;
const fs = await import('node:fs');
const os = await import('node:os');
const path = await import('node:path');

const BASE = process.env.PPS_CALC_BASE || 'http://127.0.0.1:8137';
// PPS_CALC_PAGE points this at a different build — how the pre-fix one was run
// to confirm these checks actually fail against it.
const CALC = process.env.PPS_CALC_PAGE || 'parity-saddle.html';

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// One colour per file, so which file landed on which slot is readable off the
// pixels rather than off a filename we chose ourselves.
const { jsPDF } = require(DEPS + '/jspdf/dist/jspdf.node.min.js');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'pps-slots-'));
function pdf(name, colour, pages) {
  const doc = new jsPDF({ unit:'in', format:[5.75, 8.75], orientation:'portrait' });
  for (let i = 0; i < pages; i++) {
    if (i) doc.addPage([5.75, 8.75], 'portrait');
    doc.setFillColor(colour[0], colour[1], colour[2]);
    doc.rect(0, 0, 5.75, 8.75, 'F');
  }
  const p = path.join(TMP, name);
  fs.writeFileSync(p, Buffer.from(doc.output('arraybuffer')));
  return p;
}
const A = pdf('a-red.pdf',   [201, 64, 27],  1);
const B = pdf('b-green.pdf', [0, 160, 90],   1);
const C = pdf('c-blue.pdf',  [27, 106, 201], 1);
const MULTI = pdf('multi.pdf', [120, 80, 160], 8);

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});

async function open() {
  const page = await browser.newPage({ viewport:{ width:1400, height:1100 } });
  const errs = [];
  page.on('pageerror', e => errs.push(String(e && e.message || e).slice(0, 200)));
  page.on('dialog', async d => { errs.push('dialog: ' + d.message()); await d.dismiss(); });
  await page.addInitScript(() => {
    window.PPS_CONFIG = { ajaxUrl: location.origin + '/wp-admin/admin-ajax.php',
      cartUrl: location.origin + '/cart.html', cartNonce:'c', uploadNonce:'u',
      productId: 33670, maxUpload: 200 * 1048576 };
  });
  await page.goto(BASE + '/' + CALC, { waitUntil:'domcontentloaded' });
  // The pickers are display:none — the tiles and the drop zone are the visible
  // surface — so wait for attachment, not visibility.
  await page.waitForSelector('input[type=file]', { state:'attached', timeout:30000 });
  return { page, errs };
}

// The slot grid only exists once there is a book to slot into, which is the real
// order of operations: upload something, then refine it page by page.
async function seed(page, file) {
  await page.locator('input[type=file]').first().setInputFiles(file);
  await page.waitForFunction(() => {
    const el = [...document.querySelectorAll('*')].find(e => /filled\)/.test(e.textContent || '') && e.children.length === 0);
    return !!el;
  }, null, { timeout:40000 });
  // Section 4 collapses once a file is in — open it to reach the slot grid.
  await page.getByText('Artwork & Proofing', { exact:true }).click();
  const toggle = page.getByText('Upload pages individually');
  await toggle.waitFor({ state:'visible', timeout:15000 });
  // An upload leaves the grid open, so clicking unconditionally would shut it.
  if (await page.locator('[data-pps-dropzone]').count() === 0) await toggle.click();
  await page.waitForSelector('[data-pps-dropzone]', { timeout:20000 });
}

const filled = (page) => page.evaluate(() => {
  const el = [...document.querySelectorAll('*')].find(e => /^\(\d+\/\d+ filled\)$/.test((e.textContent || '').trim()));
  return el ? el.textContent.trim() : '(not found)';
});

// What is actually shown on each slot tile, as a colour.
const tiles = (page) => page.evaluate(async () => {
  const zones = [...document.querySelectorAll('[data-pps-dropzone]')];
  const out = [];
  for (const z of zones) {
    const img = z.querySelector('img');
    if (!img) { out.push(null); continue; }
    const c = document.createElement('canvas');
    c.width = 6; c.height = 6;
    const x = c.getContext('2d');
    try { x.drawImage(img, 0, 0, 6, 6); } catch (e) { out.push('tainted'); continue; }
    const d = x.getImageData(3, 3, 1, 1).data;
    out.push('#' + [d[0], d[1], d[2]].map(v => v.toString(16).padStart(2, '0')).join(''));
  }
  return out;
});

// Clicking a slot opens the hidden picker; a programmatic click still raises a
// file chooser, which is how a test supplies the file the customer would pick.
async function dropOnSlot(page, idx, file) {
  const zone = page.locator('[data-pps-dropzone]').nth(idx);
  const [chooser] = await Promise.all([
    page.waitForEvent('filechooser', { timeout:15000 }),
    zone.locator('div').first().click(),
  ]);
  await chooser.setFiles(file);
}

// A slot that never fills is the failure this suite exists to catch, so waiting
// for one has to report rather than throw — otherwise the first regression takes
// the remaining checks down with it and you learn less than you could have.
async function slotFills(page, idx, ms = 30000) {
  try {
    await page.waitForFunction((i) => {
      const z = [...document.querySelectorAll('[data-pps-dropzone]')];
      return z[i] && z[i].querySelector('img');
    }, idx, { timeout: ms });
    return true;
  } catch (e) { return false; }
}

const near = (hex, want) => {
  if (!hex || hex === 'tainted') return false;
  const p = s => [1, 3, 5].map(i => parseInt(s.slice(i, i + 2), 16));
  const a = p(hex), b = p(want);
  return a.every((v, i) => Math.abs(v - b[i]) < 26);
};

// ── 1. three single-page PDFs, three slots ──────────────────────────────────
console.log('\n── one page at a time ──');
{
  const { page, errs } = await open();
  await seed(page, A);
  ok('the first upload gives us a book to slot into', /^\(1\//.test(await filled(page)), await filled(page));

  await dropOnSlot(page, 1, B);
  const landedB = await slotFills(page, 1);
  ok('a single-page PDF lands on the slot it was dropped on, and the count grows',
     landedB && /^\(2\//.test(await filled(page)), await filled(page));

  await dropOnSlot(page, 4, C);
  const landedC = await slotFills(page, 4);
  ok('and so does the next one — a second PDF no longer wipes the first',
     landedC && /^\(3\//.test(await filled(page)), await filled(page));

  const t = await tiles(page);
  ok('page 1 still carries the file that was uploaded first', near(t[0], '#c9401b'), String(t[0]));
  ok('page 2 carries the file dropped on page 2',              near(t[1], '#00a05a'), String(t[1]));
  ok('page 5 carries the file dropped on page 5',              near(t[4], '#1b6ac9'), String(t[4]));
  ok('and the pages nobody touched are still empty',
     !t[2] && !t[3] && !t[5], JSON.stringify(t));
  ok('nothing threw and nothing was alerted', errs.length === 0, errs.join(' | '));
  await page.close();
}

// ── 2. a multi-page PDF on a slot is still a whole book ─────────────────────
console.log('\n── an 8-page PDF dropped on one slot ──');
{
  const { page, errs } = await open();
  await seed(page, A);
  await dropOnSlot(page, 2, MULTI);
  try {
    await page.waitForFunction(() => {
      const z = [...document.querySelectorAll('[data-pps-dropzone]')];
      return z.length && z.every(e => e.querySelector('img'));
    }, null, { timeout:40000 });
  } catch (e) { /* reported by the checks below */ }
  ok('it replaces the whole book, as it always has', /^\(8\//.test(await filled(page)), await filled(page));
  const t = await tiles(page);
  // Every page that now has art came from it, and none from the red file that
  // was there before. How many tiles it fills and how many empties follow is
  // the calculator's own page model — the coupon book pads differently from the
  // saddle — so the check is about provenance, not about the count.
  const shown = t.filter(Boolean);
  ok('and every page that has art came from it, none from what was there before',
     shown.length >= 7 && shown.every(c => near(c, '#7850a0')), JSON.stringify(t.slice(0, 9)));
  ok('nothing threw', errs.length === 0, errs.join(' | '));
  await page.close();
}

await browser.close();
fs.rmSync(TMP, { recursive:true, force:true });
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> SLOT UPLOAD FAILED' : '>>> SLOT UPLOAD OK');
process.exit(failed ? 1 : 0);
