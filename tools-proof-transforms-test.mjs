// The art controls a customer had in the built-in modal must exist in the
// proofer too, or switching surfaces takes a fix away from them.
//
// Two were missing (2026-09-28):
//   - "Head to spine" / "Foot to spine": rotate every page for the binding.
//     Facing pages are stapled on opposite edges, so landscape art turned the
//     same way on every page comes out upside down on half of them. The modal's
//     Rotate menu turns rectos and versos opposite ways; the proofer could only
//     copy one angle to all pages.
//   - Scale 10–300 %. The proofer's slider stopped at 50–200.
// Everything else the modal's FitToggle offers — crop, fit, fill, stretch,
// scale, a page's own rotation, rotate-all by one angle ("Apply to all pages"),
// undo — the proofer already had.
//
// Pins: the modal's angles are read out of the calculator source and the
// proofer must agree with them page by page; a landscape fixture with a red
// head band must end up with the band against the stapled edge (head to spine)
// or away from it (foot to spine) on BOTH a recto and a verso; the mode lets go
// of "Apply to all pages"; one undo puts it back; an anchored crop keeps its
// anchor when turned; the scale range is 10–300 and 10 % really is a tenth of
// 100 %. And (review, 2026-09-28) the orientation rule: the calculator turns
// every page 90° when a whole-file upload is sideways to the book, and the
// proofer did not — the rule is lifted out of the calculator source here.
//
// Needs the harness: node tools-proof-serve.mjs &
// Run: node tools-proof-transforms-test.mjs

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

/* ── the modal's rule, from the modal ── */
const CALC = readFileSync(path.join(HERE, 'calc-preview-test.html'), 'utf8');
const ra = CALC.slice(CALC.indexOf('const rotateAll = (mode) =>'), CALC.indexOf('const rotateAll = (mode) =>') + 1600);
const rectoRule = /const recto = i % 2 === 0;/.test(ra);
const spineM = ra.match(/mode === "spine"\) steps = recto \? (\d) : (\d);/);
const edgeM  = ra.match(/mode === "edge"\) steps = recto \? (\d) : (\d);/);
ok('the calculator\'s rotateAll is where this suite expects it', rectoRule && !!spineM && !!edgeM);
// modal index i is page n = i + 1, so recto (i even) is n odd
const modalSteps = (mode, n) => {
  const m = mode === 'spine' ? spineM : edgeM;
  return Number((n - 1) % 2 === 0 ? m[1] : m[2]);
};

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

// Landscape art, head-up: a red band along its TOP edge.
await page.evaluate(async () => {
  const c = document.createElement('canvas'); c.width = 2625; c.height = 1725;
  const x = c.getContext('2d');
  x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
  x.fillStyle = '#e00000'; x.fillRect(0, 0, c.width, 260);
  const blob = await new Promise(r => c.toBlob(r, 'image/png'));
  for (let n = 1; n <= MODEL.pages.length; n++) await loadArt(n, new File([blob], 'land' + n + '.png', { type: 'image/png' }));
  renderAll();
});

// Which edge of the composed page the red band ended up on.
const bandEdge = (n) => page.evaluate((n) => {
  const r = composePage(n, 300, Math.round(300 * BLEED_H / BLEED_W));
  const x = r.canvas.getContext('2d');
  const red = (px, py) => { const d = x.getImageData(px, py, 1, 1).data; return d[0] > 200 && d[1] < 60 && d[2] < 60; };
  const W = r.canvas.width, H = r.canvas.height, m = 6;
  return { left: red(m, H / 2), right: red(W - m, H / 2), top: red(W / 2, m), bottom: red(W / 2, H - m) };
}, n);
const side = (e) => Object.keys(e).filter(k => e[k]).join(',');

console.log('\n── Head to spine ──');
{
  await page.evaluate(() => {
    state.applyAll = true;
    state.openPage = 1; state.perPage[1].behavior = 'rotate';
    renderAll();
  });
  const pills = await page.evaluate(() => [...document.querySelectorAll('[data-bookrot]')].map(b => b.textContent));
  ok('the Rotate controls offer both binding modes', pills.join('|') === 'Head to spine|Foot to spine', JSON.stringify(pills));
  await page.click('[data-bookrot="spine"]');
  const st = await page.evaluate(() => ({ rot: MODEL.pages.map(p => state.perPage[p.n].rot), applyAll: state.applyAll,
    mode: bookRotMode(), on: (document.querySelector('[data-bookrot="spine"]') || {}).className }));
  const want = [1, 2, 3, 4, 5, 6, 7, 8].map(n => ['0°', '90°', '180°', '270°'][modalSteps('spine', n)]);
  ok('every page takes the calculator\'s angle for its side', JSON.stringify(st.rot) === JSON.stringify(want),
     'proofer ' + JSON.stringify(st.rot) + ' modal ' + JSON.stringify(want));
  ok('"Apply to all pages" lets go, or the next change would undo it', st.applyAll === false);
  ok('the pill shows the mode is on', st.mode === 'spine' && / on/.test(st.on || ''), st.mode + ' ' + st.on);
  const p1 = await bandEdge(1), p2 = await bandEdge(2);
  ok('page 1 (stapled left): the head of the art is at the spine', side(p1) === 'left', side(p1));
  ok('page 2 (stapled right): the head of the art is at the spine', side(p2) === 'right', side(p2));
}

