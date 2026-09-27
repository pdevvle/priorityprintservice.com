// The points between "priced" and "placed" where an order could go through wrong — on all eight.
//
// Found 2026-09-27 walking every juncture a job passes:
//   - A ZIP the carrier cannot use (four digits — a New England ZIP that lost its leading
//     zero) passed the calculator and was refused by the server at Add to Order, which is
//     AFTER the artwork has uploaded. Now the shipping gate names it, with the likely fix.
//   - A delivery date the customer picked that the shop can no longer meet — the tab sat
//     open past the cutoff, or the job grew — was dropped in silence and the order placed
//     at the free-delivery date. Now Add to Order stops and says so.
//   - The quote never re-ran on its own: a tab left open overnight kept yesterday's dates
//     and price until something else changed, and submitted them. Now it re-quotes when
//     the shop day moves.
//
// Setup: the compiled builds as harness copies on :8137 — parity-saddle.html,
// parity-pb.html, slot-coupon.html, flat-<calc>.html (tools-harness-prep.mjs).
// PPS_JUNCTURE_PAGES selects builds, which is how the pre-fix ones were run.
//
// Run: node tools-order-junctures-test.mjs

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');

const BASE = process.env.PPS_CALC_BASE || 'http://127.0.0.1:8137';
const PAGES = (process.env.PPS_JUNCTURE_PAGES || 'parity-saddle.html,parity-pb.html,slot-coupon.html,flat-brochure.html,flat-postcard.html,flat-greeting-card.html,flat-letterhead.html,flat-sticker.html').split(',').filter(Boolean);

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const ADDR = { name: 'Test', street1: '1 Main St', city: 'Boston' };
// A Canva order with its link needs no file, so nothing but the junctures under test can stop it.
const JOB = { artwork: 0.04, proof: 0.01, canvaLink: 'https://www.canva.com/design/TEST/edit' };
// Thursday 1 October 2026, 09:00 in Phoenix (16:00 UTC): before the cutoff, early in a
// month, so the picker opens on the month that holds the earliest date.
const T0 = new Date('2026-10-01T16:00:00Z');

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });

async function open(file, reorder) {
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1100 }, timezoneId: 'America/Phoenix' });
  const p = await ctx.newPage();
  const s = { ctx, p, dialogs: [], errs: [], meta: null };
  p.on('dialog', async d => { s.dialogs.push(d.message()); await d.accept(); });
  p.on('pageerror', e => s.errs.push(String(e).slice(0, 200)));
  await p.clock.install({ time: T0 });
  await p.addInitScript((r) => {
    window.PPS_CONFIG = { ajaxUrl: location.origin + '/wp-admin/admin-ajax.php', cartUrl: location.origin + '/cart.html',
      cartNonce: 'c', uploadNonce: 'u', productId: 33670, maxUpload: 200 * 1048576, reorder: r };
  }, b64(reorder));
  await p.route('**/wp-admin/admin-ajax.php**', async (route) => {
    const body = (route.request().postDataBuffer() || Buffer.alloc(0)).toString('utf8');
    if (/name="action"\r\n\r\npps_add_to_cart/.test(body)) {
      const m = body.match(/name="pps_metadata"\r\n\r\n([\s\S]*?)\r\n--/);
      try { s.meta = JSON.parse(m ? m[1] : 'null') || {}; } catch (e) { s.meta = { unparsed: true }; }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { cart_item_key: 'k' } }) });
    }
    if (/name="action"\r\n\r\npps_upload_artwork/.test(body)) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { path: 'pps-artwork/x' } }) });
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
  for (let i = 0; i < 20 && !s.meta && !s.dialogs.length; i++) await s.p.waitForTimeout(400);
  await s.p.waitForTimeout(400);
}
// The free-delivery date as the panel shows it.
const freeText = (p) => p.evaluate(() => {
  const h = [...document.querySelectorAll('div')].find(d => (d.textContent || '').trim() === 'Free Delivery Estimate');
  return h && h.nextElementSibling ? h.nextElementSibling.textContent.trim() : null;
});
// Open the date picker and take the first day it lets you choose — the earliest date.
async function pickEarliest(p) {
  return p.evaluate(async () => {
    const lbl = [...document.querySelectorAll('label')].find(l => (l.textContent || '').trim() === 'Select a specific date');
    const trig = lbl && lbl.nextElementSibling && lbl.nextElementSibling.firstElementChild;
    if (!trig) return null;
    trig.click();
    // A day cell sits in the month grid: a parent holding at least 28 day cells.
    const findCell = () => [...document.querySelectorAll('div')].find(d => /^\d{1,2}$/.test((d.textContent || '').trim()) && d.children.length === 0
      && d.style.cursor === 'pointer' && d.parentElement && d.parentElement.children.length >= 28);
    let cell = null;
    for (let i = 0; i < 30 && !cell; i++) { await new Promise(r => setTimeout(r, 100)); cell = findCell(); }
    if (!cell) return null;
    const day = cell.textContent.trim();
    cell.click();
    await new Promise(r => setTimeout(r, 300));
    return day;
  });
}

