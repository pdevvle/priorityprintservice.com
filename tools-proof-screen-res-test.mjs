// The page a customer inspects has to be the page that prints.
//
// The proofer paints the page first from its 150 DPI screen raster, which is
// quick and is not what prints: the print file is rendered from the source at
// 300 DPI. The calculator's built-in modal has always followed its quick paint
// with a 300 DPI composition of the same page, so what the customer zooms into
// is print resolution. Until 2026-09-28 the proofer never did: it asked for
// approval of "exactly what you see" while showing, and magnifying, a
// half-resolution stand-in.
//
// Pins:
//   - once the page settles, the canvas on the proof surface is a 300 DPI
//     render (data-art="print"), and its pixels are page 1 of the PRINT_READY
//     .pdf the customer then approves (mean difference < 2 of 255 after the
//     print file's JPEG encoding);
//   - a change of transform drops back to the quick render and upgrades again,
//     to the new transform — never showing a stale press render;
//   - moving to another page upgrades that page;
//   - the magnifier samples the press render once it is there;
//   - 3D mode is left alone.
//
// Needs: node tools-proof-serve.mjs &   and PPS_DEPS_DIR with pdf-lib.
// Run:   PPS_DEPS_DIR=<node_modules> node tools-proof-screen-res-test.mjs

import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const DEPS = process.env.PPS_DEPS_DIR || path.join(HERE, 'node_modules');
const { PDFDocument, StandardFonts, rgb } =
  await import(pathToFileURL(path.join(DEPS, 'pdf-lib', 'cjs', 'index.js')).href);
const BASE = process.env.PPS_PROOF_BASE || 'http://127.0.0.1:8137';
const PAGE = BASE + '/proof-ui-draft.html';

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// 6 x 9 pages (so they are rendered, not shipped untouched), fine type and
// hairlines — the detail a 150 DPI stand-in loses.
async function fixture() {
  const doc = await PDFDocument.create();
  const font = await doc.embedFont(StandardFonts.Helvetica);
  for (let i = 1; i <= 8; i++) {
    const p = doc.addPage([6 * 72, 9 * 72]);
    p.drawRectangle({ x: 0, y: 0, width: 432, height: 648, color: rgb(1, 1, 0.94) });
    for (let k = 0; k < 60; k++) p.drawLine({ start: { x: 40 + k * 4, y: 300 }, end: { x: 40 + k * 4, y: 420 }, thickness: 0.5, color: rgb(0, 0, 0) });
    for (let k = 0; k < 12; k++) p.drawText('Page ' + i + ' — six point type that has to stay sharp in print', { x: 40, y: 560 - k * 8, size: 6, font, color: rgb(0.05, 0.05, 0.2) });
  }
  return Array.from(await doc.save());
}

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});
const errors = [];
const JOB = { calc: 'saddle', trim: { w: 5.5, h: 8.5 }, bleed: 0.125, safety: 0.125, pages: 8,
              insideColor: 'color', coverColor: 'color', proof: 0 };
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
}, JOB);
await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
await page.waitForFunction(() => window.__posts.some(m => m.type === 'pps-proof:ready'), null, { timeout: 20000 });
await page.evaluate(async (bytes) => {
  const file = new File([new Uint8Array(bytes)], 'fine.pdf', { type: 'application/pdf' });
  window.postMessage({ type: 'pps-proof:job', job: window.PPS_PROOF_JOB, files: [file] }, window.location.origin);
}, await fixture());
await page.waitForFunction(() => uploads.size >= 8, null, { timeout: 60000 });

const artState = () => page.evaluate(() => {
  const c = document.querySelector('#sheet canvas[data-art]');
  return c ? { res: c.dataset.art, w: c.width, h: c.height, key: hiRes.key } : null;
});
const waitPrint = () => page.waitForFunction(() => {
  const c = document.querySelector('#sheet canvas[data-art]');
  return c && c.dataset.art === 'print';
}, null, { timeout: 30000 });

console.log('\n── the page settles to the press render ──');
await page.evaluate(() => { state.selected = 1; renderAll(); });
await waitPrint();
{
  const a = await artState();
  ok('the proof surface now shows a 300 DPI render', a.res === 'print' && a.w === 1725 && a.h === 2625, JSON.stringify(a));
}

