// The whole order, end to end, against a fake WordPress.
//
// tools-nonce-strategy-test.mjs proves the submit function in isolation. This
// proves the calculator as the customer meets it: a real PDF dropped on the real
// uploader, proofed and approved in the real modal, then Add to Order against a
// mocked admin-ajax that behaves like production's — a bare -1 for a wrong
// nonce, JSON for the rest — and a cart page to land on.
//
// Scenarios, each run on the saddle AND perfect-bound builds:
//   fresh   — baked nonces valid: no nonce fetch at all, order lands in the cart;
//   stale   — baked nonces rejected (an aged cached page, or the old logged-in
//             failure): one admin-ajax refresh, retry, order lands in the cart;
//   package — what was uploaded: raw first, no .html, print-ready + previews +
//             manifest listed in pps_artwork_files (the perfect-bound bug).
//
// Setup: parity-saddle.html and parity-pb.html served on :8137 (see the header
// of tools-parity-saddle.mjs; prep both with tools-harness-prep.mjs).
//
// Run: PPS_DEPS_DIR=<node_modules> node tools-order-e2e-test.mjs

const PW = '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const { createRequire } = await import('node:module');
const require = createRequire(import.meta.url);
const DEPS = process.env.PPS_DEPS_DIR || new URL('./node_modules', import.meta.url).pathname;
const fs = await import('fs');

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// An 8-page PDF with unmistakable colour, like the greyscale suite uses.
{
  const { jsPDF } = require(DEPS + '/jspdf/dist/jspdf.node.min.js');
  const doc = new jsPDF({ unit: 'in', format: [5.75, 8.75], orientation: 'portrait' });
  for (let i = 0; i < 8; i++) {
    if (i) doc.addPage([5.75, 8.75], 'portrait');
    doc.setFillColor(27, 106, 201); doc.rect(0, 0, 5.75, 8.75, 'F');
    doc.setTextColor(255, 255, 255); doc.setFontSize(40); doc.text('PAGE ' + (i + 1), 1.2, 1.4);
  }
  fs.writeFileSync('./art-e2e-8p.pdf', Buffer.from(doc.output('arraybuffer')));
}

const reorder = Buffer.from(JSON.stringify({ shipState: 'AZ', shipAddr: { name: 'Test', street1: '1 Main St', city: 'Phoenix', zip: '85087' } }))
  .toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

const field = (body, name) => { const m = body.match(new RegExp('name="' + name + '"\\r\\n\\r\\n([^\\r]*)')); return m ? m[1] : null; };
const fileName = (body) => { const m = body.match(/name="artwork"; filename="([^"]*)"/); return m ? m[1] : null; };

