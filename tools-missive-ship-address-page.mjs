// The Ship page's address tools in a real browser (owner 2026-10-20: Shippo edits did not reach
// the panel, and an address changed on the Ship page was lost on leaving). tools-missive-ship-test.php
// writes the page with an address endpoint; this answers it: a check that finds Shippo holding
// another address, then the saves. Checks: the mismatch is shown with both addresses; "Use
// Shippo's address" asks, posts Shippo's address marked from=shippo and fills the fields; an edit
// shows "Save to the order" and saves the edited address; buying with an unsaved edit asks first.
import { readFileSync } from 'fs'; import { execSync } from 'child_process'; import path from 'path';
const pw = await import(path.join(execSync('npm root -g').toString().trim(), 'playwright/index.mjs'));
const html = readFileSync(process.argv[2], 'utf8');
const checks = []; const ok = (c, w, g) => checks.push([!!c, w, g === undefined ? null : g]);
const errs = [], dialogs = [], posts = [];
const b = await pw.chromium.launch(); const p = await b.newPage({ viewport: { width: 360, height: 900 } });
p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => { dialogs.push(d.message()); d.accept(); });
const shippo = { name: 'Angela Waggoner', company: '', street1: '12 New Rd', street2: '', city: 'Austin', state: 'TX', zip: '78701', phone: '' };
let saved = null;
await p.route('https://site.test/**', async r => {
  const u = r.request().url();
  if (u.endsWith('/ship-page')) return r.fulfill({ contentType: 'text/html', body: html });
  const body = JSON.parse(r.request().postData() || '{}');
  if (u.endsWith('/address')) {
    posts.push({ token: r.request().headers()['x-pps-ship-token'], body });
    if (body.check) return r.fulfill({ contentType: 'application/json', body: JSON.stringify(saved ? { ok: true, shippo: 'match', shippo_line: '', shippo_addr: saved } : { ok: true, shippo: 'stale', shippo_line: 'x', shippo_addr: shippo }) });
    saved = Object.assign({ country: 'US', source: 'shipping', email: '' }, body.address);
    return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, to: saved, shippo_words: 'Shippo has the new address.' }) });
  }
  if (u.endsWith('/rates')) return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, quote: 'Q', ship_date: '2026-10-21', delivery: '', must_ship: '', boxes: 1, notes: [], options: [{ id: 'o1', carrier: 'UPS', service: 'Ground', account: 'Shippo rates', own_account: false, amount: 9, days: 3, arrives: '2026-10-26', by: '', terms: '', on_time: null, tags: [] }] }) });
  return r.fulfill({ contentType: 'application/json', body: '{"ok":false,"error":"no"}' });
});
await p.goto('https://site.test/ship-page'); await p.waitForTimeout(300);
const txt = () => p.evaluate(() => document.body.innerText);
let t = await txt();
ok(posts.length === 1 && posts[0].body.check === 1 && posts[0].token && posts[0].token.length === 64, 'the page asks the site (with its order token) whether Shippo agrees', posts[0]);
ok(/Shippo has a different address for this order/.test(t) && /Shippo: Angela Waggoner, 12 New Rd, Austin, TX 78701/.test(t) && /Order: Angela Waggoner, 900 Henderson Ave/.test(t), 'a mismatch is shown with both addresses', t.slice(0, 400));
ok(!/Edited here only/.test(t), 'no Save bar before anything is edited');
await p.click('button:has-text("Use Shippo\'s address")'); await p.waitForTimeout(300);
const use = posts.filter(x => !x.body.check)[0];
ok(dialogs.length === 1 && /to Shippo's address\?/.test(dialogs[0]) && /12 New Rd, Austin, TX 78701/.test(dialogs[0]) && /\n\n/.test(dialogs[0]), 'Use Shippo\'s address asks first, naming it', dialogs[0]);
ok(use && use.body.from === 'shippo' && use.body.address.street1 === '12 New Rd' && use.body.address.zip === '78701' && use.body.order === 87551, 'and posts Shippo\'s address to the order, marked as from Shippo', use);
t = await txt();
ok(await p.inputValue('input[placeholder="Street"]') === '12 New Rd' && /Saved to the order/.test(t) && !/Shippo has a different address/.test(t) && /Shippo's order and this order both have: Angela Waggoner, 12 New Rd, Austin, TX 78701/.test(t), 'the fields now show it, the page says it was saved, and Shippo is checked again', t.slice(0, 300));
// an edit on the page
await p.fill('input[placeholder="Apt, suite"]', 'Suite 4');
t = await txt();
ok(/Edited here only — not saved to the order/.test(t), 'an edit shows that it is not saved yet, with Save to the order');
await p.fill('input[placeholder="Your name"]', 'Pat');
await p.click('button:has-text("Save to the order")'); await p.waitForTimeout(300);
const sv = posts.filter(x => !x.body.check).slice(-1)[0];
ok(sv.body.address.street2 === 'Suite 4' && sv.body.who === 'Pat' && sv.body.from === '', 'Save to the order posts the edited address and who saved it', sv.body);
ok(!/Edited here only/.test(await txt()) && await p.evaluate(() => localStorage.getItem('pps_ship_who')) === 'Pat', 'once saved the bar goes, and the name is remembered for next time');
// buying with an unsaved edit
await p.fill('input[placeholder="Apt, suite"]', 'Suite 9');
await p.click('text=Get rates'); await p.waitForTimeout(300);
await p.click('.rate'); await p.waitForTimeout(100);
const before = posts.length; dialogs.length = 0;
await p.click('button:has-text("Buy label")'); await p.waitForTimeout(400);
ok(/not saved to the order/.test(dialogs[0] || '') && posts.slice(before).some(x => !x.body.check && x.body.address.street2 === 'Suite 9') && !dialogs.some(d => /^Buy /.test(d)), 'buying with an unsaved edit asks first, and OK saves it to the order before anything is bought', dialogs);
ok(!errs.length, 'no script errors', errs);
await b.close();
console.log(JSON.stringify({ checks }));
