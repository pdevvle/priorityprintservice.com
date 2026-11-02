// The flats, third sweep (2026-10-13): what an adversarial pass over the shipped postcard,
// brochure, greeting card, letterhead and sticker builds found, each replayed as a customer
// would meet it. Every check here was run against the staging builds (a5a3b28) first and
// fails there.
//
//   shape     — approve, then change the job (size; perforation): the approval goes and Add to
//               Order asks for a new one. Only the postcard cleared it; the others shipped the
//               old print file for the new job.
//   artopt    — approve, switch Artwork & Design away, change the size, switch back: the empty
//               drop zone must not order the old approved package at the old size.
//   rotate    — a PDF whose pages carry /Rotate 90 (a portrait designer file, uploaded again):
//               the print file must be the page as it is seen, not squashed. The booklets'
//               extractPdfPages must measure such a page the way it renders it.
//   onesided  — a two-page PDF on a one-sided job prints one page and the proof has no Back;
//               made two-sided afterwards, page 2 becomes the back.
//   perfedit  — a cart edit or reorder carrying a moved perforation keeps it.
//   labels    — folds across the short side: the back's panel names read the right way up in
//               the proof, the template and (postcard) the designer's spec.
//   names     — a side replaced through its own box: the manifest lists the originals the
//               order carries and names each side's file; the package is named for the front.
//   testdl    — the approval-package download panel is a staff tool, not shown to customers.
//   risk      — Remove uploads, then an image: no warning left over from the removed PDF.
//   dropzone  — the drop zone promises only what it accepts (no TIFF), and a file the browser
//               cannot read says so instead of sitting on "Processing...".
//   prevperf  — the preview JPEGs (the approval record) draw the perforation, mirrored on the
//               back like the folds.
//   edit      — a cart edit that changes the size of an online-approved job is refused at Add
//               to Order instead of carrying the old approval; an unchanged edit still orders.
//
// Setup: flat-<calc>.html harness copies (tools-harness-prep.mjs) served on PPS_CALC_BASE
// (default http://127.0.0.1:8137), and for the booklet half parity-saddle.html,
// parity-pb.html and slot-coupon.html beside them; python3 with pymupdf.
// PPS_SWEEP3_PAGES / PPS_SWEEP3_BOOKS select builds (a name containing flat-<calc> says which
// calculator it is) — how the staging copies were run to prove these checks fail there.
// PPS_SWEEP3_ONLY=<regex> runs only the scenarios it matches.
//
// Run: node tools-flat-sweep3-test.mjs

import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { execFileSync } from 'node:child_process';

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const BASE = process.env.PPS_CALC_BASE || 'http://127.0.0.1:8137';
const PAGES = (process.env.PPS_SWEEP3_PAGES ?? 'flat-postcard.html,flat-brochure.html,flat-greeting-card.html,flat-letterhead.html,flat-sticker.html').split(',').filter(Boolean);
const BOOKS = (process.env.PPS_SWEEP3_BOOKS ?? 'parity-saddle.html,parity-pb.html,slot-coupon.html').split(',').filter(Boolean);
const ONLY = process.env.PPS_SWEEP3_ONLY ? new RegExp(process.env.PPS_SWEEP3_ONLY) : null;
const run = name => !ONLY || ONLY.test(name);

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};
const py = (code, ...args) => JSON.parse(execFileSync('python3', ['-I', '-c', code, ...args]).toString());

