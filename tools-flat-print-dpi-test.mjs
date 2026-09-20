// PRESS RESOLUTION — does the flats' print file actually carry 300 DPI detail?
//
// Until 2026-09-20 the five flat calculators rendered an uploaded PDF exactly
// once, at pdf.js scale 2 (= 144 DPI), JPEG 0.85, and kept only that raster.
// generateApprovalPackage() then drew it into a 300 DPI canvas and re-encoded
// at 0.95. The result declares 300 DPI and carries 144 DPI of detail. Soft
// type, mushy line art, unreliable QR codes — and unrecoverable downstream.
//
// A test that only checked the output's pixel DIMENSIONS would have passed the
// whole time: they were always 300 DPI. So this measures DETAIL instead.
//
// The fixture carries a band of vertical bars at 75 line-pairs per inch:
//   - at 144 DPI one bar is 0.96 px  -> below Nyquist, aliases to flat grey
//   - at 300 DPI one bar is 2.0 px   -> resolves as alternating light/dark
// Upscaling is interpolation and cannot invent high-frequency detail, so the
// standard deviation of luminance across that band separates the two cleanly.
//
// Run:
//   PPS_CALC_PAGE=flat-postcard.html node tools-flat-print-dpi-test.mjs
//
// Env: PPS_PLAYWRIGHT, PPS_HARNESS_DIR (serves the harness), PPS_CALC_PAGE.
// Harness dir needs: <page>.html (via tools-harness-prep.mjs), theme-main.css,
// and node_modules with react, react-dom, jspdf, pdfjs-dist 4.10.38, pdf-lib.

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const PW_DIR   = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const HARNESS  = process.env.PPS_HARNESS_DIR || '';
const PAGE     = process.env.PPS_CALC_PAGE || 'flat-postcard.html';
const CHROME   = process.env.PPS_CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const PORT     = Number(process.env.PPS_PORT || 8139);

if (!HARNESS || !fs.existsSync(path.join(HARNESS, PAGE))) {
  console.error(`harness page not found: ${path.join(HARNESS, PAGE)}`);
  console.error('build it with: node tools-harness-prep.mjs dist/<calc>.html $HARNESS/<page>.html');
  process.exit(2);
}

const require = createRequire(path.join(PW_DIR, 'package.json'));
const { chromium } = require(PW_DIR);

// ── static server ───────────────────────────────────────────────────────────
const MIME = {
  '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript',
  '.css': 'text/css', '.json': 'application/json', '.map': 'application/json',
  '.wasm': 'application/wasm', '.pdf': 'application/pdf',
};
const srv = http.createServer((req, res) => {
  let f = path.join(HARNESS, decodeURIComponent(req.url.split('?')[0]));
  if (f.endsWith('/')) f += 'index.html';
  fs.readFile(f, (e, d) => {
    if (e) { res.writeHead(404); res.end('nope'); return; }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' });
    res.end(d);
  });
});
await new Promise(r => srv.listen(PORT, r));

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  console.log((cond ? 'PASS ' : 'FAIL ') + label + (detail ? '  ' + detail : ''));
  if (!cond) failed++;
};

const browser = await chromium.launch({ executablePath: CHROME });
const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
const pageErrors = [];
page.on('pageerror', e => pageErrors.push(e.message));

await page.goto(`http://127.0.0.1:${PORT}/${PAGE}`, { waitUntil: 'networkidle' });
await page.waitForTimeout(900);

// ── instrument: capture every image jsPDF is asked to place, and every
//    resolution pdf.js is asked to render at ────────────────────────────────
await page.evaluate(() => {
  window.__capturedImages = [];

  const hookJspdf = () => {
    const J = window.jspdf && window.jspdf.jsPDF;
    if (!J || J.__ppsHooked) return;
    const orig = J.API ? J.API.addImage : null;
    if (!orig) return;
    J.API.addImage = function (data, ...rest) {
      try { if (typeof data === 'string' && data.startsWith('data:image')) window.__capturedImages.push(data); } catch {}
      return orig.call(this, data, ...rest);
    };
    J.__ppsHooked = true;
  };
  hookJspdf();
  setInterval(hookJspdf, 200);   // jsPDF lazy-loads on approve

  // NOTE: pdf.js 4.x ships as an ES module, so window.pdfjsLib is a frozen
  // namespace — getDocument cannot be wrapped to spy on render scales. That is
  // fine: the band measurement below is black-box and cannot be faked by any
  // amount of upscaling, which makes it the better assertion anyway.
});

