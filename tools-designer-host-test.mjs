// "Design it online" on the postcard calculator, end to end, against a fake WordPress.
//
// The online designer (PPS Designer, pdevvle/priorityprintpad) opens in a same-origin
// frame, gets the job's size and the price to show, and hands back a print-ready PDF.
// This proves the calculator's side of that handshake (ppsOpenDesigner, brief §6) where
// a customer meets it, on the COMPILED postcard build:
//
//   off       — no designer_url: no button, the upload path is unchanged;
//   foreign   — a cross-origin designer_url: still no button (same origin only);
//   on        — the button opens the designer at the job's size and sides, with the
//               calculator's price on its chip; Print hands back a PDF that lands on
//               the front and back exactly as an upload would; "Edit design" reopens
//               the work and Back keeps it; then the normal proof, APPROVE NOW and
//               Add to Order, with the designer's PDF as the order's artwork and the
//               previews and manifest listed for Drive.
//               Order options: the designer's slide-out panel offers this calculator's
//               size, sides and quantity (plus orientation); a change there sets the
//               calculator's own fields, its new price comes back to the designer, and
//               a new size resizes the open design, so the print file and the order
//               agree.
//   portrait  — a card designed standing up: the print-ready file is the wide sheet with
//               the front turned 90° and the back 270° (it is turned over left to right),
//               recorded in the manifest; after a size change "Edit design" reopens the
//               work resized, each design has its own file name (Drive and the imposition
//               tool find artwork by name), and approving then changing the size clears
//               the approval, so the order can never carry a file of another size.
//               PPS_PRINT_READY=<path> keeps that print-ready PDF to look at.
//
// Setup (everything is served on 127.0.0.1:8139 by this script):
//   1. compile:  BABEL_DIR=<dir with @babel/core, @babel/preset-react> node tools-compile-calcs.mjs
//   2. a built designer site: the `designer-site-<sha>` artefact of priorityprintpad's
//      wasm.yml (designer.html, designer.js, designer.css, pps_bridge.js,
//      pps_bridge_bg.wasm, designer-manifest.json), unpacked into a directory.
//
// Run: PPS_DEPS_DIR=<node_modules with react, react-dom, jspdf, pdfjs-dist@4.10.38> \
//      PPS_DESIGNER_DIR=<designer site> node tools-designer-host-test.mjs

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const DEPS = process.env.PPS_DEPS_DIR || path.join(HERE, 'node_modules');
const DESIGNER = process.env.PPS_DESIGNER_DIR;
const CALC = process.env.PPS_CALC_FILE || path.join(HERE, 'dist', 'calc-postcard.html');
if (!DESIGNER || !fs.existsSync(path.join(DESIGNER, 'designer.html'))) { console.error('PPS_DESIGNER_DIR must hold a built designer site'); process.exit(2); }
if (!fs.existsSync(CALC)) { console.error('no compiled calculator at ' + CALC + ' (run tools-compile-calcs.mjs)'); process.exit(2); }

// The harness: the compiled calculator with its CDN scripts vendored, and the designer
// under /designer/ on the same origin, as the site serves it from the plugin directory.
const H = fs.mkdtempSync('/tmp/pps-designer-host-');
execFileSync(process.execPath, [path.join(HERE, 'tools-harness-prep.mjs'), CALC, path.join(H, 'calc.html')], { stdio: 'ignore' });
fs.writeFileSync(path.join(H, 'theme-main.css'), '');
fs.symlinkSync(DEPS, path.join(H, 'node_modules'));
fs.symlinkSync(path.resolve(DESIGNER), path.join(H, 'designer'));