// ── Artwork, made here so the suite carries nothing binary ──
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'pps-sweep3-'));
py(`
import sys, json, pymupdf
out = sys.argv[1]
def sheet(doc, W, H, label, bg):
    p = doc.new_page(width=W*72, height=H*72)
    p.draw_rect(p.rect, color=None, fill=bg)
    # Corner squares as the side is SEEN: red top left, green top right, blue bottom left, yellow bottom right.
    for (x, y, c) in [(0.25, 0.25, (1,0,0)), (W-0.75, 0.25, (0,0.7,0)), (0.25, H-0.75, (0,0,1)), (W-0.75, H-0.75, (1,0.85,0))]:
        p.draw_rect(pymupdf.Rect(x*72, y*72, (x+0.5)*72, (y+0.5)*72), color=None, fill=c)
    p.insert_text((W*72*0.35, H*72*0.55), label, fontsize=min(W, H)*8)
def two(W, H, name, labels):
    d = pymupdf.open()
    for i, l in enumerate(labels): sheet(d, W, H, l, (0.93,0.93,0.93) if i == 0 else (0.85,0.88,1))
    d.save(out + '/' + name)
def rotated(W, H, name, n):
    # The same sides as seen, stored as pages the other way up and turned by /Rotate 90.
    src = pymupdf.open(); sheet(src, W, H, "FRONT", (0.93,0.93,0.93)); sheet(src, W, H, "BACK", (0.85,0.88,1))
    d = pymupdf.open()
    for i in range(n):
        p = d.new_page(width=H*72, height=W*72); p.show_pdf_page(p.rect, src, min(i, 1), rotate=90); p.set_rotation(90)
    d.save(out + '/' + name)
for W, H in [(6.25, 4.25), (11.25, 8.75)]:
    k = '%gx%g' % (W, H)
    two(W, H, 'two-' + k + '.pdf', ['FRONT', 'BACK'])
    two(W, H, 'alpha-' + k + '.pdf', ['ALPHA 1', 'ALPHA 2'])
    two(W, H, 'bravo-front-' + k + '.pdf', ['BRAVO'])
    two(W, H, 'charlie-back-' + k + '.pdf', ['CHARLIE'])
    rotated(W, H, 'rot-' + k + '.pdf', 2)
rotated(5.75, 8.75, 'rot-book.pdf', 4)
d = pymupdf.open(); p = d.new_page(width=450, height=306)
p.draw_rect(pymupdf.Rect(20, 20, 300, 200), color=None, fill=(1,0,0), fill_opacity=0.5); d.save(out + '/transp.pdf')
pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 625, 425), False); pix.set_rect(pix.irect, (200, 220, 240)); pix.save(out + '/plain.png')
print('1')`, TMP);
const ART = n => path.join(TMP, n);

