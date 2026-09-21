// The booklets' print file, measured — not read off the constant.
//
// tools-flat-print-dpi-test.mjs exists because the five flats said "300 DPI" in
// every manifest while shipping a 144 DPI preview blown up (order 87171). The
// booklets were then declared safe because their generator renders the PDF page
// at `DPI / 72`. That is the same kind of claim: a line of code, not a file. This
// suite makes the same measurement on the saddle, perfect-bound and coupon-book
// builds, driven like a customer with an 8-page PDF that carries a QR code at
// order 87171's module size (0.0133″, 4 px at 300 DPI) dead centre of every page.
// The art is 6″ × 9″ against a 5.75″ × 8.75″ bleed sheet, so — exactly as on
// order 87152 — the raw-file path is missed by 0.25″ and every page is rasterised
// through the print generator. Then the print-ready PDF the calculator uploads is
// pulled out of the mocked admin-ajax body, each side's JPEG decoded, and:
//   - the JPEG is the bleed sheet at 300 DPI,
//   - the QR decodes to that page's payload,
//   - the QR's modules are crisp (mid-grey share under 5 %; a 144 DPI upscale
//     measures ~36 %, a half-pixel placement ~20 %).
//
// Setup: parity-saddle.html, parity-pb.html and slot-coupon.html served on :8137
// (compiled builds prepared with tools-harness-prep.mjs; see the header of
// tools-order-e2e-test.mjs).
//
// Run: PPS_DEPS_DIR=<node_modules with jspdf, qrcode, jsqr, jpeg-js> node tools-book-print-dpi-test.mjs

const PW = '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const { createRequire } = await import('node:module');
const require = createRequire(import.meta.url);
const DEPS = process.env.PPS_DEPS_DIR || new URL('./node_modules', import.meta.url).pathname;
const fs = await import('fs');

const FILES = (process.env.PPS_BOOK_PAGES || 'parity-saddle.html,parity-pb.html,slot-coupon.html').split(',').map(s => s.trim()).filter(Boolean);
const DPI = 300;
const MODULE_IN = 4 / DPI;
const PAGES = 8;
const PAYLOAD = Array.from({ length: PAGES }, (_, i) => 'HTTPS://PRIORITYPRINTSERVICE.COM/GATE/87152/PAGE-' + (i + 1) + '/QR-FIDELITY');

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

const QR_MODULES = [];
{
  const { jsPDF } = require(DEPS + '/jspdf/dist/jspdf.node.min.js');
  const QR = require(DEPS + '/qrcode');
  const W = 6, H = 9;
  const doc = new jsPDF({ unit: 'in', format: [W, H], orientation: 'portrait' });
  PAYLOAD.forEach((text, i) => {
    if (i) doc.addPage([W, H], 'portrait');
    doc.setFillColor(255, 255, 255); doc.rect(0, 0, W, H, 'F');
    doc.setTextColor(0, 0, 0); doc.setFontSize(18); doc.text('PAGE ' + (i + 1), 0.6, 0.8);
    const q = QR.create(text, { errorCorrectionLevel: 'M' });
    const n = q.modules.size, side = n * MODULE_IN; QR_MODULES.push(n);
    const x0 = (W - side) / 2, y0 = (H - side) / 2;
    doc.setFillColor(0, 0, 0);
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) {
      if (q.modules.get(r, c)) doc.rect(x0 + c * MODULE_IN, y0 + r * MODULE_IN, MODULE_IN, MODULE_IN, 'F');
    }
  });
  fs.writeFileSync('./art-qr-book.pdf', Buffer.from(doc.output('arraybuffer')));
}

const jsQR = require(DEPS + '/jsqr');
const jpeg = require(DEPS + '/jpeg-js');

