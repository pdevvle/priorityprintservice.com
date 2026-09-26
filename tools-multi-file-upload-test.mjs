// Every file the customer chose has to reach the order.
//
// Order 87273 (2026-09-25): a coupon book, a staff digital proof, twenty JPGs
// chosen at once. The calculator showed all twenty pages. Production received
// one file, 1.jpg. The uploader's multi-file handler passed `[fileList[0]]` to
// the order, and the whole list was only ever assembled inside the
// self-approval path — so any order without a self-approved package (a staff
// or hardcopy proof) shipped file 1 of N, silently, on all three booklets,
// from the first commit in March. Slot uploads had the twin: a page dropped on
// a slot sent the slot files ALONE, dropping the whole-file set beneath them.
//
// Perfect bound and coupon book had a third gap in the same place: clearing an
// approval (the Review button, a transform, a new wraparound cover) cleared it
// in the panel but never told the order, so the parent kept the old package
// marked approved and the approval gate let the changed job through. The saddle
// closed that on 2026-08-24 with revokeApproval(); the copies never got it.
//
// Scenarios, on the saddle, perfect-bound and coupon builds:
//   twenty  — staff proof, twenty images chosen at once, Add to Order: all
//             twenty are uploaded, in order, the first is the order artwork,
//             and all twenty are listed for Drive;
//   slot    — the same twenty, then page 3 replaced on its slot: the twenty
//             are still there AND page_003_<name> is added;
//   revoke  — self-approve, then Review: Add to Order is refused by the
//             approval gate instead of shipping the stale package.
//
// Setup: parity-saddle.html, parity-pb.html and slot-coupon.html served on
// :8137 (tools-harness-prep.mjs; see the header of tools-parity-saddle.mjs).
// PPS_MULTI_PAGES=a.html,b.html selects builds — how the pre-fix ones were run
// to prove these checks fail there.
//
// Run: PPS_DEPS_DIR=<node_modules> node tools-multi-file-upload-test.mjs

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const { createRequire } = await import('node:module');
const require = createRequire(import.meta.url);
const DEPS = process.env.PPS_DEPS_DIR || new URL('./node_modules', import.meta.url).pathname;
const fs = await import('node:fs');
const os = await import('node:os');
const path = await import('node:path');
const jpeg = require(DEPS + '/jpeg-js');

const BASE = process.env.PPS_CALC_BASE || 'http://127.0.0.1:8137';
const PAGES = (process.env.PPS_MULTI_PAGES ?? 'parity-saddle.html,parity-pb.html,slot-coupon.html').split(',').filter(Boolean);

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// Twenty page images named the way 87273's were (1.jpg …), each its own colour
// and size in bytes, at 300 DPI for a 5.75 × 8.75″ bleed page.
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'pps-multi-'));
function img(name, i) {
  const w = 575, h = 875;                       // 1/3 scale keeps 20 decodes quick
  const data = Buffer.alloc(w * h * 4);
  for (let p = 0; p < w * h; p++) {
    data[p * 4] = (i * 53) % 256; data[p * 4 + 1] = (i * 97) % 256; data[p * 4 + 2] = (i * 29 + 60) % 256; data[p * 4 + 3] = 255;
  }
  const out = jpeg.encode({ data, width: w, height: h }, 90);
  const f = path.join(TMP, name);
  fs.writeFileSync(f, out.data);
  return f;
}
const TWENTY = Array.from({ length: 20 }, (_, i) => img((i + 1) + '.jpg', i + 1));
const SLOTFILE = img('fixed-page.jpg', 77);

const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const SHIP = { shipState: 'AZ', shipAddr: { name: 'Test', street1: '1 Main St', city: 'Phoenix', zip: '85087' } };

const field = (body, name) => { const m = body.match(new RegExp('name="' + name + '"\\r\\n\\r\\n([^\\r]*)')); return m ? m[1] : null; };
const fileName = (body) => { const m = body.match(/name="artwork"; filename="([^"]*)"/); return m ? m[1] : null; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });

async function open(file, reorder) {
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1100 } });
  const p = await ctx.newPage();
  const dialogs = []; p.on('dialog', async d => { dialogs.push(d.message().slice(0, 120)); await d.accept(); });
  const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0, 200)));
  const s = { ctx, p, dialogs, errs, uploads: [], cartPost: null };
  await p.addInitScript((r) => {
    window.PPS_CONFIG = { ajaxUrl: location.origin + '/wp-admin/admin-ajax.php', cartUrl: location.origin + '/cart.html',
      cartNonce: 'c', uploadNonce: 'u', productId: 33670, maxUpload: 200 * 1048576, reorder: r };
  }, b64(reorder));
  await p.route('**/wp-admin/admin-ajax.php**', async (route) => {
    const body = (route.request().postDataBuffer() || Buffer.alloc(0)).toString('latin1');
    const action = field(body, 'action');
    if (action === 'pps_upload_artwork') {
      const name = fileName(body); s.uploads.push(name);
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { path: 'pps-artwork/2026/09/' + s.uploads.length + '-' + name } }) });
    }
    if (action === 'pps_add_to_cart') {
      s.cartPost = { files: field(body, 'pps_artwork_files'), path: field(body, 'pps_artwork_path') };
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { cart_item_key: 'k1' } }) });
    }
    return route.fulfill({ status: 400, body: '0' });
  });
  await p.route('**/cart.html', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>CART</h1>' }));
  await p.goto(BASE + '/' + file, { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('input[type=file]', { state: 'attached', timeout: 30000 });
  await p.waitForTimeout(2000);
  return s;
}

async function chooseAll(s, files) {
  await s.p.locator('input[type=file]').first().setInputFiles(files);
  // Wait until the book shows every image (the "n/m pg" or "(n/m filled)" counter).
  try {
    await s.p.waitForFunction((n) => [...document.querySelectorAll('*')].some(e => e.children.length === 0 &&
      new RegExp('(^|\\D)' + n + '/\\d+(pg| filled)').test(e.textContent || '')), files.length, { timeout: 60000 });
  } catch (e) { /* the checks below report it */ }
  await s.p.waitForTimeout(800);
}

async function addToOrder(s) {
  const clicked = await s.p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim()));
    const b = btns.find(x => x.offsetParent !== null && !x.disabled) || btns.find(x => !x.disabled);
    if (!b) return false; b.click(); return true;
  });
  for (let i = 0; i < 60 && !/cart\.html$/.test(s.p.url()) && !s.dialogs.some(d => /approve your artwork/i.test(d)); i++) await s.p.waitForTimeout(1000);
  return clicked;
}

const listed = (s) => { try { return JSON.parse(s.cartPost.files).map(f => f.name); } catch (e) { return []; } };
const want = TWENTY.map(f => path.basename(f));

for (const file of PAGES) {
  // ── twenty images, staff proof ─────────────────────────────────────────────
  console.log('\n── ' + file + ' / twenty images, staff digital proof ──');
  {
    const s = await open(file, { ...SHIP, artwork: 0.01, proof: 0.01 });
    await chooseAll(s, TWENTY);
    ok(`${file}: Add to Order was clickable`, await addToOrder(s));
    ok(`${file}: the order landed in the cart`, /cart\.html$/.test(s.p.url()), 'dialogs=' + s.dialogs.join(' || '));
    const got = s.uploads.filter(n => /^\d+\.jpg$/.test(n));
    ok(`${file}: all twenty images were uploaded, in the order chosen`, JSON.stringify(got) === JSON.stringify(want), JSON.stringify(s.uploads));
    ok(`${file}: the first image is the order's artwork`, s.cartPost && /1-1\.jpg$/.test(s.cartPost.path || ''), s.cartPost && s.cartPost.path);
    const l = listed(s);
    ok(`${file}: all twenty are listed for the Drive folder`, want.every(n => l.includes(n)), l.join(','));
    ok(`${file}: no page errors`, s.errs.length === 0, s.errs.join(' | '));
    await s.ctx.close();
  }

  // ── twenty images, then page 3 replaced on its slot ───────────────────────
  console.log('\n── ' + file + ' / twenty images, then one slot replaced ──');
  {
    const s = await open(file, { ...SHIP, artwork: 0.01, proof: 0.01 });
    await chooseAll(s, TWENTY);
    if (await s.p.locator('[data-pps-dropzone]').count() === 0) {
      const t = s.p.getByText('Upload pages individually');
      if (await t.count()) await t.first().click();
    }
    let slotted = false;
    try {
      await s.p.waitForSelector('[data-pps-dropzone]', { timeout: 15000 });
      const zone = s.p.locator('[data-pps-dropzone]').nth(2);
      const [chooser] = await Promise.all([s.p.waitForEvent('filechooser', { timeout: 15000 }), zone.locator('div').first().click()]);
      await chooser.setFiles(SLOTFILE);
      await s.p.waitForTimeout(2500);
      slotted = true;
    } catch (e) { s.errs.push('slot: ' + String(e).slice(0, 120)); }
    ok(`${file}: a page was dropped on slot 3`, slotted);
    await addToOrder(s);
    ok(`${file}: the order landed in the cart`, /cart\.html$/.test(s.p.url()), 'dialogs=' + s.dialogs.join(' || '));
    const got = s.uploads.filter(n => /^\d+\.jpg$/.test(n));
    ok(`${file}: the twenty are still uploaded after a slot change`, JSON.stringify(got) === JSON.stringify(want), JSON.stringify(s.uploads));
    ok(`${file}: and the slot file rides along named by its page`, s.uploads.includes('page_003_fixed-page.jpg'), JSON.stringify(s.uploads.filter(n => /page_/.test(n))));
    await s.ctx.close();
  }

  // ── self-approve, then Review: the order must not keep the old approval ───
  console.log('\n── ' + file + ' / approve, then Review ──');
  {
    const s = await open(file, { ...SHIP, artwork: 0.01, proof: 0 });
    await chooseAll(s, TWENTY.slice(0, 4));
    await s.p.evaluate(() => { window.__manifests = 0; const OB = window.Blob; window.Blob = function (parts, opts) { const bl = new OB(parts, opts); if (opts && opts.type === 'text/plain') window.__manifests++; return bl; }; window.Blob.prototype = OB.prototype; });
    await s.p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Proof required|Proof ✓|🔍|Review proof/i.test(y.textContent || '')); x && x.click(); });
    await s.p.waitForTimeout(2500);
    await s.p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input[type=checkbox]'); cb && cb.click(); });
    await s.p.waitForTimeout(300);
    await s.p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /^\s*Approve (artwork|Now)\s*$/i.test(y.textContent || '')); btn && btn.click(); });
    let man = 0;
    for (let i = 0; i < 90 && man < 1; i++) { await s.p.waitForTimeout(1000); man = await s.p.evaluate(() => window.__manifests); }
    ok(`${file}: the approval package was generated`, man >= 1);
    await s.p.waitForTimeout(1500);
    await s.p.keyboard.press('Escape'); await s.p.waitForTimeout(500);
    const reviewed = await s.p.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => (x.textContent || '').trim() === 'Review' && x.offsetParent !== null); if (!b) return false; b.click(); return true; });
    ok(`${file}: the Review button un-approved the proof`, reviewed);
    await s.p.waitForTimeout(800);
    await addToOrder(s);
    ok(`${file}: Add to Order is refused until it is approved again`,
       !s.cartPost && s.dialogs.some(d => /approve your artwork/i.test(d)),
       'cartPost=' + JSON.stringify(s.cartPost && listed(s)) + ' dialogs=' + s.dialogs.join(' || '));
    await s.ctx.close();
  }
}

