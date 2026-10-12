// The back of a flat is the sheet turned over like a book page, left to right (owner,
// 2026-10-12). A vertical fold or perforation x in from the left edge of the front is therefore
// x in from the RIGHT edge of the back.
//
// Until 2026-10-12 the four flats with folds or perforations (brochure, postcard, greeting card,
// letterhead) drew the back's lines where the front has them: in the proof's Back view, its
// loupe, the back preview image and the downloadable template's back page. A trifold's back
// folds were 1/16 in out, a coupon perforation showed on the wrong end of the back, and dragging
// or typing a perforation's position in the Back view moved the real perforation to the mirror
// of where the customer put it. Positions are still stored as the front sees them; the back now
// shows and edits them as the back sees them.
//
// This drives each COMPILED build: in the proof, a perforation typed at 1.5 in on the Front reads
// (long edge − 1.5) on the Back, and one typed at 2 in on the Back comes back mirrored on the
// Front; then Download Template must draw that perforation (and a trifold's folds, whose inside
// flap is narrower) mirrored on its back page.
//
// Setup: flat-<calc>.html harness copies (tools-harness-prep.mjs) served on PPS_CALC_BASE
// (default http://127.0.0.1:8137); python3 with pymupdf for reading the template's lines.
// PPS_MIRROR_PAGES selects builds.
//
// Run: PPS_DEPS_DIR=<node_modules with jspdf> node tools-flat-back-mirror-test.mjs

import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const DEPS = process.env.PPS_DEPS_DIR || new URL('./node_modules', import.meta.url).pathname;
const BASE = process.env.PPS_CALC_BASE || 'http://127.0.0.1:8137';
const PAGES = (process.env.PPS_MIRROR_PAGES || 'flat-postcard.html,flat-brochure.html,flat-greeting-card.html,flat-letterhead.html').split(',').filter(Boolean);
// Template generator per build, and whether it takes fold arguments.
const TEMPLATE = { postcard: ['generatePostcardTemplate', true], brochure: ['generateBrochureTemplate', true], 'greeting-card': ['generateGreetingTemplate', true], letterhead: ['generateLetterheadTemplate', false] };

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};
const near = (a, b, tol = 0.02) => Math.abs(a - b) <= tol;

// A two-page 6 × 4 artwork, so the proof has a front and a back.
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'pps-mirror-'));
const ART = path.join(TMP, 'art-two-sides.pdf');
{
  const { jsPDF } = require(DEPS + '/jspdf/dist/jspdf.node.min.js');
  const doc = new jsPDF({ unit: 'in', format: [6.25, 4.25], orientation: 'landscape' });
  doc.setFillColor(240, 240, 240); doc.rect(0, 0, 6.25, 4.25, 'F'); doc.setFontSize(40); doc.text('FRONT', 2, 2.3);
  doc.addPage([6.25, 4.25], 'landscape');
  doc.setFillColor(220, 220, 255); doc.rect(0, 0, 6.25, 4.25, 'F'); doc.text('BACK', 2, 2.3);
  fs.writeFileSync(ART, Buffer.from(doc.output('arraybuffer')));
}

// The template's lines: x positions (in) of near-vertical strokes in a colour, per page.
function templateLines(pdfFile, rgb) {
  const py = `
import sys, json, pymupdf
d = pymupdf.open(sys.argv[1]); want = tuple(json.loads(sys.argv[2])); out = []
for pg in d:
    xs = set()
    for dr in pg.get_drawings():
        c = dr.get('color')
        if not c or any(abs(c[i] * 255 - want[i]) > 3 for i in range(3)): continue
        for it in dr['items']:
            if it[0] == 'l' and abs(it[1].x - it[2].x) < 0.01 and abs(it[1].y - it[2].y) > 2: xs.add(round(it[1].x / 72, 3))
    out.append(sorted(xs))
print(json.dumps(out))`;
  return JSON.parse(execFileSync('python3', ['-I', '-c', py, pdfFile, JSON.stringify(rgb)]).toString());
}

