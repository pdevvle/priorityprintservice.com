// Regression battery for the 2026-08-24 adversarial-audit findings. Setup identical to tools-parity-saddle.mjs (see its header). Run all three after any proof/composition change.
// NEW-BUG BATTERY — regression tests for the adversarial-review findings.
// S5: slot-replace page 1 of an uploaded PDF with a PNG → the PRINT file must
//     contain the replacement (old code resurrected the original PDF page),
//     and untouched pages must still come from the right PDF pages.
// S6: "Make inside covers blank" placement on a short PDF → print pages must
//     follow the RECONCILED layout (old code printed shifted/wrong pages).
// S7: Manual Digital proof (0.01) + uploaded files → order must PROCEED
//     without online approval (the gate blocked all paid-proofing uploads).
// S8: switching Artwork to "Email Art After Order" after an approval → the
//     stale approved package must NOT ride into the order.
const PW = '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const fs = await import('fs');
const FILE = process.argv[2] || 'parity-saddle.html';
let failures = [];
const check = (name, ok, detail) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  ' + detail : '')); if (!ok) failures.push(name); };

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 } });

async function newPage() {
  const p = await ctx.newPage();
  p.__dialogs = []; p.__errs = [];
  p.on('dialog', async d => { p.__dialogs.push(d.message()); await d.dismiss(); });
  p.on('pageerror', e => p.__errs.push(String(e).slice(0, 200)));
  await p.goto('http://127.0.0.1:8137/' + FILE, { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('select'); await p.waitForTimeout(2200);
  await p.evaluate(() => {
    window.__pdfBlobs = [];
    const hook = () => {
      if (!(window.jspdf && window.jspdf.jsPDF) || window.__jspdfHooked) return;
      window.__jspdfHooked = true;
      const OrigJ = window.jspdf.jsPDF;
      function WrapJ(...a) { const inst = new OrigJ(...a); const o = inst.output.bind(inst); inst.output = (...aa) => { const r = o(...aa); if (aa[0] === 'blob') window.__pdfBlobs.push(r); return r; }; return inst; }
      WrapJ.API = OrigJ.API; window.jspdf.jsPDF = WrapJ;
    };
    hook(); setInterval(hook, 300);
    window.__dls = [];
    const oc = HTMLAnchorElement.prototype.click;
    HTMLAnchorElement.prototype.click = function () { if (this.download) window.__dls.push(this.download); return oc.call(this); };
  });
  return p;
}

// distinct solid colors per page so rasterized samples identify pages
const COLORS = [[27, 106, 201], [201, 64, 27], [27, 160, 80], [160, 27, 160], [220, 160, 20], [20, 160, 220], [90, 90, 90], [200, 30, 90]];
const mkPdf = (p, n) => p.evaluate(async ({ n, COLORS }) => {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation: 'portrait', unit: 'in', format: [5.75, 8.75] });
  for (let i = 0; i < n; i++) {
    if (i > 0) doc.addPage([5.75, 8.75], 'portrait');
    doc.setFillColor(...COLORS[i % COLORS.length]); doc.rect(0, 0, 5.75, 8.75, 'F');
  }
  const ab = await doc.output('blob').arrayBuffer();
  let s = ''; const u = new Uint8Array(ab);
  for (let i = 0; i < u.length; i++) s += String.fromCharCode(u[i]);
  window.__pdfBlobs.length = 0; // our source-pdf build is not a captured deliverable
  return btoa(s);
}, { n, COLORS });

const openProof = async (p) => { await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Proof required|Proof ✓|🔍|Review proof/i.test(y.textContent || '')); x && x.click(); }); await p.waitForTimeout(1500); };
const tickAckIfAny = async (p) => { await p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input'); if (cb && !cb.checked) cb.click(); }); await p.waitForTimeout(400); };
const clickApprove = async (p) => { await p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /Approve artwork/i.test(y.textContent || '')); btn && btn.click(); }); for (let i = 0; i < 60; i++) { await p.waitForTimeout(800); if (await p.evaluate(() => /✓ Approved/i.test(document.body.innerText))) return true; } return false; };

