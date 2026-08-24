// Setup identical to tools-parity-saddle.mjs (see its header). Run all three after any proof/composition change.
// EXTENDED PROOF-PARITY BATTERY — everything the single crop-mode run didn't cover.
// S1: 8-page bleed-exact PDF upload → NO ack gate (clean file), skippedGeneration
//     path → proof parity vs raw PDF pages 1 and 5, manifest hash == sha256(raw).
// S2: oversized PNG → fit-mode matrix (Fill/cover, Fit/contain, Stretch, Rotate90):
//     approve each, compare proof bitmap vs generated print PDF page 1.
// S3: staleness — approve-less: upload blue art, view proof, slot-replace with
//     green art, reopen → composed proof must show green.
// S4: revocation — approve, then change a transform, then Add to Order → the
//     gate must BLOCK (alert mentions approval, no files downloaded).
const PW = '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const nodeCrypto = await import('crypto');
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
  return p;
}

const openProof = async (p) => { await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Proof required|Proof ✓|🔍|Review proof/i.test(y.textContent || '')); x && x.click(); }); await p.waitForTimeout(1500); };
const waitBadge = async (p, tries = 50) => { for (let i = 0; i < tries; i++) { await p.waitForTimeout(400); if (await p.evaluate(() => [...document.querySelectorAll('div')].some(d => /print pixels/.test(d.textContent || '') && d.children.length === 0))) return true; } return false; };
const proofUrl = (p) => p.evaluate(() => { const f = document.querySelector('.bp-checker .bp-checker') || document.querySelector('.bp-checker'); const im = f && f.querySelector('img'); return im ? im.src : null; });
const gateState = (p) => p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /Approve artwork/i.test(y.textContent || '')); const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input'); return { btn: !!btn, disabled: btn ? btn.disabled : null, ack: !!ack, ackChecked: cb ? cb.checked : null }; });
const tickAck = (p) => p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input'); if (cb && !cb.checked) cb.click(); });
const clickApprove = async (p) => { await p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /Approve artwork/i.test(y.textContent || '')); btn && btn.click(); }); for (let i = 0; i < 60; i++) { await p.waitForTimeout(800); if (await p.evaluate(() => /✓ Approved/i.test(document.body.innerText))) return true; } return false; };
const blobCounts = (p) => p.evaluate(() => ({ pdf: window.__pdfBlobs.length, man: window.__manifests.length }));

// Rasterize a PDF (as base64) page N at 300 DPI in-page and diff against a proof dataUrl.
const comparePage = (p, args) => p.evaluate(async ({ pdfB64, pageNum, proofDataUrl }) => {
  const bin = atob(pdfB64); const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  const pdfjs = await window.__ppsPdfJs;
  const doc = await pdfjs.getDocument({ data: bytes }).promise;
  const pg = await doc.getPage(pageNum);
  const vp = pg.getViewport({ scale: 300 / 72 });
  const cB = document.createElement('canvas'); cB.width = Math.round(vp.width); cB.height = Math.round(vp.height);
  const bctx = cB.getContext('2d'); bctx.fillStyle = '#fff'; bctx.fillRect(0, 0, cB.width, cB.height);
  await pg.render({ canvasContext: bctx, viewport: vp }).promise;
  const imgA = await new Promise((res, rej) => { const im = new Image(); im.onload = () => res(im); im.onerror = rej; im.src = proofDataUrl; });
  const cA = document.createElement('canvas'); cA.width = imgA.naturalWidth; cA.height = imgA.naturalHeight;
  cA.getContext('2d').drawImage(imgA, 0, 0);
  const w = Math.min(cA.width, cB.width), h = Math.min(cA.height, cB.height);
  const dA = cA.getContext('2d').getImageData(0, 0, w, h).data;
  const dB = cB.getContext('2d').getImageData(0, 0, w, h).data;
  let sum = 0, over24 = 0; const n = w * h;
  for (let i = 0; i < n; i++) { const j = i * 4; const d = (Math.abs(dA[j] - dB[j]) + Math.abs(dA[j + 1] - dB[j + 1]) + Math.abs(dA[j + 2] - dB[j + 2])) / 3; sum += d; if (d > 24) over24++; }
  return { dimsA: [cA.width, cA.height], dimsB: [cB.width, cB.height], drift: [Math.abs(cA.width - cB.width), Math.abs(cA.height - cB.height)], mean: +(sum / n).toFixed(3), pctOver24: +(over24 / n * 100).toFixed(3) };
}, args);
const parityOk = r => r.drift[0] <= 2 && r.drift[1] <= 2 && r.mean < 3 && r.pctOver24 < 1;

