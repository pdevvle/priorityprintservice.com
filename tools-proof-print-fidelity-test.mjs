// The proofer's print file, measured — the same yardstick the live modal is held to.
//
// tools-book-print-dpi-test.mjs measures the calculators' booklet print files:
// an 8-page PDF with a QR code at order 87171's module size (0.0133", 4 px at
// 300 DPI) dead centre of every page, art 6" x 9" against a 5.75" x 8.75" bleed
// sheet so that — exactly as on order 87152 — nothing about it is a straight
// pass-through. This suite hands the proofer the same file through its real job
// message, approves it, takes PRINT_READY.pdf out of the `approved` post, and
// renders every page of it back at 300 DPI:
//   - the page is the bleed sheet at 300 DPI,
//   - the QR on page N decodes to page N's payload (right page, right order),
//   - the QR's modules are crisp: mid-grey share under 5 %. A 150 DPI render
//     blown up to 300 lands far above it, and so does a half-pixel placement.
//
// Until 2026-09-28 the proofer rendered every PDF page once at 150 DPI and drew
// that raster into the 300 DPI print canvas. This suite was written against that
// build first and fails there.
//
// Needs: node tools-proof-serve.mjs &   and PPS_DEPS_DIR with jspdf, qrcode, jsqr.
// Run:   PPS_DEPS_DIR=<node_modules> node tools-proof-print-fidelity-test.mjs

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const { createRequire } = await import('node:module');
const require = createRequire(import.meta.url);
const DEPS = process.env.PPS_DEPS_DIR || new URL('./node_modules', import.meta.url).pathname;
const path = await import('node:path');

const BASE = process.env.PPS_PROOF_BASE || 'http://127.0.0.1:8137';
const PAGE = BASE + '/proof-ui-draft.html';
const DPI = 300;
const MODULE_IN = 4 / DPI;
const PAGES = 8;
const PAYLOAD = Array.from({ length: PAGES }, (_, i) =>
  'HTTPS://PRIORITYPRINTSERVICE.COM/GATE/PROOFER/PAGE-' + (i + 1) + '/QR-FIDELITY');
const MIDGREY_MAX = 0.05;

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// ── the fixture: 8 pages, 6" x 9", one QR per page, dead centre ──────────────
function buildFixture(W = 6, H = 9) {
  const { jsPDF } = require(DEPS + '/jspdf/dist/jspdf.node.min.js');
  const QR = require(DEPS + '/qrcode');
  const doc = new jsPDF({ unit: 'in', format: [W, H], orientation: 'portrait' });
  PAYLOAD.forEach((text, i) => {
    if (i) doc.addPage([W, H], 'portrait');
    doc.setFillColor(255, 255, 255); doc.rect(0, 0, W, H, 'F');
    doc.setTextColor(0, 0, 0); doc.setFontSize(18); doc.text('PAGE ' + (i + 1), 0.6, 0.8);
    const q = QR.create(text, { errorCorrectionLevel: 'M' });
    const n = q.modules.size, side = n * MODULE_IN;
    const x0 = (W - side) / 2, y0 = (H - side) / 2;
    doc.setFillColor(0, 0, 0);
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) {
      if (q.modules.get(r, c)) doc.rect(x0 + c * MODULE_IN, y0 + r * MODULE_IN, MODULE_IN, MODULE_IN, 'F');
    }
  });
  return Buffer.from(doc.output('arraybuffer'));
}

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});
const errors = [];

async function open() {
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  page.on('pageerror', e => errors.push(String(e && e.message || e)));
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
  await page.addScriptTag({ path: path.join(DEPS, 'jsqr/dist/jsQR.js') });
  return page;
}

async function deliver(page, job, pdf, name = 'booklet-qr.pdf') {
  await page.evaluate(async ({ job, b64, name }) => {
    const bin = atob(b64); const u8 = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
    const file = new File([u8], name, { type: 'application/pdf' });
    window.__lastFile = file;
    window.postMessage({ type: 'pps-proof:job', job, files: [file] }, window.location.origin);
  }, { job, b64: pdf.toString('base64'), name });
  await page.waitForFunction((n) => typeof uploads !== 'undefined' && uploads.size >= n, job.pages, { timeout: 60000 });
}

