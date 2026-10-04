// Delivery-address checks at Add to Order — on all eight calculators.
//
// Until 2026-10-04 nothing asked whether an address was real: the calculator checked that
// four fields were filled in and that the ZIP looked like a ZIP, and the server re-checked
// the ZIP's shape. "Phoenix, TX 85001" ordered; so did a street that does not exist; a PO
// Box was quoted as UPS Ground, which cannot deliver to one. Now, and every one of these is
// a question with a "keep it as entered" answer, never a refusal (owner: nobody is blocked
// at ordering):
//   - a ZIP that belongs to another state is pointed out under the field and asked about;
//   - a PO Box is quoted with PCF.po_box_extra_days more transit (Ground Advantage) and says so;
//   - with Verify Addresses on, Shippo's answer is asked about: "Did you mean …?", "needs
//     an apartment number?", "couldn't find this address"; a correction that moves the ZIP
//     re-quotes before ordering; a failure or timeout orders, marked "not checked";
//   - a hardcopy proof sent elsewhere gets the same questions;
//   - what the customer chose reaches the order (addrCheck, proofAddrCheck, poBox) and the
//     Job Ticket.
//
// Setup: compiled builds as harness copies on :8137 — parity-saddle.html, parity-pb.html,
// slot-coupon.html, flat-<calc>.html. PPS_ADDR_PAGES selects builds (how the pre-change
// copies were run to confirm they fail).
//
// Run: node tools-address-check-test.mjs

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');

const BASE = process.env.PPS_CALC_BASE || 'http://127.0.0.1:8137';
const PAGES = (process.env.PPS_ADDR_PAGES || 'parity-saddle.html,parity-pb.html,slot-coupon.html,flat-brochure.html,flat-postcard.html,flat-greeting-card.html,flat-letterhead.html,flat-sticker.html').split(',').filter(Boolean);

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
// A Canva order with its link needs no file, so only the address can stop it.
const JOB = { artwork: 0.04, proof: 0.01, canvaLink: 'https://www.canva.com/design/TEST/edit' };
const AZ = { name: 'Test', street1: '100 N 1st Ave', city: 'Phoenix', zip: '85003' };
const T0 = new Date('2026-10-01T16:00:00Z');

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });

// answers: what each dialog gets, in order — true = OK, false = Cancel; OK when exhausted.
// verify: the endpoint's reply (object), 'fail' for HTTP 500, 'hang' for no reply; null = verification off.
// local: the reply to a localOnly (free ZIP → city) request, counted separately.
async function open(file, reorder, { answers = [], verify = null, local = null } = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1100 }, timezoneId: 'America/Phoenix' });
  const p = await ctx.newPage();
  const s = { ctx, p, dialogs: [], errs: [], meta: null, verifyHits: 0, verifyBodies: [], localHits: 0 };
  p.on('dialog', async d => {
    s.dialogs.push(d.message());
    if (d.type() !== 'confirm') return d.accept();
    const a = answers.length ? answers.shift() : true;
    if (a) await d.accept(); else await d.dismiss();
  });
  p.on('pageerror', e => s.errs.push(String(e).slice(0, 200)));
  await p.clock.install({ time: T0 });
  await p.addInitScript(([r, on]) => {
    window.PPS_CONFIG = { ajaxUrl: location.origin + '/wp-admin/admin-ajax.php', cartUrl: location.origin + '/cart.html',
      cartNonce: 'c', uploadNonce: 'u', productId: 33670, maxUpload: 200 * 1048576, reorder: r };
    if (on) window.PPS_CONFIG.calc = { pcf: { address_verify: 1 } };
  }, [b64(reorder), verify !== null]);
  await p.route('**/wp-json/pps/v1/shipping/verify', async (route) => {
    const body = JSON.parse(route.request().postData() || '{}');
    if (body.localOnly) {
      s.localHits++;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(local ? local(body) : { status: 'off' }) });
    }
    s.verifyHits++;
    s.verifyBodies.push(route.request().postData() || '');
    if (verify === 'hang') return; // never answered
    if (verify === 'fail') return route.fulfill({ status: 500, body: 'err' });
    const v = typeof verify === 'function' ? verify(JSON.parse(route.request().postData() || '{}')) : verify;
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(v) });
  });
  await p.route('**/wp-admin/admin-ajax.php**', async (route) => {
    const body = (route.request().postDataBuffer() || Buffer.alloc(0)).toString('utf8');
    if (/name="action"\r\n\r\npps_add_to_cart/.test(body)) {
      const m = body.match(/name="pps_metadata"\r\n\r\n([\s\S]*?)\r\n--/);
      try { s.meta = JSON.parse(m ? m[1] : 'null') || {}; } catch (e) { s.meta = { unparsed: true }; }
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
async function addToOrder(s, waitMs = 8000) {
  const before = s.dialogs.length;
  await s.p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim()));
    const b = btns.find(x => x.offsetParent !== null && !x.disabled) || btns.find(x => !x.disabled);
    b && b.click();
  });
  for (let i = 0; i < waitMs / 200 && !s.meta; i++) {
    await s.p.waitForTimeout(200);
    if (s.dialogs.length > before && i > 5) { await s.p.waitForTimeout(600); if (!s.meta) break; }
  }
  await s.p.waitForTimeout(400);
}
// Some builds open with the shipping section collapsed; a customer opens it to type.
async function showShip(p) {
  const visible = () => p.evaluate(() => { const e = document.querySelector('[autocomplete="shipping address-line1"]'); return !!(e && e.offsetParent); });
  if (await visible()) return;
  await p.evaluate(() => {
    const h = [...document.querySelectorAll('button[aria-expanded="false"]')].find(x => /Shipping & Delivery/.test(x.textContent || ''));
    h && h.click();
  });
  for (let i = 0; i < 20 && !(await visible()); i++) await p.waitForTimeout(150);
}
const ticket = (m) => (m && Array.isArray(m.ticket) ? m.ticket : []).map(x => x[0] + ': ' + x[1]).join('\n');
const shipText = (p) => p.evaluate(() => { const n = document.querySelector('.pps-ship-notes'); return n ? n.textContent : ''; });