async function run(b, file, scenario) {
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 } });
  const p = await ctx.newPage();
  const dialogs = []; p.on('dialog', async d => { dialogs.push(d.message()); await d.dismiss(); });
  const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0, 200)));
  const log = []; const uploads = []; let cartPost = null;
  const valid = scenario === 'stale' ? { upload: 'upFresh', cart: 'cartFresh' } : { upload: 'upBaked', cart: 'cartBaked' };

  await p.addInitScript((r) => {
    window.PPS_CONFIG = { ajaxUrl: 'http://127.0.0.1:8137/wp-admin/admin-ajax.php', cartUrl: 'http://127.0.0.1:8137/cart.html',
      cartNonce: 'cartBaked', uploadNonce: 'upBaked', productId: 33670, maxUpload: 200 * 1048576, reorder: r };
  }, reorder);

  await p.route('**/wp-admin/admin-ajax.php**', async (route) => {
    const req = route.request();
    if (req.method() === 'GET' && /action=pps_nonces/.test(req.url())) {
      log.push('GET pps_nonces');
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ cart: 'cartFresh', upload: 'upFresh', user: 1 }) });
    }
    const body = (req.postDataBuffer() || Buffer.alloc(0)).toString('latin1');
    const action = field(body, 'action'), nonce = field(body, 'nonce');
    if (action === 'pps_upload_artwork') {
      const name = fileName(body);
      log.push('UPLOAD ' + name + ' nonce=' + nonce);
      if (nonce !== valid.upload) return route.fulfill({ status: 403, contentType: 'text/html', body: '-1' });
      const ext = (name || '').split('.').pop().toLowerCase();
      if (!['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'eps', 'ai', 'txt'].includes(ext)) {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: false, data: 'File type not allowed: .' + ext }) });
      }
      uploads.push(name);
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { path: 'pps-artwork/2026/09/' + uploads.length + '-' + name } }) });
    }
    if (action === 'pps_add_to_cart') {
      log.push('POST add_to_cart nonce=' + nonce);
      if (nonce !== valid.cart) return route.fulfill({ status: 403, contentType: 'text/html', body: '-1' });
      cartPost = { product_id: field(body, 'product_id'), files: field(body, 'pps_artwork_files'), path: field(body, 'pps_artwork_path'), lock: field(body, 'pps_lock'), tier: field(body, 'pps_tier') };
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { cart_item_key: 'k1' } }) });
    }
    return route.fulfill({ status: 400, body: '0' });
  });
  await p.route('**/cart.html', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<h1 id="cart">CART</h1>' }));

  await p.goto('http://127.0.0.1:8137/' + file, { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('select'); await p.waitForTimeout(2500);
  await p.evaluate(() => { window.__manifests = 0; const OB = window.Blob; window.Blob = function (parts, opts) { const bl = new OB(parts, opts); if (opts && opts.type === 'text/plain') window.__manifests++; return bl; }; window.Blob.prototype = OB.prototype; });

  await p.locator('input[type=file]').first().setInputFiles('./art-e2e-8p.pdf');
  await p.waitForTimeout(7000);
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /Proof required|Proof ✓|🔍|Review proof/i.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(2500);
  await p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input[type=checkbox]'); cb && cb.click(); });
  await p.waitForTimeout(300);
  await p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /^\s*Approve (artwork|Now)\s*$/i.test(y.textContent || '')); btn && btn.click(); });
  let man = 0;
  for (let i = 0; i < 90 && man < 1; i++) { await p.waitForTimeout(1000); man = await p.evaluate(() => window.__manifests); }
  ok(`${file}/${scenario}: the approval package was generated`, man >= 1);
  await p.waitForTimeout(1500);
  // Close the proof modal if it is still open, then Add to Order.
  await p.keyboard.press('Escape'); await p.waitForTimeout(500);
  const clicked = await p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim()));
    const b = btns.find(x => x.offsetParent !== null && !x.disabled) || btns.find(x => !x.disabled);
    if (!b) return false; b.click(); return true;
  });
  ok(`${file}/${scenario}: Add to Order was clickable`, clicked);
  let landed = false;
  for (let i = 0; i < 60 && !landed; i++) { await p.waitForTimeout(1000); landed = /cart\.html$/.test(p.url()); }
  ok(`${file}/${scenario}: the order landed in the cart`, landed, 'url=' + p.url() + ' dialogs=' + dialogs.join(' || '));

  const uploadsLog = log.filter(l => /^UPLOAD/.test(l));
  if (scenario === 'fresh') {
    ok(`${file}/${scenario}: no nonce was fetched, the baked ones were used`, !log.some(l => /pps_nonces/.test(l)), log.join(' | '));
  } else {
    const firstUpload = log.indexOf(uploadsLog[0]);
    const fetchIdx = log.indexOf('GET pps_nonces');
    ok(`${file}/${scenario}: the first upload used the baked nonce and was refused`, uploadsLog[0] && /nonce=upBaked$/.test(uploadsLog[0]), uploadsLog[0]);
    ok(`${file}/${scenario}: exactly one refresh, after that refusal`, log.filter(l => l === 'GET pps_nonces').length === 1 && fetchIdx > firstUpload, log.join(' | '));
    ok(`${file}/${scenario}: every later upload used the fresh nonce`, uploadsLog.slice(1).every(l => /nonce=upFresh$/.test(l)), uploadsLog.join(' | '));
    ok(`${file}/${scenario}: add-to-cart used the fresh cart nonce without a second refresh`, log.some(l => l === 'POST add_to_cart nonce=cartFresh'), log.join(' | '));
  }
  // The package.
  const names = uploads.map(n => n.replace(/^[^-]*-/, ''));
  const listed = cartPost && cartPost.files ? JSON.parse(cartPost.files).map(f => f.name) : [];
  ok(`${file}/${scenario}: the raw PDF went first and is the order artwork`, uploads[0] === 'art-e2e-8p.pdf' && cartPost && /1-art-e2e-8p\.pdf$/.test(cartPost.path || ''), JSON.stringify({ first: uploads[0], path: cartPost && cartPost.path }));
  ok(`${file}/${scenario}: no .html deliverable was ever sent`, !log.some(l => /\.html nonce=/.test(l)), log.filter(l => /\.html/.test(l)).join(' | '));
  ok(`${file}/${scenario}: previews and the manifest are listed for Drive`, listed.some(n => /preview/.test(n)) && listed.some(n => /manifest\.txt$/.test(n)), listed.join(','));
  ok(`${file}/${scenario}: product id and tamper fields posted`, cartPost && cartPost.product_id === '33670' && cartPost.tier === 'retail' && /^[0-9a-z]+$/.test(cartPost.lock || ''), JSON.stringify(cartPost && { pid: cartPost.product_id, tier: cartPost.tier, lock: cartPost.lock }));
  ok(`${file}/${scenario}: no page errors`, errs.length === 0, errs.join(' | '));
  await ctx.close();
}

const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
for (const file of ['parity-saddle.html', 'parity-pb.html']) {
  for (const scenario of ['fresh', 'stale']) {
    console.log('\n── ' + file + ' / ' + scenario + ' ──');
    await run(b, file, scenario);
  }
}
await b.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ORDER E2E FAILED' : '>>> ORDER E2E OK');
process.exit(failed ? 1 : 0);