console.log('\n── Foot to spine ──');
{
  await page.click('[data-bookrot="edge"]');
  const rot = await page.evaluate(() => MODEL.pages.map(p => state.perPage[p.n].rot));
  const want = [1, 2, 3, 4, 5, 6, 7, 8].map(n => ['0°', '90°', '180°', '270°'][modalSteps('edge', n)]);
  ok('every page takes the calculator\'s angle for its side', JSON.stringify(rot) === JSON.stringify(want),
     'proofer ' + JSON.stringify(rot) + ' modal ' + JSON.stringify(want));
  const p1 = await bandEdge(1), p2 = await bandEdge(2);
  ok('page 1: the head of the art is at the fore-edge', side(p1) === 'right', side(p1));
  ok('page 2: the head of the art is at the fore-edge', side(p2) === 'left', side(p2));
  await page.click('#undoBtn');
  const back = await page.evaluate(() => ({ rot: MODEL.pages.map(p => state.perPage[p.n].rot), mode: bookRotMode() }));
  ok('one undo goes back to head-to-spine', back.mode === 'spine', JSON.stringify(back));
}

console.log('\n── Scale range ──');
{
  const range = await page.evaluate(() => {
    const s = BEHAVIORS.scale.subs.find(x => x.type === 'slider');
    state.perPage[3] = { behavior: 'scale', anchor: 'Center', scale: 100, rot: '0°' };
    const a = placeArt(3, 1000, 1500, SRCget(3));
    state.perPage[3].scale = 10;
    const b = placeArt(3, 1000, 1500, SRCget(3));
    state.perPage[3].scale = 300;
    const c = placeArt(3, 1000, 1500, SRCget(3));
    state.perPage[3] = { behavior: 'scale', anchor: 'Center', scale: 100, rot: '0°' };
    state.openPage = 3; renderAll();
    const rng = document.querySelector('.scalerow input[type=range]');
    return { min: s.min, max: s.max, r10: b.drawW / a.drawW, r300: c.drawW / a.drawW,
             domMin: rng && rng.min, domMax: rng && rng.max };
  });
  ok('the slider runs 10–300 %, like the modal', range.min === 10 && range.max === 300 && range.domMin === '10' && range.domMax === '300',
     JSON.stringify(range));
  ok('10 % is a tenth of 100 %, and 300 % three times it',
     Math.abs(range.r10 - 0.1) < 1e-6 && Math.abs(range.r300 - 3) < 1e-6, range.r10 + ' ' + range.r300);
}

console.log('\n── Head to spine keeps an anchored crop where the customer put it ──');
{
  // Rotate is crop-with-an-angle, CENTRED. Switching an anchored crop over to it
  // would move the art as well as turn it (found in review, 2026-09-28).
  // Art bigger than the page once turned (10 x 8 in), so the anchor decides
  // what is cropped. The rail is closed: an open page's grid renames an anchor
  // that makes no difference, which would hide what this checks.
  const st = await page.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 3000; c.height = 2400;
    c.getContext('2d').fillRect(0, 0, 8, 8);
    await loadArt(3, new File([await new Promise(r => c.toBlob(r, 'image/png'))], 'big.png', { type: 'image/png' }));
    state.openPage = null;
    const at = (t) => { state.perPage[3] = t; const g = placeArt(3, 1000, Math.round(1000 * BLEED_H / BLEED_W), SRCget(3)); return [Math.round(g.dx), Math.round(g.dy)]; };
    const want    = at({ behavior: 'crop',   anchor: 'Top Left', scale: 100, rot: '270°' });
    const centred = at({ behavior: 'rotate', anchor: 'Center',   scale: 100, rot: '270°' });
    state.perPage[3] = { behavior: 'crop', anchor: 'Top Left', scale: 100, rot: '0°' };
    state.perPage[5] = { behavior: 'crop', anchor: 'Center', scale: 100, rot: '0°' };
    rotateBook('spine');
    const g = placeArt(3, 1000, Math.round(1000 * BLEED_H / BLEED_W), SRCget(3));
    return { p3: { ...state.perPage[3] }, got: [Math.round(g.dx), Math.round(g.dy)], want, centred, p5: { ...state.perPage[5] } };
  });
  ok('(the anchor matters for this art: anchored and centred placements differ)',
     JSON.stringify(st.want) !== JSON.stringify(st.centred), JSON.stringify(st.want) + ' vs ' + JSON.stringify(st.centred));
  ok('an anchored crop turns and stays where the customer anchored it',
     st.p3.behavior === 'crop' && st.p3.rot === '270°' && JSON.stringify(st.got) === JSON.stringify(st.want),
     JSON.stringify(st.p3) + ' at ' + JSON.stringify(st.got) + ', anchored ' + JSON.stringify(st.want));
  ok('a centred crop becomes Rotate, where its angle is shown', st.p5.behavior === 'rotate' && st.p5.rot === '270°',
     JSON.stringify(st.p5));
}