const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css', '.wasm': 'application/wasm', '.json': 'application/json', '.pdf': 'application/pdf' };
const server = http.createServer((req, res) => {
  const rel = decodeURIComponent(req.url.split('?')[0]).replace(/^\/+/, '');
  const file = path.join(H, path.normalize(rel));
  if (!file.startsWith(H)) { res.writeHead(403); res.end(); return; }
  fs.readFile(file, (e, b) => {
    if (e) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'content-type': TYPES[path.extname(file)] || 'application/octet-stream' });
    res.end(b);
  });
});
await new Promise(r => server.listen(8139, '127.0.0.1', r));
const BASE = 'http://127.0.0.1:8139';

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// Each design gets its own name, so two designed jobs in one order can't be confused on Drive.
const DESIGN_NAME = /^online-design-[a-z0-9]+\.pdf$/;
const field = (body, name) => { const m = body.match(new RegExp('name="' + name + '"\\r\\n\\r\\n([^\\r]*)')); return m ? m[1] : null; };
const fileName = (body) => { const m = body.match(/name="artwork"; filename="([^"]*)"/); return m ? m[1] : null; };
const reorder = Buffer.from(JSON.stringify({ shipState: 'AZ', shipAddr: { name: 'Test', street1: '1 Main St', city: 'Phoenix', zip: '85087' } }))
  .toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

