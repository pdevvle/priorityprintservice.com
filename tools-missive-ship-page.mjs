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
const qctx = await b2.newContext({ viewport: { width: 360, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] }); const q = await qctx.newPage();
q.on('pageerror', e => errs.push(e.message));
q.on('dialog', d => { dialogs.push(d.message()); confirmAnswer ? d.accept() : d.dismiss(); });
const posts2 = [];
await q.route('https://site.test/**', async route => {
  const req = route.request();
  if (req.url().endsWith('/ship-page')) return route.fulfill({ contentType: 'text/html', body: html });
  const body = JSON.parse(req.postData() || '{}');
  posts2.push({ url: req.url(), token: req.headers()['x-pps-ship-token'], body });
  if (req.url().endsWith('/rates')) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, quote: 'Q1', ship_date: '2026-10-21', delivery: '2026-10-29', must_ship: '2026-10-22', boxes: 2, extra: { signature: 'ADULT', insure: 150 }, notes: ['UPS: reconnect your carrier account.'], options: [
    { id: 'o-ga', carrier: 'USPS', service: 'Ground Advantage', account: 'Shippo rates', own_account: false, amount: 21.2, days: 5, arrives: '2026-10-28', by: '', terms: '', on_time: true, tags: [] },
    { id: 'o-ups', carrier: 'UPS', service: 'Ground', account: 'Shippo rates', own_account: false, amount: 24.8, each: 12.4, insurance: 7.28, days: 3, arrives: '2026-10-26', by: '', terms: '', on_time: true, tags: [] },
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
ok(/estimated 64\.4 lb in 2 cartons/.test(t) && (await q.evaluate(() => document.querySelectorAll('.box').length)) === 1 && (await q.evaluate(() => document.querySelector('.box input.n').value)) === '2' && /2 labels/.test(t), 'the calculator\'s estimate opens as one box row "× 2" — two labels — with a note to weigh them');
// twelve boxes like this: one row, a count
await q.locator('.box input.n').fill('12'); await q.locator('.box input.n').dispatchEvent('change'); await q.waitForTimeout(100);
ok(/12 labels/.test(await txt()), 'a count of 12 is twelve labels to the same address');
await q.locator('.box input.n').fill('2'); await q.locator('.box input.n').dispatchEvent('change'); await q.waitForTimeout(100);
// signature and insurance
await q.evaluate(() => { const s = [...document.querySelectorAll('select')].find(x => [...x.options].some(o => o.value === 'ADULT')); s.value = 'ADULT'; s.dispatchEvent(new Event('change')); });
await q.waitForTimeout(50);
await q.locator('input[title="Declared value of each box"]').fill('150'); await q.locator('input[title="Declared value of each box"]').dispatchEvent('change'); await q.waitForTimeout(100);
ok(/Carriers that cannot take a signature are left out/.test(await txt()) && /its cost is included in each rate/.test(await txt()), 'choosing a signature and insurance says what they do to the rates');
ok(/From: Priority Print Service, 2941 W Eastman Dr, Anthem, AZ 85086/.test(t) && /No shop phone is set/.test(t), 'the ship-from is shown, and a missing shop phone is said');
const buyBtn = () => q.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /^Buy |Pick a rate/.test(x.textContent)); return b ? { text: b.textContent, disabled: b.disabled } : null; });
ok((await buyBtn()) === null, 'no buy button before rates');
await q.getByText('Get rates').click(); await q.waitForTimeout(300);
t = await txt();
ok(posts2.length === 1 && posts2[0].token && posts2[0].token.length === 64 && posts2[0].body.order === 87551 && posts2[0].body.boxes.length === 1 && posts2[0].body.boxes[0].count === 2 && posts2[0].body.to.zip === '77058' && posts2[0].body.extra.signature === 'ADULT' && posts2[0].body.extra.insure === 150, 'Get rates sends the order, its token, the address, the boxes with their count, and the signature and insurance', posts2[0]);
ok(/2 labels, ships Wed, Oct 21 · Adult signature \(21\+\) · insured \$150\.00\/box/i.test(t) && /\$12\.40 each/.test(t) && /includes \$7\.28 insurance/.test(t), 'the rates say what they were priced with, and the price each', t.slice(t.indexOf('RATES'), t.indexOf('RATES') + 120));
ok(/USPS Ground Advantage/.test(t) && /arrives Wed, Oct 28 \(5 business days\)/.test(t) && /· on time/.test(t), 'each rate says when it arrives and that it is on time');
ok(/LATE vs promised Thu, Oct 29/.test(t) && /Work Hard Play Hard Dec 24/.test(t), 'a late rate is marked, and another account is named on its rate');
ok(/Carrier notes \(1\)/.test(t), 'carrier notes are folded away');
ok((await buyBtn()).disabled && /Pick a rate/.test((await buyBtn()).text), 'Buy waits for a rate to be picked');
await q.evaluate(() => { const s = [...document.querySelectorAll('select')].find(x => [...x.options].some(o => o.value === 'fast')); s.value = 'fast'; s.dispatchEvent(new Event('change')); });
await q.waitForTimeout(100);
ok((await q.evaluate(() => [...document.querySelectorAll('.rate b')].map(b => b.textContent)))[0] === 'UPS Ground', 'fastest first puts the earliest arrival on top');
await q.locator('.rate', { hasText: 'UPS Ground' }).click(); await q.waitForTimeout(100);
ok(/Buy 2 labels — \$24\.80/.test((await buyBtn()).text) && !(await buyBtn()).disabled, 'picking a rate offers to buy both labels, at the total', (await buyBtn()).text);
confirmAnswer = false;
await q.getByText(/Buy 2 labels/).click(); await q.waitForTimeout(200);
ok(dialogs.length === 1 && /Buy 2 labels for order #87551\?/.test(dialogs[0]) && /UPS Ground — \$24\.80 \(2 labels\)/.test(dialogs[0]) && /Adult signature \(21\+\)/.test(dialogs[0]) && /Insured for \$150\.00 a box \(insurance \$7\.28, included\)/.test(dialogs[0]) && /Billed to: UPS · Shippo rates/.test(dialogs[0]) && /To: Angela Waggoner, 900 Henderson Ave #610, Houston, TX 77058/.test(dialogs[0]), 'Buy asks first: carrier, service, price, account billed and the address', dialogs[0]);
ok(posts2.length === 1, 'and a cancelled confirm buys nothing');
confirmAnswer = true;
await q.getByText(/Buy 2 labels/).click(); await q.waitForTimeout(300);
ok(posts2.length === 2 && posts2[1].url.endsWith('/buy') && posts2[1].body.quote === 'Q1' && posts2[1].body.option === 'o-ups' && posts2[1].token === posts2[0].token, 'confirmed: the buy names this quote and the option — never a rate', posts2[1]);
t = await txt();
ok(/✓ 2 labels bought/.test(t) && /Attached to this order in Shippo/.test(t) && /Labels bought here/i.test(t) && /1ZB/.test(t), 'the labels, their tracking numbers and where they were filed are shown', t.slice(0, 900));
ok(/Tracking links/i.test(t), 'after buying: a list of tracking links');
ok((await q.evaluate(() => document.querySelector('textarea.links').value)) === 'Box 1 of 2: https://ups.example/1ZA\nBox 2 of 2: https://ups.example/1ZB', 'and the same list as text, ready to paste to the customer', await q.evaluate(() => document.querySelector('textarea.links').value));
await q.getByText('Copy tracking links').click(); await q.waitForTimeout(200);
ok(await q.evaluate(() => [...document.querySelectorAll('button')].some(b => b.textContent === 'Copied ✓')), 'Copy tracking links copies them');
ok(await q.evaluate(() => [...document.querySelectorAll('button')].some(b => b.textContent === 'Open all 2 label PDFs')), 'and every label PDF opens with one press');
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