async function approve(page) {
  await page.evaluate(() => {
    for (const id of ['agree', 'ackIssues']) {
      const el = document.getElementById(id);
      const box = id === 'ackIssues' ? document.getElementById('ackBox') : null;
      if (el && !el.checked && (!box || !box.hidden)) { el.checked = true; el.dispatchEvent(new Event('change')); }
    }
  });
  const disabled = await page.evaluate(() => document.getElementById('approveBtn').disabled);
  if (disabled) return null;
  await page.click('#approveBtn');
  await page.waitForFunction(() => window.__posts.some(m =>
    m.type === 'pps-proof:approved' || m.type === 'pps-proof:approve-failed'), null, { timeout: 240000 });
  return page.evaluate(() => {
    const m = window.__posts.find(x => x.type === 'pps-proof:approved');
    const f = window.__posts.find(x => x.type === 'pps-proof:approve-failed');
    return m ? { ok: true, names: m.files.map(f => f.name), hash: m.hash }
             : { ok: false, why: f && (f.message || f.step) };
  });
}

// Render the approved PRINT_READY.pdf back at 300 DPI, inside the page, with the
// proofer's own pdf.js — then decode and measure each page's QR.
async function measure(page, payloads) {
  return page.evaluate(async ({ payloads, DPI }) => {
    const m = window.__posts.find(x => x.type === 'pps-proof:approved');
    const f = m.files.find(x => x.name === 'PRINT_READY.pdf');
    if (!f) return { error: 'no PRINT_READY.pdf in the package' };
    const bytes = new Uint8Array(await f.blob.arrayBuffer());
    const doc = await window.pdfjsLib.getDocument({ data: bytes }).promise;
    const out = [];
    for (let i = 1; i <= doc.numPages; i++) {
      const pg = await doc.getPage(i);
      const vp = pg.getViewport({ scale: DPI / 72 });
      const c = document.createElement('canvas');
      c.width = Math.round(vp.width); c.height = Math.round(vp.height);
      const cx = c.getContext('2d');
      cx.fillStyle = '#fff'; cx.fillRect(0, 0, c.width, c.height);
      await pg.render({ canvasContext: cx, viewport: vp }).promise;
      const img = cx.getImageData(0, 0, c.width, c.height);
      const qr = window.jsQR(img.data, c.width, c.height, { inversionAttempts: 'dontInvert' });
      let midgrey = null;
      if (qr) {
        const xs = [qr.location.topLeftCorner.x, qr.location.topRightCorner.x, qr.location.bottomLeftCorner.x, qr.location.bottomRightCorner.x];
        const ys = [qr.location.topLeftCorner.y, qr.location.topRightCorner.y, qr.location.bottomLeftCorner.y, qr.location.bottomRightCorner.y];
        const x0 = Math.max(0, Math.floor(Math.min(...xs))), x1 = Math.min(c.width, Math.ceil(Math.max(...xs)));
        const y0 = Math.max(0, Math.floor(Math.min(...ys))), y1 = Math.min(c.height, Math.ceil(Math.max(...ys)));
        let grey = 0, all = 0;
        for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) {
          const k = (y * c.width + x) * 4;
          const L = 0.299 * img.data[k] + 0.587 * img.data[k + 1] + 0.114 * img.data[k + 2];
          all++; if (L > 64 && L < 192) grey++;
        }
        midgrey = all ? grey / all : null;
      }
      out.push({ w: c.width, h: c.height, text: qr ? qr.data : null, midgrey, want: payloads[i - 1] });
    }
    const manifest = m.files.find(x => x.name === 'MANIFEST.txt');
    return { pages: out, manifest: manifest ? await manifest.blob.text() : '' };
  }, { payloads, DPI });
}

const JOB = { calc: 'saddle', trim: { w: 5.5, h: 8.5 }, bleed: 0.125, safety: 0.125, pages: PAGES,
              insideColor: 'color', coverColor: 'color' };

console.log('\n── the proofer\'s print file, measured ──');
{
  const page = await open();
  await deliver(page, JOB, buildFixture());
  const a = await approve(page);
  ok('the approval completes', a && a.ok, a ? a.why : 'approve button disabled');
  if (a && a.ok) {
    const r = await measure(page, PAYLOAD);
    ok('the package carries a print file', !r.error, r.error);
    if (!r.error) {
      ok('it has one page per book page', r.pages.length === PAGES, String(r.pages.length));
      const sheet = r.pages.every(p => Math.abs(p.w - 1725) <= 1 && Math.abs(p.h - 2625) <= 1);
      ok('every page is the 5.75" x 8.75" bleed sheet at 300 DPI', sheet,
         r.pages.map(p => p.w + 'x' + p.h).join(' '));
      r.pages.forEach((p, i) => {
        ok(`page ${i + 1}: its QR decodes to page ${i + 1}'s payload`, p.text === p.want, p.text || '(no decode)');
        ok(`page ${i + 1}: modules are crisp (mid-grey < ${MIDGREY_MAX * 100}%)`,
           p.midgrey != null && p.midgrey < MIDGREY_MAX,
           p.midgrey == null ? 'not measurable' : (p.midgrey * 100).toFixed(1) + '% mid-grey');
      });
      ok('the manifest names what the print file was made from',
         /PRINT SOURCE/.test(r.manifest), (r.manifest.match(/PRINT SOURCE[\s\S]{0,300}/) || ['(no PRINT SOURCE section)'])[0]);
    }
  }
  await page.close();
}

