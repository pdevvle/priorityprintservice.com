// What arrives from outside the form — a share link, a reorder or cart edit, a product
// default, the injected config — must either become a job the shop can make, or say
// why not. Found 2026-09-27 by driving every entry point with bad values:
//   - Perfect bound went blank when Mixed colour had 0 + 0 pages, and a negative
//     quantity blanked all three booklets: calculate() returned an EMPTY error list, and
//     the Panel draws a total whenever the list is empty.
//   - Page counts from outside were never checked: a 4-page perfect-bound default (the
//     minimum is 8), 10 pages on a saddle stitch, 9 or 700 pages in Mixed mode, 2,000
//     from a link (which hung the tab). The select showed "8 Pages"; the order said 10.
//   - An unknown size label on a booklet was priced as the first preset while the order
//     kept the old label.
//   - A non-numeric reorder quantity on a flat priced "$NaN" and posted it.
//   - An option list injected empty ([]) blanked the page on its first lookup.
//   - An edited mixed-colour perfect-bound book came back as full colour.
//
// Setup: harness copies on :8137 (tools-harness-prep.mjs). PPS_INPUT_PAGES selects builds.
// Run: node tools-order-inputs-test.mjs

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const BASE = process.env.PPS_CALC_BASE || 'http://127.0.0.1:8137';
const PAGES = (process.env.PPS_INPUT_PAGES || 'parity-saddle.html,parity-pb.html,slot-coupon.html,flat-brochure.html,flat-postcard.html,flat-greeting-card.html,flat-letterhead.html,flat-sticker.html').split(',').filter(Boolean);

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};
const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const JOB = { artwork: 0.04, proof: 0.01, canvaLink: 'https://www.canva.com/design/TEST/edit', shipState: 'AZ', shipAddr: { name: 'T', street1: '1 Main St', city: 'Phoenix', zip: '85087' } };
const field = (body, name) => { const m = body.match(new RegExp('name="' + name + '"\\r\\n\\r\\n([\\s\\S]*?)\\r\\n--')); return m ? m[1] : null; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });

async function open(file, { reorder = JOB, query = '', calc = null } = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1100 } });
  const p = await ctx.newPage();
  const s = { ctx, p, dialogs: [], errs: [], meta: null, price: null, loaded: false };
  p.on('dialog', async d => { s.dialogs.push(d.message()); await d.accept(); });
  p.on('pageerror', e => s.errs.push(String(e).slice(0, 200)));
  await p.addInitScript(([r, c]) => {
    window.PPS_CONFIG = { ajaxUrl: location.origin + '/wp-admin/admin-ajax.php', cartUrl: location.origin + '/cart.html',
      cartNonce: 'c', uploadNonce: 'u', productId: 33670, maxUpload: 200 * 1048576, reorder: r };
    if (c) window.PPS_CONFIG.calc = c;
  }, [b64(reorder), calc]);
  await p.route('**/wp-admin/admin-ajax.php**', async (route) => {
    const body = (route.request().postDataBuffer() || Buffer.alloc(0)).toString('utf8');
    if (/name="action"\r\n\r\npps_add_to_cart/.test(body)) {
      try { s.meta = JSON.parse(field(body, 'pps_metadata') || 'null') || {}; } catch (e) { s.meta = { unparsed: true }; }
      s.price = field(body, 'pps_price');
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { cart_item_key: 'k' } }) });
    }
    return route.fulfill({ status: 400, body: '0' });
  });
  await p.route('**/cart.html', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>CART</h1>' }));
  try {
    await p.goto(BASE + '/' + file + query, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await p.waitForSelector('select', { timeout: 20000 });
    await p.waitForTimeout(2000);
    s.loaded = true;
  } catch (e) { s.loadErr = String(e).slice(0, 120); }
  return s;
}
async function addToOrder(s) {
  await s.p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim()));
    const b = btns.find(x => x.offsetParent !== null && !x.disabled) || btns.find(x => !x.disabled);
    b && b.click();
  });
  for (let i = 0; i < 20 && !s.meta && !s.dialogs.length; i++) await s.p.waitForTimeout(300);
}
const alive = async (s) => s.loaded && !s.errs.length && (await s.p.evaluate(() => document.body.innerText.length)) > 1500;
const text = (s) => s.p.evaluate(() => document.body.innerText);
const pagesOf = (m) => m && Array.isArray(m.sets) && m.sets[0] ? Number(m.sets[0].pages) : NaN;

const RULE = {
  'parity-saddle.html': { ok: n => n % 4 === 0 && n >= 8 && n <= 64, odd: 10 },
  'parity-pb.html':     { ok: n => n % 2 === 0 && n >= 8 && n <= 350, odd: 9 },
  'slot-coupon.html':   { ok: n => n >= 4 && n <= 200, odd: 1000 },
};
// Every list a calculator reads from the injected config, empty.
const EMPTY = { papers_nc: [], papers_cs: [], size_presets: [], postcard_papers: [], sticker_papers: [], letterhead_papers: [],
  greetingcard_papers_nc: [], greetingcard_papers_cs: [], brochure_sizes: [], postcard_sizes: [], greetingcard_sizes: [],
  letterhead_sizes: [], sticker_sizes: [], coatings: [], art_opts: [], bleed_opts: [], fold_types: [], page_counts: [] };

