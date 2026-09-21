// The print-ready PDF a flat product ships must carry what the customer uploaded
// at print resolution — not the screen preview blown up.
//
// Found 2026-09-21 on order 87171 (accordion brochure): the QR code on the
// printed piece would not scan. The five flats — brochure, postcard, letterhead,
// greeting card, sticker — rendered an uploaded PDF exactly once, at upload, as
// a 144 DPI JPEG (pdf.js scale 2, quality 0.85) for the on-screen proof, and
// generateApprovalPackage() then drew THAT onto its 300 DPI canvas. The manifest
// said "300 DPI"; the pixels were 144 DPI upscaled 2.08×. A QR module of 0.013″
// is under two pixels at 144 DPI, so it was unscannable before the file ever
// reached the press. The three booklets never had this: their generator renders
// the PDF page at DPI / 72.
//
// This drives each COMPILED flat build like a customer: a two-page PDF with a
// QR code at the customer's module size (0.0133″, 4 px at 300 DPI) is dropped
// on the real uploader, approved in the real modal, and Added to Order against
// a mocked admin-ajax. The print-ready PDF it uploads is pulled out of the
// multipart body, its JPEG decoded, and the QR must scan — on both sides — and
// the JPEG must be the bleed sheet at 300 DPI. Run against the pre-fix builds
// (PPS_FLAT_PAGES=flat-brochure-old.html …) the QR does not decode, which is
// how this suite was shown to discriminate.
//
// Setup: flat-<calc>.html served on :8137 — compiled builds prepared with
//   node tools-harness-prep.mjs dist/calc-brochure.html <harness>/flat-brochure.html
// for brochure, postcard, letterhead, greeting-card, sticker; a static server
// rooted at <harness> on 127.0.0.1:8137 (python3 -m http.server 8137).
//
// Run: PPS_DEPS_DIR=<node_modules with jspdf, qrcode, jsqr, jpeg-js> node tools-flat-print-dpi-test.mjs

const PW = '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const { createRequire } = await import('node:module');
const require = createRequire(import.meta.url);
const DEPS = process.env.PPS_DEPS_DIR || new URL('./node_modules', import.meta.url).pathname;
const fs = await import('fs');

const FILES = (process.env.PPS_FLAT_PAGES || 'flat-brochure.html,flat-postcard.html,flat-letterhead.html,flat-greeting-card.html,flat-sticker.html').split(',').map(s => s.trim()).filter(Boolean);
const DPI = 300;
const MODULE_IN = 4 / DPI;           // the module size measured on order 87171's print-ready file
const PAYLOAD = ['HTTPS://PRIORITYPRINTSERVICE.COM/GATE/87171/SIDE-1/QR-FIDELITY', 'HTTPS://PRIORITYPRINTSERVICE.COM/GATE/87171/SIDE-2/QR-FIDELITY'];

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// ── The customer's file: a 6×4 two-pager with a QR code dead centre of each page.
// Centred, so any product's crop mode keeps it whatever the sheet size.
const QR_MODULES = [];
{
  const { jsPDF } = require(DEPS + '/jspdf/dist/jspdf.node.min.js');
  const QR = require(DEPS + '/qrcode');
  const W = 6, H = 4;
  const doc = new jsPDF({ unit: 'in', format: [W, H], orientation: 'landscape' });
  PAYLOAD.forEach((text, i) => {
    if (i) doc.addPage([W, H], 'landscape');
    doc.setFillColor(255, 255, 255); doc.rect(0, 0, W, H, 'F');
    doc.setTextColor(0, 0, 0); doc.setFontSize(14); doc.text('SIDE ' + (i + 1), 0.4, 0.5);
    const q = QR.create(text, { errorCorrectionLevel: 'M' });
    const n = q.modules.size, side = n * MODULE_IN; QR_MODULES.push(n);
    const x0 = (W - side) / 2, y0 = (H - side) / 2;
    doc.setFillColor(0, 0, 0);
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) {
      if (q.modules.get(r, c)) doc.rect(x0 + c * MODULE_IN, y0 + r * MODULE_IN, MODULE_IN, MODULE_IN, 'F');
    }
  });
  fs.writeFileSync('./art-qr-flat.pdf', Buffer.from(doc.output('arraybuffer')));
}