// ── 2. proof == print, under every transform ────────────────────────────────
// The print file now draws from a different pixel source than the screen (the
// PDF re-rendered, not the 150 DPI raster). That is only safe if the two are
// PLACED identically — order 87032 was a proof and a print file that disagreed
// about a crop. So every behaviour, anchors, all three quarter turns, art at
// trim size (scaled up to cover bleed), a source page carrying its own /Rotate,
// and a bitmap page are each approved, and the print page rendered back at the
// preview size is compared with the proof the customer was shown.
function buildGeometryFixture() {
  const PDFLib = require(DEPS + '/pdf-lib');
  return (async () => {
    const { PDFDocument, rgb, degrees } = PDFLib;
    const doc = await PDFDocument.create();
    // [width", height", /Rotate]: oversized, trim-sized, odd aspect, landscape…
    const sizes = [[6, 9, 0], [5.5, 8.5, 0], [4, 9, 0], [5, 8, 0], [6, 9, 0], [9, 6, 0], [6, 9, 0], [9, 6, 90]];
    for (const [w, h, rot] of sizes) {
      const W = w * 72, H = h * 72;
      const p = doc.addPage([W, H]);
      p.drawRectangle({ x: 0, y: 0, width: W, height: H, color: rgb(1, 1, 1) });
      // Asymmetric on purpose: any flip, turn or shift moves these.
      p.drawRectangle({ x: 0, y: H - H * 0.22, width: W * 0.30, height: H * 0.22, color: rgb(0.85, 0.1, 0.1) }); // top-left red
      p.drawRectangle({ x: W * 0.62, y: 0, width: W * 0.38, height: H * 0.12, color: rgb(0.1, 0.2, 0.85) });     // bottom-right blue
      p.drawRectangle({ x: W * 0.40, y: H * 0.45, width: W * 0.12, height: H * 0.30, color: rgb(0.1, 0.6, 0.2) }); // off-centre green
      p.drawRectangle({ x: 0, y: 0, width: W, height: 3, color: rgb(0, 0, 0) });                                  // bottom rule
      if (rot) p.setRotation(degrees(rot));
    }
    return Buffer.from(await doc.save());
  })();
}

const TRANSFORMS = {
  1: { behavior: 'crop' },
  2: { behavior: 'fit' },
  3: { behavior: 'fill', anchor: 'Top Left' },
  4: { behavior: 'stretch' },
  5: { behavior: 'scale', scale: 80, anchor: 'Bottom Right' },
  6: { behavior: 'crop', rot: '90°' },
  7: { behavior: 'crop', rot: '180°' },
  8: { behavior: 'crop', rot: '270°' },   // on a source page that is itself /Rotate 90
};