// rasterize captured print blob page N at 72 DPI and return the center pixel
const samplePrintPage = (p, blobIdx, pageNum) => p.evaluate(async ({ blobIdx, pageNum }) => {
  const bl = window.__pdfBlobs[blobIdx];
  if (!bl) return { err: 'no blob at ' + blobIdx + ' (have ' + window.__pdfBlobs.length + ')' };
  const bytes = new Uint8Array(await bl.arrayBuffer());
  const pdfjs = await window.__ppsPdfJs;
  const doc = await pdfjs.getDocument({ data: bytes }).promise;
  if (pageNum > doc.numPages) return { err: 'page ' + pageNum + ' > ' + doc.numPages };
  const pg = await doc.getPage(pageNum);
  const vp = pg.getViewport({ scale: 1 });
  const c = document.createElement('canvas'); c.width = Math.round(vp.width); c.height = Math.round(vp.height);
  const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
  await pg.render({ canvasContext: x, viewport: vp }).promise;
  const d = x.getImageData(Math.floor(c.width / 2), Math.floor(c.height / 2), 1, 1).data;
  return { rgb: [d[0], d[1], d[2]], pages: doc.numPages };
}, { blobIdx, pageNum });
const near = (rgb, want, tol = 28) => rgb && Math.abs(rgb[0] - want[0]) <= tol && Math.abs(rgb[1] - want[1]) <= tol && Math.abs(rgb[2] - want[2]) <= tol;

// ════ S5: slot replacement must reach the print file ════
{
  const p = await newPage();
  const pdfB64 = await mkPdf(p, 8);
  fs.writeFileSync('./s5.pdf', Buffer.from(pdfB64, 'base64'));
  await p.locator('input[type=file]').first().setInputFiles('./s5.pdf');
  await p.waitForTimeout(6000);
  // slot-replace page 1 with solid green PNG at bleed size
  const png = await p.evaluate(() => { const c = document.createElement('canvas'); c.width = 1725; c.height = 2625; const x = c.getContext('2d'); x.fillStyle = '#10c040'; x.fillRect(0, 0, 1725, 2625); return c.toDataURL('image/png'); });
  fs.writeFileSync('./s5-green.png', Buffer.from(png.split(',')[1], 'base64'));
  await p.evaluate(() => { const z = document.querySelector('[data-pps-dropzone]'); if (z) { const cl = z.querySelector('div'); cl && cl.click(); } });
  await p.waitForTimeout(500);
  await p.locator('input[type=file]').last().setInputFiles('./s5-green.png');
  await p.waitForTimeout(3500);
  await openProof(p);
  await tickAckIfAny(p);
  const before = await p.evaluate(() => window.__pdfBlobs.length);
  const okA = await clickApprove(p);
  await p.waitForTimeout(1500);
  const s1 = await samplePrintPage(p, before, 1);
  const s2 = await samplePrintPage(p, before, 2);
  const s8 = await samplePrintPage(p, before, 8);
  check('S5 approve generated a print file (raw-skip disabled by slot file)', okA && !s1.err, JSON.stringify(s1));
  check('S5 print p1 IS the replacement (green)', near(s1.rgb, [16, 192, 64]), JSON.stringify(s1));
  check('S5 print p2 still original PDF page 2', near(s2.rgb, COLORS[1]), JSON.stringify(s2));
  check('S5 print p8 still original PDF page 8', near(s8.rgb, COLORS[7]), JSON.stringify(s8));
  check('S5 no page errors', p.__errs.length === 0, p.__errs[0] || '');
  await p.close();
}