const jsQR = require(DEPS + '/jsqr');
const jpeg = require(DEPS + '/jpeg-js');

// Pull one uploaded file's bytes out of a multipart body.
function partBytes(buf, nameRe) {
  const s = buf.toString('latin1');
  const m = nameRe.exec(s); if (!m) return null;
  const hdrEnd = s.indexOf('\r\n\r\n', m.index); if (hdrEnd < 0) return null;
  const start = hdrEnd + 4;
  const bnd = s.slice(0, s.indexOf('\r\n'));       // "--<boundary>"
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
function decodeCentre(jpg, qrModules) {
  const img = jpeg.decode(jpg, { useTArray: true, formatAsRGBA: true, maxMemoryUsageInMB: 2048 });
  const cw = Math.round(img.width * 0.5), ch = Math.round(img.height * 0.5);
  const cx = Math.round((img.width - cw) / 2), cy = Math.round((img.height - ch) / 2);
  const data = new Uint8ClampedArray(cw * ch * 4);
  for (let y = 0; y < ch; y++) data.set(img.data.subarray(((cy + y) * img.width + cx) * 4, ((cy + y) * img.width + cx + cw) * 4), y * cw * 4);
  const r = jsQR(data, cw, ch);
  // Edge contrast over the QR itself. A 4 px module grid copied 1:1 onto the sheet
  // is black and white with no ramp at all; a 144 DPI render blown up 2.08× is
  // mostly ramp. jsQR is lenient enough to read both, so decoding alone does not
  // discriminate; the share of mid-grey pixels does. Measured: old brochure build
  // 36 %, order 87171's print-ready file 27 %, the fix with the art placed on a
  // half-pixel offset 20 % (Canvas resamples the whole sheet), the fix with
  // whole-pixel placement 0 %. The limit sits under the half-pixel case so that
  // regression is caught too.
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
      cartNonce: 'cartBaked', uploadNonce: 'upBaked', productId: 22673, maxUpload: 200 * 1048576, reorder: r };
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

  await p.locator('input[type=file]').first().setInputFiles('./art-qr-flat.pdf');
  await p.waitForTimeout(7000);
  // Open the proof: the flats open it from the side thumbnail, some builds from a button.
  await p.evaluate(() => {
    const x = [...document.querySelectorAll('button')].find(y => /Proof required|Proof ✓|🔍|Review proof/i.test(y.textContent || ''));
    if (x) return x.click();
    const img = [...document.querySelectorAll('img')].find(i => /^data:image\/jpeg/.test(i.src));
    img && img.parentElement && img.parentElement.click();
  });
  await p.waitForTimeout(2500);

  // "Render at full resolution (slow to load)": the proof on screen is the 144 DPI
  // upload preview (6″ art → 864 px) until the box is ticked; then a status bar shows
  // while each PDF side is rendered at 300 DPI, and the proof becomes the 1800 px render.
  const widest = () => p.evaluate(() => Math.max(0, ...[...document.querySelectorAll('img')].filter(i => /^data:image\/jpeg/.test(i.src)).map(i => i.naturalWidth)));
  const before = await widest();
  ok(`${file}: the proof opens on the quick preview (144 DPI)`, before > 0 && before < 1000, 'widest data image ' + before + ' px');
  // A small sheet renders in well under the poll interval, so the bar is watched with a
  // MutationObserver armed BEFORE the tick — a bar that appears and goes between two
  // polls still counts, and a bar that is never mounted still fails.
  await p.evaluate(() => {
    window.__sawBar = false;
    new MutationObserver(() => { if (document.querySelector('[role=progressbar][aria-label="Rendering at full resolution"]')) window.__sawBar = true; })
      .observe(document.body, { childList: true, subtree: true });
  });
  const ticked = await p.evaluate(() => { const l = [...document.querySelectorAll('label')].find(x => /Render at full resolution/i.test(x.textContent || '')); const cb = l && l.querySelector('input[type=checkbox]'); if (!cb || cb.disabled) return false; cb.click(); return true; });
  ok(`${file}: the full-resolution checkbox is there and enabled for a PDF upload`, ticked);
  let after = before;
  for (let i = 0; i < 120; i++) {
    await p.waitForTimeout(250);
    after = await widest();
    const busy = await p.evaluate(() => !!document.querySelector('[role=progressbar][aria-label="Rendering at full resolution"]'));
    if (!busy && after >= 1700) break;
  }
  ok(`${file}: a status bar was shown while rendering`, await p.evaluate(() => window.__sawBar));
  ok(`${file}: the proof now shows the 300 DPI render`, after >= 1700, 'widest data image ' + after + ' px');
  ok(`${file}: the label confirms 300 DPI`, await p.evaluate(() => /✓ 300 DPI/.test(([...document.querySelectorAll('label')].find(x => /Render at full resolution/i.test(x.textContent || '')) || {}).textContent || '')));

  await p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input[type=checkbox]'); cb && cb.click(); });
  await p.waitForTimeout(300);
  const approveClicked = await p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /^\s*Approve (artwork|Now)\s*$/i.test(y.textContent || '')); if (!btn) return false; btn.click(); return true; });
  ok(`${file}: the Approve button was found`, approveClicked);
  let man = 0;
  for (let i = 0; i < 120 && man < 1; i++) { await p.waitForTimeout(1000); man = await p.evaluate(() => window.__manifests); }
  ok(`${file}: the approval package was generated`, man >= 1, 'dialogs=' + dialogs.join(' || ') + ' errs=' + errs.join(' || '));
  await p.waitForTimeout(1500);
  await p.keyboard.press('Escape'); await p.waitForTimeout(500);
  await p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim()));
    const b = btns.find(x => x.offsetParent !== null && !x.disabled) || btns.find(x => !x.disabled);
    b && b.click();
  });
  for (let i = 0; i < 90 && !(printReady && manifest); i++) await p.waitForTimeout(1000);
  ok(`${file}: the print-ready PDF and the manifest were uploaded`, !!(printReady && manifest), 'uploads=' + uploads.join(',') + ' dialogs=' + dialogs.join(' || '));
  await ctx.close();
  if (!(printReady && manifest)) return;

  fs.writeFileSync('./flat-print-ready-' + file.replace(/\.html$/, '') + '.pdf', printReady);   // kept for eyeballing
  const bm = manifest.match(/Bleed-inclusive size: ([\d.]+)″ × ([\d.]+)″/);
  const sides = jpegStreams(printReady);
  ok(`${file}: the print-ready PDF carries one JPEG per side`, sides.length >= 1, sides.length + ' streams');
  const expW = bm ? Math.round(parseFloat(bm[1]) * DPI) : null, expH = bm ? Math.round(parseFloat(bm[2]) * DPI) : null;
  sides.forEach((jpg, i) => {
    const r = decodeCentre(jpg, QR_MODULES[i]);
    ok(`${file}: side ${i + 1} is the bleed sheet at ${DPI} DPI`, expW && r.width === expW && r.height === expH, `${r.width}×${r.height} expected ${expW}×${expH}`);
    ok(`${file}: side ${i + 1}'s QR code scans from the print-ready file`, r.text === PAYLOAD[i], 'decoded=' + JSON.stringify(r.text));
    ok(`${file}: side ${i + 1}'s QR modules are crisp, not an upscaled preview`, r.midGrey < 0.05, 'mid-grey share ' + (r.midGrey * 100).toFixed(1) + '% (limit 5%)');
  });
  ok(`${file}: the manifest says where the print pixels came from`, /print source: pdf page 1 rendered at 300 DPI/.test(manifest), (manifest.match(/print source:[^\n]*/g) || ['(absent)']).join(' | '));
}

const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
for (const f of FILES) { try { await run(b, f); } catch (e) { ok(`${f}: ran`, false, String(e && e.stack || e).slice(0, 400)); } }
await b.close();

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> FLAT PRINT DPI FAILED' : '>>> FLAT PRINT DPI OK');
process.exit(failed ? 1 : 0);
