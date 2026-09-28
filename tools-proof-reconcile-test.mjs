// A file whose page count differs from the order has to become the same book in
// the proofer as it does in the calculator.
//
// The calculator reconciles (the displayPages memo in calc-preview-test.html):
// the file's first page is the front cover and its LAST page is the back cover,
// whatever the count; a short file gets blanks where the customer chose — the
// end of the interior, or the inside covers first; a long one loses interior
// pages from the end and keeps its back cover. The customer is shown that, and
// asked where the blanks go, before they ever open a proof.
//
// Until 2026-09-28 the proofer filled forward from page 1 instead. A 10-page
// file in a 12-page book had its back cover printed as page 10 with the real
// back blank, and a 16-page file in the same book lost its back cover outright.
// The customer approved a different book from the one they had just been shown.
//
// This suite pins:
//   1. PARITY. The calculator's own reconciliation code is lifted out of the
//      calculator source and run against the proofer's reconcilePlan() for every
//      file length 1–70, every saddle page count 4–64 and both placements. The
//      two cannot drift apart without this failing.
//   2. Real files land where the plan says: PDFs short (both placements) and
//      long, and a set of images.
//   3. The added blanks are reported as what they are — a warn-level "added"
//      finding (so the acknowledgment covers it), not the "no artwork supplied"
//      error — and the page-count check and the manifest say what happened.
//   4. A job with per-page uploads is NOT reconciled, matching the calculator,
//      whose page grid is already the book's length once a slot is used.
//
// Needs: node tools-proof-serve.mjs &   and PPS_DEPS_DIR with pdf-lib.
// Run:   PPS_DEPS_DIR=<node_modules> node tools-proof-reconcile-test.mjs

import { readFileSync } from 'node:fs';
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

/* ── the calculator's reconciliation, taken from the calculator ── */
const CALC_SRC = readFileSync(path.join(HERE, 'calc-preview-test.html'), 'utf8');
const OPEN  = 'const { displayPages, reconcileInfo } = useMemo(() => {';
const CLOSE = '}, [effectivePages, pageCount, blankPage, blankPlacement]);';
const a = CALC_SRC.indexOf(OPEN), b = CALC_SRC.indexOf(CLOSE, a);
if (a < 0 || b < 0) { console.log('FAIL could not find the calculator\'s reconciliation memo'); process.exit(1); }
const modalReconcile = new Function('effectivePages', 'pageCount', 'blankPage', 'blankPlacement', 'pageLabel',
  CALC_SRC.slice(a + OPEN.length, b));
const BLANK = 'data:blank';
function modalMap(raw, target, placement) {
  const pages = Array.from({ length: raw }, (_, i) => ({ src: i, dataUrl: 'p' + i }));
  const r = modalReconcile(pages, target, BLANK, placement, (i) => 'L' + i);
  return { map: r.displayPages.map(p => (p.isBlank ? null : p.src)), info: r.reconcileInfo };
}

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});
const errors = [];

async function open() {
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  page.on('pageerror', e => errors.push(String(e && e.message || e)));
  page.on('dialog', d => { errors.push('dialog: ' + d.message()); d.dismiss(); });
  await page.route('**/*', route => {
    const u = route.request().url();
    if (u.startsWith(BASE) || u.startsWith('data:') || u.startsWith('blob:')) return route.continue();
    return route.fulfill({ status: 204, body: '' });
  });
  await page.addInitScript(() => {
    window.__posts = [];
    window.PPS_PROOF_HOST = m => window.__posts.push(m);
    window.PPS_LIB_BASE = '/vendor/';
  });
  await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => window.__posts.some(m => m.type === 'pps-proof:ready'), null, { timeout: 20000 });
  return page;
}