async function open(b, designerUrl) {
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 } });
  const p = await ctx.newPage();
  const dialogs = []; p.on('dialog', async d => { dialogs.push(d.message()); await d.dismiss(); });
  const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0, 200)));
  const uploads = [], bodies = {}; let cartPost = null;
  await p.addInitScript(([r, u]) => {
    window.PPS_CONFIG = { ajaxUrl: location.origin + '/wp-admin/admin-ajax.php', cartUrl: location.origin + '/cart.html',
      cartNonce: 'cartBaked', uploadNonce: 'upBaked', productId: 33670, maxUpload: 200 * 1048576, reorder: r,
      calc: { pcf: u ? { designer_url: u } : {} } };
  }, [reorder, designerUrl]);
  await p.route('**/wp-admin/admin-ajax.php**', async (route) => {
    const req = route.request();
    const body = (req.postDataBuffer() || Buffer.alloc(0)).toString('latin1');
    const action = field(body, 'action');
    if (action === 'pps_upload_artwork') {
      const name = fileName(body);
      uploads.push(name);
      bodies[name] = body;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { path: 'pps-artwork/2026/10/' + uploads.length + '-' + name } }) });
    }
    if (action === 'pps_add_to_cart') {
      cartPost = { product_id: field(body, 'product_id'), files: field(body, 'pps_artwork_files'), path: field(body, 'pps_artwork_path'), metadata: field(body, 'pps_metadata') };
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { cart_item_key: 'k1' } }) });
    }
    return route.fulfill({ status: 400, body: '0' });
  });
  await p.route('**/cart.html', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<h1 id="cart">CART</h1>' }));
  await p.goto(BASE + '/calc.html', { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('select'); await p.waitForTimeout(2500);
  return { ctx, p, dialogs, errs, uploads, bodies, cart: () => cartPost };
}

const b = await chromium.launch({ executablePath: process.env.PPS_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });

// ── off: no designer_url, no button ──
{
  const t = await open(b, '');
  ok('off: no "Design it online" button without designer_url', (await t.p.locator('[data-pps-design]').count()) === 0);
  ok('off: the upload drop zone is still there', (await t.p.getByText('Drop your artwork here or click to upload').count()) === 1);
  ok('off: no page errors', t.errs.length === 0, t.errs.join(' | '));
  await t.ctx.close();
}

// ── foreign: a cross-origin designer_url is refused ──
{
  const t = await open(b, 'https://example.com/designer/designer.html');
  ok('foreign: a cross-origin designer_url shows no button', (await t.p.locator('[data-pps-design]').count()) === 0);
  await t.ctx.close();
}

// ── on: design, print, edit, back, proof, approve, order ──
{
  const t = await open(b, '/designer/designer.html');
  const { p } = t;
  ok('on: the "Design it online" button is offered', await p.locator('[data-pps-design="open"]').isVisible());
  await p.click('[data-pps-design="open"]');
  const frame = p.frameLocator('.pps-designer-overlay iframe');
  await frame.locator('#print:not([disabled])').waitFor({ timeout: 120000 });
  ok('on: the designer opens over the calculator', await p.locator('.pps-designer-overlay').isVisible());
  const size = await frame.locator('#productSize').textContent();
  const dims = (size || '').match(/^([\d.]+) × ([\d.]+) in$/);
  ok('on: the designer gets the job\'s size, landscape (the default preset is 3.5 × 2)', dims && +dims[1] === 3.5 && +dims[2] === 2, size);
  ok('on: two-sided job → Front and Back', (await frame.locator('#sides button').allTextContents()).join(',') === 'Front,Back');
  const chip = await frame.locator('#price').textContent();
  ok('on: the price chip shows the calculator\'s price (display only)', /^\$\d[\d,]*\.\d\d · [\d,]+ pcs$/.test(chip || ''), chip);
  ok('on: no demo notice inside the calculator', !(await frame.locator('#demo').isVisible()));

  // Order options: the calculator's own fields, from inside the designer.
  ok('on: the designer offers Order options', await frame.locator('#orderTab').isVisible());
  await frame.locator('#orderTab').click();
  ok('on: …size, orientation, sides, folding, perforation and quantity, from the calculator', (await frame.locator('#orderFields label > span:first-child').allTextContents()).join(',') === 'Size,Orientation,Sides printed,Folding,Perforation,Quantity');
  await frame.locator('#orderFields select[data-key="size"]').selectOption('4×6');
  await frame.locator('#productSize', { hasText: '6 × 4 in' }).waitFor({ timeout: 10000 });
  ok('on: a size picked in the designer resizes the design (4×6 → 6 × 4 in, landscape)', (await frame.locator('#productSize').textContent()) === '6 × 4 in');
  await p.waitForFunction(() => [...document.querySelectorAll('select')].some(s => s.value === '4×6'), null, { timeout: 5000 }).catch(() => {});
  ok('on: …and sets the calculator\'s own size field', await p.evaluate(() => [...document.querySelectorAll('select')].some(s => s.value === '4×6')));
  await frame.locator('#orderFields input[data-key="qty"]').fill('500');
  await frame.locator('#orderFields input[data-key="qty"]').press('Enter');
  await frame.locator('#price', { hasText: '500 pcs' }).waitFor({ timeout: 10000 });
  const chip2 = await frame.locator('#price').textContent();
  ok('on: a quantity change comes back as the calculator\'s new price', /· 500 pcs$/.test(chip2 || '') && chip2 !== chip, chip + ' → ' + chip2);
  ok('on: …shown in the panel too', /\$[\d,]+\.\d\d/.test(await frame.locator('#orderPrice').textContent()));
  // Folding and perforation from the designer: the calculator's fields change, and the
  // designer draws the calculator's fold and perforation lines (as guides).
  await frame.locator('#orderFields select[data-key="fold"]').selectOption('trifold');
  await frame.locator('#sides button', { hasText: 'Outside' }).waitFor({ timeout: 10000 });
  ok('on: picking Trifold in the designer folds the job (Outside / Inside)', (await frame.locator('#sides button').allTextContents()).join(',') === 'Outside,Inside');
  ok('on: …sets the calculator\'s own fold field', await p.evaluate(() => [...document.querySelectorAll('select')].some(s => s.value === 'trifold')));
  ok('on: …and offers no portrait for a folded sheet', (await frame.locator('#orderFields select[data-key="orientation"]').count()) === 0);
  await frame.locator('#orderFields select[data-key="perforation"]').selectOption({ index: 1 });
  await frame.locator('#legendPerf:not([hidden])').waitFor({ timeout: 10000 });
  ok('on: a perforation picked in the designer is drawn there and shows in the legend', await frame.locator('#legendFold').isVisible() && await frame.locator('#legendPerf').isVisible());
  ok('on: …and the calculator offers its direction', (await frame.locator('#orderFields select[data-key="perfDir"]').count()) === 1);
  await frame.locator('#orderClose').click();

  await frame.locator('[data-text="heading"]').click();
  await frame.locator('#textInput').fill('Grand Opening');
  await frame.locator('#textDone').click();
  await frame.locator('[data-cat="background"]').click();
  await frame.locator('#bgSwatches button[title="Teal"]').click();
  await frame.locator('#sides button:nth-child(2)').click();
  await frame.locator('[data-cat="text"]').click();
  await frame.locator('[data-text="body"]').click();
  await frame.locator('#textInput').fill('Saturday 10 to 4');
  await frame.locator('#textDone').click();
  await frame.locator('#print').click();
  await frame.locator('#sheetGo').click();
  await p.locator('.pps-designer-overlay').waitFor({ state: 'detached', timeout: 60000 });
  ok('on: Print closes the designer', true);
  await p.waitForTimeout(4000);
  ok('on: the design lands as the artwork (file name shown)', (await p.getByText(DESIGN_NAME).count()) >= 1);
  ok('on: "Edit design" is offered once a design is on the job', await p.locator('[data-pps-design="edit"]').isVisible());
  ok('on: the proof button is there, unapproved', (await p.locator('button', { hasText: '🔍 Proof' }).count()) >= 1);
  ok('on: no surprise dialogs from the intake (the PDF is the size the order now is)', t.dialogs.length === 0, t.dialogs.join(' || '));

  // Edit, then Back: the work comes back, and Back leaves the artwork as it was.
  await p.click('[data-pps-design="edit"]');
  await frame.locator('#print:not([disabled])').waitFor({ timeout: 120000 });
  ok('on: "Edit design" reopens the work, not a blank card', !(await frame.locator('#emptyHint').isVisible()));
  await frame.locator('#back').click();
  await p.locator('.pps-designer-overlay').waitFor({ state: 'detached', timeout: 20000 });
  ok('on: Back closes the designer and keeps the artwork', (await p.getByText(DESIGN_NAME).count()) >= 1);

  // The normal proof and approval, then the order.
  await p.evaluate(() => { window.__manifests = 0; const OB = window.Blob; window.Blob = function (parts, opts) { const bl = new OB(parts, opts); if (opts && opts.type === 'text/plain') window.__manifests++; return bl; }; window.Blob.prototype = OB.prototype; });
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /🔍 Proof/.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(2500);
  await p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input[type=checkbox]'); cb && cb.click(); });
  await p.waitForTimeout(300);
  await p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /APPROVE NOW|^\s*Approve (artwork|Now)\s*$/i.test(y.textContent || '')); btn && btn.click(); });
  let man = 0;
  for (let i = 0; i < 90 && man < 1; i++) { await p.waitForTimeout(1000); man = await p.evaluate(() => window.__manifests); }
  ok('on: the approval package is generated from the designer\'s PDF', man >= 1);
  await p.keyboard.press('Escape'); await p.waitForTimeout(500);
  const clicked = await p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim()));
    const bt = btns.find(x => x.offsetParent !== null && !x.disabled) || btns.find(x => !x.disabled);
    if (!bt) return false; bt.click(); return true;
  });
  ok('on: Add to Order is clickable', clicked);
  let landed = false;
  for (let i = 0; i < 60 && !landed; i++) { await p.waitForTimeout(1000); landed = /cart\.html$/.test(p.url()); }
  ok('on: the order lands in the cart', landed, 'url=' + p.url() + ' dialogs=' + t.dialogs.join(' || '));
  const cartPost = t.cart();
  const listed = cartPost && cartPost.files ? JSON.parse(cartPost.files).map(f => f.name) : [];
  ok('on: the designer\'s PDF is the order\'s artwork', DESIGN_NAME.test(t.uploads[0] || '') && cartPost && /^pps-artwork\/2026\/10\/1-online-design-[a-z0-9]+\.pdf$/.test(cartPost.path || ''), JSON.stringify({ first: t.uploads[0], path: cartPost && cartPost.path }));
  ok('on: previews and the manifest are listed for Drive', listed.some(n => /preview/.test(n)) && listed.some(n => /manifest\.txt$/.test(n)), listed.join(','));
  ok('on: no page errors', t.errs.length === 0, t.errs.join(' | '));
  await t.ctx.close();
}

