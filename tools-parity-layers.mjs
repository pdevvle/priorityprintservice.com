// Setup identical to tools-parity-saddle.mjs (see its header); also needs ./layered.pdf
// (a PDF with an OFF optional-content layer) in the serve dir - the test file is
// hand-authored, see the parity-layers section of docs/HANDOFF_2026-08-23.md.
// Hidden-layer detection end-to-end: upload a PDF whose OFF layer holds a red
// square. Expect: preflight warn + ack gate; risk banner mentions hidden
// layers; proof AND print-ready both exclude the square (blue at centre);
// manifest records the check and the acknowledgment.
const PW = '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
let failures = [];
const check = (n, ok, d) => { console.log((ok ? 'PASS ' : 'FAIL ') + n + (d ? '  ' + d : '')); if (!ok) failures.push(n); };
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0, 200)));
p.on('dialog', async d => { await d.dismiss(); });
await p.goto('http://127.0.0.1:8137/parity-saddle.html', { waitUntil: 'domcontentloaded' });
await p.waitForSelector('select'); await p.waitForTimeout(2200);
await p.evaluate(() => {
  window.__pdfBlobs = []; window.__manifests = [];
  const OB = window.Blob;
  window.Blob = function (parts, opts) { const bl = new OB(parts, opts); if (opts && opts.type === 'text/plain') window.__manifests.push(bl); return bl; };
  window.Blob.prototype = OB.prototype;
  const hook = () => {
    if (!(window.jspdf && window.jspdf.jsPDF) || window.__jspdfHooked) return;
    window.__jspdfHooked = true;
    const OrigJ = window.jspdf.jsPDF;
    function WrapJ(...a) { const inst = new OrigJ(...a); const o = inst.output.bind(inst); inst.output = (...aa) => { const r = o(...aa); if (aa[0] === 'blob') window.__pdfBlobs.push(r); return r; }; return inst; }
  WrapJ.API = OrigJ.API; window.jspdf.jsPDF = WrapJ;
  };
  hook(); setInterval(hook, 300);
});
await p.locator('input[type=file]').first().setInputFiles('./layered.pdf');
await p.waitForTimeout(6000);
// open proof
await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Proof required|Proof ✓|🔍|Review proof/i.test(y.textContent || '')); x && x.click(); });
await p.waitForTimeout(1500);
for (let i = 0; i < 40; i++) { await p.waitForTimeout(400); if (await p.evaluate(() => [...document.querySelectorAll('div')].some(d => /print pixels/.test(d.textContent || '') && d.children.length === 0))) break; }
const body = await p.evaluate(() => document.body.innerText);
check('risk banner mentions hidden layers', /hidden layers/i.test(body) && /Stale Layer/.test(body));
const gate = await p.evaluate(() => {
  const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || ''));
  const btn = [...document.querySelectorAll('button')].find(y => /Approve artwork/i.test(y.textContent || ''));
  return { ack: ack ? ack.textContent.slice(0, 160) : null, disabled: btn ? btn.disabled : null };
});
check('preflight ack gate names Hidden layers', !!gate.ack && /Hidden layers/i.test(gate.ack) && gate.disabled === true, JSON.stringify(gate));
// proof centre pixel must be blue (hidden red square NOT rendered)
const proofPx = await p.evaluate(async () => {
  const f = document.querySelector('.bp-checker .bp-checker'); const im = f && f.querySelector('img'); if (!im) return null;
  const img = await new Promise((res, rej) => { const i2 = new Image(); i2.onload = () => res(i2); i2.onerror = rej; i2.src = im.src; });
  const c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = img.naturalHeight;
  const x = c.getContext('2d'); x.drawImage(img, 0, 0);
  const d = x.getImageData(Math.floor(c.width / 2), Math.floor(c.height / 2), 1, 1).data;
  return [d[0], d[1], d[2]];
});
check('proof excludes hidden layer (centre is blue)', proofPx && proofPx[2] > 120 && proofPx[0] < 90, JSON.stringify(proofPx));
// ack + approve
await p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input'); cb && !cb.checked && cb.click(); });
await p.waitForTimeout(400);
await p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /Approve artwork/i.test(y.textContent || '')); btn && btn.click(); });
let ok = false;
for (let i = 0; i < 40; i++) { await p.waitForTimeout(800); if (await p.evaluate(() => /✓ Approved/i.test(document.body.innerText))) { ok = true; break; } }
check('approve succeeds through ack', ok);
// print-ready page 1 centre must be blue. NB: 1 page → 7 blanks padded, page-count warn also acked.
const res = await p.evaluate(async () => {
  const bl = window.__pdfBlobs[0]; if (!bl) return { err: 'no print blob (skip path took raw?)' };
  const bytes = new Uint8Array(await bl.arrayBuffer());
  const pdfjs = await window.__ppsPdfJs;
  const doc = await pdfjs.getDocument({ data: bytes }).promise;
  const pg = await doc.getPage(1);
  const vp = pg.getViewport({ scale: 1 });
  const c = document.createElement('canvas'); c.width = Math.round(vp.width); c.height = Math.round(vp.height);
  const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
  await pg.render({ canvasContext: x, viewport: vp }).promise;
  const d = x.getImageData(Math.floor(c.width / 2), Math.floor(c.height / 2), 1, 1).data;
  const man = window.__manifests[window.__manifests.length - 1];
  const manText = man ? await man.text() : '';
  return { rgb: [d[0], d[1], d[2]], pages: doc.numPages, manHasLayers: /Hidden layers/i.test(manText), manHasAck: /acknowledged the flagged checks/i.test(manText) };
});
check('print-ready excludes hidden layer (centre is blue)', res.rgb && res.rgb[2] > 120 && res.rgb[0] < 90, JSON.stringify(res));
check('manifest records Hidden layers check + acknowledgment', res.manHasLayers && res.manHasAck);
check('no page errors', errs.length === 0, errs[0] || '');
await b.close();
console.log(failures.length ? `>>> ${failures.length} FAILURE(S)` : '>>> HIDDEN-LAYER TEST: ALL PASS');
process.exit(failures.length ? 1 : 0);