// ════ S6: covers blank placement must not shift printed pages ════
{
  const p = await newPage();
  const pdfB64 = await mkPdf(p, 4);
  fs.writeFileSync('./s6.pdf', Buffer.from(pdfB64, 'base64'));
  await p.locator('input[type=file]').first().setInputFiles('./s6.pdf');
  await p.waitForTimeout(6000);
  const chose = await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Make inside cover/i.test(y.textContent || '')); if (x) { x.click(); return true; } return false; });
  await p.waitForTimeout(1200);
  check('S6 chose "inside covers" blank placement', chose);
  await openProof(p);
  await tickAckIfAny(p);
  const before = await p.evaluate(() => window.__pdfBlobs.length);
  const okA = await clickApprove(p);
  await p.waitForTimeout(1500);
  // expected: [pdf1, blank, pdf2, pdf3, blank, blank, blank, pdf4]
  const s1 = await samplePrintPage(p, before, 1);
  const s2 = await samplePrintPage(p, before, 2);
  const s3 = await samplePrintPage(p, before, 3);
  const s4 = await samplePrintPage(p, before, 4);
  const s8 = await samplePrintPage(p, before, 8);
  check('S6 approve generated print file', okA && !s1.err && s1.pages === 8, JSON.stringify(s1));
  check('S6 p1 = PDF page 1', near(s1.rgb, COLORS[0]), JSON.stringify(s1));
  check('S6 p2 = BLANK (inside front cover)', near(s2.rgb, [255, 255, 255], 6), JSON.stringify(s2));
  check('S6 p3 = PDF page 2 (not page 3!)', near(s3.rgb, COLORS[1]), JSON.stringify(s3));
  check('S6 p4 = PDF page 3', near(s4.rgb, COLORS[2]), JSON.stringify(s4));
  check('S6 p8 = PDF page 4 (back cover)', near(s8.rgb, COLORS[3]), JSON.stringify(s8));
  check('S6 no page errors', p.__errs.length === 0, p.__errs[0] || '');
  await p.close();
}