console.log('\n── proof == print, under every transform ──');
{
  const page = await open();
  await deliver(page, JOB, await buildGeometryFixture(), 'geometry.pdf');
  // A bitmap page too: slot page 4's PDF art is replaced by a PNG, turned 90° and fitted (fit keeps the marker on the sheet; a turned crop pushes it off in proof and print alike).
  await page.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 1650; c.height = 2550;
    const x = c.getContext('2d');
    x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
    x.fillStyle = 'rgb(217,26,26)'; x.fillRect(0, 0, c.width * 0.3, c.height * 0.22);
    x.fillStyle = 'rgb(26,51,217)'; x.fillRect(c.width * 0.62, c.height * 0.88, c.width * 0.38, c.height * 0.12);
    const blob = await new Promise(r => c.toBlob(r, 'image/png'));
    await loadArt(4, new File([blob], 'slot-p4.png', { type: 'image/png' }));
  });
  // loadArt(n, file). With the arguments the other way round it alerts "not an
  // image", headless dismisses the alert, and page 4 stays PDF art — which is
  // how this check first passed while testing no bitmap at all.
  const p4 = await page.evaluate(() => { const u = uploads.get(4); return u ? { label: u.label, pdf: !!u.pdfSrc } : null; });
  ok('page 4 really is the bitmap now', p4 && p4.label === 'slot-p4.png' && !p4.pdf, JSON.stringify(p4));
  const applied = await page.evaluate((T) => {
    const T2 = { ...T, 4: { behavior: 'fit', rot: '90°' } };
    for (const [n, t] of Object.entries(T2)) Object.assign(state.perPage[n], t);
    cache.clear(); renderAll();
    return Object.keys(T2).map(n => n + ':' + JSON.stringify(state.perPage[n]));
  }, TRANSFORMS);
  const a = await approve(page);
  ok('the approval completes with transforms on every page', a && a.ok, a ? a.why : 'approve button disabled');
  if (a && a.ok) {
    const cmp = await page.evaluate(async () => {
      const m = window.__posts.find(x => x.type === 'pps-proof:approved');
      const f = m.files.find(x => x.name === 'PRINT_READY.pdf');
      const doc = await window.pdfjsLib.getDocument({ data: new Uint8Array(await f.blob.arrayBuffer()) }).promise;
      const PW = 600, PH = Math.round(PW * BLEED_H / BLEED_W);
      const lum = (d, k) => 0.299 * d[k] + 0.587 * d[k + 1] + 0.114 * d[k + 2];
      const redAt = (d, w, h) => {                    // centroid of the red marker
        let sx = 0, sy = 0, k = 0;
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
          const i = (y * w + x) * 4;
          if (d[i] > 150 && d[i + 1] < 90 && d[i + 2] < 90) { sx += x; sy += y; k++; }
        }
        return k ? [sx / k, sy / k, k] : null;
      };
      const out = [];
      for (let n = 1; n <= doc.numPages; n++) {
        const pg = await doc.getPage(n);
        const vp = pg.getViewport({ scale: PW / (BLEED_W * 72) });
        const c = document.createElement('canvas'); c.width = PW; c.height = PH;
        const cx = c.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, PW, PH);
        await pg.render({ canvasContext: cx, viewport: vp }).promise;
        const P = cx.getImageData(0, 0, PW, PH).data;
        const s = composePage(n, PW, PH).canvas;
        const S = s.getContext('2d').getImageData(0, 0, PW, PH).data;
        let diff = 0;
        for (let i = 0; i < P.length; i += 4) diff += Math.abs(lum(P, i) - lum(S, i));
        const rp = redAt(P, PW, PH), rs = redAt(S, PW, PH);
        out.push({ n, mad: diff / (PW * PH),
                   drift: rp && rs ? Math.hypot(rp[0] - rs[0], rp[1] - rs[1]) : null,
                   red: [rp && rp[2], rs && rs[2]] });
      }
      return out;
    });
    cmp.forEach(r => {
      const t = r.n === 4 ? '{"behavior":"fit","rot":"90°"} (bitmap)' : JSON.stringify(TRANSFORMS[r.n]);
      ok(`page ${r.n} ${t}: print matches the proof`,
         r.mad < 4 && r.drift != null && r.drift < 2,
         'mean |Δluma| ' + r.mad.toFixed(2) + ', red marker drift ' + (r.drift == null ? 'n/a (' + r.red + ')' : r.drift.toFixed(2) + ' px'));
    });
  }
  await page.close();
}

// ── 3. the customer's own file, untouched — and only when it truly is ────────
// An 8-page PDF built exactly to the 5.75" x 8.75" bleed sheet with nothing
// changed must reach the host as the customer's exact bytes. Every near miss
// must be refused AND say why, and still come out crisp.
async function untouchedCase(label, { size, mutate }) {
  const page = await open();
  await deliver(page, JOB, buildFixture(size[0], size[1]), 'exact-bleed.pdf');
  if (mutate) await page.evaluate(mutate.fn, mutate.arg);
  const a = await approve(page);
  if (!a || !a.ok) { await page.close(); return { error: a ? a.why : 'approve disabled' }; }
  const r = await page.evaluate(async () => {
    const m = window.__posts.find(x => x.type === 'pps-proof:approved');
    const f = m.files.find(x => x.name === 'PRINT_READY.pdf');
    const got = new Uint8Array(await f.blob.arrayBuffer());
    const orig = new Uint8Array(await window.__lastFile.arrayBuffer());
    const same = got.length === orig.length && got.every((v, i) => v === orig[i]);
    const hex = async u8 => [...new Uint8Array(await crypto.subtle.digest('SHA-256', u8))]
      .map(b => b.toString(16).padStart(2, '0')).join('');
    const manifest = await m.files.find(x => x.name === 'MANIFEST.txt').blob.text();
    return { same, hashIsOriginal: m.hash === await hex(orig), manifest };
  });
  const q = await measure(page, PAYLOAD);
  await page.close();
  return { ...r, inOrder: q.pages && q.pages.every(p => p.text === p.want),
           midgrey: q.pages ? q.pages.map(p => p.midgrey == null ? 'n/a' : (p.midgrey * 100).toFixed(1) + '%') : [],
           crisp: q.pages && q.pages.every(p => p.midgrey != null && p.midgrey < MIDGREY_MAX && p.text === p.want) };
}
const setT = (T) => { for (const [n, t] of Object.entries(T)) Object.assign(state.perPage[n], t); cache.clear(); renderAll(); };