for (const file of PAGES) {
  console.log('\n── ' + file + ' / a four-digit ZIP ──');
  {
    const s = await open(file, { ...JOB, shipState: 'MA', shipAddr: { ...ADDR, zip: '2134' } });
    await addToOrder(s);
    const txt = await s.p.evaluate(() => document.body.innerText);
    ok(`${file}: a ZIP the carrier cannot use stops the order before anything uploads`, !s.meta, 'posted=' + !!s.meta);
    ok(`${file}: and the customer is told what to fix`, /5-digit ZIP code \(did you mean 02134\?\)/.test(txt), (txt.match(/Shipping address[^\n]*/) || [''])[0]);
    await s.ctx.close();
  }

  console.log('\n── ' + file + ' / a hardcopy proof to another address ──');
  {
    // An edited line restores from the same config a reorder does. It dropped the proof
    // address, so the proof went to the order's ship-to; and a blank one was accepted.
    const PA = { name: 'Kelley Curry', street: '9 Proof Ln', city: 'Tempe', state: 'AZ', zip: '85281' };
    const s = await open(file, { ...JOB, proof: 3.01, proofAddrSame: false, proofAddr: PA, shipState: 'AZ', shipAddr: { ...ADDR, city: 'Phoenix', zip: '85087' } });
    await addToOrder(s);
    const m = s.meta || {};
    ok(`${file}: an edited hardcopy-proof line keeps its proof address`, m.proofAddrSame === false && m.proofAddr && m.proofAddr.street === '9 Proof Ln' && m.proofAddr.zip === '85281',
       JSON.stringify({ same: m.proofAddrSame, addr: m.proofAddr, dialogs: s.dialogs }));
    await s.ctx.close();
    const t = await open(file, { ...JOB, proof: 3.01, proofAddrSame: false, proofAddr: { ...PA, street: '', zip: '8528' }, shipState: 'AZ', shipAddr: { ...ADDR, city: 'Phoenix', zip: '85087' } });
    await addToOrder(t);
    ok(`${file}: a proof address with no street or a bad ZIP stops the order and says which`, !t.meta && t.dialogs.some(d => /hardcopy proof[\s\S]*street address[\s\S]*5-digit ZIP/.test(d)),
       'posted=' + !!t.meta + ' dialogs=' + t.dialogs.join(' || ').slice(0, 200));
    await t.ctx.close();
  }

  console.log('\n── ' + file + ' / the quote follows the clock ──');
  {
    const s = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...ADDR, city: 'Phoenix', zip: '85087' } });
    const before = await freeText(s.p);
    await s.p.clock.fastForward('24:00:00');
    await s.p.waitForTimeout(800);
    const after = await freeText(s.p);
    ok(`${file}: a tab left open overnight re-quotes the delivery date without being touched`, !!before && !!after && before !== after, `${before} → ${after}`);
    await s.ctx.close();
  }

  console.log('\n── ' + file + ' / a picked date the shop can no longer meet ──');
  {
    const s = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...ADDR, city: 'Phoenix', zip: '85087' } });
    const day = await pickEarliest(s.p);
    const fb = await freeText(s.p);
    await s.p.clock.fastForward('24:00:00');
    let txt = '';
    for (let i = 0; i < 25 && !/We can no longer deliver by this date/.test(txt); i++) { await s.p.waitForTimeout(200); txt = await s.p.evaluate(() => document.body.innerText); }
    const fa = await freeText(s.p);
    ok(`${file}: the page says the picked date can no longer be met`, /We can no longer deliver by this date/.test(txt), 'picked day ' + day + ' free ' + fb + ' → ' + fa);
    await addToOrder(s);
    ok(`${file}: and Add to Order stops, saying why, instead of quietly quoting another date`, !s.meta && s.dialogs.some(d => /can no longer deliver by the date you picked[\s\S]*has not been placed/.test(d)),
       'posted=' + !!s.meta + (s.meta ? ' est=' + s.meta.estimatedDeliveryDate + ' req=' + s.meta.requestedBizDays : '') + ' dialogs=' + s.dialogs.join(' || ').slice(0, 200));
    ok(`${file}: no page errors`, !s.errs.length, s.errs.join(' | '));
    await s.ctx.close();
  }
}

await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ORDER JUNCTURES FAILED' : '>>> ORDER JUNCTURES OK');
process.exit(failed ? 1 : 0);