// The proof's APPROVE NOW, waiting for the package (its manifest) to be made.
async function approve(p) {
  await p.evaluate(() => { window.__manifests = 0; if (!window.__blobHooked) { window.__blobHooked = true; const OB = window.Blob; window.Blob = function (parts, opts) { const bl = new OB(parts, opts); if (opts && opts.type === 'text/plain') window.__manifests++; return bl; }; window.Blob.prototype = OB.prototype; } });
  await p.evaluate(() => { const x = [...document.querySelectorAll('button')].find(y => /🔍 Proof/.test(y.textContent || '')); x && x.click(); });
  await p.waitForTimeout(2500);
  await p.evaluate(() => { const ack = [...document.querySelectorAll('label')].find(l => /print anyway/i.test(l.textContent || '')); const cb = ack && ack.querySelector('input[type=checkbox]'); cb && cb.click(); });
  await p.waitForTimeout(300);
  await p.evaluate(() => { const btn = [...document.querySelectorAll('button')].find(y => /APPROVE NOW|^\s*Approve (artwork|Now)\s*$/i.test(y.textContent || '')); btn && btn.click(); });
  let man = 0;
  for (let i = 0; i < 90 && man < 1; i++) { await p.waitForTimeout(1000); man = await p.evaluate(() => window.__manifests); }
  await p.keyboard.press('Escape'); await p.waitForTimeout(500);
  return man >= 1;
}
const approvedShown = p => p.evaluate(() => [...document.querySelectorAll('button')].some(x => (x.textContent || '').trim() === 'Review' && x.offsetParent !== null));