// ════ S1: clean 8-page bleed-exact PDF — no gate, skip path, hash of raw ════
{
  const p = await newPage();
  const pdfB64 = await p.evaluate(async () => {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'portrait', unit: 'in', format: [5.75, 8.75] });
    const colors = [[27, 106, 201], [201, 64, 27], [27, 160, 80], [160, 27, 160], [220, 160, 20], [20, 160, 220], [90, 90, 90], [200, 30, 90]];
    for (let i = 0; i < 8; i++) {
      if (i > 0) doc.addPage([5.75, 8.75], 'portrait');
      doc.setFillColor(...colors[i]); doc.rect(0, 0, 5.75, 8.75, 'F');
      doc.setFontSize(48); doc.setTextColor(255, 255, 255); doc.text('PAGE ' + (i + 1), 1.2, 4.4);
    }
    const ab = await doc.output('blob').arrayBuffer();
    let s = ''; const u = new Uint8Array(ab);
    for (let i = 0; i < u.length; i++) s += String.fromCharCode(u[i]);
    return btoa(s);
  });
  fs.writeFileSync('./clean8.pdf', Buffer.from(pdfB64, 'base64'));
  const rawSha = nodeCrypto.createHash('sha256').update(Buffer.from(pdfB64, 'base64')).digest('hex');
  await p.locator('input[type=file]').first().setInputFiles('./clean8.pdf');
  await p.waitForTimeout(6000);
  await openProof(p);
  await waitBadge(p);
  const g1 = await gateState(p);
  check('S1 clean file has NO ack gate', g1.btn && !g1.ack && g1.disabled === false, JSON.stringify(g1));
  const proof1 = await proofUrl(p);
  const cmp1 = await comparePage(p, { pdfB64, pageNum: 1, proofDataUrl: proof1 });
  check('S1 proof p1 vs RAW pdf p1 parity (skip path)', parityOk(cmp1), JSON.stringify(cmp1));
  // flip to page 5
  await p.evaluate(() => { for (let k = 0; k < 4; k++) { const nx = [...document.querySelectorAll('button')].find(y => (y.textContent || '').trim().startsWith('Next')); nx && nx.click(); } });
  await waitBadge(p);
  const proof5 = await proofUrl(p);
  const cmp5 = await comparePage(p, { pdfB64, pageNum: 5, proofDataUrl: proof5 });
  check('S1 proof p5 vs RAW pdf p5 parity', parityOk(cmp5), JSON.stringify(cmp5));
  const okAppr = await clickApprove(p);
  check('S1 approve succeeds', okAppr);
  const man = await p.evaluate(async () => { const m = window.__manifests[window.__manifests.length - 1]; return m ? await m.text() : ''; });
  const mm = man.match(/Print file SHA-256: ([0-9a-f]{64})/);
  check('S1 manifest hash == sha256(raw uploaded PDF)', !!mm && mm[1] === rawSha, (mm ? mm[1].slice(0, 12) : 'none') + ' vs ' + rawSha.slice(0, 12));
  check('S1 manifest says print-ready SKIPPED (vector preserved)', /SKIPPED/.test(man));
  check('S1 no page errors', p.__errs.length === 0, p.__errs[0] || '');
  await p.close();
}