console.log('\n── the proofer and the calculator reconcile identically ──');
{
  const page = await open();
  const cases = [];
  for (let target = 4; target <= 64; target += 4)
    for (let raw = 1; raw <= 70; raw++)
      for (const placement of [null, 'end', 'covers']) cases.push([raw, target, placement]);
  const mine = await page.evaluate((cases) => cases.map(([raw, target, pl]) => {
    const p = reconcilePlan(raw, target, pl);
    return { map: p.map, type: p.info ? p.info.type : null, count: p.info ? p.info.count : 0,
             blanks: p.info ? p.info.blanks : [], dropped: p.info ? p.info.dropped : [] };
  }), cases);
  const bad = [];
  let blankMiscount = 0, dropMiscount = 0;
  cases.forEach(([raw, target, pl], i) => {
    const m = modalMap(raw, target, pl), p = mine[i];
    const same = JSON.stringify(m.map) === JSON.stringify(p.map)
      && (m.info ? m.info.type : null) === p.type
      && (m.info ? m.info.count : 0) === p.count;
    if (!same && bad.length < 5) bad.push(raw + '→' + target + ' ' + pl + ': calc ' + JSON.stringify(m.map) + ' proofer ' + JSON.stringify(p.map));
    else if (!same) bad.push('…');
    // the page lists the proofer reports must agree with its own map
    const nulls = p.map.map((v, k) => v === null ? k + 1 : 0).filter(Boolean);
    if (p.type === 'padded' && JSON.stringify(nulls) !== JSON.stringify(p.blanks)) blankMiscount++;
    if (p.type === 'trimmed') {
      const used = new Set(p.map);
      const missing = []; for (let k = 0; k < raw; k++) if (!used.has(k)) missing.push(k + 1);
      if (JSON.stringify(missing) !== JSON.stringify(p.dropped)) dropMiscount++;
    }
  });
  ok('reconcilePlan matches the calculator on all ' + cases.length + ' combinations', bad.length === 0, bad.slice(0, 5).join('\n       '));
  ok('its list of added blank pages is exactly the blank slots in its map', blankMiscount === 0, blankMiscount + ' disagree');
  ok('its list of dropped file pages is exactly the pages its map leaves out', dropMiscount === 0, dropMiscount + ' disagree');
  // Sanity on the extracted code itself: a harness that returned nothing would
  // agree with anything.
  const probe = modalMap(10, 12, 'end');
  ok('(the lifted calculator code really runs: 10 pages into 12 keeps the back cover last)',
     probe.map[11] === 9 && probe.map[9] === null && probe.info && probe.info.type === 'padded',
     JSON.stringify(probe.map));
  await page.close();
}

/* ── real files ─────────────────────────────────────────────── */
async function numberedPdf(n, wIn = 5.75, hIn = 8.75) {
  const doc = await PDFDocument.create();
  const font = await doc.embedFont(StandardFonts.HelveticaBold);
  for (let i = 1; i <= n; i++) {
    const p = doc.addPage([wIn * 72, hIn * 72]);
    p.drawRectangle({ x: 0, y: 0, width: wIn * 72, height: hIn * 72, color: rgb(0.9, 0.95, 1) });
    p.drawText('FILE PAGE ' + i, { x: 60, y: hIn * 36, size: 28, font, color: rgb(0.1, 0.1, 0.3) });
  }
  return Array.from(await doc.save());
}

const JOB = { calc: 'saddle', trim: { w: 5.5, h: 8.5 }, bleed: 0.125, safety: 0.125, pages: 12,
              insideColor: 'color', coverColor: 'color' };

async function deliverPdf(page, job, bytes, slots = []) {
  await page.evaluate(async ({ job, bytes, slots }) => {
    const file = new File([new Uint8Array(bytes)], 'booklet.pdf', { type: 'application/pdf' });
    const s = [];
    for (const n of slots) {
      const c = document.createElement('canvas'); c.width = 1725; c.height = 2625;
      const x = c.getContext('2d'); x.fillStyle = '#c9401b'; x.fillRect(0, 0, c.width, c.height);
      const blob = await new Promise(r => c.toBlob(r, 'image/png'));
      s.push({ page: n, file: new File([blob], 'slot_' + n + '.png', { type: 'image/png' }) });
    }
    window.__lastFile = file;
    window.postMessage({ type: 'pps-proof:job', job, files: [file], slots: s }, window.location.origin);
  }, { job, bytes, slots });
}
async function waitPlaced(page, n) {
  await page.waitForFunction((n) => uploads.size >= n, n, { timeout: 60000 });
  await page.waitForTimeout(150);
}
// book page -> file page (1-based) or 'img:<name>' or null
const layout = (page) => page.evaluate(() => MODEL.pages.map(pg => {
  const u = uploads.get(pg.n);
  if (!u) return null;
  return u.pdfSrc ? u.pdfSrc.pageIndex + 1 : 'img:' + u.label;
}));
const findings = (page) => page.evaluate(() => {
  invalidateFlags();
  const f = jobFlags();
  const pc = runFileChecks().find(c => c.id === 'pages');
  return {
    added: f.all.filter(i => i.kind === 'added').map(i => i.n),
    addedLevel: [...new Set(f.all.filter(i => i.kind === 'added').map(i => i.level))],
    noart: f.all.filter(i => i.kind === 'noart').map(i => i.n),
    addedText: (f.all.find(i => i.kind === 'added') || {}).text || '',
    pagesCheck: pc ? pc.state + ': ' + pc.text : '',
  };
});
async function approve(page) {
  await page.evaluate(() => {
    for (const id of ['agree', 'ackIssues']) {
      const el = document.getElementById(id);
      if (el && !el.checked && !el.disabled) el.click();
    }
  });
  const before = await page.evaluate(() => window.__posts.length);
  await page.click('#approveBtn');
  await page.waitForFunction((b) => window.__posts.slice(b).some(m => m.type === 'pps-proof:approved' || m.type === 'pps-proof:approve-failed'),
    before, { timeout: 180000 });
  return page.evaluate(async (b) => {
    const m = window.__posts.slice(b).find(m => m.type === 'pps-proof:approved' || m.type === 'pps-proof:approve-failed');
    if (m.type !== 'pps-proof:approved') return { failed: m };
    const man = (m.files || []).find(f => /MANIFEST/i.test(f.name));
    return { manifest: man ? await (man.blob || man).text() : '' };
  }, before);
}

