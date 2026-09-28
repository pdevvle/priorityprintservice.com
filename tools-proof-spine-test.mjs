// The stapled edge has to be marked wherever the customer and prepress look.
//
// The calculator's built-in modal draws the bound edge — a blue bar inside the
// trim, on the left of odd pages and the right of even ones — with a tick at
// each staple (one at the middle, or two at a third and two thirds), on the
// proof, in the magnifier and on every preview JPEG in the approval package.
// Until 2026-09-28 the standalone proofer drew the bar on the proof surface
// only: not in the magnifier, not on the previews, and no staples anywhere,
// because the job never said how many there were.
//
// Pins: the job's `staples` (1 | 2, from the calculator's twoStaplesApplied)
// puts the right number of ticks in the right places on the surface, in the
// lens and in PREVIEW_pNN.jpg, on the correct side of each page; the print file
// carries no guide at all; a bad staples value is refused.
//
// Needs the harness: node tools-proof-serve.mjs &
// Run: node tools-proof-spine-test.mjs

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

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});
const errors = [];
const JOB = { calc: 'saddle', trim: { w: 5.5, h: 8.5 }, bleed: 0.125, safety: 0.125, pages: 4,
              insideColor: 'color', coverColor: 'color', proof: 0 };

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
  await page.waitForFunction(() => window.__posts.some(m => m.type === 'pps-proof:ready'), null, { timeout: 20000 });
  // White art on every page, so any blue is a guide.
  await page.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 1725; c.height = 2625;
    const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
    const blob = await new Promise(r => c.toBlob(r, 'image/png'));
    for (let n = 1; n <= MODEL.pages.length; n++) await loadArt(n, new File([blob], 'p' + n + '.png', { type: 'image/png' }));
    renderAll();
  });
  return page;
}

// Blue-ish runs down a vertical band at x (fraction of width): returns the
// centre (as a fraction of height) of every run of staple-dark blue pixels.
const STAPLE_SCAN = `
  function scan(ctx, W, H, xFrac, halfBand, minStaple){
    const x0 = Math.max(0, Math.round(W * xFrac) - halfBand), w = halfBand * 2 + 1;
    const d = ctx.getImageData(x0, 0, Math.min(w, W - x0), H).data, cols = Math.min(w, W - x0);
    const barRows = [], stapleRows = [];
    for (let y = 0; y < H; y++){
      let bar = 0, staple = 0;
      for (let i = 0; i < cols; i++){
        const k = (y * cols + i) * 4, r = d[k], g = d[k+1], b = d[k+2];
        if (b > 150 && r < 120 && g < 170 && b - r > 80) bar++;
        if (b > 110 && b < 215 && r < 80 && g < 110 && b - r > 70) staple++;
      }
      // A staple is a tick several times the bar's width; requiring that many
      // staple-dark pixels in one row keeps JPEG fringing where the blue bar
      // meets the black trim line from reading as a staple.
      barRows.push(bar > 0); stapleRows.push(staple >= minStaple);
    }
    const runs = [];
    for (let y = 0; y < H; y++){
      if (!stapleRows[y]) continue;
      let e = y; while (e + 1 < H && stapleRows[e + 1]) e++;
      runs.push(((y + e) / 2) / H); y = e;
    }
    return { bar: barRows.filter(Boolean).length / H, staples: runs };
  }`;