// ── portrait: a card designed standing up prints the right way round on the wide sheet ──
{
  const t = await open(b, '/designer/designer.html');
  const { p } = t;
  await p.locator('select:has(option[value="4×6"])').first().selectOption('4×6');
  await p.waitForTimeout(800);
  await p.click('[data-pps-design="portrait"]');
  const frame = p.frameLocator('.pps-designer-overlay iframe');
  await frame.locator('#print:not([disabled])').waitFor({ timeout: 120000 });
  ok('portrait: the designer opens the card standing up (4 × 6 in)', (await frame.locator('#productSize').textContent()) === '4 × 6 in');
  await frame.locator('[data-text="heading"]').click();
  await frame.locator('#textInput').fill('Tall card');
  await frame.locator('#textDone').click();
  await frame.locator('[data-cat="background"]').click();
  await frame.locator('#bgSwatches button[title="Teal"]').click();
  await frame.locator('#sides button:nth-child(2)').click();
  await frame.locator('[data-cat="text"]').click();
  await frame.locator('[data-text="heading"]').click();
  await frame.locator('#textInput').fill('Back top');
  await frame.locator('#textDone').click();
  await frame.locator('#print').click();
  await frame.locator('#sheetGo').click();
  await p.locator('.pps-designer-overlay').waitFor({ state: 'detached', timeout: 60000 });
  await p.waitForTimeout(4000);
  const first = await p.getByText(DESIGN_NAME).first().textContent().catch(() => '');
  ok('portrait: the design lands under its own name', DESIGN_NAME.test((first || '').trim()), first);
  ok('portrait: …with no dialogs', t.dialogs.length === 0, t.dialogs.join(' || '));

  // The order changes size after the design: "Edit design" reopens the work at the new size.
  await p.locator('select:has(option[value="5×7"])').first().selectOption('5×7');
  await p.waitForTimeout(800);
  await p.click('[data-pps-design="edit"]');
  await frame.locator('#print:not([disabled])').waitFor({ timeout: 120000 });
  ok('portrait: after a size change, "Edit design" reopens the work, not a blank card', !(await frame.locator('#emptyHint').isVisible()));
  ok('portrait: …resized to the order (5 × 7 in, still standing up)', (await frame.locator('#productSize').textContent()) === '5 × 7 in');
  await frame.locator('#print').click();
  await frame.locator('#sheetGo').click();
  await p.locator('.pps-designer-overlay').waitFor({ state: 'detached', timeout: 60000 });
  await p.waitForTimeout(4000);
  const second = await p.getByText(DESIGN_NAME).first().textContent().catch(() => '');
  ok('portrait: a second design gets a name of its own', DESIGN_NAME.test((second || '').trim()) && second !== first, first + ' → ' + second);

  ok('portrait: approving makes the package', await approve(p));
  ok('portrait: …and the job shows as approved', await approvedShown(p));
  // A change to the job's size after approval clears it: the approved file is the old size.
  await p.locator('select:has(option[value="4×6"])').first().selectOption('4×6');
  await p.waitForTimeout(1500);
  ok('portrait: changing the size after approving clears the approval', !(await approvedShown(p)));
  await p.locator('select:has(option[value="5×7"])').first().selectOption('5×7');
  await p.waitForTimeout(1500);
  ok('portrait: …and approving again makes a new package', await approve(p) && await approvedShown(p));
  const clicked = await p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim()));
    const bt = btns.find(x => x.offsetParent !== null && !x.disabled) || btns.find(x => !x.disabled);
    if (!bt) return false; bt.click(); return true;
  });
  let landed = false;
  for (let i = 0; i < 60 && clicked && !landed; i++) { await p.waitForTimeout(1000); landed = /cart\.html$/.test(p.url()); }
  ok('portrait: the order lands in the cart', landed, t.dialogs.join(' || '));
  const manName = Object.keys(t.bodies).find(n => /manifest\.txt$/.test(n)) || '';
  const man = Buffer.from(t.bodies[manName] || '', 'latin1').toString('utf8');   // the manifest is UTF-8 text
  ok('portrait: the manifest records the front turned 90° and the back 270° onto the sheet', /1: Front[\s\S]*?rotate=90°/.test(man) && /2: Back[\s\S]*?rotate=270°/.test(man), (man.match(/rotate=\d+°/g) || []).join(','));
  ok('portrait: …at the order\'s size', /Trim size: 7″ × 5″/.test(man), (man.match(/Trim size: [^\r\n]*/) || [''])[0]);
  const prName = Object.keys(t.bodies).find(n => /_print-ready\.pdf$/.test(n)) || '';
  if (process.env.PPS_PRINT_READY) {   // keep the portrait print-ready file to look at
    const raw = Buffer.from(t.bodies[prName] || '', 'latin1');
    fs.writeFileSync(process.env.PPS_PRINT_READY, raw.subarray(raw.indexOf('%PDF'), raw.lastIndexOf('%%EOF') + 5));
  }
  const mbs = [...(t.bodies[prName] || '').matchAll(/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]/g)].map(m => [+m[1], +m[2]]);
  ok('portrait: the print-ready file is the wide sheet with bleed, both sides (7.25 × 5.25 in)', mbs.length === 2 && mbs.every(([w, h]) => Math.abs(w - 522) < 1 && Math.abs(h - 378) < 1), JSON.stringify(mbs));
  ok('portrait: no page errors', t.errs.length === 0, t.errs.join(' | '));
  await t.ctx.close();
}

await b.close();
server.close();
fs.rmSync(H, { recursive: true, force: true });
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> DESIGNER HOST FAILED' : '>>> DESIGNER HOST OK');
process.exit(failed ? 1 : 0);