// ── fixture: a PDF with a 75 lp/in bar band ─────────────────────────────────
await page.addScriptTag({ path: path.join(HARNESS, 'node_modules/pdf-lib/dist/pdf-lib.min.js') });

const LP_PER_IN = 75;          // line pairs per inch in the fixture band
const PRINT_DPI_EXPECT = 300;  // what the flats' print canvas is built at
const DETAIL_SD_MIN = Number(process.env.PPS_DETAIL_SD_MIN || 70);
// Deliberately LARGER than any flat's default size, with the band spanning the
// whole sheet. At the default "crop" fit the art is placed at its native size —
// drawW = 300 × ed.wIn — so it lands 1:1 at 300 DPI whatever the product is,
// and the centre of the output always falls inside the band. That means one
// fixture works for all five calculators without knowing their default sizes.
const FIXTURE_W_IN = Number(process.env.PPS_FIXTURE_W || 12.5);
const FIXTURE_H_IN = Number(process.env.PPS_FIXTURE_H || 12.5);
const fixture = await page.evaluate(async ({ lp, wIn, hIn }) => {
  const { PDFDocument, rgb } = PDFLib;
  const doc = await PDFDocument.create();
  const W = wIn * 72, H = hIn * 72;
  const pg = doc.addPage([W, H]);
  pg.drawRectangle({ x: 0, y: 0, width: W, height: H, color: rgb(1, 1, 1) });

  // The measurement band: vertical bars over the entire sheet.
  const barPt = 72 / (lp * 2);            // one bar, in points
  for (let x = 0; x < W; x += barPt * 2) {
    pg.drawRectangle({ x, y: 0, width: barPt, height: H, color: rgb(0, 0, 0) });
  }

  const bytes = await doc.save();
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin);
}, { lp: LP_PER_IN, wIn: FIXTURE_W_IN, hIn: FIXTURE_H_IN });

// ── upload it ───────────────────────────────────────────────────────────────
// The artwork section is collapsed on load. Open it ONCE: the header row also
// carries a "Proof & Approve Online" chip that is itself a <button>, so a
// loose /Proof/ match here closes the section again instead of opening a proof.
await page.locator('button').filter({ hasText: /Artwork & Proofing/ }).first().click();
await page.waitForTimeout(700);

const inputs = await page.locator('input[type=file]').count();
ok('the page offers a file input', inputs > 0, `${inputs} found`);

const buf = Buffer.from(fixture, 'base64');
await page.locator('input[type=file]').first().setInputFiles({
  name: 'resolution-fixture.pdf', mimeType: 'application/pdf', buffer: buf,
});
await page.waitForTimeout(2500);

ok('the artwork was accepted',
   await page.locator('button').filter({ hasText: /Remove uploads/ }).count() > 0);

// ── open the proof, then approve ────────────────────────────────────────────
const proofBtn = page.locator('button').filter({ hasText: /Proof required|Review proof|Open proof/ }).first();
ok('a proof control is offered', await proofBtn.isVisible().catch(() => false));
await proofBtn.click();
await page.waitForTimeout(3000);

const clicked = await page.evaluate(async () => {
  // Tick any acknowledgment the product puts in front of Approve.
  for (const cb of document.querySelectorAll('input[type=checkbox]')) {
    const lab = (cb.closest('label') || {}).textContent || '';
    if (/understand|accept|acknowledg|approve|responsib|correct/i.test(lab) && !cb.checked) cb.click();
  }
  const btn = [...document.querySelectorAll('button')]
    .find(b => /^\s*(approve|approve artwork|approve & continue)/i.test((b.textContent || '').trim()));
  if (!btn) return { found: false, labels: [...document.querySelectorAll('button')].map(b => (b.textContent || '').trim()).filter(Boolean).slice(0, 30) };
  if (btn.disabled) return { found: true, disabled: true };
  btn.click();
  return { found: true };
});
ok('an Approve control is reachable', clicked.found && !clicked.disabled,
   clicked.found ? (clicked.disabled ? 'present but disabled' : '') : 'buttons seen: ' + JSON.stringify(clicked.labels));