/* ── Orientation: the calculator turns a sideways file for you ──
   processFiles() in the calculator seeds every page at 90° when the file's first
   page and the book disagree on orientation by more than half an inch each way.
   The rule is lifted out of the calculator source, so the two cannot drift. */
const mm = CALC.match(/const artIsPortrait = hIn > wIn, bookIsPortrait = dims\.h > dims\.w;\s*const isMismatch = ([^;]+);/);
ok('the calculator\'s orientation rule is where this suite expects it', !!mm);
const modalMismatch = mm ? new Function('wIn', 'hIn', 'dims',
  'const artIsPortrait = hIn > wIn, bookIsPortrait = dims.h > dims.w; return (' + mm[1] + ');') : () => null;

async function jobWith(spec) {
  const p = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  p.on('pageerror', e => errors.push(String(e && e.message || e)));
  p.on('dialog', d => d.dismiss());
  await p.addInitScript(() => { window.__posts = []; window.PPS_PROOF_HOST = m => window.__posts.push(m); window.PPS_LIB_BASE = '/vendor/'; });
  await p.goto(PAGE, { waitUntil: 'domcontentloaded' });
  await p.waitForFunction(() => window.__posts.some(m => m.type === 'pps-proof:ready'), null, { timeout: 20000 });
  await p.evaluate(async ({ job, spec }) => {
    await ensureLibs();
    const { PDFDocument, rgb } = window.PDFLib;
    const pdf = async (wIn, hIn, n) => {
      const d = await PDFDocument.create();
      for (let i = 0; i < n; i++) d.addPage([wIn * 72, hIn * 72]).drawRectangle({ x: 0, y: 0, width: wIn * 72, height: hIn * 72, color: rgb(0.8, 0.9, 1) });
      return new File([await d.save()], 'art.pdf', { type: 'application/pdf' });
    };
    const png = async (wPx, hPx, name) => {
      const c = document.createElement('canvas'); c.width = wPx; c.height = hPx;
      c.getContext('2d').fillRect(0, 0, 4, 4);
      return new File([await new Promise(r => c.toBlob(r, 'image/png'))], name, { type: 'image/png' });
    };
    const files = [], slots = [];
    if (spec.pdf) files.push(await pdf(spec.pdf[0], spec.pdf[1], spec.pdf[2]));
    if (spec.pngs) for (let i = 0; i < spec.pngs[2]; i++) files.push(await png(spec.pngs[0], spec.pngs[1], 'p' + (i + 1) + '.png'));
    if (spec.slot) slots.push({ page: 3, file: await pdf(spec.slot[0], spec.slot[1], 1) });
    window.postMessage({ type: 'pps-proof:job', job, files, slots }, window.location.origin);
  }, { job: JOB, spec });
  await p.waitForFunction((n) => uploads.size >= n, spec.expect, { timeout: 60000 });
  await p.waitForTimeout(200);
  const rots = await p.evaluate(() => MODEL.pages.map(pg => state.perPage[pg.n].behavior + ' ' + state.perPage[pg.n].rot));
  await p.close();
  return rots;
}
const all = (rots, want) => rots.every(r => r === want);
const book = { w: JOB.trim.w, h: JOB.trim.h };

console.log('\n── A sideways file opens turned, as it does in the calculator ──');
{
  const land = await jobWith({ pdf: [11.25, 8.75, 8], expect: 8 });
  ok('(the calculator calls 11.25 x 8.75 in a 5.5 x 8.5 book a mismatch)', modalMismatch(11.25, 8.75, book) === true);
  ok('a landscape PDF for a portrait book: every page Rotate 90°', all(land, 'rotate 90°'), JSON.stringify(land));
  const imgs = await jobWith({ pngs: [3375, 2625, 8], expect: 8 });   // 11.25 x 8.75 in at 300 DPI
  ok('a landscape image set: every page Rotate 90°', all(imgs, 'rotate 90°'), JSON.stringify(imgs));
  const port = await jobWith({ pdf: [5.75, 8.75, 8], expect: 8 });
  ok('(and a portrait file is not a mismatch)', modalMismatch(5.75, 8.75, book) === false);
  ok('a portrait PDF is left upright', all(port, 'crop 0°'), JSON.stringify(port));
  const near = await jobWith({ pdf: [8.75, 8.5, 8], expect: 8 });
  ok('(within half an inch of square is not a mismatch either)', modalMismatch(8.75, 8.5, book) === false);
  ok('a near-square landscape PDF is left upright', all(near, 'crop 0°'), JSON.stringify(near));
  const slotOnly = await jobWith({ slot: [11.25, 8.75], expect: 1 });
  ok('a page-slot upload alone is not turned (the calculator seeds on whole-file upload only)',
     all(slotOnly, 'crop 0°'), JSON.stringify(slotOnly));
}

ok('no page errors', errors.length === 0, errors.join('\n       '));
await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> PROOF TRANSFORMS FAILED' : '>>> PROOF TRANSFORMS OK');
process.exit(failed ? 1 : 0);