console.log('\n── a short PDF, blanks at the end (the calculator\'s default) ──');
{
  const page = await open();
  await deliverPdf(page, JOB, await numberedPdf(10));
  await waitPlaced(page, 10);
  const L = await layout(page);
  ok('front cover is file page 1 and the back cover is file page 10',
     L[0] === 1 && L[11] === 10, JSON.stringify(L));
  ok('the two blanks are the last interior pages, 10 and 11',
     JSON.stringify(L) === JSON.stringify([1, 2, 3, 4, 5, 6, 7, 8, 9, null, null, 10]), JSON.stringify(L));
  const f = await findings(page);
  ok('added blanks are flagged as added, not as missing art', JSON.stringify(f.added) === '[10,11]' && f.noart.length === 0,
     'added ' + JSON.stringify(f.added) + ' noart ' + JSON.stringify(f.noart));
  ok('…at warn level, so the acknowledgment covers them', JSON.stringify(f.addedLevel) === '["warn"]', JSON.stringify(f.addedLevel));
  ok('…and say where they were put and why', /10 pages/.test(f.addedText) && /12/.test(f.addedText) && /end of the book/.test(f.addedText), f.addedText);
  ok('the page-count check warns and names the pages', /^warn: /.test(f.pagesCheck) && /pages 10, 11/.test(f.pagesCheck), f.pagesCheck);
  const r = await approve(page);
  ok('it approves', !r.failed, r.failed ? JSON.stringify(r.failed) : '');
  const man = r.manifest || '';
  ok('the manifest records the reconciliation', /PAGE COUNT/.test(man) && /the file has 10 pages; the booklet has 12/.test(man)
     && /2 blank pages added at the end of the interior/.test(man), (man.match(/PAGE COUNT[\s\S]*?\n\n/) || [''])[0]);
  ok('…and lists the added blanks apart from missing art', /pages 10, 11 — added to make up a short file/.test(man)
     && !/no artwork was supplied/.test(man));
  ok('the print file takes page 12 from file page 10', /page 12 {2}re-rendered from "booklet\.pdf[^"]*" page 10 /.test(man),
     (man.match(/ {2}page 12 {2}[^\n]*/) || [''])[0]);
  ok('it is not shipped as the untouched original (pages were added)', /not shipped untouched/.test(man));
  await page.close();
}

console.log('\n── a short PDF, blanks on the inside covers ──');
{
  const page = await open();
  await deliverPdf(page, { ...JOB, blankPlacement: 'covers' }, await numberedPdf(10));
  await waitPlaced(page, 10);
  const L = await layout(page);
  ok('inside front and inside back are the blanks; everything else in order',
     JSON.stringify(L) === JSON.stringify([1, null, 2, 3, 4, 5, 6, 7, 8, 9, null, 10]), JSON.stringify(L));
  const f = await findings(page);
  ok('the finding says inside covers', /inside covers/.test(f.addedText), f.addedText);
  await page.close();
}

console.log('\n── a very short PDF on the inside covers: the overflow works backwards from the end ──');
{
  const page = await open();
  await deliverPdf(page, { ...JOB, blankPlacement: 'covers' }, await numberedPdf(7));
  await waitPlaced(page, 7);
  const L = await layout(page);
  ok('7 pages into 12: blanks on 2, 11, 10, 9, 8',
     JSON.stringify(L) === JSON.stringify([1, null, 2, 3, 4, 5, 6, null, null, null, null, 7]), JSON.stringify(L));
  await page.close();
}