// package generation is main-thread heavy; give it room
for (let i = 0; i < 60; i++) {
  const n = await page.evaluate(() => window.__capturedImages.length);
  if (n > 0) break;
  await page.waitForTimeout(500);
}

const imgs = await page.evaluate(() => window.__capturedImages.slice());
ok('a print-file page image was produced', imgs.length > 0, `${imgs.length} captured`);
if (!imgs.length) { await finish(); }

// ── measure ─────────────────────────────────────────────────────────────────
const measured = await page.evaluate(async ({ dataUrl }) => {
  const img = await new Promise((res, rej) => {
    const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = dataUrl;
  });
  const c = document.createElement('canvas');
  c.width = img.naturalWidth; c.height = img.naturalHeight;
  const ctx = c.getContext('2d');
  ctx.drawImage(img, 0, 0);

  // Sample several scanlines through the band and take the best standard
  // deviation — a single line could land badly on any phase.
  let best = 0, bestMean = 0;
  for (const fy of [0.42, 0.47, 0.5, 0.53, 0.58]) {
    const y = Math.round(c.height * fy);
    const x0 = Math.round(c.width * 0.25), x1 = Math.round(c.width * 0.75);
    const d = ctx.getImageData(x0, y, x1 - x0, 1).data;
    const lum = [];
    for (let i = 0; i < d.length; i += 4) lum.push(0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]);
    const mean = lum.reduce((a, b) => a + b, 0) / lum.length;
    const sd = Math.sqrt(lum.reduce((a, v) => a + (v - mean) ** 2, 0) / lum.length);
    if (sd > best) { best = sd; bestMean = mean; }
  }
  return { w: img.naturalWidth, h: img.naturalHeight, sd: best, mean: bestMean };
}, { dataUrl: imgs[0] });

console.log('');
console.log(`  print image      : ${measured.w} × ${measured.h} px`);
console.log(`  product size     : ${(measured.w / PRINT_DPI_EXPECT).toFixed(3)}" × ${(measured.h / PRINT_DPI_EXPECT).toFixed(3)}" incl. bleed`);
console.log(`  band contrast    : sd ${measured.sd.toFixed(1)} (mean luma ${measured.mean.toFixed(0)}), ${LP_PER_IN} lp/in`);
console.log('');

// Sanity only: the canvas must be big enough for the band measurement to mean
// anything. It was ALWAYS 300 DPI-shaped, before and after the fix, which is
// exactly why dimensions are not evidence of resolution.
ok('print canvas is large enough to measure',
   measured.w >= 600 && measured.h >= 300,
   `${measured.w} × ${measured.h} px`);

// The actual question. A 144 DPI source enlarged into this canvas cannot
// resolve a 75 lp/in band: interpolation does not create detail it never had.
// Measured on calc-postcard with this fixture, 2026-09-20:
//   pre-fix  (144 DPI raster, upscaled) -> sd 40.8   FAIL
//   post-fix (re-rendered from source)  -> sd 127.5  PASS
// The threshold sits between them with room either side for JPEG noise.
ok('fine detail SURVIVES into the print file',
   measured.sd >= DETAIL_SD_MIN,
   `sd ${measured.sd.toFixed(1)} (need >= ${DETAIL_SD_MIN}; upscaled 144 DPI measures ~41)`);

await finish();

async function finish() {
  ok('no uncaught page errors', pageErrors.length === 0, pageErrors.join(' | '));
  console.log('');
  console.log(`${checks} checks, ${failed} failed`);
  console.log(failed ? '>>> FLAT PRINT DPI FAILED' : '>>> FLAT PRINT DPI OK');
  await browser.close();
  srv.close();
  process.exit(failed ? 1 : 0);
}