function partBytes(buf, nameRe) {
  const s = buf.toString('latin1');
  const m = nameRe.exec(s); if (!m) return null;
  const hdrEnd = s.indexOf('\r\n\r\n', m.index); if (hdrEnd < 0) return null;
  const start = hdrEnd + 4;
  const bnd = s.slice(0, s.indexOf('\r\n'));
  const end = s.indexOf('\r\n' + bnd, start); if (end < 0) return null;
  return buf.subarray(start, end);
}
function jpegStreams(pdf) {
  const out = []; let i = 0;
  while ((i = pdf.indexOf('/DCTDecode', i)) >= 0) {
    let s = pdf.indexOf('stream', i) + 6;
    if (pdf[s] === 13 && pdf[s + 1] === 10) s += 2; else if (pdf[s] === 10) s += 1;
    const e = pdf.indexOf('endstream', s);
    out.push(pdf.subarray(s, e)); i = e;
  }
  return out;
}
function measure(jpg, qrModules) {
  const img = jpeg.decode(jpg, { useTArray: true, formatAsRGBA: true, maxMemoryUsageInMB: 2048 });
  const cw = Math.round(img.width * 0.5), ch = Math.round(img.height * 0.4);
  const cx = Math.round((img.width - cw) / 2), cy = Math.round((img.height - ch) / 2);
  const data = new Uint8ClampedArray(cw * ch * 4);
  for (let y = 0; y < ch; y++) data.set(img.data.subarray(((cy + y) * img.width + cx) * 4, ((cy + y) * img.width + cx + cw) * 4), y * cw * 4);
  const r = jsQR(data, cw, ch);
  const n = qrModules * 4 + 16, x0 = Math.round((img.width - n) / 2), y0 = Math.round((img.height - n) / 2);
  let mid = 0;
  for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) {
    const o = ((y0 + y) * img.width + x0 + x) * 4;
    const g = 0.299 * img.data[o] + 0.587 * img.data[o + 1] + 0.114 * img.data[o + 2];
    if (g > 60 && g < 195) mid++;
  }
  return { width: img.width, height: img.height, text: r ? r.data : null, midGrey: mid / (n * n) };
}

const reorder = Buffer.from(JSON.stringify({ shipState: 'AZ', shipAddr: { name: 'Test', street1: '1 Main St', city: 'Phoenix', zip: '85087' } }))
  .toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const field = (body, name) => { const m = body.match(new RegExp('name="' + name + '"\\r\\n\\r\\n([^\\r]*)')); return m ? m[1] : null; };
const fileName = (body) => { const m = body.match(/name="artwork"; filename="([^"]*)"/); return m ? m[1] : null; };