// Per calculator: the two-sided 6 × 4 job (letterhead: its 8.5 × 11 default), the sheet with
// bleed, and what it has.
const CALC = {
  postcard:        { q2: 'sides=2&sizemode=custom&long=6&short=4', q1: 'sides=1&sizemode=custom&long=6&short=4', k: '6.25x4.25', perfs: true, folds: true },
  brochure:        { q2: 'sides=2&sizemode=custom&long=6&short=4', q1: 'sides=1&sizemode=custom&long=6&short=4', k: '6.25x4.25', perfs: true, folds: true },
  'greeting-card': { q2: 'sides=2&sizemode=custom&long=6&short=4', q1: 'sides=1&sizemode=custom&long=6&short=4', k: '6.25x4.25', perfs: true, folds: true },
  letterhead:      { q2: 'sides=2', q1: 'sides=1', k: '11.25x8.75', perfs: true, folds: false },
  sticker:         { q2: 'size=4x6', q1: 'size=4x6', k: '6.25x4.25', perfs: false, folds: false, oneSided: true },
};
const ADDR = { shipState: 'AZ', shipAddr: { name: 'Test', street1: '1 Main St', city: 'Phoenix', zip: '85087' } };
// As the server writes it (wp_json_encode escapes non-ASCII; the calculator reads it with atob).
const b64url = o => Buffer.from(JSON.stringify(o).replace(/[\u0080-\uffff]/g, c => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0'))).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

// One page against a fake WordPress: uploads and add-to-cart recorded, dialogs answered.
function parts(buf) {
  const s = buf.toString('latin1'); const bnd = s.slice(0, s.indexOf('\r\n')); const out = [];
  let i = 0;
  while ((i = s.indexOf(bnd, i)) >= 0) {
    const h0 = i + bnd.length + 2; const he = s.indexOf('\r\n\r\n', h0); if (he < 0) break;
    const hdr = s.slice(h0, he); const st = he + 4; const en = s.indexOf('\r\n' + bnd, st); if (en < 0) break;
    const nm = /name="([^"]*)"/.exec(hdr), fn = /filename="([^"]*)"/.exec(hdr);
    out.push({ name: nm && nm[1], filename: fn && fn[1], bytes: buf.subarray(st, en) });
    i = en + 2;
  }
  return out;
}
async function open(b, file, { query = '', rc = ADDR, cfg = {}, dialog = 'accept' } = {}) {
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 } });
  const p = await ctx.newPage();
  const st = { dialogs: [], errs: [], uploads: [], cart: [] };
  p.on('dialog', async d => { st.dialogs.push(d.message()); if (dialog === 'dismiss') await d.dismiss(); else await d.accept(); });
  p.on('pageerror', e => st.errs.push(String(e).slice(0, 200)));
  await p.addInitScript(([r, cfg]) => {
    window.PPS_CONFIG = Object.assign({ ajaxUrl: location.origin + '/wp-admin/admin-ajax.php', cartUrl: location.origin + '/cart.html', cartNonce: 'c', uploadNonce: 'u', productId: 22673, maxUpload: 209715200, reorder: r }, cfg);
  }, [rc ? b64url(rc) : undefined, cfg]);
  await p.route('**/wp-admin/admin-ajax.php**', async (route) => {
    const ps = parts(route.request().postDataBuffer() || Buffer.alloc(0));
    const get = n => { const x = ps.find(q => q.name === n && !q.filename); return x ? x.bytes.toString('utf8') : null; };
    const action = get('action');
    if (action === 'pps_upload_artwork') {
      const f = ps.find(q => q.filename != null);
      st.uploads.push({ name: f ? f.filename : null, bytes: f ? Buffer.from(f.bytes) : null });
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { path: 'pps-artwork/2026/10/' + st.uploads.length + '-' + (f && f.filename) } }) });
    }
    if (action === 'pps_add_to_cart') {
      st.cart.push(Object.fromEntries(ps.filter(q => !q.filename).map(q => [q.name, q.bytes.toString('utf8')])));
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { cart_item_key: 'k' + st.cart.length } }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: false }) });
  });
  await p.route('**/cart.html', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>CART</h1>' }));
  await p.goto(BASE + '/' + file + (query ? '?' + query : ''), { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('select'); await p.waitForTimeout(2000);
  return { ctx, p, st };
}
const upload = async (p, files, idx = 0) => { await p.locator('input[type=file]').nth(idx).setInputFiles(files); await p.waitForTimeout(3500); };
const slotIdx = p => p.evaluate(() => [...document.querySelectorAll('input[type=file]')].map((x, i) => [x, i]).filter(([x]) => !x.multiple && x.accept === '.pdf,.jpg,.jpeg,.png').map(([, i]) => i));
const clickText = (p, re) => p.evaluate(([src, fl]) => { const r = new RegExp(src, fl); const b = [...document.querySelectorAll('button')].filter(y => r.test((y.textContent || '').trim()) && y.offsetParent !== null); if (!b.length) return false; b[0].click(); return true; }, [re.source, re.flags]);
async function openProof(p) {
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /🔍 Proof|Proof Approved/.test(y.textContent || '')); if (x) return x.click(); const img = [...document.querySelectorAll('img')].find(i => /^data:image/.test(i.src)); img && img.parentElement && img.parentElement.click(); });
  await p.waitForTimeout(1500);
}
async function closeModal(p) { await p.keyboard.press('Escape'); await p.waitForTimeout(300); await p.evaluate(() => { const b = [...document.querySelectorAll('button')].filter(x => (x.textContent || '').trim() === '✕' && x.offsetParent !== null && x.closest('[style*="z-index: 9999"]')); b.length && b[b.length - 1].click(); }); await p.waitForTimeout(400); }
async function approve(p) {
  await openProof(p);
  await p.evaluate(() => { window.__man = 0; if (!window.__blobWrapped) { window.__blobWrapped = 1; const OB = window.Blob; window.Blob = function (parts, o) { const bl = new OB(parts, o); if (o && o.type === 'text/plain') window.__man++; return bl; }; window.Blob.prototype = OB.prototype; } });
  await p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input[type=checkbox]'); cb && !cb.checked && cb.click(); });
  if (!(await clickText(p, /^Approve (artwork|Now)$/i))) return false;
  for (let i = 0; i < 120; i++) { await p.waitForTimeout(500); if (await p.evaluate(() => window.__man > 0 && ![...document.querySelectorAll('button')].some(b => /Rendering…|Previews…|Finalizing…|Preparing…/.test(b.textContent || '')))) break; }
  await p.waitForTimeout(600);
  return p.evaluate(() => window.__man > 0);
}
async function addToOrder(p, st, wait = 40) {
  const n0 = st.cart.length;
  await p.evaluate(() => { const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim())); const b = btns.find(x => x.offsetParent !== null && !x.disabled) || btns.find(x => !x.disabled); b && b.click(); });
  for (let i = 0; i < wait * 2 && st.cart.length === n0; i++) { await p.waitForTimeout(500); if (i > 6 && st.dialogs.length && st.uploads.length === 0) break; }
  return st.cart.length > n0;
}
const approvedShown = p => p.evaluate(() => /Artwork Approved/.test(document.body.innerText));
const setSel = (p, pred, pick) => p.evaluate(([pred, pick]) => { const s = [...document.querySelectorAll('select')].find(x => new Function('x', 'return ' + pred)(x)); if (!s) return null; const v = new Function('s', 'return ' + pick)(s); const set = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set; set.call(s, v); s.dispatchEvent(new Event('change', { bubbles: true })); return v; }, [pred, pick]);
const SIZE_SEL = "[...x.options].some(o => /×/.test(o.text) && !/Custom/.test(o.text))";
const otherSize = p => setSel(p, SIZE_SEL, "[...s.options].map(o => o.value).find(v => v !== s.value && /×/.test(v))");
const artOpt = (p, v) => setSel(p, "[...x.options].some(o => /Email Art After Order/.test(o.text))", JSON.stringify(v));
const saveUp = (st, re) => { const u = st.uploads.find(x => re.test(x.name)); if (!u) return null; const f = path.join(TMP, String(Math.random()).slice(2) + '-' + u.name); fs.writeFileSync(f, u.bytes); return f; };
const meta = st => { try { return JSON.parse(st.cart[0].pps_metadata); } catch (e) { return null; } };

