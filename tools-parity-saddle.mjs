// PROOF-PARITY TRIPWIRE — the pixels the customer approves vs the pixels we print.
//
// Setup (run before/after ANY change to the proof, composition or PDF code):
//   1. node tools-compile-calcs.mjs                         (build dist/)
//   2. node tools-harness-prep.mjs dist/calc-preview-test.html <servedir>/parity-saddle.html
//      (vendors the CDN scripts and injects the PRODUCTION THEME CSS — a parity
//       claim made without the theme loaded is worthless; that is how the 87032
//       clamp survived. <servedir> needs node_modules with react/react-dom umd,
//       jspdf 2.5.1, pdfjs-dist 4.10.38, plus theme-main.css = pps-theme main.css)
//   3. python3 -m http.server 8137 in <servedir>
//   4. node tools-parity-saddle.mjs parity-saddle.html      (exit 0 = parity holds)
//
// Passing means: proof bitmap == rasterized print-ready PDF page (pixel diff at
// JPEG-noise level), manifest SHA-256 == blob SHA-256, approve gated on preflight
// acknowledgment, proof <img> exactly fills its frame (theme clamp impossible).
//
// Loads the compiled saddle calculator WITH the production theme CSS, uploads
// 87032-shaped oversized art (6.667"x9.167" on a smaller page, fit=crop),
// captures:
//   A. the proof modal's displayed bitmap (composePageCanvas at 300 DPI), and
//   B. page 1 of the print-ready PDF the Approve button generates,
// rasterizes B at 300 DPI with the page's own pdf.js, and pixel-compares.
// Also verifies the approval binding: SHA-256(print blob) must equal the hash
// recorded in the manipulation manifest, and approval must be BLOCKED until
// the preflight acknowledgment is ticked.
//
// Run: node parity.mjs parity-saddle.html   (http server on :8137 serving ui/)
const PW = '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const file = process.argv[2] || 'parity-saddle.html';

const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0, 200)));
await p.goto('http://127.0.0.1:8137/' + file, { waitUntil: 'domcontentloaded' });
await p.waitForSelector('select'); await p.waitForTimeout(2500);

// Capture hooks: every jsPDF blob output + every text/plain Blob (the manifest).
await p.evaluate(() => {
  window.__pdfBlobs = []; window.__manifests = [];
  const OB = window.Blob;
  window.Blob = function (parts, opts) {
    const bl = new OB(parts, opts);
    if (opts && opts.type === 'text/plain') window.__manifests.push(bl);
    return bl;
  };
  window.Blob.prototype = OB.prototype;
  // jsPDF defines output per-instance, so wrap the constructor (the page
  // destructures window.jspdf.jsPDF at call time).
  const hookJspdf = () => {
    if (!(window.jspdf && window.jspdf.jsPDF) || window.__jspdfHooked) return;
    window.__jspdfHooked = true;
    const OrigJ = window.jspdf.jsPDF;
    function WrapJ(...a) {
      const inst = new OrigJ(...a);
      const o = inst.output.bind(inst);
      inst.output = (...aa) => { const r = o(...aa); if (aa[0] === 'blob') window.__pdfBlobs.push(r); return r; };
      return inst;
    }
    WrapJ.API = OrigJ.API;
    window.jspdf.jsPDF = WrapJ;
  };
  hookJspdf();
  setInterval(hookJspdf, 300); // jsPDF may lazy-load on approve
});

// 87032-shaped art: 2000x2750 px = 6.667" x 9.167" at 300 DPI, with distinct
// edge markers so a crop-vs-fit divergence is unmissable in pixels.
const png = await p.evaluate(() => {
  const c = document.createElement('canvas'); c.width = 2000; c.height = 2750;
  const x = c.getContext('2d');
  const g = x.createLinearGradient(0, 0, 2000, 2750);
  g.addColorStop(0, '#1b6ac9'); g.addColorStop(1, '#c9401b');
  x.fillStyle = g; x.fillRect(0, 0, 2000, 2750);
  x.strokeStyle = '#fff'; x.lineWidth = 24; x.strokeRect(40, 40, 1920, 2670);
  x.fillStyle = '#fff'; x.font = 'bold 150px sans-serif';
  x.fillText('TOP EDGE', 600, 220); x.fillText('BOTTOM EDGE', 480, 2660);
  return c.toDataURL('image/png');
});
const fs = await import('fs');
fs.writeFileSync('./art-parity.png', Buffer.from(png.split(',')[1], 'base64'));
await p.locator('input[type=file]').first().setInputFiles('./art-parity.png');
await p.waitForTimeout(5000);

// Open the proof modal, force fit=Crop (the 87032 mode)
await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Proof required|Proof ✓|🔍|Review proof/i.test(y.textContent || '')); x && x.click(); });
await p.waitForTimeout(2500);
await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => y.textContent.trim() === 'Crop'); x && x.click(); });

// Wait for the print-resolution composition badge
let badge = false;
for (let i = 0; i < 40 && !badge; i++) {
  await p.waitForTimeout(500);
  badge = await p.evaluate(() => [...document.querySelectorAll('div')].some(d => /print pixels/.test(d.textContent || '') && d.children.length === 0));
}
console.log('print-pixels badge:', badge ? 'shown' : 'NOT SHOWN');

// Geometry sanity: the proof <img> must exactly fill its bleed frame (no clamp possible)
const geom = await p.evaluate(() => {
  const frame = document.querySelector('.bp-checker .bp-checker') || document.querySelector('.bp-checker');
  const img = frame && frame.querySelector('img');
  if (!img) return { err: 'no proof img' };
  const f = frame.getBoundingClientRect(), i = img.getBoundingClientRect();
  return { frameW: +f.width.toFixed(1), imgW: +i.width.toFixed(1), ratio: +(i.width / f.width).toFixed(3), src0: img.src.slice(0, 40) };
});
console.log('proof img geometry:', JSON.stringify(geom));