// ════ S2: oversized PNG — fit-mode matrix, each approved & compared ════
{
  const p = await newPage();
  const png = await p.evaluate(() => { const c = document.createElement('canvas'); c.width = 2000; c.height = 2750; const x = c.getContext('2d'); const g = x.createLinearGradient(0, 0, 2000, 2750); g.addColorStop(0, '#1b6ac9'); g.addColorStop(1, '#c9401b'); x.fillStyle = g; x.fillRect(0, 0, 2000, 2750); x.strokeStyle = '#fff'; x.lineWidth = 24; x.strokeRect(40, 40, 1920, 2670); x.fillStyle = '#fff'; x.font = 'bold 150px sans-serif'; x.fillText('TOP EDGE', 600, 220); x.fillText('BOTTOM EDGE', 480, 2660); return c.toDataURL('image/png'); });
  fs.writeFileSync('./art-matrix.png', Buffer.from(png.split(',')[1], 'base64'));
  await p.locator('input[type=file]').first().setInputFiles('./art-matrix.png');
  await p.waitForTimeout(5000);
  await openProof(p);
  // Fit modes live in <select> action pickers (title-matched), not buttons.
  const setMode = (p, mode) => p.evaluate((m) => {
    const sset = (sel, val) => { const s = [...document.querySelectorAll('select')].find(x => x.title === sel); if (!s) return false;
      Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(s, val); s.dispatchEvent(new Event('change', { bubbles: true })); return true; };
    if (m === 'rotate90') return sset('Rotate this page, or every page at once', 'p1');
    return sset('How the art is sized to the page', m);
  }, mode);
  for (const mode of ['cover', 'contain', 'stretch', 'rotate90']) {
    const clicked = await setMode(p, mode);
    await p.waitForTimeout(800);
    const badge = await waitBadge(p);
    const g = await gateState(p);
    if (g.ack && !g.ackChecked) { await tickAck(p); await p.waitForTimeout(400); }
    const before = await blobCounts(p);
    const okA = await clickApprove(p);
    let after = before;
    for (let i = 0; i < 30 && after.pdf < before.pdf + 2; i++) { await p.waitForTimeout(700); after = await blobCounts(p); }
    if (after.pdf < before.pdf + 1) { check(`S2 [${mode}] badge+approve+parity`, false, JSON.stringify({ clicked, badge, okA, before, after })); continue; }
    const printB64 = await p.evaluate(async (idx) => { const bl = window.__pdfBlobs[idx]; const ab = await bl.arrayBuffer(); let s = ''; const u = new Uint8Array(ab); for (let i = 0; i < u.length; i++) s += String.fromCharCode(u[i]); return btoa(s); }, before.pdf);
    const purl = await proofUrl(p);
    const cmp = await comparePage(p, { pdfB64: printB64, pageNum: 1, proofDataUrl: purl });
    check(`S2 [${mode}] badge+approve+parity`, clicked && badge && okA && parityOk(cmp), JSON.stringify(cmp));
  }
  check('S2 no page errors', p.__errs.length === 0, p.__errs[0] || '');
  await p.close();
}

// ════ S3: staleness — slot-replace must recompose ════
{
  const p = await newPage();
  const mk = (color) => p.evaluate((c) => { const cv = document.createElement('canvas'); cv.width = 1725; cv.height = 2625; const x = cv.getContext('2d'); x.fillStyle = c; x.fillRect(0, 0, 1725, 2625); return cv.toDataURL('image/png'); }, color);
  fs.writeFileSync('./art-blue.png', Buffer.from((await mk('#1040c0')).split(',')[1], 'base64'));
  fs.writeFileSync('./art-green.png', Buffer.from((await mk('#10c040')).split(',')[1], 'base64'));
  await p.locator('input[type=file]').first().setInputFiles('./art-blue.png');
  await p.waitForTimeout(4500);
  await openProof(p);
  await waitBadge(p);
  const sample = async () => p.evaluate(async () => {
    const f = document.querySelector('.bp-checker .bp-checker'); const im = f && f.querySelector('img'); if (!im) return null;
    const img = await new Promise((res, rej) => { const i2 = new Image(); i2.onload = () => res(i2); i2.onerror = rej; i2.src = im.src; });
    const c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = img.naturalHeight;
    const x = c.getContext('2d'); x.drawImage(img, 0, 0);
    const d = x.getImageData(Math.floor(c.width / 2), Math.floor(c.height / 2), 1, 1).data;
    return [d[0], d[1], d[2]];
  });
  const s1 = await sample();
  // close modal
  await p.evaluate(() => { const ovs = [...document.querySelectorAll('div')].filter(d => getComputedStyle(d).position === 'fixed' && d.getBoundingClientRect().width > 600); const ov = ovs[ovs.length - 1]; if (ov) { const x = [...ov.querySelectorAll('button')].find(y => /^(✕|✖)$/.test(y.textContent.trim())); x && x.click(); } });
  await p.waitForTimeout(800);
  // slot-replace page 1 (front cover) with green via the slot grid's hidden input
  await p.evaluate(() => { const z = document.querySelector('[data-pps-dropzone]'); if (z) { const clickable = z.querySelector('div'); clickable && clickable.click(); } });
  await p.waitForTimeout(500);
  const slotInput = await p.evaluate(() => { const ins = [...document.querySelectorAll('input[type=file]')]; return ins.length; });
  // the slot click arms slotInputRef; find the input whose value was cleared (last file input)
  await p.locator('input[type=file]').last().setInputFiles('./art-green.png');
  await p.waitForTimeout(3500);
  await openProof(p);
  await waitBadge(p);
  const s2 = await sample();
  const wasBlue = s1 && s1[2] > 140 && s1[0] < 100;
  const nowGreen = s2 && s2[1] > 140 && s2[2] < 110;
  check('S3 slot replace recomposes proof (blue→green)', wasBlue && nowGreen, JSON.stringify({ before: s1, after: s2, inputs: slotInput }));
  check('S3 approved badge cleared after slot replace', await p.evaluate(() => !/✓ Approved/.test(document.body.innerText)));
  check('S3 no page errors', p.__errs.length === 0, p.__errs[0] || '');
  await p.close();
}