const b = await chromium.launch({ args: ['--no-sandbox'] });
for (const file of PAGES) {
  const calc = (file.match(/flat-(postcard|brochure|greeting-card|letterhead|sticker)/) || [])[1];
  if (!calc) { console.log('skip ' + file + ' (no calculator in its name)'); continue; }
  const C = CALC[calc];

  // ── shape: approve, then change the job ──
  if (run('shape')) {
    const { ctx, p, st } = await open(b, file, { query: 'sides=2' });
    await upload(p, ART('two-' + C.k + '.pdf'));
    const ap = await approve(p); await closeModal(p);
    const before = await approvedShown(p);
    const did = await otherSize(p); await p.waitForTimeout(1200);
    const after = await approvedShown(p);
    const added = await addToOrder(p, st, 20);
    ok(`${calc} shape: changing the size after approval clears the approval and Add to Order asks again`,
      ap && before && did && !after && !added && st.dialogs.some(d => /approve your artwork proof/i.test(d)), JSON.stringify({ ap, before, did, after, added, dialogs: st.dialogs.map(d => d.slice(0, 60)) }));
    await ctx.close();
    if (C.perfs) {
      const t = await open(b, file, { query: 'sides=2' });
      await upload(t.p, ART('two-' + C.k + '.pdf'));
      await approve(t.p); await closeModal(t.p);
      const b4 = await approvedShown(t.p);
      const perf = await setSel(t.p, "[...x.options].some(o => /Perforation Line/.test(o.text))", "[...s.options].find(o => /1 Perforation Line/.test(o.text)).value");
      await t.p.waitForTimeout(1200);
      ok(`${calc} shape: adding a perforation after approval clears the approval`, b4 && perf && !(await approvedShown(t.p)), JSON.stringify({ b4, perf }));
      await t.ctx.close();
    }
  }

  // ── artopt: the approved package outlives the uploader ──
  if (run('artopt')) {
    const { ctx, p, st } = await open(b, file, { query: 'sides=2' });
    await upload(p, ART('two-' + C.k + '.pdf'));
    const ap = await approve(p); await closeModal(p);
    await artOpt(p, '0.02'); await p.waitForTimeout(600);
    await otherSize(p); await p.waitForTimeout(800);
    await artOpt(p, '0.01'); await p.waitForTimeout(800);
    const added = await addToOrder(p, st, 20);
    ok(`${calc} artopt: back on "Upload Art" with an empty drop zone, the old approved package is not ordered`,
      ap && !added && !st.uploads.some(u => /_print-ready\.pdf$/.test(u.name)) && st.dialogs.some(d => /add your artwork file/i.test(d)),
      JSON.stringify({ ap, added, uploads: st.uploads.map(u => u.name), dialogs: st.dialogs.map(d => d.slice(0, 60)) }));
    await ctx.close();
  }

  // ── rotate: a /Rotate 90 PDF prints as it is seen ──
  if (run('rotate')) {
    const { ctx, p, st } = await open(b, file, { query: C.q2 });
    await upload(p, ART('rot-' + C.k + '.pdf'));
    const ap = await approve(p); await closeModal(p);
    const added = await addToOrder(p, st);
    const pr = saveUp(st, /_print-ready\.pdf$/);
    const corners = pr ? py(`
import sys, json, pymupdf
d = pymupdf.open(sys.argv[1]); p = d[0]; pix = p.get_pixmap(dpi=40)
W = p.rect.width / 72; H = p.rect.height / 72
at = lambda x, y: pix.pixel(int(x * 40), int(y * 40))
def name(c):
    r, g, b = c[:3]
    return 'red' if r > 200 and g < 80 and b < 80 else 'green' if g > 140 and r < 80 and b < 80 else 'blue' if b > 200 and r < 80 and g < 80 else 'yellow' if r > 200 and g > 180 and b < 80 else 'other'
print(json.dumps([name(at(0.5, 0.5)), name(at(W - 0.5, 0.5)), name(at(0.5, H - 0.5)), name(at(W - 0.5, H - 0.5))]))`, pr) : null;
    ok(`${calc} rotate: a page turned by /Rotate 90 prints the way it is seen, corners where they belong`,
      ap && added && JSON.stringify(corners) === JSON.stringify(['red', 'green', 'blue', 'yellow']), JSON.stringify({ ap, added, corners }));
    await ctx.close();
  }

  // ── onesided: page 2 of a PDF is a back only when the job has one ──
  if (run('onesided') && !C.oneSided) {
    {
      const { ctx, p, st } = await open(b, file, { query: C.q1 });
      await upload(p, ART('two-' + C.k + '.pdf'));
      await openProof(p);
      const toggle = await p.evaluate(() => [...document.querySelectorAll('button')].some(b => (b.textContent || '').trim() === 'Back' && b.offsetParent));
      await closeModal(p);
      const ap = await approve(p); await closeModal(p);
      const added = await addToOrder(p, st);
      const pr = saveUp(st, /_print-ready\.pdf$/);
      const pages = pr ? py('import sys, json, pymupdf; print(len(pymupdf.open(sys.argv[1])))', pr) : null;
      ok(`${calc} onesided: a two-page PDF on a one-sided job has no Back in the proof and prints one page`,
        !toggle && ap && added && pages === 1 && !st.uploads.some(u => /_preview_back/.test(u.name)), JSON.stringify({ toggle, ap, added, pages, uploads: st.uploads.map(u => u.name) }));
      await ctx.close();
    }
    {
      const { ctx, p, st } = await open(b, file, { query: C.q1 });
      await upload(p, ART('two-' + C.k + '.pdf'));
      await clickText(p, /^Both Sides$/); await p.waitForTimeout(3000);
      const ap = await approve(p); await closeModal(p);
      const added = await addToOrder(p, st);
      const pr = saveUp(st, /_print-ready\.pdf$/);
      const pages = pr ? py('import sys, json, pymupdf; print(len(pymupdf.open(sys.argv[1])))', pr) : null;
      ok(`${calc} onesided: made two-sided afterwards, page 2 of the same PDF becomes the back`,
        ap && added && pages === 2 && !st.dialogs.some(d => /only one side has artwork/.test(d)), JSON.stringify({ ap, added, pages, dialogs: st.dialogs.map(d => d.slice(0, 60)) }));
      await ctx.close();
    }
  }

  // ── perfedit: a moved perforation survives a cart edit or reorder ──
  if (run('perfedit') && C.perfs) {
    const rc = Object.assign({}, ADDR, { perforation: 2000, perfDir: 'length', perfPositions: [25], artwork: 0.02, sides: 2 });
    const { ctx, p, st } = await open(b, file, { rc });
    await p.waitForTimeout(800);
    const added = await addToOrder(p, st, 20);
    const m = meta(st);
    ok(`${calc} perfedit: a perforation moved to 25% comes back at 25% from an edit, not the middle`,
      added && m && JSON.stringify(m.perfPositions) === '[25]', JSON.stringify({ added, perfPositions: m && m.perfPositions }));
    await ctx.close();
  }

  // ── labels: panel names on the back of a sheet folded across its short side ──
  if (run('labels') && C.folds) {
    const cfg = calc === 'postcard' ? { calc: { pcf: { designer_url: '/fake-designer.html' } } } : {};
    const { ctx, p } = await open(b, file, { query: 'sides=2&sizemode=custom&long=11&short=8.5&fold=trifold&folddir=short', cfg });
    if (calc === 'postcard') {
      // A stand-in designer: says it is ready and keeps the job it is given.
      await p.route(/fake-designer\.html/, route => route.fulfill({ status: 200, contentType: 'text/html', body:
        '<div id="app"></div><script>addEventListener("message", e => { if (e.data && e.data.type === "pps-design:job") window.__job = e.data.spec; });' +
        'parent.postMessage({ v: 1, type: "pps-design:ready" }, location.origin);<' + '/script>' }));
      await p.evaluate(() => { const bt = document.querySelector('[data-pps-design="open"]'); bt && bt.click(); });
      await p.waitForTimeout(2000);
      const fr = p.frames().find(f => /fake-designer/.test(f.url()));
      const spec = fr ? await fr.evaluate(() => window.__job && window.__job.panelLabels) : null;
      ok(`${calc} labels: the designer's back page names read top to bottom behind the front's`,
        JSON.stringify(spec) === JSON.stringify([['Inside Flap', 'Back', 'Front'], ['Inside Right', 'Inside Center', 'Inside Left']]), JSON.stringify(spec));
      await ctx.close();
    }
    const t = calc === 'postcard' ? await open(b, file, { query: 'sides=2&sizemode=custom&long=11&short=8.5&fold=trifold&folddir=short' }) : { ctx, p };
    await upload(t.p, ART('two-11.25x8.75.pdf'));
    await openProof(t.p);
    const read = () => t.p.evaluate(() => {
      const c = [...document.querySelectorAll('.bp-checker')].find(d => d.style.aspectRatio); const R = c.getBoundingClientRect();
      return [...c.querySelectorAll('div[style*="z-index: 3"] > span')].map(s => { const r = s.getBoundingClientRect(); return [s.textContent, (r.top + r.height / 2 - R.top) / R.height]; })
        .filter(x => !/Bleed|Trim|Safety|Fold|Perf/.test(x[0])).sort((a, b2) => a[1] - b2[1]).map(x => x[0]);
    });
    const front = await read();
    await clickText(t.p, /^Back$/); await t.p.waitForTimeout(500);
    const back = await read();
    ok(`${calc} labels: in the proof, the back's top panel is the inside of the front's top panel`,
      JSON.stringify(front) === JSON.stringify(['Inside Flap', 'Back', 'Front']) && JSON.stringify(back) === JSON.stringify(['Inside Right', 'Inside Center', 'Inside Left']), JSON.stringify({ front, back }));
    await closeModal(t.p);
    const tpl = await t.p.evaluate(() => {
      let out = null; const J = window.jspdf.jsPDF;
      window.jspdf.jsPDF = function (...a) { const d = new J(...a); d.save = () => { out = d.output('datauristring'); }; return d; };
      try { const bt = [...document.querySelectorAll('button')].find(x => /Download Template/.test(x.textContent || '')); bt && bt.click(); } finally { window.jspdf.jsPDF = J; }
      return out && out.split(',')[1];
    });
    let names = null;
    if (tpl) {
      const f = path.join(TMP, calc + '-tpl.pdf'); fs.writeFileSync(f, Buffer.from(tpl, 'base64'));
      names = py(`
import sys, json, pymupdf
d = pymupdf.open(sys.argv[1]); want = ["Inside Flap", "Back", "Front", "Inside Left", "Inside Center", "Inside Right"]; out = []
for pg in d:
    found = []
    for b in pg.get_text("dict")["blocks"]:
        for l in b.get("lines", []):
            for s in l["spans"]:
                t = s["text"].strip()
                c = s["color"]; rgb = ((c >> 16) & 255, (c >> 8) & 255, c & 255)
                if t in want and all(abs(rgb[i] - (180, 140, 60)[i]) <= 3 for i in range(3)): found.append((s["bbox"][1], t))
    out.append([t for _, t in sorted(found)])
print(json.dumps(out))`, f);
    }
    ok(`${calc} labels: the template's back page names read top to bottom behind the front's`,
      names && JSON.stringify(names[1]) === JSON.stringify(['Inside Right', 'Inside Center', 'Inside Left']), JSON.stringify(names));
    await t.ctx.close();
  }

  // ── names (+ testdl): a side replaced through its own box ──
  if (run('names')) {
    const { ctx, p, st } = await open(b, file, { query: C.q2 });
    await upload(p, ART('alpha-' + C.k + '.pdf'));
    const si = await slotIdx(p);
    const sides = C.oneSided ? [['bravo-front', si[si.length - 1]]] : [['bravo-front', si[si.length - 2]], ['charlie-back', si[si.length - 1]]];
    for (const [n, i] of sides) { await p.locator('input[type=file]').nth(i).setInputFiles(ART(n + '-' + C.k + '.pdf')); await p.waitForTimeout(2500); }
    const ap = await approve(p);
    const testDl = await p.evaluate(() => /TEST DOWNLOAD/.test(document.body.innerText));
    ok(`${calc} testdl: a customer who approves sees no "TEST DOWNLOAD" staff panel`, ap && !testDl, JSON.stringify({ ap, testDl }));
    await closeModal(p);
    await addToOrder(p, st);
    const man = (st.uploads.find(u => /manifest\.txt$/.test(u.name)) || {}).bytes;
    const text = man ? man.toString('utf8') : '';
    const names = st.uploads.map(u => u.name);
    const want = C.oneSided ? ['bravo-front'] : ['bravo-front', 'charlie-back'];
    ok(`${calc} names: the manifest lists the originals the order carries and names each side's file, never the replaced one`,
      !!man && !/alpha/.test(text) && want.every(n => new RegExp('Raw files?: [^\\n]*' + n).test(text) && new RegExp('source file: ' + n).test(text)),
      (text.match(/(Filename|Raw files?|source file):[^\n]*/g) || []).join(' | '));
    ok(`${calc} names: the package is named for the front's file`, names.some(n => /^bravo-front-[\d.x]+_print-ready\.pdf$/.test(n)) && !names.some(n => /^alpha/.test(n)), names.join(','));
    await ctx.close();
  }

  // ── risk + dropzone ──
  if (run('risk')) {
    const { ctx, p, st } = await open(b, file, { query: C.q2 });
    const zone = await p.evaluate(() => [...document.querySelectorAll('div')].map(d => d.textContent).find(t => /up to 200 MB/.test(t || '') && t.length < 140) || '');
    ok(`${calc} dropzone: it promises only the formats it accepts (no TIFF)`, /PDF, JPG or PNG/.test(zone) && !/TIFF/.test(zone), zone);
    const risk = () => p.evaluate(() => { const d = [...document.querySelectorAll('div')].find(x => /^⚠ Uses /.test((x.textContent || '').trim())); return d ? d.textContent.trim().slice(0, 40) : null; });
    await upload(p, ART('transp.pdf'));
    await openProof(p); const r1 = await risk(p); await closeModal(p);
    await clickText(p, /^Remove uploads$/); await p.waitForTimeout(500);
    await upload(p, ART('plain.png'));
    await openProof(p); const r2 = await risk(p); await closeModal(p);
    ok(`${calc} risk: the removed PDF's transparency warning does not follow an image uploaded after it`, !!r1 && !r2, JSON.stringify({ withPdf: r1, afterImage: r2 }));
    await ctx.close();
  }
  if (run('dropzone')) {
    const { ctx, p, st } = await open(b, file, { query: C.q2 });
    await p.evaluate(() => { const R = FileReader.prototype.readAsDataURL; FileReader.prototype.readAsDataURL = function (f) { if (/\.png$/.test(f.name)) { setTimeout(() => { this.onerror && this.onerror(new Event('error')); }, 10); return; } return R.call(this, f); }; });
    await p.locator('input[type=file]').first().setInputFiles(ART('plain.png'));
    await p.waitForTimeout(2500);
    const zone = await p.evaluate(() => { const d = [...document.querySelectorAll('div')].find(x => /^(Processing\.\.\.|Drop your artwork here or click to upload)$/.test((x.textContent || '').trim())); return d ? d.textContent.trim() : null; });
    ok(`${calc} dropzone: a file the browser cannot read is named, and the drop zone does not hang on "Processing..."`,
      zone !== 'Processing...' && st.dialogs.some(d => /plain\.png/.test(d)), JSON.stringify({ zone, dialogs: st.dialogs.map(d => d.slice(0, 60)) }));
    await ctx.close();
  }

  // ── prevperf: the preview JPEGs carry the perforation, mirrored on the back ──
  if (run('prevperf') && C.perfs) {
    const { ctx, p, st } = await open(b, file, { query: C.q2 + '&perf=2000&perfpos=25' });
    await upload(p, ART('two-' + C.k + '.pdf'));
    const ap = await approve(p); await closeModal(p);
    const added = await addToOrder(p, st);
    const res = {};
    for (const s of ['front', 'back']) {
      const f = saveUp(st, new RegExp('_preview_' + s + '\\.jpg$'));
      res[s] = f ? py(`
import sys, json, pymupdf
pix = pymupdf.Pixmap(sys.argv[1]); W, H, n, s = pix.width, pix.height, pix.n, pix.samples
xs, ys = [], []
# The bottom strip holds the guide legend (its "Perf" is purple too): leave it out.
for y in range(0, int(H * 0.96), 2):
    for x in range(0, W, 2):
        o = (y * W + x) * n; r, g, b = s[o], s[o + 1], s[o + 2]
        if 110 < r < 170 and g < 120 and b > 200: xs.append(x); ys.append(y)
if not xs: print('null')
else:
    # The line is the column (or row) holding the most purple: a few fringe pixels round the
    # artwork's blue corner squares must not pull it.
    from collections import Counter
    cx, cy = Counter(xs).most_common(1)[0], Counter(ys).most_common(1)[0]
    vertical = cx[1] >= cy[1]
    near = [x for x in xs if abs(x - cx[0]) <= 4] if vertical else [y for y in ys if abs(y - cy[0]) <= 4]
    mx = my = sum(near) / len(near)
    # As a share of the trim (0.125 in bleed each side of the bleed sheet).
    print(json.dumps({ 'vertical': vertical, 'at': round(((mx if vertical else my) / (W if vertical else H) * float(sys.argv[2] if vertical else sys.argv[3]) - 0.125) / (float(sys.argv[2] if vertical else sys.argv[3]) - 0.25), 3) }))`, f, C.k.split('x')[0], C.k.split('x')[1]) : null;
    }
    const f = res.front, k = res.back;
    const want = f && f.vertical ? [0.25, 0.75] : [0.25, 0.25];
    ok(`${calc} prevperf: both preview images draw the perforation, the back's mirrored as the back sees it`,
      ap && added && f && k && Math.abs(f.at - want[0]) < 0.02 && Math.abs(k.at - want[1]) < 0.02, JSON.stringify(res));
    await ctx.close();
  }

  // ── edit: an online-approved job edited to another size must be approved again ──
  if (run('edit')) {
    const probe = await open(b, file, { query: C.q2 === 'size=4x6' ? '' : 'sides=2' });
    const label = await probe.p.evaluate((sel) => { const s = [...document.querySelectorAll('select')].find(x => new Function('x', 'return ' + sel)(x)); return s ? s.value : null; }, SIZE_SEL);
    await probe.ctx.close();
    const rc = Object.assign({}, ADDR, { artwork: 0.01, proof: 0, artworkPath: 'pps-artwork/2026/10/1-old.pdf', sizeMode: 'preset', sizeLabel: label }, C.oneSided ? {} : { sides: 2 });
    {
      const { ctx, p, st } = await open(b, file, { rc });
      const added = await addToOrder(p, st, 20);
      ok(`${calc} edit: an edit that keeps the job orders with its artwork as before`, added && st.cart[0].pps_artwork_path === 'pps-artwork/2026/10/1-old.pdf', JSON.stringify({ added, dialogs: st.dialogs.map(d => d.slice(0, 60)) }));
      await ctx.close();
    }
    {
      const { ctx, p, st } = await open(b, file, { rc });
      const did = await otherSize(p); await p.waitForTimeout(800);
      const added = await addToOrder(p, st, 20);
      ok(`${calc} edit: an edit to another size is not ordered on the old approval, and says why`,
        did && !added && st.dialogs.some(d => /changed the size/.test(d)), JSON.stringify({ from: label, to: did, added, dialogs: st.dialogs.map(d => d.slice(0, 60)) }));
      await ctx.close();
    }
  }
}