async function run(b, file) {
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 } });
  const p = await ctx.newPage();
  const dialogs = []; p.on('dialog', async d => { dialogs.push(d.message()); await d.dismiss(); });
  const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0, 200)));
  let printReady = null, manifest = null; const uploads = [];

  await p.addInitScript((r) => {
    window.PPS_CONFIG = { ajaxUrl: 'http://127.0.0.1:8137/wp-admin/admin-ajax.php', cartUrl: 'http://127.0.0.1:8137/cart.html',
      cartNonce: 'cartBaked', uploadNonce: 'upBaked', productId: 33670, maxUpload: 200 * 1048576, reorder: r };
  }, reorder);
  await p.route('**/wp-admin/admin-ajax.php**', async (route) => {
    const req = route.request();
    const buf = req.postDataBuffer() || Buffer.alloc(0);
    const body = buf.toString('latin1');
    const action = field(body, 'action');
    if (action === 'pps_upload_artwork') {
      const name = fileName(body) || ''; uploads.push(name);
      if (/_print-ready\.pdf$/.test(name)) printReady = Buffer.from(partBytes(buf, /name="artwork"; filename="[^"]*_print-ready\.pdf"/));
      if (/_manipulation_manifest\.txt$/.test(name)) manifest = partBytes(buf, /name="artwork"; filename="[^"]*_manipulation_manifest\.txt"/).toString('utf8');
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { path: 'pps-artwork/2026/09/' + uploads.length + '-' + name } }) });
    }
    if (action === 'pps_add_to_cart') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { cart_item_key: 'k1' } }) });
    return route.fulfill({ status: 400, body: '0' });
  });
  await p.route('**/cart.html', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<h1 id="cart">CART</h1>' }));

  await p.goto('http://127.0.0.1:8137/' + file, { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('select'); await p.waitForTimeout(2500);
  await p.evaluate(() => { window.__manifests = 0; const OB = window.Blob; window.Blob = function (parts, opts) { const bl = new OB(parts, opts); if (opts && opts.type === 'text/plain') window.__manifests++; return bl; }; window.Blob.prototype = OB.prototype; });

  await p.locator('input[type=file]').first().setInputFiles('./art-qr-book.pdf');
  await p.waitForTimeout(9000);
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Proof required|Proof ✓|🔍|Review proof/i.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(2500);
  await p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input[type=checkbox]'); cb && cb.click(); });
  await p.waitForTimeout(300);
  const approveClicked = await p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /^\s*Approve (artwork|Now)\s*$/i.test(y.textContent || '')); if (!btn) return false; btn.click(); return true; });
  ok(`${file}: the Approve button was found`, approveClicked);
  let man = 0;
  for (let i = 0; i < 180 && man < 1; i++) { await p.waitForTimeout(1000); man = await p.evaluate(() => window.__manifests); }
  ok(`${file}: the approval package was generated`, man >= 1, 'dialogs=' + dialogs.join(' || ') + ' errs=' + errs.join(' || '));
  await p.waitForTimeout(1500);
  await p.keyboard.press('Escape'); await p.waitForTimeout(500);
  await p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim()));
    const b = btns.find(x => x.offsetParent !== null && !x.disabled) || btns.find(x => !x.disabled);
    b && b.click();
  });
  for (let i = 0; i < 120 && !(printReady && manifest); i++) await p.waitForTimeout(1000);
  ok(`${file}: the print-ready PDF and the manifest were uploaded`, !!(printReady && manifest), 'uploads=' + uploads.join(',') + ' dialogs=' + dialogs.join(' || '));
  await ctx.close();
  if (!(printReady && manifest)) return;
  fs.writeFileSync('./book-print-ready-' + file.replace(/\.html$/, '') + '.pdf', printReady);

  const bm = manifest.match(/Bleed-inclusive size: ([\d.]+)″ × ([\d.]+)″/);
  const sides = jpegStreams(printReady);
  ok(`${file}: the print-ready PDF carries one JPEG per page`, sides.length === PAGES, sides.length + ' streams');
  const expW = bm ? Math.round(parseFloat(bm[1]) * DPI) : null, expH = bm ? Math.round(parseFloat(bm[2]) * DPI) : null;
  sides.forEach((jpg, i) => {
    const r = measure(jpg, QR_MODULES[i] || QR_MODULES[0]);
    ok(`${file}: page ${i + 1} is the bleed sheet at ${DPI} DPI`, expW && r.width === expW && r.height === expH, `${r.width}×${r.height} expected ${expW}×${expH}`);
    ok(`${file}: page ${i + 1}'s QR code scans from the print-ready file`, r.text === PAYLOAD[i], 'decoded=' + JSON.stringify(r.text));
    ok(`${file}: page ${i + 1}'s QR modules are crisp`, r.midGrey < 0.05, 'mid-grey share ' + (r.midGrey * 100).toFixed(1) + '% (limit 5%)');
  });
}

const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
for (const f of FILES) { try { await run(b, f); } catch (e) { ok(`${f}: ran`, false, String(e && e.stack || e).slice(0, 400)); } }
await b.close();

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> BOOK PRINT DPI FAILED' : '>>> BOOK PRINT DPI OK');
process.exit(failed ? 1 : 0);