const b = await chromium.launch({ args: ['--no-sandbox'] });
for (const file of PAGES) {
  const calc = file.replace(/^flat-|\.html$/g, '');
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0, 200)));
  p.on('dialog', d => d.accept());
  await p.addInitScript(() => {
    window.PPS_CONFIG = { ajaxUrl: location.origin + '/wp-admin/admin-ajax.php', cartUrl: location.origin + '/cart.html', cartNonce: 'c', uploadNonce: 'u', productId: 1, maxUpload: 209715200 };
  });
  // 1 perforation line (the calculators' own option value) on a sheet folded the long way.
  await p.goto(BASE + '/' + file + '?perf=2000' + (TEMPLATE[calc] && TEMPLATE[calc][1] ? '&fold=trifold&folddir=long' : ''), { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('select'); await p.waitForTimeout(2500);

  // ── the proof ──
  await p.locator('input[type=file]').first().setInputFiles(ART);
  await p.waitForTimeout(5000);
  await p.evaluate(() => {
    const img = [...document.querySelectorAll('img')].find(i => /^data:image\/jpeg/.test(i.src));
    img && img.parentElement && img.parentElement.click();
  });
  await p.waitForTimeout(2000);
  const label = () => p.evaluate(() => { const s = [...document.querySelectorAll('span')].find(x => /^Perf 1 · [\d.]+"$/.test((x.textContent || '').trim())); return s ? parseFloat(s.textContent.split('·')[1]) : null; });
  const typeAt = async (v) => {
    await p.evaluate(() => { const s = [...document.querySelectorAll('span')].find(x => /^Perf 1 · [\d.]+"$/.test((x.textContent || '').trim())); s && s.click(); });
    await p.waitForTimeout(200);
    await p.locator('input[type=number][step="0.125"]').fill(String(v));
    await p.locator('input[type=number][step="0.125"]').press('Enter');
    await p.waitForTimeout(400);
  };
  const toSide = async (s) => { await p.evaluate((s) => { const bt = [...document.querySelectorAll('button')].filter(x => (x.textContent || '').trim() === s && x.offsetParent !== null); bt.length && bt[0].click(); }, s); await p.waitForTimeout(500); };
  const start = await label();
  ok(`${calc}: the proof shows the perforation`, start != null, String(start));
  if (start == null) { await ctx.close(); continue; }
  const long = start * 2;   // the default perforation sits mid-sheet
  await typeAt(1.5);
  ok(`${calc}: on the Front, the perforation is where it was typed (1.5 in)`, near(await label(), 1.5), String(await label()));
  await toSide('Back');
  const back = await label();
  ok(`${calc}: on the Back, the same perforation reads from the back's left edge (${long - 1.5} in)`, near(back, long - 1.5), back + ' (long edge ' + long + ')');
  await typeAt(2);
  await toSide('Front');
  ok(`${calc}: a position typed on the Back (2 in) is the mirror on the Front (${long - 2} in)`, near(await label(), long - 2), String(await label()));
  // ── the template, with the perforation where the proof left it (long − 2 in on the front) ──
  const [fn, folds] = TEMPLATE[calc] || [];
  const b64 = await p.evaluate(() => {
    let out = null;
    // jsPDF puts save() on each document, so the constructor is wrapped for the one click.
    const J = window.jspdf.jsPDF;
    window.jspdf.jsPDF = function (...a) { const d = new J(...a); d.save = () => { out = d.output('datauristring'); }; return d; };
    try { const bt = [...document.querySelectorAll('button')].find(x => /Download Template/.test(x.textContent || '')); bt && bt.click(); }
    finally { window.jspdf.jsPDF = J; }
    return out && out.split(',')[1];
  });
  ok(`${calc}: the template is made`, !!b64);
  if (b64) {
    const f = path.join(TMP, calc + '-template.pdf');
    fs.writeFileSync(f, Buffer.from(b64, 'base64'));
    const perf = templateLines(f, [139, 92, 246]);
    ok(`${calc}: template perforation at ${long - 2} in on the front page and ${2} in on the back page`,
      perf.length === 2 && perf[0].length === 1 && perf[1].length === 1 && near(perf[0][0], 0.125 + long - 2) && near(perf[1][0], 0.125 + 2), JSON.stringify(perf));
    if (folds) {
      const fold = templateLines(f, [245, 166, 35]);
      const front = fold[0] || [], back = fold[1] || [];
      const asymmetric = front.length === 2 && !near(front[0] - 0.125, long - (front[1] - 0.125), 0.005);
      ok(`${calc}: template trifold folds (narrow flap) on the back are the front's, mirrored`, asymmetric && back.length === 2 && front.every((x, i) => near(long + 0.25 - x, back[back.length - 1 - i], 0.005)), JSON.stringify(fold));
    }
  }
  ok(`${calc}: no page errors`, errs.length === 0, errs.join(' | '));
  await ctx.close();
}
await b.close();
fs.rmSync(TMP, { recursive: true, force: true });
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> FLAT BACK MIRROR FAILED' : '>>> FLAT BACK MIRROR OK');
process.exit(failed ? 1 : 0);