for (const file of PAGES) {
  console.log('\n── ' + file + ' / ZIP in another state ──');
  {
    const s = await open(file, { ...JOB, shipState: 'TX', shipAddr: { ...AZ } }, { answers: [true] });
    const note = await shipText(s.p);
    ok(`${file}: the field says the ZIP is in another state before anything is pressed`, /ZIP 85003 is in AZ, not TX/.test(note), JSON.stringify(note));
    await addToOrder(s);
    ok(`${file}: Add to Order asks, and "go back and correct it" goes back`, !s.meta && s.dialogs.some(d => /85003 belongs to AZ, but the delivery address says TX/.test(d)), 'posted=' + !!s.meta + ' ' + s.dialogs.join(' | ').slice(0, 200));
    await s.ctx.close();
    const t = await open(file, { ...JOB, shipState: 'TX', shipAddr: { ...AZ } }, { answers: [false] });
    await addToOrder(t);
    ok(`${file}: "it's right, keep it" orders, and the order says what was kept`, !!t.meta && t.meta.addrCheck && t.meta.addrCheck?.zipState === 'AZ' && /ZIP belongs to AZ/.test(ticket(t.meta)),
      'posted=' + !!t.meta + ' addrCheck=' + JSON.stringify(t.meta && t.meta.addrCheck));
    ok(`${file}: no page errors`, !s.errs.length && !t.errs.length, s.errs.concat(t.errs).join(' | '));
    await t.ctx.close();
  }

  console.log('\n── ' + file + ' / the state typed into City ──');
  {
    // Order 87339: "Fayetteville, TN" in City with TN chosen printed "Fayetteville, TN TN".
    const s = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ, city: 'Phoenix, AZ' } });
    await addToOrder(s);
    ok(`${file}: a state repeated in City is tidied without a question`, !!s.meta && s.meta.shipAddr?.city === 'Phoenix' && s.dialogs.length === 0, 'city=' + JSON.stringify(s.meta && s.meta.shipAddr?.city) + ' dialogs=' + s.dialogs.length);
    await s.ctx.close();
  }

  console.log('\n── ' + file + ' / a PO Box ──');
  {
    const base = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } });
    await addToOrder(base);
    const box = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ, street1: 'PO Box 1234' } });
    const note = await shipText(box.p);
    await addToOrder(box);
    const b0 = base.meta ? Number(base.meta.transitDays) : NaN, b1 = box.meta ? Number(box.meta.transitDays) : NaN;
    ok(`${file}: a PO Box is quoted one more transit day (Ground Advantage)`, b1 === b0 + 1, 'street transit=' + b0 + ' PO Box transit=' + b1);
    ok(`${file}: the customer is told before ordering`, /PO Box: UPS can't deliver/.test(note), JSON.stringify(note).slice(0, 120));
    ok(`${file}: the order and the Job Ticket say PO Box`, box.meta && box.meta.poBox === true && /PO Box: UPS can't deliver/.test(ticket(box.meta)), 'poBox=' + (box.meta && box.meta.poBox));
    ok(`${file}: a street address is not taken for a PO Box`, base.meta && base.meta.poBox === false && !/PO Box/.test(ticket(base.meta)), 'poBox=' + (base.meta && base.meta.poBox));
    await base.ctx.close(); await box.ctx.close();
  }

  console.log('\n── ' + file + ' / the postal check ──');
  {
    // Off: nothing is sent, and nothing is said.
    const off = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } });
    await addToOrder(off);
    ok(`${file}: verification off sends nothing and orders`, !!off.meta && off.verifyHits === 0 && (!off.meta.addrCheck || off.meta.addrCheck?.status === 'off') && !/Address check/.test(ticket(off.meta)), 'hits=' + off.verifyHits);
    await off.ctx.close();

    // Verified: no question; the ticket says so.
    const v = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } }, { verify: { status: 'verified', type: 'commercial' } });
    await addToOrder(v);
    ok(`${file}: a verified address orders without a question`, !!v.meta && v.dialogs.length === 0 && v.meta.addrCheck && v.meta.addrCheck?.status === 'verified' && /Address check: Verified \(commercial\)/.test(ticket(v.meta)),
      'dialogs=' + v.dialogs.length + ' ' + JSON.stringify(v.meta && v.meta.addrCheck));
    const sent = v.verifyBodies[0] ? JSON.parse(v.verifyBodies[0]) : {};
    ok(`${file}: the check is sent the address as typed`, sent.street1 === '100 N 1st Ave' && sent.city === 'Phoenix' && sent.state === 'AZ' && sent.zip === '85003', JSON.stringify(sent));
    await v.ctx.close();

    // A spelling correction, same ZIP: OK takes it and orders in one press.
    const sug = { street1: '100 N 1ST AVE STE 2', street2: '', city: 'PHOENIX', state: 'AZ', zip: '85003-1902' };
    const c = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } }, { verify: { status: 'corrected', type: 'commercial', suggested: sug }, answers: [true] });
    await addToOrder(c);
    ok(`${file}: "Did you mean …?" — OK uses the suggestion and orders`, !!c.meta && c.dialogs.some(d => /Did you mean this delivery address\?/.test(d) && /100 N 1ST AVE STE 2/.test(d))
      && c.meta.shipAddr && c.meta.shipAddr?.street1 === '100 N 1ST AVE STE 2' && c.meta.shipAddr?.zip === '85003-1902' && c.meta.addrCheck?.used === 'suggested',
      'posted=' + !!c.meta + ' ship=' + JSON.stringify(c.meta && c.meta.shipAddr));
    await c.ctx.close();
    const k = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } }, { verify: { status: 'corrected', type: 'commercial', suggested: sug }, answers: [false] });
    await addToOrder(k);
    ok(`${file}: Cancel keeps the address as entered, and the order records it`, !!k.meta && k.meta.shipAddr?.street1 === '100 N 1st Ave' && k.meta.addrCheck?.used === 'entered'
      && /kept their version over the postal suggestion/.test(ticket(k.meta)), 'ship=' + JSON.stringify(k.meta && k.meta.shipAddr));
    await k.ctx.close();

    // A correction that moves the ZIP: the quote was for another destination, so stop,
    // show the new address, and order on the next press without asking again.
    const mv = { street1: '100 N 1ST AVE', street2: '', city: 'PHOENIX', state: 'AZ', zip: '85004' };
    const r = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } }, { verify: { status: 'corrected', type: 'commercial', suggested: mv }, answers: [true] });
    await addToOrder(r);
    const zipNow = await r.p.inputValue('#pps-ship-zip').catch(() => null);
    ok(`${file}: a correction that changes the ZIP stops to re-quote`, !r.meta && r.dialogs.some(d => /updated the delivery address/.test(d)) && zipNow === '85004', 'posted=' + !!r.meta + ' field=' + zipNow + ' ' + r.dialogs.join(' | ').slice(0, 160));
    const n = r.dialogs.length;
    await addToOrder(r);
    ok(`${file}: the next press orders with the corrected address, without asking again`, !!r.meta && r.meta.shipAddr?.zip === '85004' && r.dialogs.length === n, 'posted=' + !!r.meta + ' dialogs+' + (r.dialogs.length - n));
    await r.ctx.close();

    // Not found: OK goes back, Cancel orders and is recorded.
    const nf1 = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ, street1: '99999 Nowhere Rd' } }, { verify: { status: 'not_found', type: '' }, answers: [true] });
    await addToOrder(nf1);
    ok(`${file}: "couldn't find this address" — OK goes back`, !nf1.meta && nf1.dialogs.some(d => /couldn't find this delivery address/.test(d)), 'posted=' + !!nf1.meta);
    await nf1.ctx.close();
    const nf2 = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ, street1: '99999 Nowhere Rd' } }, { verify: { status: 'not_found', type: '' }, answers: [false] });
    await addToOrder(nf2);
    ok(`${file}: Cancel ships to it as entered, flagged NOT FOUND on the ticket`, !!nf2.meta && nf2.meta.addrCheck?.status === 'not_found' && /NOT FOUND in postal records/.test(ticket(nf2.meta)), 'posted=' + !!nf2.meta);
    await nf2.ctx.close();

    // Missing apartment number.
    const u = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } }, { verify: { status: 'unit', type: 'residential' }, answers: [false] });
    await addToOrder(u);
    ok(`${file}: "needs an apartment number?" — Cancel orders and records it`, !!u.meta && u.dialogs.some(d => /apartment, suite or unit number/.test(d)) && u.meta.addrCheck?.status === 'unit', 'posted=' + !!u.meta);
    await u.ctx.close();

    // The service failing never stops an order.
    const f = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } }, { verify: 'fail' });
    await addToOrder(f);
    ok(`${file}: the check failing orders anyway, marked not checked`, !!f.meta && f.dialogs.length === 0 && f.meta.addrCheck?.status === 'unavailable' && /Not checked/.test(ticket(f.meta)), 'posted=' + !!f.meta);
    await f.ctx.close();
  }

  console.log('\n── ' + file + ' / checked when the address is finished, settled in place ──');
  {
    // Owner 2026-10-04: suggest once the street address is complete, not on every keystroke.
    const sug = { street1: '100 N 1ST AVE STE 2', street2: '', city: 'PHOENIX', state: 'AZ', zip: '85003-1902' };
    const s = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { name: 'Test', street1: '', city: 'Phoenix', zip: '85003' } }, { verify: { status: 'corrected', type: 'commercial', suggested: sug } });
    await showShip(s.p);
    await s.p.click('[autocomplete="shipping address-line1"]');
    await s.p.keyboard.type('100 N 1st Ave', { delay: 30 });
    await s.p.waitForTimeout(800);
    ok(`${file}: typing the street sends nothing`, s.verifyHits === 0, 'calls while typing=' + s.verifyHits);
    await s.p.locator('[autocomplete="shipping address-line1"]').blur();
    await s.p.waitForTimeout(800);
    const card = await s.p.evaluate(() => { const n = document.querySelector('[data-pps-addr="corrected"]'); return n ? n.textContent : ''; });
    ok(`${file}: leaving the finished address checks it once and shows the suggestion under the fields`, s.verifyHits === 1 && /Did you mean 100 N 1ST AVE STE 2/.test(card), 'calls=' + s.verifyHits + ' card=' + JSON.stringify(card.slice(0, 80)));
    await s.p.evaluate(() => { const b = [...document.querySelectorAll('[data-pps-addr="corrected"] button')].find(x => /Use this address/.test(x.textContent)); b && b.click(); });
    await s.p.waitForTimeout(700);
    const f1 = await s.p.inputValue('[autocomplete="shipping address-line1"]'), fz = await s.p.inputValue('#pps-ship-zip');
    ok(`${file}: "Use this address" fills the fields`, f1 === '100 N 1ST AVE STE 2' && fz === '85003-1902', f1 + ' / ' + fz);
    await addToOrder(s);
    ok(`${file}: and Add to Order neither asks again nor calls again`, !!s.meta && s.dialogs.length === 0 && s.verifyHits === 1 && s.meta.addrCheck?.used === 'suggested' && s.meta.shipAddr?.street1 === '100 N 1ST AVE STE 2',
      'posted=' + !!s.meta + ' dialogs=' + s.dialogs.length + ' calls=' + s.verifyHits + ' ' + JSON.stringify(s.meta && s.meta.addrCheck));
    await s.ctx.close();

    const k = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } }, { verify: { status: 'corrected', type: 'commercial', suggested: sug } });
    await showShip(k.p);
    await k.p.click('#pps-ship-zip'); await k.p.locator('#pps-ship-zip').blur();
    await k.p.waitForTimeout(800);
    await k.p.evaluate(() => { const b = [...document.querySelectorAll('[data-pps-addr="corrected"] button')].find(x => /Keep mine/.test(x.textContent)); b && b.click(); });
    await k.p.waitForTimeout(300);
    const gone = await k.p.evaluate(() => !document.querySelector('[data-pps-addr="corrected"]'));
    await addToOrder(k);
    ok(`${file}: "Keep mine" closes the suggestion and Add to Order does not ask again`, gone && !!k.meta && k.dialogs.length === 0 && k.meta.addrCheck?.used === 'entered' && k.meta.shipAddr?.street1 === '100 N 1st Ave',
      'gone=' + gone + ' posted=' + !!k.meta + ' dialogs=' + k.dialogs.length);
    await k.ctx.close();

    const u = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } }, { verify: { status: 'unit', type: 'residential' } });
    await showShip(u.p);
    await u.p.click('#pps-ship-city'); await u.p.locator('#pps-ship-city').blur();
    await u.p.waitForTimeout(800);
    await u.p.evaluate(() => { const b = [...document.querySelectorAll('[data-pps-addr="unit"] button')].find(x => /None needed/.test(x.textContent)); b && b.click(); });
    await addToOrder(u);
    ok(`${file}: "None needed" on a missing unit is remembered at Add to Order`, !!u.meta && u.dialogs.length === 0 && u.meta.addrCheck?.status === 'unit' && u.meta.addrCheck?.used === 'entered', 'posted=' + !!u.meta + ' dialogs=' + u.dialogs.length);
    await u.ctx.close();

    // Verification off: the same moment asks our own server for the free ZIP → city hint.
    const h = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ, city: 'Pheonix' } },
      { verify: null, local: (b) => b.localOnly ? { status: 'off', cityHint: { zipCity: 'Phoenix', typo: true } } : { status: 'off' } });
    await showShip(h.p);
    await h.p.click('#pps-ship-city'); await h.p.locator('#pps-ship-city').blur();
    await h.p.waitForTimeout(800);
    const hint = await h.p.evaluate(() => { const n = document.querySelector('[data-pps-addr="city"]'); return n ? n.textContent : ''; });
    ok(`${file}: with verification off, a misspelt city gets the free ZIP → city hint`, /ZIP 85003 is Phoenix\. Did you mean Phoenix\?/.test(hint) && h.localHits === 1, JSON.stringify(hint.slice(0, 80)) + ' localCalls=' + h.localHits);
    await h.p.evaluate(() => { const b = [...document.querySelectorAll('[data-pps-addr="city"] button')].find(x => /Use Phoenix/.test(x.textContent)); b && b.click(); });
    await h.p.waitForTimeout(500);
    ok(`${file}: "Use Phoenix" fixes the city field`, (await h.p.inputValue('#pps-ship-city')) === 'Phoenix');
    ok(`${file}: no page errors in the inline flow`, !s.errs.length && !k.errs.length && !u.errs.length && !h.errs.length, s.errs.concat(k.errs, u.errs, h.errs).join(' | '));
    await h.ctx.close();
  }

  console.log('\n── ' + file + ' / a hardcopy proof sent elsewhere ──');
  {
    const pa = { name: 'K', street: '9 Proof Ln', city: 'Tempe', state: 'TX', zip: '85281' };
    const s = await open(file, { ...JOB, proof: 3.01, proofAddrSame: false, proofAddr: pa, shipState: 'AZ', shipAddr: { ...AZ } }, { answers: [false] });
    await addToOrder(s);
    ok(`${file}: the proof address gets the ZIP/state question too, and keeping it orders`, !!s.meta && s.dialogs.some(d => /proof address says TX/.test(d)) && s.meta.proofAddrCheck && s.meta.proofAddrCheck?.zipState === 'AZ',
      'posted=' + !!s.meta + ' ' + s.dialogs.join(' | ').slice(0, 160));
    await s.ctx.close();
  }
}

// One timeout, on one build: a check that never answers must not hold the order.
{
  const file = PAGES[0];
  console.log('\n── ' + file + ' / the check never answers ──');
  const s = await open(file, { ...JOB, shipState: 'AZ', shipAddr: { ...AZ } }, { verify: 'hang' });
  const t0 = Date.now();
  await addToOrder(s, 15000);
  ok(`${file}: a check that never answers orders after the timeout`, !!s.meta && s.meta.addrCheck?.status === 'unavailable', 'posted=' + !!s.meta + ' after ' + Math.round((Date.now() - t0) / 1000) + 's');
  await s.ctx.close();
}

await browser.close();
console.log(`\n>>> ${checks - failed}/${checks} passed${failed ? ' — ' + failed + ' FAILED' : ''}`);
process.exit(failed ? 1 : 0);
