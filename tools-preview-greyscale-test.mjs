let branch = '';
// The preview JPEGs show what the customer approved — including greyscale.
//
// Order 87154 (2026-09-11): greyscale interiors, approved grey on screen, and
// page 28 filed in the order's Drive folder in full colour. The simulation was a
// CSS filter on the <img> in the modal; the package generator drew the same
// pixels to a canvas and encoded them without it. So the deliverable whose job
// is to record "what the customer saw" recorded something else.
//
// This drives the compiled saddle calculator exactly as tools-parity-saddle.mjs
// does — production theme, real art, real approve — with Inside Printing set to
// Greyscale, and intercepts every canvas encode on the way out:
//   - preview JPEGs (toBlob, q0.85) must be grey on interior pages and colour
//     on the four cover pages, in page order;
//   - the print pages (toDataURL, q0.95, into jsPDF) must ALL stay colour —
//     baking grey into the press file would be the opposite bug.
// The manifest must say which pages are greyscale, so prepress can read it.
//
// Setup is the parity harness: parity-saddle.html served on :8137 (see the
// header of tools-parity-saddle.mjs).
//
// Run: node tools-preview-greyscale-test.mjs parity-saddle.html

const PW = '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const file = process.argv[2] || 'parity-saddle.html';

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

async function run(which) {
  branch = which;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0, 200)));
  await p.goto('http://127.0.0.1:8137/' + file, { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('select'); await p.waitForTimeout(2500);

  // Every canvas that leaves the page is classified at the moment it is encoded.
  // A page is "grey" when its centre region has no chroma; the guides drawn on
  // the previews are coloured lines at the edges, so the centre is what counts.
  await p.evaluate(() => {
    window.__encodes = []; window.__manifests = [];
    const chroma = (c) => {
      const w = c.width, h = c.height;
      const d = c.getContext('2d').getImageData(Math.round(w * 0.3), Math.round(h * 0.3),
                                                Math.round(w * 0.4), Math.round(h * 0.4)).data;
      let maxd = 0;
      for (let k = 0; k < d.length; k += 4) {
        const m = Math.max(d[k], d[k+1], d[k+2]), n = Math.min(d[k], d[k+1], d[k+2]);
        if (m - n > maxd) maxd = m - n;
      }
      return maxd;
    };
    const OB = HTMLCanvasElement.prototype.toBlob;
    HTMLCanvasElement.prototype.toBlob = function (cb, type, q) {
      if (type === 'image/jpeg') window.__encodes.push({ via: 'toBlob', q, w: this.width, h: this.height, chroma: chroma(this) });
      return OB.call(this, cb, type, q);
    };
    const OD = HTMLCanvasElement.prototype.toDataURL;
    HTMLCanvasElement.prototype.toDataURL = function (type, q) {
      if (type === 'image/jpeg' && q === 0.95) window.__encodes.push({ via: 'toDataURL', q, w: this.width, h: this.height, chroma: chroma(this) });
      return OD.call(this, type, q);
    };
    const OBlob = window.Blob;
    window.Blob = function (parts, opts) {
      const bl = new OBlob(parts, opts);
      if (opts && opts.type === 'text/plain') { window.__manifests.push(bl); }
      return bl;
    };
    window.Blob.prototype = OBlob.prototype;
  });

  // Greyscale interiors, colour cover — the shape of order 87154. The pill is
  // inside "Printing & Paper", which is collapsed (and unmounted) until opened.
  await p.evaluate(() => {
    const h = [...document.querySelectorAll('button[aria-expanded="false"]')]
      .find(x => /Printing & Paper/.test(x.textContent || ''));
    h && h.click();
  });
  await p.waitForTimeout(500);
  const picked = await p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => x.textContent.trim() === 'Greyscale');
    if (!btns.length) return 0;
    btns[0].click();          // Inside Printing comes before Cover Printing
    return btns.length;
  });
  ok('Inside Printing offers Greyscale, and it was selected', picked >= 1, picked + ' Greyscale pill(s)');
  await p.waitForTimeout(600);

  // An 8-page PDF, every page unmistakably coloured. A single image only ever
  // composes page 1, and the whole point is the interior pages.
  const { createRequire } = await import('node:module');
  const require = createRequire(import.meta.url);
  const DEPS = process.env.PPS_DEPS_DIR || new URL('./node_modules', import.meta.url).pathname;
  const { jsPDF } = require(DEPS + '/jspdf/dist/jspdf.node.min.js');
  const doc = new jsPDF({ unit: 'in', format: [5.75, 8.75], orientation: 'portrait' });
  for (let i = 0; i < 8; i++) {
    if (i) doc.addPage([5.75, 8.75], 'portrait');
    doc.setFillColor(27, 106, 201); doc.rect(0, 0, 5.75, 8.75, 'F');
    doc.setFillColor(201, 64, 27);  doc.rect(0, 4.4, 5.75, 4.35, 'F');
    doc.setFillColor(255, 224, 0);  doc.rect(1.4, 2.4, 3, 3.9, 'F');
    doc.setTextColor(255, 255, 255); doc.setFontSize(40); doc.text('PAGE ' + (i + 1), 1.2, 1.4);
  }
  const fs = await import('fs');
  fs.writeFileSync('./art-grey-8p.pdf', Buffer.from(doc.output('arraybuffer')));
  await p.locator('input[type=file]').first().setInputFiles('./art-grey-8p.pdf');
  await p.waitForTimeout(7000);

  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Proof required|Proof ✓|🔍|Review proof/i.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(2500);
  if (branch === 'composed') {
    // A transform forces the composed branch: every page is re-rendered through
    // composePageCanvas into a new print PDF, and the previews come off that.
    const picked = await p.evaluate(() => {
      // Fill lives in the modal's "Fit" <select>, not on a button.
      const sel = [...document.querySelectorAll('select')].find(s => [...s.options].some(o => /^fill/i.test(o.textContent.trim())));
      if (!sel) return null;
      const opt = [...sel.options].find(o => /^fill/i.test(o.textContent.trim()));
      const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set;
      setter.call(sel, opt.value);
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      return opt.value;
    });
    ok('a transform (Fill) was applied so the composed branch runs', !!picked, String(picked));
    await p.waitForTimeout(1500);
  }

  // The modal itself still greys the interior on screen — this is the behaviour
  // the previews are being brought into line with.
  const onScreen = await p.evaluate(() => {
    const frame = document.querySelector('.bp-checker .bp-checker') || document.querySelector('.bp-checker');
    const img = frame && frame.querySelector('img');
    return img ? getComputedStyle(img).filter : null;
  });
  ok('the modal shows the cover (page 1) in colour', onScreen === 'none', String(onScreen));

  // Approve: acknowledge whatever preflight flagged, then click.
  await p.evaluate(() => {
    const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || ''));
    const cb = ack && ack.querySelector('input[type=checkbox]');
    cb && cb.click();
  });
  await p.waitForTimeout(300);
  await p.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find(y => /Approve artwork/i.test(y.textContent || ''));
    btn && btn.click();
  });
  let man = 0;
  for (let i = 0; i < 90 && man < 1; i++) { await p.waitForTimeout(1000); man = await p.evaluate(() => window.__manifests.length); }
  ok('the package was generated', man >= 1, man + ' manifest(s)');

  const enc = await p.evaluate(() => window.__encodes);
  const previews = enc.filter(e => e.via === 'toBlob' && e.q === 0.85);
  const prints   = enc.filter(e => e.via === 'toDataURL');
  const pages = 8;   // the fixture's page count, and the calculator's default
  ok('one preview per page came out', previews.length === pages, previews.length + ' previews for ' + pages + ' pages');
  if (branch === 'composed') {
    ok('and one print page per page', prints.length === pages, prints.length + ' print pages');
  } else {
    // Untransformed PDF: the customer's own file IS the print file, untouched —
    // order 87154's path. Nothing is re-encoded for print, and the manifest says so.
    ok('the raw file was used as the print file, so nothing was re-encoded for print', prints.length === 0,
       prints.length + ' print encodes');
  }

  // Cover = pages 1, 2, N-1, N (the modal's isCoverPage). Everything else is interior.
  const isCover = i => i === 0 || i === 1 || i === pages - 2 || i === pages - 1;
  const GREY = 8, COLOUR = 40;   // JPEG-safe: grey has near-zero chroma, this art has ~180
  const wrongPrev = previews.map((e, i) => ({ i, e })).filter(({ i, e }) => isCover(i) ? e.chroma < COLOUR : e.chroma > GREY);
  ok('interior previews are grey, cover previews are colour',
     wrongPrev.length === 0,
     previews.map((e, i) => 'p' + (i + 1) + (isCover(i) ? '[cover]' : '') + ':' + e.chroma).join(' '));
  const wrongPrint = prints.filter(e => e.chroma < COLOUR);
  if (branch === 'composed') ok('every PRINT page keeps its colour — the press does the greyscale',
     wrongPrint.length === 0, prints.map((e, i) => 'p' + (i + 1) + ':' + e.chroma).join(' '));

  const manifest = await p.evaluate(async () => await window.__manifests[window.__manifests.length - 1].text());
  ok('the manifest records greyscale interiors', /Inside printing: Greyscale/.test(manifest));
  if (branch === 'raw') ok('and that the print file is the raw upload', /Print-ready PDF: SKIPPED/.test(manifest));
  ok('and a colour cover', /Cover printing: Full color/.test(manifest));
  ok('and says the previews reflect it while the print file does not',
     /greyscale pages rendered greyscale/.test(manifest) && /print file keeps its colour/.test(manifest));

  ok('no page errors', errs.length === 0, errs.join(' | '));
  await b.close();
}

// Both package branches: the raw file kept as the print file (no transforms),
// and every page re-composed (a transform applied). Each greys its previews
// through separate code, so each is exercised.
await run('raw');
await run('composed');

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> PREVIEW GREYSCALE FAILED' : '>>> PREVIEW GREYSCALE OK');
process.exit(failed ? 1 : 0);
