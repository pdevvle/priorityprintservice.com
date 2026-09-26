// What the customer typed and chose reaches the order — on all eight calculators.
//
// Found 2026-09-26, asking of every field whether it reaches a person:
//   - "Canva design" orders: the three booklets never put the Canva link or the
//     customer's Special Instructions into the order at all; the five flats kept the
//     instructions only inside the metadata blob, off the Job Ticket. And no
//     calculator checked that a link was given, so a Canva order could arrive with
//     no artwork and no link.
//   - Editing a Canva line in the cart emptied its link (the calculators never read
//     it back from the edit config).
//   - A reorder did not say it was one, so nobody knew where the earlier files were.
//   - A flat given a PDF with more pages than the job prints dropped the extras
//     without a word to the customer.
//
// Setup: the compiled builds as harness copies on :8137 — parity-saddle.html,
// parity-pb.html, slot-coupon.html, flat-<calc>.html (tools-harness-prep.mjs).
// PPS_FIELDS_PAGES selects builds, which is how the pre-fix ones were run.
//
// Run: PPS_DEPS_DIR=<node_modules> node tools-order-fields-test.mjs

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const { createRequire } = await import('node:module');
const require = createRequire(import.meta.url);
const DEPS = process.env.PPS_DEPS_DIR || new URL('./node_modules', import.meta.url).pathname;
const fs = await import('node:fs');
const os = await import('node:os');
const path = await import('node:path');

const BASE = process.env.PPS_CALC_BASE || 'http://127.0.0.1:8137';
const PAGES = (process.env.PPS_FIELDS_PAGES || 'parity-saddle.html,parity-pb.html,slot-coupon.html,flat-brochure.html,flat-postcard.html,flat-greeting-card.html,flat-letterhead.html,flat-sticker.html').split(',').filter(Boolean);

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// A four-page PDF for the flats.
const { jsPDF } = require(DEPS + '/jspdf/dist/jspdf.node.min.js');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'pps-fields-'));
const FOUR = path.join(TMP, 'four-pages.pdf');
{
  const doc = new jsPDF({ unit: 'in', format: [6.25, 4.25], orientation: 'landscape' });
  for (let i = 0; i < 4; i++) { if (i) doc.addPage([6.25, 4.25], 'landscape'); doc.setFillColor(30 + i * 50, 90, 160); doc.rect(0, 0, 6.25, 4.25, 'F'); }
  fs.writeFileSync(FOUR, Buffer.from(doc.output('arraybuffer')));
}

// For the booklets' batch reader: three good images, a PDF, and a file that claims
// to be a JPEG and is not one (what a renamed HEIC or a truncated download looks like).
const jpeg = require(DEPS + '/jpeg-js');
const JPGS = [1, 2, 3].map(i => {
  const w = 300, h = 450, data = Buffer.alloc(w * h * 4, 200);
  const f = path.join(TMP, i + '.jpg'); fs.writeFileSync(f, jpeg.encode({ data, width: w, height: h }, 80).data); return f;
});
const BROKEN = path.join(TMP, '4.jpg'); fs.writeFileSync(BROKEN, Buffer.from('this is not a jpeg at all'));
const ONEPDF = path.join(TMP, 'cover.pdf');
{ const doc = new jsPDF({ unit: 'in', format: [5.75, 8.75] }); fs.writeFileSync(ONEPDF, Buffer.from(doc.output('arraybuffer'))); }

const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const SHIP = { shipState: 'AZ', shipAddr: { name: 'Test', street1: '1 Main St', city: 'Phoenix', zip: '85087' } };
const field = (body, name) => { const m = body.match(new RegExp('name="' + name + '"\\r\\n\\r\\n([\\s\\S]*?)\\r\\n--')); return m ? m[1] : null; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });

async function open(file, reorder) {
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1100 } });
  const p = await ctx.newPage();
  const s = { ctx, p, dialogs: [], errs: [], meta: null };
  p.on('dialog', async d => { s.dialogs.push(d.message()); await d.accept(); });
  p.on('pageerror', e => s.errs.push(String(e).slice(0, 200)));
  await p.addInitScript((r) => {
    window.PPS_CONFIG = { ajaxUrl: location.origin + '/wp-admin/admin-ajax.php', cartUrl: location.origin + '/cart.html',
      cartNonce: 'c', uploadNonce: 'u', productId: 33670, maxUpload: 200 * 1048576, reorder: r };
  }, b64(reorder));
  await p.route('**/wp-admin/admin-ajax.php**', async (route) => {
    const body = (route.request().postDataBuffer() || Buffer.alloc(0)).toString('utf8');
    if (/name="action"\r\n\r\npps_upload_artwork/.test(body)) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { path: 'pps-artwork/2026/09/x' } }) });
    if (/name="action"\r\n\r\npps_add_to_cart/.test(body)) {
      try { s.meta = JSON.parse(field(body, 'pps_metadata') || 'null'); } catch (e) { s.meta = { unparsed: true }; }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { cart_item_key: 'k' } }) });
    }
    return route.fulfill({ status: 400, body: '0' });
  });
  await p.route('**/cart.html', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>CART</h1>' }));
  await p.goto(BASE + '/' + file, { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('select', { timeout: 30000 });
  await p.waitForTimeout(2500);
  return s;
}
async function addToOrder(s) {
  await s.p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim()));
    const b = btns.find(x => x.offsetParent !== null && !x.disabled) || btns.find(x => !x.disabled);
    b && b.click();
  });
  for (let i = 0; i < 30 && !s.meta && !s.dialogs.length; i++) await s.p.waitForTimeout(500);
  await s.p.waitForTimeout(500);
}
const ticketHas = (meta, label, re) => !!(meta && Array.isArray(meta.ticket) && meta.ticket.some(([l, v]) => l === label && re.test(String(v))));