for (const file of PAGES) {
  const book = RULE[file.replace(/^pre-?/, '')] || (/preview-test/.test(file) ? RULE['parity-saddle.html'] : /perfect-bound/.test(file) ? RULE['parity-pb.html'] : /coupon/.test(file) ? RULE['slot-coupon.html'] : null);
  console.log('\n── ' + file + ' ──');

  { const s = await open(file, { calc: EMPTY });
    ok(`${file}: an empty option list in the config does not blank the page`, await alive(s), (s.errs[0] || s.loadErr || ''));
    await s.ctx.close(); }

  if (book) {
    { const s = await open(file, { query: '?qty=-5' });
      const up = await alive(s); if (up) await addToOrder(s);
      ok(`${file}: a negative quantity in a link does not blank the page, and orders a real quantity`, up && s.meta && s.meta.sets && s.meta.sets[0].qty >= 1, (s.errs[0] || '') + ' posted=' + JSON.stringify(s.meta && s.meta.sets)); await s.ctx.close(); }
    { const s = await open(file, { query: '?pages=2000' });
      const up = await alive(s); if (up) await addToOrder(s);
      ok(`${file}: 2,000 pages in a link neither hangs the tab nor orders an impossible book`, up && (!s.meta || book.ok(pagesOf(s.meta))), (s.loadErr || s.errs[0] || '') + ' pages=' + pagesOf(s.meta)); await s.ctx.close(); }
    { const s = await open(file, { reorder: { ...JOB, sets: [{ qty: 100, pages: book.odd }] } });
      await addToOrder(s);
      const pg = pagesOf(s.meta), sel = await s.p.evaluate(() => { const o = [...document.querySelectorAll('select option')].find(x => x.selected && / Pages$/.test(x.textContent)); return o ? parseInt(o.textContent) : null; });
      ok(`${file}: a reorder of ${book.odd} pages becomes a book we can make, and the form shows the same number`, s.meta && book.ok(pg) && (sel === null || sel === pg), `posted=${pg} select=${sel}`); await s.ctx.close(); }
    { const s = await open(file, { reorder: { ...JOB, sizeLabel: 'Bogus 99x99' } });
      await addToOrder(s);
      ok(`${file}: a size we no longer offer is refused and named, not priced as the first preset`, !s.meta && /isn't a size we offer/.test(await text(s)), 'posted=' + !!s.meta); await s.ctx.close(); }
  } else {
    { const s = await open(file, { reorder: { ...JOB, qty: 'abc' } });
      await addToOrder(s);
      ok(`${file}: a non-numeric reorder quantity cannot post a NaN price`, !s.meta || (isFinite(Number(s.price)) && Number(s.price) > 0), 'price=' + s.price); await s.ctx.close(); }
    if (!/sticker/.test(file)) {
      const s = await open(file, { reorder: { ...JOB, sides: 3 } });
      await addToOrder(s);
      ok(`${file}: "3 sides" from a reorder becomes two, not one`, s.meta && Number(s.meta.sides) === 2, 'sides=' + (s.meta && s.meta.sides)); await s.ctx.close();
    }
  }

  if (/perfect-bound|parity-pb/.test(file)) {
    { const s = await open(file);
      await addToOrder(s);
      ok(`${file}: with no product default the book opens at a page count we bind (the default was 4)`, s.meta && pagesOf(s.meta) >= 8, 'pages=' + pagesOf(s.meta)); await s.ctx.close(); }
    { const s = await open(file, { reorder: { ...JOB, insideColor: 'color', sets: [{ qty: 100, pages: 40, colorMode: 'mixed', colorPages: 10, bwPages: 30 }] } });
      await addToOrder(s);
      const st = s.meta && s.meta.sets && s.meta.sets[0];
      ok(`${file}: an edited mixed-colour book stays mixed, with its split`, st && st.colorMode === 'mixed' && st.colorPages === 10 && st.bwPages === 30, JSON.stringify(st)); await s.ctx.close(); }
    { const s = await open(file, { reorder: { ...JOB, sets: [{ qty: 100, pages: 40, colorMode: 'mixed', colorPages: 20, bwPages: 20 }] } });
      // Type 0 into both split inputs, as a customer would.
      for (const lab of ['Color Pages', 'B&W Pages']) {
        const inp = s.p.locator(`xpath=//label[contains(normalize-space(.), "${lab}")]/following::input[1]`).first();
        if (await inp.count()) { await inp.fill('0'); await inp.press('Tab'); await s.p.waitForTimeout(400); }
      }
      const t = await text(s);
      ok(`${file}: 0 colour + 0 B&W pages says what is wrong instead of blanking the calculator`, !s.errs.length && t.length > 1500 && /Enter a quantity and a page count|needs at least/.test(t), s.errs[0] || t.slice(0, 80));
      await s.ctx.close(); }
    { const s = await open(file, { reorder: { ...JOB, sets: [{ qty: 100, pages: 40, colorMode: 'mixed', colorPages: 20, bwPages: 20 }] } });
      const inp = s.p.locator('xpath=//label[contains(normalize-space(.), "Color Pages")]/following::input[1]').first();
      if (await inp.count()) { await inp.fill('3'); await inp.press('Tab'); await s.p.waitForTimeout(300); }
      const inp2 = s.p.locator('xpath=//label[contains(normalize-space(.), "B&W Pages")]/following::input[1]').first();
      if (await inp2.count()) { await inp2.fill('0'); await inp2.press('Tab'); await s.p.waitForTimeout(400); }
      await addToOrder(s);
      ok(`${file}: a 3-page mixed book cannot be ordered`, !s.meta || RULE['parity-pb.html'].ok(pagesOf(s.meta)), 'posted pages=' + pagesOf(s.meta));
      await s.ctx.close(); }
  }
}

await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ORDER INPUTS FAILED' : '>>> ORDER INPUTS OK');
process.exit(failed ? 1 : 0);