// ── rotate, booklets: extractPdfPages measures a turned page as it renders it ──
if (run('rotate')) for (const file of BOOKS) {
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 } });
  const p = await ctx.newPage();
  await p.addInitScript(() => { window.PPS_CONFIG = { ajaxUrl: location.origin + '/wp-admin/admin-ajax.php', cartUrl: location.origin + '/cart.html', cartNonce: 'c', uploadNonce: 'u', productId: 1, maxUpload: 209715200 }; });
  await p.goto(BASE + '/' + file, { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('select'); await p.waitForTimeout(2000);
  // The calculators run sealed (one closure), so the two functions are lifted out of the
  // build's own text and run in its page, beside the pdf.js it loaded.
  const html = await (await fetch(BASE + '/' + file)).text();
  const lift = (name) => { const at = html.search(new RegExp('(async )?function ' + name + '\\(')); if (at < 0) return ''; let d = 0; for (let i = html.indexOf('{', at); i < html.length; i++) { if (html[i] === '{') d++; else if (html[i] === '}' && --d === 0) return html.slice(at, i + 1); } return ''; };
  const src = lift('pageLabel') + '\n' + lift('extractPdfPages') + '\nreturn extractPdfPages;';
  const r = await p.evaluate(async ([b64, src]) => {
    const bytes = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
    const extractPdfPages = new Function(src)();
    const ex = await extractPdfPages(new File([bytes], 'rot-book.pdf', { type: 'application/pdf' }));
    const img = await new Promise(res => { const i = new Image(); i.onload = () => res([i.naturalWidth, i.naturalHeight]); i.src = ex.pages[0].dataUrl; });
    return { wIn: ex.dims.wIn, hIn: ex.dims.hIn, px: img };
  }, [fs.readFileSync(ART('rot-book.pdf')).toString('base64'), src]);
  ok(`${file} rotate: a /Rotate 90 page is measured as it renders (5.75 × 8.75 in, the same shape as its pixels)`,
    Math.abs(r.wIn - 5.75) < 0.01 && Math.abs(r.hIn - 8.75) < 0.01 && Math.abs(r.wIn / r.hIn - r.px[0] / r.px[1]) < 0.01, JSON.stringify(r));
  await ctx.close();
}

await b.close();
fs.rmSync(TMP, { recursive: true, force: true });
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> FLAT SWEEP 3 FAILED' : '>>> FLAT SWEEP 3 OK');
process.exit(failed ? 1 : 0);