console.log('\n── the magnifier samples it ──');
{
  const box = await page.evaluate(() => {
    document.getElementById('mag').checked = true;
    const b = document.getElementById('sheet').getBoundingClientRect();
    return { x: b.left + b.width * 0.3, y: b.top + b.height * 0.5 };
  });
  await page.mouse.move(box.x, box.y);
  const lens = await page.evaluate(() => {
    const l = document.querySelector('#lens canvas');
    if (!l) return null;
    // the lens is 3x the DISPLAY scale; at 300 DPI source that is still real
    // detail, so hairlines keep hard edges: count near-black and near-white px
    const d = l.getContext('2d').getImageData(0, 0, l.width, l.height).data;
    let dark = 0, mid = 0;
    for (let k = 0; k < d.length; k += 4) {
      const y = (d[k] + d[k + 1] + d[k + 2]) / 3;
      if (y < 60) dark++; else if (y < 200) mid++;
    }
    return { dark, mid };
  });
  ok('the lens draws after the swap', !!lens && lens.dark > 0, JSON.stringify(lens));
  await page.mouse.move(5, 5);
  await page.evaluate(() => { document.getElementById('mag').checked = false; hideLens(); });
}

console.log('\n── a transform change never shows a stale press render ──');
{
  const before = await artState();
  await page.evaluate(() => { pushHistory(); state.perPage[1].behavior = 'scale'; state.perPage[1].scale = 80; renderAll(); });
  const right = await artState();
  ok('it drops straight back to the quick render', right.res === 'screen', JSON.stringify(right));
  await waitPrint();
  const after = await artState();
  ok('and upgrades again, for the new transform', after.res === 'print' && after.key !== before.key, before.key + ' → ' + after.key);
  await page.evaluate(() => { undo(); });
  await waitPrint();
}

console.log('\n── another page upgrades too ──');
{
  await page.evaluate(() => { state.selected = 3; renderAll(); });
  await waitPrint();
  const a = await artState();
  ok('page 3 is shown at press resolution', a.res === 'print' && /^3\|/.test(a.key), JSON.stringify(a));
}

console.log('\n── 3D is left alone ──');
{
  await page.evaluate(() => { state.mode = 'book'; renderAll(); });
  await page.waitForTimeout(800);
  const a = await page.evaluate(() => document.querySelectorAll('#sheet canvas[data-art="print"]').length);
  ok('no press render is put into the 3D book', a === 0, String(a));
  await page.evaluate(() => { state.mode = 'proof'; state.selected = 1; renderAll(); });
  await waitPrint();
}

console.log('\n── what was on screen is what prints ──');
{
  // Grab the on-screen press render of page 1 before approving.
  const shown = await page.evaluate(() => {
    const c = document.querySelector('#sheet canvas[data-art]');
    return c.dataset.art === 'print' ? c.toDataURL('image/png') : null;
  });
  await page.evaluate(() => { for (const id of ['agree', 'ackIssues']) { const el = document.getElementById(id); if (el && !el.checked && !el.disabled) el.click(); } });
  await page.click('#approveBtn');
  await page.waitForFunction(() => window.__posts.some(m => /pps-proof:approve(d|-failed)/.test(m.type)), null, { timeout: 180000 });
  const diff = await page.evaluate(async (shown) => {
    const m = window.__posts.find(m => m.type === 'pps-proof:approved');
    if (!m || !shown) return { err: 'no approval or nothing shown' };
    const pr = m.files.find(f => f.name === 'PRINT_READY.pdf');
    await ensureLibs();
    const doc = await window.pdfjsLib.getDocument({ data: new Uint8Array(await pr.blob.arrayBuffer()) }).promise;
    const pg = await doc.getPage(1);
    const vp = pg.getViewport({ scale: 300 / 72 });
    const a = document.createElement('canvas'); a.width = Math.round(vp.width); a.height = Math.round(vp.height);
    await pg.render({ canvasContext: a.getContext('2d'), viewport: vp }).promise;
    const img = new Image(); img.src = shown; await img.decode();
    const b = document.createElement('canvas'); b.width = img.width; b.height = img.height;
    b.getContext('2d').drawImage(img, 0, 0);
    if (a.width !== b.width || a.height !== b.height) return { err: 'size ' + a.width + 'x' + a.height + ' vs ' + b.width + 'x' + b.height };
    const da = a.getContext('2d').getImageData(0, 0, a.width, a.height).data;
    const db = b.getContext('2d').getImageData(0, 0, b.width, b.height).data;
    let sum = 0;
    for (let k = 0; k < da.length; k += 4) sum += Math.abs(da[k] - db[k]) + Math.abs(da[k + 1] - db[k + 1]) + Math.abs(da[k + 2] - db[k + 2]);
    return { mean: sum / (da.length / 4 * 3), w: a.width, h: a.height };
  }, shown);
  ok('the approved print file\'s page 1 matches what the surface showed', !diff.err && diff.mean < 2, JSON.stringify(diff));
}

ok('no page errors', errors.length === 0, errors.join('\n       '));
await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> PROOF SCREEN-RES FAILED' : '>>> PROOF SCREEN-RES OK');
process.exit(failed ? 1 : 0);