// Grab the displayed proof bitmap
const proofDataUrl = await p.evaluate(() => {
  const frame = document.querySelector('.bp-checker .bp-checker') || document.querySelector('.bp-checker');
  const img = frame && frame.querySelector('img');
  return img ? img.src : null;
});
if (!proofDataUrl || !proofDataUrl.startsWith('data:image')) { console.log('FAIL: no composed proof dataUrl'); await b.close(); process.exit(1); }

// Approve must be BLOCKED before the preflight acknowledgment is ticked
const gate = await p.evaluate(() => {
  const btn = [...document.querySelectorAll('button')].find(y => /Approve artwork/i.test(y.textContent || ''));
  const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || ''));
  return { btnFound: !!btn, disabledBeforeAck: btn ? btn.disabled : null, ackFound: !!ack };
});
console.log('approve gate:', JSON.stringify(gate));
if (gate.ackFound) {
  await p.evaluate(() => {
    const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || ''));
    const cb = ack && ack.querySelector('input[type=checkbox]');
    cb && cb.click();
  });
  await p.waitForTimeout(400);
}
// Click approve, wait for package generation (jsPDF blob + manifest captured)
await p.evaluate(() => {
  const btn = [...document.querySelectorAll('button')].find(y => /Approve artwork/i.test(y.textContent || ''));
  btn && btn.click();
});
let counts = { pdf: 0, man: 0 };
for (let i = 0; i < 60; i++) {
  await p.waitForTimeout(1000);
  counts = await p.evaluate(() => ({ pdf: window.__pdfBlobs.length, man: window.__manifests.length }));
  if (counts.pdf >= 1 && counts.man >= 1) break;
}
console.log('captured:', JSON.stringify(counts));
if (!counts.pdf || !counts.man) { console.log('FAIL: package generation did not produce pdf+manifest'); console.log('page errors:', errs); await b.close(); process.exit(1); }

// In-page: hash check + rasterize PDF page 1 at 300 DPI + pixel compare
const result = await p.evaluate(async (proofUrl) => {
  const out = {};
  const pdfBlob = window.__pdfBlobs[0]; // first blob output = print-ready (preview pdf comes later)
  const manBlob = window.__manifests[window.__manifests.length - 1];
  const manText = await manBlob.text();
  const mm = manText.match(/Print file SHA-256: ([0-9a-f]{64})/);
  out.manifestHash = mm ? mm[1] : null;
  const buf = await pdfBlob.arrayBuffer();
  const dig = await crypto.subtle.digest('SHA-256', buf);
  out.blobHash = Array.from(new Uint8Array(dig)).map(x => x.toString(16).padStart(2, '0')).join('');
  out.hashMatch = !!out.manifestHash && out.manifestHash === out.blobHash;

  const pdfjs = await window.__ppsPdfJs;
  const doc = await pdfjs.getDocument({ data: new Uint8Array(buf) }).promise;
  const pg = await doc.getPage(1);
  const vp = pg.getViewport({ scale: 300 / 72 });
  const cB = document.createElement('canvas'); cB.width = Math.round(vp.width); cB.height = Math.round(vp.height);
  await pg.render({ canvasContext: cB.getContext('2d'), viewport: vp }).promise;

  const imgA = await new Promise((res, rej) => { const im = new Image(); im.onload = () => res(im); im.onerror = rej; im.src = proofUrl; });
  const cA = document.createElement('canvas'); cA.width = imgA.naturalWidth; cA.height = imgA.naturalHeight;
  cA.getContext('2d').drawImage(imgA, 0, 0);
  out.dimsA = [cA.width, cA.height]; out.dimsB = [cB.width, cB.height];

  // Same nominal size (bleed sheet at 300 DPI) — allow 2px rounding drift, compare at min dims
  const w = Math.min(cA.width, cB.width), h = Math.min(cA.height, cB.height);
  out.dimDrift = [Math.abs(cA.width - cB.width), Math.abs(cA.height - cB.height)];
  const dA = cA.getContext('2d').getImageData(0, 0, w, h).data;
  const dB = cB.getContext('2d').getImageData(0, 0, w, h).data;
  let sum = 0, worst = 0, over24 = 0;
  const n = w * h;
  for (let i = 0; i < n; i++) {
    const j = i * 4;
    const d = (Math.abs(dA[j] - dB[j]) + Math.abs(dA[j + 1] - dB[j + 1]) + Math.abs(dA[j + 2] - dB[j + 2])) / 3;
    sum += d; if (d > worst) worst = d; if (d > 24) over24++;
  }
  out.meanDiff = +(sum / n).toFixed(3);
  out.worstDiff = worst;
  out.pctOver24 = +(over24 / n * 100).toFixed(3);
  return out;
}, proofDataUrl);

console.log('parity:', JSON.stringify(result));
const pass =
  badge &&
  geom.ratio > 0.99 && geom.ratio < 1.01 &&
  gate.btnFound && (gate.ackFound ? gate.disabledBeforeAck === true : true) &&
  result.hashMatch &&
  result.dimDrift[0] <= 2 && result.dimDrift[1] <= 2 &&
  result.meanDiff < 3 && result.pctOver24 < 1;
console.log('page errors:', errs.length ? errs : 'none');
console.log(pass ? '>>> PARITY PASS: the approved pixels are the printed pixels, hash-bound.' : '>>> PARITY FAIL');
await b.close();
process.exit(pass ? 0 : 1);