for (const file of PAGES) {
  const flat = /^(pre)?flat-/.test(file);

  console.log('\n── ' + file + ' / Canva chosen, no link ──');
  {
    const s = await open(file, { ...SHIP, artwork: 0.04, proof: 0.01 });
    await addToOrder(s);
    ok(`${file}: a Canva order with no link is stopped and told why`, !s.meta && s.dialogs.some(d => /Canva editor link/i.test(d)), 'dialogs=' + s.dialogs.join(' || ') + ' posted=' + !!s.meta);
    await s.ctx.close();
  }

  console.log('\n── ' + file + ' / Canva with link and instructions, a reorder ──');
  {
    const s = await open(file, { ...SHIP, artwork: 0.04, proof: 0.01, canvaLink: 'https://www.canva.com/design/TEST123/edit', canvaInstructions: 'Put the map on page 3, not page 2.', reorderOf: 87000 });
    await addToOrder(s);
    const m = s.meta || {};
    ok(`${file}: the order carries the Canva link`, m.canvaLink === 'https://www.canva.com/design/TEST123/edit', JSON.stringify({ canvaLink: m.canvaLink, dialogs: s.dialogs }));
    ok(`${file}: and the customer's instructions`, m.canvaInstructions === 'Put the map on page 3, not page 2.', String(m.canvaInstructions));
    ok(`${file}: both are on the Job Ticket, in words`, ticketHas(m, 'Canva link', /TEST123/) && ticketHas(m, 'Special instructions', /map on page 3/), JSON.stringify(m.ticket || null));
    ok(`${file}: the order says it is a reorder, and of which order`, m.reorderOf === 87000 && ticketHas(m, 'Reorder of', /#87000/), JSON.stringify({ reorderOf: m.reorderOf }));
    await s.ctx.close();
  }

  if (!flat) {
    console.log('\n── ' + file + ' / a batch with a PDF in it ──');
    {
      const s = await open(file, { ...SHIP, artwork: 0.01, proof: 0.01 });
      await s.p.locator('input[type=file]').first().setInputFiles([...JPGS, ONEPDF]);
      for (let i = 0; i < 20 && !s.dialogs.length; i++) await s.p.waitForTimeout(500);
      ok(`${file}: images and a PDF chosen together are refused with the reason, not half-used`, s.dialogs.some(d => /at least one is a PDF/.test(d)), 'dialogs=' + s.dialogs.join(' || '));
      await s.ctx.close();
    }
    console.log('\n── ' + file + ' / a batch with an unreadable image ──');
    {
      const s = await open(file, { ...SHIP, artwork: 0.01, proof: 0.01 });
      await s.p.locator('input[type=file]').first().setInputFiles([...JPGS, BROKEN]);
      // The saddle reports read errors in the upload panel; the other two in a dialog.
      const said = async () => s.dialogs.some(d => /could not be opened: 4\.jpg/.test(d)) || await s.p.evaluate(() => /could not be opened: 4\.jpg/.test(document.body.innerText));
      let told = false;
      for (let i = 0; i < 30 && !(told = await said()); i++) await s.p.waitForTimeout(500);
      ok(`${file}: an image that cannot be opened is named, not made into a broken page or skipped`, told, 'dialogs=' + s.dialogs.join(' || '));
      await s.ctx.close();
    }
  }

  if (flat) {
    console.log('\n── ' + file + ' / a four-page PDF ──');
    const twoSided = !/sticker/.test(file);
    const s = await open(file, { ...SHIP, artwork: 0.01, proof: 0.01, ...(twoSided ? { sides: 2 } : {}) });
    await s.p.locator('input[type=file]').first().setInputFiles(FOUR);
    for (let i = 0; i < 40 && !s.dialogs.length; i++) await s.p.waitForTimeout(500);
    const want = twoSided ? /Pages 3–4 will NOT be printed/ : /Pages 2–4 will NOT be printed/;
    ok(`${file}: pages the job will not print are named to the customer`, s.dialogs.some(d => want.test(d)), 'dialogs=' + s.dialogs.join(' || '));
    await s.ctx.close();
  }
}

await browser.close();
fs.rmSync(TMP, { recursive: true, force: true });
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ORDER FIELDS FAILED' : '>>> ORDER FIELDS OK');
process.exit(failed ? 1 : 0);
