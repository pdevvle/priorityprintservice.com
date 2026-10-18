// The Missive Ship page in a real browser, for tools-missive-ship-test.php (which writes the
// page with pps_ms_page_html() and reads the JSON this prints). The site's two endpoints are
// answered here: rates, then a buy that must carry the order token, the quote and the chosen
// option — and only after the confirm is accepted.
import { readFileSync } from 'fs'; import { execSync } from 'child_process'; import path from 'path';
const pw = await import(path.join(execSync('npm root -g').toString().trim(), 'playwright/index.mjs'));
const html = readFileSync(process.argv[2], 'utf8');
const checks = []; const ok = (c, w, g) => checks.push([!!c, w, g === undefined ? null : g]);
const errs = [], dialogs = []; let confirmAnswer = false;
const b2 = await pw.chromium.launch();
const q = await b2.newPage({ viewport: { width: 360, height: 900 } });
q.on('pageerror', e => errs.push(e.message));
q.on('dialog', d => { dialogs.push(d.message()); confirmAnswer ? d.accept() : d.dismiss(); });
const posts2 = [];
await q.route('https://site.test/**', async route => {
  const req = route.request();
  if (req.url().endsWith('/ship-page')) return route.fulfill({ contentType: 'text/html', body: html });
  const body = JSON.parse(req.postData() || '{}');
  posts2.push({ url: req.url(), token: req.headers()['x-pps-ship-token'], body });
  if (req.url().endsWith('/rates')) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, quote: 'Q1', ship_date: '2026-10-21', delivery: '2026-10-29', must_ship: '2026-10-22', boxes: 2, notes: ['UPS: reconnect your carrier account.'], options: [
    { id: 'o-ga', carrier: 'USPS', service: 'Ground Advantage', account: 'Shippo rates', own_account: false, amount: 21.2, days: 5, arrives: '2026-10-28', by: '', terms: '', on_time: true, tags: [] },
    { id: 'o-ups', carrier: 'UPS', service: 'Ground', account: 'Shippo rates', own_account: false, amount: 24.8, days: 3, arrives: '2026-10-26', by: '', terms: '', on_time: true, tags: [] },
    { id: 'o-fx', carrier: 'FedEx', service: '2Day® (One Rate)', account: 'Work Hard Play Hard Dec 24', own_account: true, amount: 62, days: 2, arrives: '2026-10-30', by: '17:00:00', terms: '', on_time: false, tags: [] } ] }) });
  return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, attached: true, error: '', labels: [
    { box: '1 of 2', carrier: 'UPS', service: 'Ground', amount: 12.4, tracking: '1ZA', track_url: 'https://ups.example/1ZA', label: 'https://deliver.goshippo.com/a.pdf', at: '2026-10-21T18:00:00Z' },
    { box: '2 of 2', carrier: 'UPS', service: 'Ground', amount: 12.4, tracking: '1ZB', track_url: 'https://ups.example/1ZB', label: 'https://deliver.goshippo.com/b.pdf', at: '2026-10-21T18:00:00Z' } ] }) });
});
await q.goto('https://site.test/ship-page');
await q.waitForTimeout(300);
const txt = () => q.evaluate(() => document.body.innerText);
let t = await txt();
ok(/Order #87551/.test(t) && /Must ship\s+Thu, Oct 22/.test(t) && /promised delivery\s+Thu, Oct 29/.test(t), 'the order, must-ship and promised delivery are shown', t.slice(0, 200));
ok(await q.evaluate(() => [...document.querySelectorAll('input')].some(i => i.value === '900 Henderson Ave #610')), 'the ship-to is filled from the order, editable');
ok(/estimated 64\.4 lb in 2 cartons/.test(t) && (await q.evaluate(() => document.querySelectorAll('.box').length)) === 2, 'two boxes from the calculator\'s estimate, with a note to weigh them');
ok(/From: Priority Print Service, 2941 W Eastman Dr, Anthem, AZ 85086/.test(t) && /No shop phone is set/.test(t), 'the ship-from is shown, and a missing shop phone is said');
const buyBtn = () => q.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /Buy label|Pick a rate/.test(x.textContent)); return b ? { text: b.textContent, disabled: b.disabled } : null; });
ok((await buyBtn()) === null, 'no buy button before rates');
await q.getByText('Get rates').click(); await q.waitForTimeout(300);
t = await txt();
ok(posts2.length === 1 && posts2[0].token && posts2[0].token.length === 64 && posts2[0].body.order === 87551 && posts2[0].body.boxes.length === 2 && posts2[0].body.to.zip === '77058', 'Get rates sends the order, its token, the address and the boxes', posts2[0]);
ok(/USPS Ground Advantage/.test(t) && /arrives Wed, Oct 28 \(5 business days\)/.test(t) && /· on time/.test(t), 'each rate says when it arrives and that it is on time');
ok(/LATE vs promised Thu, Oct 29/.test(t) && /Work Hard Play Hard Dec 24/.test(t), 'a late rate is marked, and another account is named on its rate');
ok(/Carrier notes \(1\)/.test(t), 'carrier notes are folded away');
ok((await buyBtn()).disabled && /Pick a rate/.test((await buyBtn()).text), 'Buy waits for a rate to be picked');
await q.evaluate(() => { const s = [...document.querySelectorAll('select')].find(x => [...x.options].some(o => o.value === 'fast')); s.value = 'fast'; s.dispatchEvent(new Event('change')); });
await q.waitForTimeout(100);
ok((await q.evaluate(() => [...document.querySelectorAll('.rate b')].map(b => b.textContent)))[0] === 'UPS Ground', 'fastest first puts the earliest arrival on top');
await q.locator('.rate', { hasText: 'UPS Ground' }).click(); await q.waitForTimeout(100);
ok(/Buy labels — \$24\.80/.test((await buyBtn()).text) && !(await buyBtn()).disabled, 'picking a rate offers to buy it, at its price');
confirmAnswer = false;
await q.getByText(/Buy labels/).click(); await q.waitForTimeout(200);
ok(dialogs.length === 1 && /Buy 2 labels for order #87551\?/.test(dialogs[0]) && /UPS Ground — \$24\.80/.test(dialogs[0]) && /Billed to: UPS · Shippo rates/.test(dialogs[0]) && /To: Angela Waggoner, 900 Henderson Ave #610, Houston, TX 77058/.test(dialogs[0]), 'Buy asks first: carrier, service, price, account billed and the address', dialogs[0]);
ok(posts2.length === 1, 'and a cancelled confirm buys nothing');
confirmAnswer = true;
await q.getByText(/Buy labels/).click(); await q.waitForTimeout(300);
ok(posts2.length === 2 && posts2[1].url.endsWith('/buy') && posts2[1].body.quote === 'Q1' && posts2[1].body.option === 'o-ups' && posts2[1].token === posts2[0].token, 'confirmed: the buy names this quote and the option — never a rate', posts2[1]);
t = await txt();
ok(/✓ 2 labels bought/.test(t) && /Attached to this order in Shippo/.test(t) && /Labels bought here/i.test(t) && /1ZB/.test(t), 'the labels, their tracking numbers and where they were filed are shown', t.slice(0, 900));
ok(errs.length === 0, 'no script errors', errs);
// buying switched off
const off = html.replace('"canBuy":true', '"canBuy":false');
const q2 = await b2.newPage();
await q2.route('https://site.test/**', async route => route.request().url().endsWith('/ship-page') ? route.fulfill({ contentType: 'text/html', body: off }) :
  route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, quote: 'Q2', ship_date: '2026-10-21', delivery: '', must_ship: '', boxes: 1, notes: [], options: [{ id: 'x', carrier: 'UPS', service: 'Ground', account: 'Shippo rates', own_account: false, amount: 9, days: 3, arrives: '2026-10-26', by: '', terms: '', on_time: null, tags: [] }] }) }));
await q2.goto('https://site.test/ship-page'); await q2.getByText('Get rates').click(); await q2.waitForTimeout(200);
await q2.locator('.rate', { hasText: 'UPS Ground' }).click(); await q2.waitForTimeout(100);
ok(await q2.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /Buy label/.test(x.textContent)); return b && b.disabled; }) && /Buying is switched off/.test(await q2.evaluate(() => document.body.innerText)), 'with buying off, rates show and Buy stays disabled, saying why');
await b2.close();
console.log(JSON.stringify({ checks }));