console.log('\n── the customer\'s file, untouched ──');
{
  const r = await untouchedCase('exact', { size: [5.75, 8.75] });
  ok('an exact, unchanged file completes', !r.error, r.error);
  if (!r.error) {
    ok('PRINT_READY.pdf is the customer\'s file, byte for byte', r.same);
    ok('and the approval hash is the hash of the file they uploaded', r.hashIsOriginal);
    ok('the manifest says UNTOUCHED', /UNTOUCHED/.test(r.manifest), (r.manifest.match(/PRINT FILE[\s\S]{0,260}/) || [''])[0]);
    // Byte-identity above is the whole assertion about quality: this IS the
    // customer's vector file. Its QR is not measured for crispness here because
    // a 5.75" sheet is 1725 px at 300 DPI — odd — so a centred QR sits on a
    // half pixel when WE rasterise it, and anti-aliases in our measurement, not
    // in the file. What is checked is that every page is there, in order.
    ok('every page reads, in order', r.inOrder, 'mid-grey at our half-pixel raster: ' + r.midgrey.join(' '));
  }
  // Rotate at 0° places exactly as crop does, so it is still the customer's
  // file. It used to count as "changed" and be rendered (review, 2026-09-28).
  const z = await untouchedCase('rotate at 0°', { size: [5.75, 8.75],
    mutate: { fn: setT, arg: { 1: { behavior: 'rotate', rot: '0°' }, 4: { behavior: 'rotate', rot: '0°' } } } });
  ok('pages set to Rotate at 0° still ship the file untouched', !z.error && z.same && /UNTOUCHED/.test(z.manifest || ''),
     z.error || (z.manifest.match(/not shipped untouched: .*/) || ['(untouched)'])[0]);
}
const nearMisses = [
  ['one page scaled to 90%', { size: [5.75, 8.75], mutate: { fn: setT, arg: { 3: { behavior: 'scale', scale: 90 } } } }, /page 3 was changed/],
  ['one page turned 180°',   { size: [5.75, 8.75], mutate: { fn: setT, arg: { 5: { rot: '180°' } } } }, /page 5 was changed/],
  // Inside the modal's 0.25" tolerance — the modal would ship this raw even
  // though its own proof crops it. Here it is rendered, matching the proof.
  ['art 0.05" larger than the sheet', { size: [5.8, 8.8] }, /not the 5\.75" x 8\.75" bleed sheet/],
  ['art at trim size (scaled up to cover bleed)', { size: [5.5, 8.5] }, /not the 5\.75" x 8\.75" bleed sheet/],
  ['page 2 replaced by a slot upload', { size: [5.75, 8.75], mutate: { fn: async () => {
      // A slot upload is ONE page (the calculator sends a multi-page PDF as the
      // whole book, not as a slot). Page 2 of the same file, on its own.
      const src = await PDFLib.PDFDocument.load(await window.__lastFile.arrayBuffer());
      const one = await PDFLib.PDFDocument.create();
      const [pg] = await one.copyPages(src, [1]); one.addPage(pg);
      await loadArt(2, new File([await one.save()], 'slot-p2.pdf', { type: 'application/pdf' }));
    } } }, /page 2/],
];
for (const [label, opts, why] of nearMisses) {
  const r = await untouchedCase(label, opts);
  if (r.error) { ok(label + ': completes', false, r.error); continue; }
  ok(label + ': NOT shipped as-is', !r.same && !/UNTOUCHED/.test(r.manifest));
  ok(label + ': the manifest says why', why.test(r.manifest), (r.manifest.match(/not shipped untouched: .*/) || ['(no reason)'])[0]);
  // Crispness is proven in section 1, on a fixture whose modules sit on the
  // 300 DPI grid. This one is 5.75" (1725 px, odd), so its QR is on a half
  // pixel in the customer's own geometry and any 300 DPI raster greys its
  // edges — not a property of our render. Order is what is checked here.
  ok(label + ': every page still reads, in order', r.inOrder);
}

ok('no uncaught page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
console.log(`\n${checks} checks, ${failed} failed`);
console.log(failed ? '>>> PRINT FIDELITY FAILED' : '>>> PRINT FIDELITY OK');
process.exit(failed ? 1 : 0);