// ── the five flats (2026-09-26) ─────────────────────────────────────────────
// A flat has a front and a back, each with its own upload slot beside the main
// drop zone. Only the drop zone ever told the order anything: a side uploaded or
// replaced through its slot never reached it, two files dropped at once kept the
// first and silently dropped the second, and clearing an approval left the order
// holding the old package marked approved.
const FLATS = (process.env.PPS_MULTI_FLATS || 'flat-brochure.html,flat-postcard.html,flat-greeting-card.html,flat-letterhead.html,flat-sticker.html').split(',').filter(Boolean);
const FRONT = img('front.jpg', 3), BACK = img('back.jpg', 5), NEWFRONT = img('new-front.jpg', 9);
// The side slots' inputs: same accept list as the drop zone, not the multi-select
// reference picker. Once art is in, the drop zone goes and only the slots remain, so
// they are counted from the end: the last is the back on a two-sided job.
const sideInputs = (p) => p.locator('input[type=file][accept=".pdf,.jpg,.jpeg,.png"]:not([multiple])');
async function slotInput(p, which, twoSided) {
  const n = await sideInputs(p).count();
  const idx = which === 'back' ? n - 1 : n - (twoSided ? 2 : 1);
  return idx >= 0 && (which !== 'back' || twoSided) ? sideInputs(p).nth(idx) : null;
}
async function waitArt(p, n) {
  try { await p.waitForFunction((k) => [...document.querySelectorAll('img')].filter(i => /^data:image/.test(i.src)).length >= k, n, { timeout: 30000 }); } catch (e) {}
  await p.waitForTimeout(800);
}
for (const file of FLATS) {
  const twoSided = !/sticker/.test(file);
  const base = { ...SHIP, artwork: 0.01, proof: 0.01, ...(twoSided ? { sides: 2 } : {}) };

  if (twoSided) {
    console.log('\n── ' + file + ' / front on the drop zone, back on its own slot, staff proof ──');
    const s = await open(file, base);
    await s.p.locator('input[type=file]').first().setInputFiles(FRONT);
    await waitArt(s.p, 1);
    const backIn = await slotInput(s.p, 'back', twoSided);
    if (backIn) await backIn.setInputFiles(BACK);
    await waitArt(s.p, 2);
    ok(`${file}: there is a back slot to upload to`, !!backIn);
    await addToOrder(s);
    ok(`${file}: the order landed in the cart`, /cart\.html$/.test(s.p.url()), 'dialogs=' + s.dialogs.join(' || '));
    ok(`${file}: the back uploaded through its slot reaches the order, after the front`,
       s.uploads.indexOf('front.jpg') === 0 && s.uploads.includes('back.jpg'), JSON.stringify(s.uploads));
    await s.ctx.close();

    console.log('\n── ' + file + ' / front and back dropped together ──');
    const t = await open(file, base);
    const b64s = [FRONT, BACK].map(f => ({ name: path.basename(f), data: fs.readFileSync(f).toString('base64') }));
    await t.p.evaluate((items) => {
      const dt = new DataTransfer();
      for (const it of items) { const bin = atob(it.data); const u = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i); dt.items.add(new File([u], it.name, { type: 'image/jpeg' })); }
      const input = document.querySelector('input[type=file]');
      let zone = input && input.parentElement;
      while (zone && !zone.ondrop && !Object.keys(zone).some(k => k.startsWith('__reactProps') && zone[k] && zone[k].onDrop)) zone = zone.parentElement;
      (zone || input.parentElement).dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
    }, b64s);
    await waitArt(t.p, 2);
    await addToOrder(t);
    ok(`${file}: two files dropped at once become front and back, and both reach the order`,
       t.uploads.includes('front.jpg') && t.uploads.includes('back.jpg'), JSON.stringify(t.uploads) + ' dialogs=' + t.dialogs.join(' || '));
    await t.ctx.close();
  }

  console.log('\n── ' + file + ' / the front replaced on its slot, staff proof ──');
  {
    const s = await open(file, base);
    await s.p.locator('input[type=file]').first().setInputFiles(FRONT);
    await waitArt(s.p, 1);
    { const fin = await slotInput(s.p, 'front', twoSided); if (fin) await fin.setInputFiles(NEWFRONT); }
    await s.p.waitForTimeout(2500);
    await addToOrder(s);
    ok(`${file}: the order carries the replacement, not the file it replaced`,
       s.uploads[0] === 'new-front.jpg' && !s.uploads.includes('front.jpg'), JSON.stringify(s.uploads) + ' dialogs=' + s.dialogs.join(' || '));
    await s.ctx.close();
  }

  console.log('\n── ' + file + ' / approve, then replace the front ──');
  {
    const s = await open(file, { ...base, proof: 0 });
    await s.p.locator('input[type=file]').first().setInputFiles(FRONT);
    await waitArt(s.p, 1);
    await s.p.evaluate(() => { window.__manifests = 0; const OB = window.Blob; window.Blob = function (parts, opts) { const bl = new OB(parts, opts); if (opts && opts.type === 'text/plain') window.__manifests++; return bl; }; window.Blob.prototype = OB.prototype; });
    await s.p.evaluate(() => {
      const x = [...document.querySelectorAll('button')].find(y => /Proof required|Proof ✓|🔍|Review proof/i.test(y.textContent || ''));
      if (x) return x.click();
      const im = [...document.querySelectorAll('img')].find(i => /^data:image/.test(i.src)); im && im.parentElement && im.parentElement.click();
    });
    await s.p.waitForTimeout(2000);
    await s.p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input[type=checkbox]'); cb && cb.click(); });
    await s.p.waitForTimeout(300);
    await s.p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /^\s*Approve (artwork|Now)\s*$/i.test(y.textContent || '')); btn && btn.click(); });
    let man = 0;
    for (let i = 0; i < 90 && man < 1; i++) { await s.p.waitForTimeout(1000); man = await s.p.evaluate(() => window.__manifests); }
    ok(`${file}: the approval package was generated`, man >= 1);
    await s.p.keyboard.press('Escape'); await s.p.waitForTimeout(800);
    { const fin = await slotInput(s.p, 'front', twoSided); if (fin) await fin.setInputFiles(NEWFRONT); }
    await s.p.waitForTimeout(2500);
    await addToOrder(s);
    ok(`${file}: changed art after approval is refused at Add to Order until approved again`,
       !s.cartPost && s.dialogs.some(d => /approve your artwork/i.test(d)),
       'cartPost=' + JSON.stringify(s.cartPost && listed(s)) + ' dialogs=' + s.dialogs.join(' || '));
    await s.ctx.close();
  }
}

await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> MULTI-FILE UPLOAD FAILED' : '>>> MULTI-FILE UPLOAD OK');
process.exit(failed ? 1 : 0);