async function previews(page) {
  await page.evaluate(() => {
    for (const id of ['agree', 'ackIssues']) { const el = document.getElementById(id); if (el && !el.checked && !el.disabled) el.click(); }
  });
  await page.click('#approveBtn');
  await page.waitForFunction(() => window.__posts.some(m => /pps-proof:approve(d|-failed)/.test(m.type)), null, { timeout: 180000 });
  return page.evaluate(async (SCAN) => {
    const scan = new Function('return ' + SCAN.trim())();
    const m = window.__posts.find(m => m.type === 'pps-proof:approved');
    if (!m) return { failed: true };
    const out = {};
    const bleedFrac = MODEL.bleed / BLEED_W;
    for (const f of m.files.filter(f => /^PREVIEW_p\d+\.jpg$/.test(f.name))) {
      const bmp = await createImageBitmap(f.blob);
      const c = document.createElement('canvas'); c.width = bmp.width; c.height = bmp.height;
      const x = c.getContext('2d'); x.drawImage(bmp, 0, 0);
      const L = scan(x, c.width, c.height, bleedFrac + 0.004, 10, 8);
      const R = scan(x, c.width, c.height, 1 - bleedFrac - 0.004, 10, 8);
      out[f.name] = { left: L, right: R };
    }
    // and the print file must carry no guides: page 1 of PRINT_READY, rendered
    const pr = m.files.find(f => f.name === 'PRINT_READY.pdf');
    const doc = await window.pdfjsLib.getDocument({ data: new Uint8Array(await pr.blob.arrayBuffer()) }).promise;
    const p1 = await doc.getPage(1);
    const vp = p1.getViewport({ scale: 1.5 });
    const c = document.createElement('canvas'); c.width = Math.ceil(vp.width); c.height = Math.ceil(vp.height);
    await p1.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
    const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
    let blue = 0; for (let k = 0; k < d.length; k += 4) if (d[k+2] > 150 && d[k] < 120 && d[k+2] - d[k] > 80) blue++;
    out.printBlue = blue;
    return out;
  }, STAPLE_SCAN);
}
const near = (list, want, tol = 0.03) => list.length === want.length && want.every((w, i) => Math.abs(list[i] - w) < tol);
// spineGuide geometry on a 5.75 x 8.75 bleed sheet, as fractions of height
const H = 8.75, y0 = (0.125 + H * 0.02) / H, len = (H - 0.25 - H * 0.04) / H;
const at = (fr) => fr.map(f => y0 + len * f);

for (const staples of [1, 2]) {
  console.log('\n── ' + staples + ' staple' + (staples === 1 ? '' : 's') + ' ──');
  const page = await open({ ...JOB, staples });
  const want = at(staples === 2 ? [0.33, 0.67] : [0.5]);

  const surface = await page.evaluate(() => ({
    bars: document.querySelectorAll('#sheet .g.spine').length,
    ticks: document.querySelectorAll('#sheet .g.staple').length,
    left: (document.querySelector('#sheet .g.spine') || { style: {} }).style.left || '',
  }));
  ok('the proof surface marks the stapled edge with ' + staples + ' tick' + (staples === 1 ? '' : 's'),
     surface.bars === 1 && surface.ticks === staples, JSON.stringify(surface));
  ok('…on the left of page 1', !!surface.left, JSON.stringify(surface));

  const lens = await page.evaluate((SCAN) => {
    const scan = new Function('return ' + SCAN.trim())();
    const sheet = document.getElementById('sheet');
    const r = composePage(1, 1100, Math.round(1100 * BLEED_H / BLEED_W));
    const g = spineGuide(1);
    const b = sheet.getBoundingClientRect();
    showLens(sheet, r, g.x / BLEED_W, g.staples[0] / BLEED_H, b.width * g.x / BLEED_W, b.height * g.staples[0] / BLEED_H);
    const lc = document.querySelector('#lens canvas');
    const x = lc.getContext('2d');
    // the bar starts at the trim line, which the lens centres on
    const res = scan(x, lc.width, lc.height, 0.5 + 0.01, 3 * LENS_Z, 12);
    hideLens();
    return res;
  }, STAPLE_SCAN);
  ok('the magnifier shows the bar and the staple under it', lens.bar > 0.9 && lens.staples.length === 1 && Math.abs(lens.staples[0] - 0.5) < 0.08,
     JSON.stringify(lens));

  const pv = await previews(page);
  ok('it approves', !pv.failed);
  const p1 = pv['PREVIEW_p01.jpg'], p2 = pv['PREVIEW_p02.jpg'];
  ok('PREVIEW_p01 has the bar on the LEFT with staples at ' + want.map(v => v.toFixed(2)).join(', '),
     p1 && p1.left.bar > 0.85 && near(p1.left.staples, want) && p1.right.bar < 0.05,
     JSON.stringify(p1));
  ok('PREVIEW_p02 has it on the RIGHT', p2 && p2.right.bar > 0.85 && near(p2.right.staples, want) && p2.left.bar < 0.05,
     JSON.stringify(p2));
  ok('the print file carries no guide', pv.printBlue === 0, pv.printBlue + ' blue pixels');
  await page.close();
}

console.log('\n── a bad staples value is refused ──');
{
  const page = await open(JOB);
  const errs = await page.evaluate(() => validateJob({ calc: 'saddle', trim: { w: 5.5, h: 8.5 }, pages: 8, staples: 3 }));
  ok('staples 3 is an error', errs.some(e => /staples/.test(e)), JSON.stringify(errs));
  await page.close();
}

ok('no page errors', errors.length === 0, errors.join('\n       '));
await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> PROOF SPINE FAILED' : '>>> PROOF SPINE OK');
process.exit(failed ? 1 : 0);