// ════ S4: post-approval transform change revokes parent approval — order blocked ════
{
  const p = await newPage();
  fs.writeFileSync('./art-s4.png', fs.readFileSync('./art-matrix.png'));
  await p.locator('input[type=file]').first().setInputFiles('./art-s4.png');
  await p.waitForTimeout(4500);
  // fill shipping first so the gate (not missing-address) is what blocks
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Shipping/i.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(800);
  const setV = async (ph, v) => p.evaluate(([ph, v]) => { const i = [...document.querySelectorAll('input')].find(x => x.placeholder === ph); if (!i) return false; Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(i, v); i.dispatchEvent(new Event('input', { bubbles: true })); return true; }, [ph, v]);
  for (const [ph, v] of [['Full Name', 'Test Buyer'], ['Street address', '123 Main St'], ['City', 'Hermosa Beach'], ['ZIP', '90254']]) await setV(ph, v);
  await p.evaluate(() => { const sels = [...document.querySelectorAll('select')]; const st = sels.find(s => [...s.options].some(o => /^CA$|California/.test(o.value || o.textContent))); if (!st) return; const o = [...st.options].find(o => /^CA$|California/.test(o.value || o.textContent)); Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(st, o.value); st.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.waitForTimeout(2000);
  await openProof(p);
  await waitBadge(p);
  const g = await gateState(p);
  if (g.ack) { await tickAck(p); await p.waitForTimeout(400); }
  const okA = await clickApprove(p);
  check('S4 approved', okA);
  // change a transform AFTER approval
  const s4clicked = await p.evaluate(() => { const s = [...document.querySelectorAll('select')].find(x => x.title === 'How the art is sized to the page'); if (!s) return false;
    Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(s, 'cover'); s.dispatchEvent(new Event('change', { bubbles: true })); return true; });
  check('S4 transform change applied', s4clicked);
  await p.waitForTimeout(1200);
  check('S4 approval badge cleared by transform change', await p.evaluate(() => !/✓ Approved/.test(document.body.innerText)));
  // close modal, try to order
  await p.evaluate(() => { const ovs = [...document.querySelectorAll('div')].filter(d => getComputedStyle(d).position === 'fixed' && d.getBoundingClientRect().width > 600); const ov = ovs[ovs.length - 1]; if (ov) { const x = [...ov.querySelectorAll('button')].find(y => /^(✕|✖)$/.test(y.textContent.trim())); x && x.click(); } });
  await p.waitForTimeout(800);
  const dlsBefore = await p.evaluate(() => (window.__dls || []).length);
  await p.evaluate(() => { window.__dls = []; const oc = HTMLAnchorElement.prototype.click; HTMLAnchorElement.prototype.click = function () { if (this.download) window.__dls.push(this.download); return oc.call(this); }; });
  p.__dialogs.length = 0;
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Add to Order/i.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(3500);
  const dls = await p.evaluate(() => window.__dls);
  const blocked = p.__dialogs.some(d => /approv/i.test(d));
  check('S4 order BLOCKED after post-approval transform change', blocked && dls.length === 0, JSON.stringify({ dialogs: p.__dialogs.map(d => d.slice(0, 60)), dls }));
  check('S4 no page errors', p.__errs.length === 0, p.__errs[0] || '');
  await p.close();
}

await b.close();
console.log(failures.length ? `>>> ${failures.length} FAILURE(S): ${failures.join('; ')}` : '>>> EXTENDED BATTERY: ALL PASS');
process.exit(failures.length ? 1 : 0);