console.log('\n── a long PDF keeps its back cover ──');
{
  const page = await open();
  await deliverPdf(page, JOB, await numberedPdf(16));
  await waitPlaced(page, 12);
  const L = await layout(page);
  ok('16 pages into 12: file pages 1–11 and then 16 as the back cover',
     JSON.stringify(L) === JSON.stringify([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 16]), JSON.stringify(L));
  const f = await findings(page);
  ok('the page-count check warns and names the pages left out', /^warn: /.test(f.pagesCheck) && /pages 12–15 of your file/.test(f.pagesCheck), f.pagesCheck);
  ok('no page is flagged blank', f.added.length === 0 && f.noart.length === 0);
  const r = await approve(page);
  ok('it approves', !r.failed, r.failed ? JSON.stringify(r.failed) : '');
  ok('the manifest names what will not print', /NOT PRINTED: pages 12–15 of the file/.test(r.manifest || ''),
     ((r.manifest || '').match(/PAGE COUNT[\s\S]*?\n\n/) || [''])[0]);
  await page.close();
}

console.log('\n── a set of images is a book too ──');
{
  const page = await open();
  await page.evaluate(async (job) => {
    const mk = async (name, colour) => {
      const c = document.createElement('canvas'); c.width = 1725; c.height = 2625;
      const x = c.getContext('2d'); x.fillStyle = colour; x.fillRect(0, 0, c.width, c.height);
      const blob = await new Promise(r => c.toBlob(r, 'image/png'));
      return new File([blob], name, { type: 'image/png' });
    };
    // Out of order on purpose: pages are taken in name order, numerically.
    const files = [await mk('p10.png', '#333'), await mk('p1.png', '#c00'), await mk('p2.png', '#0c0')];
    window.postMessage({ type: 'pps-proof:job', job: { ...job, pages: 8 }, files, slots: [] }, window.location.origin);
  }, JOB);
  await waitPlaced(page, 3);
  const L = await layout(page);
  ok('3 images into 8: p1 front, p2 inside, p10 the back cover',
     JSON.stringify(L) === JSON.stringify(['img:p1.png', 'img:p2.png', null, null, null, null, null, 'img:p10.png']), JSON.stringify(L));
  await page.close();
}

console.log('\n── an exact file is left alone ──');
{
  const page = await open();
  await deliverPdf(page, JOB, await numberedPdf(12));
  await waitPlaced(page, 12);
  const L = await layout(page);
  ok('12 into 12 is page for page', JSON.stringify(L) === JSON.stringify([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]), JSON.stringify(L));
  const f = await findings(page);
  ok('and the page-count check passes', /^pass: /.test(f.pagesCheck), f.pagesCheck);
  await page.close();
}

console.log('\n── per-page uploads: not reconciled, as in the calculator ──');
{
  const page = await open();
  await deliverPdf(page, JOB, await numberedPdf(10), [5]);
  await page.waitForFunction(() => uploads.size >= 10 && uploads.get(5) && !uploads.get(5).pdfSrc, null, { timeout: 60000 });
  const L = await layout(page);
  ok('the file fills from page 1 and the slot wins on page 5',
     JSON.stringify(L) === JSON.stringify([1, 2, 3, 4, 'img:slot_5.png', 6, 7, 8, 9, 10, null, null]), JSON.stringify(L));
  const f = await findings(page);
  ok('the empty pages are missing art, not added blanks', JSON.stringify(f.noart) === '[11,12]' && f.added.length === 0,
     'noart ' + JSON.stringify(f.noart) + ' added ' + JSON.stringify(f.added));
  await page.close();
}

console.log('\n── a bad placement value is refused, not defaulted ──');
{
  const page = await open();
  const errs = await page.evaluate(() => validateJob({ calc: 'saddle', trim: { w: 5.5, h: 8.5 }, pages: 8, blankPlacement: 'middle' }));
  ok('blankPlacement "middle" is an error', errs.some(e => /blankPlacement/.test(e)), JSON.stringify(errs));
  await page.close();
}

const pageErrors = errors.filter(e => !/^dialog: /.test(e));
const dialogs = errors.filter(e => /^dialog: /.test(e));
ok('no page errors', pageErrors.length === 0, pageErrors.join('\n       '));
ok('no alerts popped at the customer', dialogs.length === 0, dialogs.join('\n       '));
await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> PROOF RECONCILE FAILED' : '>>> PROOF RECONCILE OK');
process.exit(failed ? 1 : 0);