// ════ S7: paid proofing + uploads must be orderable without online approval ════
{
  const p = await newPage();
  const png = await p.evaluate(() => { const c = document.createElement('canvas'); c.width = 1725; c.height = 2625; const x = c.getContext('2d'); x.fillStyle = '#8040c0'; x.fillRect(0, 0, 1725, 2625); return c.toDataURL('image/png'); });
  fs.writeFileSync('./s7.png', Buffer.from(png.split(',')[1], 'base64'));
  await p.locator('input[type=file]').first().setInputFiles('./s7.png');
  await p.waitForTimeout(4000);
  const setSel = (optText, val) => p.evaluate(([optText, val]) => {
    const s = [...document.querySelectorAll('select')].find(se => [...se.options].some(o => (o.textContent || '').includes(optText)));
    if (!s) return false;
    Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(s, val);
    s.dispatchEvent(new Event('change', { bubbles: true })); return true;
  }, [optText, val]);
  check('S7 selected Manual Digital Proof', await setSel('Manual Digital Proof', '0.01'));
  await p.waitForTimeout(1500);
  // shipping
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Shipping/i.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(800);
  const setV = async (ph, v) => p.evaluate(([ph, v]) => { const i = [...document.querySelectorAll('input')].find(x => x.placeholder === ph); if (!i) return false; Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(i, v); i.dispatchEvent(new Event('input', { bubbles: true })); return true; }, [ph, v]);
  for (const [ph, v] of [['Full Name', 'Test Buyer'], ['Street address', '123 Main St'], ['City', 'Hermosa Beach'], ['ZIP', '90254']]) await setV(ph, v);
  await p.evaluate(() => { const sels = [...document.querySelectorAll('select')]; const st = sels.find(s => [...s.options].some(o => /^CA$|California/.test(o.value || o.textContent))); if (!st) return; const o = [...st.options].find(o => /^CA$|California/.test(o.value || o.textContent)); Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(st, o.value); st.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.waitForTimeout(2000);
  p.__dialogs.length = 0;
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Add to Order/i.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(4000);
  const dls = await p.evaluate(() => window.__dls);
  const blockedAlert = p.__dialogs.some(d => /approv/i.test(d) && !/Standalone/i.test(d));
  const proceeded = p.__dialogs.some(d => /Standalone mode/i.test(d)) && dls.includes('s7.png');
  check('S7 order PROCEEDS for Manual Digital proof without online approval', proceeded && !blockedAlert, JSON.stringify({ dialogs: p.__dialogs.map(d => d.slice(0, 50)), dls }));
  check('S7 no page errors', p.__errs.length === 0, p.__errs[0] || '');
  await p.close();
}

// ════ S8: switching artwork option clears the stale approved package ════
{
  const p = await newPage();
  fs.writeFileSync('./s8.png', fs.readFileSync('./s7.png'));
  await p.locator('input[type=file]').first().setInputFiles('./s8.png');
  await p.waitForTimeout(4000);
  await openProof(p);
  await tickAckIfAny(p);
  const okA = await clickApprove(p);
  check('S8 approved', okA);
  // close modal
  await p.evaluate(() => { const ovs = [...document.querySelectorAll('div')].filter(d => getComputedStyle(d).position === 'fixed' && d.getBoundingClientRect().width > 600); const ov = ovs[ovs.length - 1]; if (ov) { const x = [...ov.querySelectorAll('button')].find(y => /^(✕|✖)$/.test(y.textContent.trim())); x && x.click(); } });
  await p.waitForTimeout(600);
  // switch Artwork & Design to "Email Art After Order"
  const switched = await p.evaluate(() => {
    const s = [...document.querySelectorAll('select')].find(se => [...se.options].some(o => (o.textContent || '').includes('Email Art After Order')));
    if (!s) return false;
    const o = [...s.options].find(o => (o.textContent || '').includes('Email Art After Order'));
    Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(s, o.value);
    s.dispatchEvent(new Event('change', { bubbles: true })); return true;
  });
  check('S8 switched to Email Art After Order', switched);
  await p.waitForTimeout(1200);
  // shipping + order
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Shipping/i.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(800);
  const setV = async (ph, v) => p.evaluate(([ph, v]) => { const i = [...document.querySelectorAll('input')].find(x => x.placeholder === ph); if (!i) return false; Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(i, v); i.dispatchEvent(new Event('input', { bubbles: true })); return true; }, [ph, v]);
  for (const [ph, v] of [['Full Name', 'Test Buyer'], ['Street address', '123 Main St'], ['City', 'Hermosa Beach'], ['ZIP', '90254']]) await setV(ph, v);
  await p.evaluate(() => { const sels = [...document.querySelectorAll('select')]; const st = sels.find(s => [...s.options].some(o => /^CA$|California/.test(o.value || o.textContent))); if (!st) return; const o = [...st.options].find(o => /^CA$|California/.test(o.value || o.textContent)); Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(st, o.value); st.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.waitForTimeout(2000);
  p.__dialogs.length = 0;
  await p.evaluate(() => { window.__dls = []; });
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Add to Order/i.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(4000);
  const dls = await p.evaluate(() => window.__dls);
  const proceeded = p.__dialogs.some(d => /Standalone mode/i.test(d));
  const noArtRode = !dls.some(n => /s8|print-ready|preview_page|manifest/.test(n));
  check('S8 order proceeds WITHOUT the stale approved package', proceeded && noArtRode, JSON.stringify({ dialogs: p.__dialogs.map(d => d.slice(0, 60)), dls }));
  check('S8 no page errors', p.__errs.length === 0, p.__errs[0] || '');
  await p.close();
}

await b.close();
console.log(failures.length ? `>>> ${failures.length} FAILURE(S): ${failures.join('; ')}` : '>>> NEW-BUG BATTERY: ALL PASS');
process.exit(failures.length ? 1 : 0);
