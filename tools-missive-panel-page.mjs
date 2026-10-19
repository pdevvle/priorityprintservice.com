// The Missive panel page in a real browser, for tools-missive-panel-test.php (which writes the
// page with pps_mp_page_html() and reads the JSON this prints). The panel API answers with a
// small board; checks the job specs render as coloured pills (one colour per value, the same
// paper the same colour on every row), the late-start pill, and the Analytics button opening
// the analytics page in the panel's frame without a data refresh closing it.
import { readFileSync } from 'fs'; import { execSync } from 'child_process'; import path from 'path';
const pw = await import(path.join(execSync('npm root -g').toString().trim(), 'playwright/index.mjs'));
const html = readFileSync(process.argv[2], 'utf8'); const shot = process.argv[3] || '';
const checks = []; const ok = (c, w, g) => checks.push([!!c, w, g === undefined ? null : g]);
const errs = [];
const row = (o) => Object.assign({ order: 87001, item: 1, customer: 'Ann Lee', what: 'Menu', qty: '500', size: '8.5×11', start: '2026-10-21', must_ship: '2026-10-23', delivery: '2026-10-28',
  rush: false, printed: false, source: 'calculator', finishing: [], addons: [], paper: '100lb Gloss Text', sheet: '13x19', paper_key: 'g', sheets: 250,
  papers: [{ label: '100lb Gloss Text', sheet: '13x19', sheets: 250, key: 'g' }], task: null, due_mismatch: false, drive: '' }, o);
const answer = { today: '2026-10-19', orders: [], missing: [], missive: { connected: false }, board: [
  row({ order: 87001, finishing: ['Coating', 'Fold'], addons: ['Coating: UV Gloss (both sides)', 'Fold: Tri-fold'] }),
  row({ order: 87002, item: 2, customer: 'Bo Ray', start: '2026-10-15', qty: '1000', size: '6×9', papers: [{ label: '80lb Matte Cardstock', sheet: '13x19', sheets: 90, key: 'm' }] }),
  row({ order: 87003, item: 3, customer: 'Cy Doe', addons: ['Coating: Aqueous'], finishing: ['Coating'] }) ] };
const b = await pw.chromium.launch(); const top = await b.newPage({ viewport: { width: 380, height: 900 } });
top.on('pageerror', e => errs.push(e.message + ' @ ' + (e.stack||'').split('\n').slice(0,3).join(' | ')));
// Missive loads the panel in a frame and the panel listens for conversation changes; a stand-in
// Missive object lets the test send one (the panel's only data refresh).
await top.addInitScript(() => { if (window.parent === window) return; window.Missive = { _h: {}, on(ev, fn) { this._h[ev] = fn; fn([]); }, fetchConversations: () => Promise.resolve([]), search: () => Promise.resolve([]), openURL() {} }; });
let apiCalls = 0;
await top.route('https://site.test/**', async r => {
  const u = r.request().url();
  if (u.endsWith('/wrap')) return r.fulfill({ contentType: 'text/html', body: '<!doctype html><body style="margin:0"><iframe id="f" src="https://site.test/panel-page" style="border:0;width:380px;height:900px"></iframe>' });
  if (u.includes('/panel-page')) return r.fulfill({ contentType: 'text/html', body: html });
  if (u.includes('pps_missive_analytics')) return r.fulfill({ contentType: 'text/html', body: '<!doctype html><title>an</title><h1>Production Analytics</h1>' });
  if (u.includes('missive.css')) return r.fulfill({ contentType: 'text/css', body: '' });
  apiCalls++; return r.fulfill({ contentType: 'application/json', body: JSON.stringify(answer) });
});
// missive.css carries .alert as a full-screen overlay: a class the panel must never use.
await top.route('https://integrations.missiveapp.com/**', r => r.request().url().endsWith('.css')
  ? r.fulfill({ contentType: 'text/css', body: '.alert{z-index:999;width:100%;height:100%;position:fixed;top:0;left:0;background:#000}' })
  : r.fulfill({ contentType: 'application/javascript', body: '' }));
await top.goto('https://site.test/wrap'); await top.waitForTimeout(500);
const p = top.frames().find(f => f.url().includes('/panel-page'));
p.on = () => {};
const pills = await p.evaluate(() => [...document.querySelectorAll('.row .pill')].map(e => ({ t: e.textContent, bg: e.style.background, cls: e.className })));
const byText = (t) => pills.filter(x => x.t === t);
ok(pills.length >= 12, 'every job\'s specs render as pills', pills.length);
ok(byText('100lb Gloss Text · 13×19 · 250').length === 2 && byText('100lb Gloss Text · 13×19 · 250')[0].bg === byText('100lb Gloss Text · 13×19 · 250')[1].bg, 'the same paper is the same colour on every row', byText('100lb Gloss Text · 13×19 · 250'));
const gloss = byText('100lb Gloss Text · 13×19 · 250')[0], matte = pills.find(x => x.t.startsWith('80lb Matte Cardstock'));
ok(gloss && matte && gloss.bg !== matte.bg, 'different papers get different colours', [gloss, matte]);
ok(byText('Coating: UV Gloss (both sides)').length === 1 && byText('Fold: Tri-fold').length === 1, 'each add-on is its own pill, in the words the customer chose');
ok(byText('Coating: UV Gloss (both sides)')[0].bg === byText('Coating: Aqueous')[0].bg && byText('Coating: UV Gloss (both sides)')[0].bg !== byText('Fold: Tri-fold')[0].bg, 'add-ons are coloured by kind: every coating one colour, folds another');
ok(byText('500 qty').length === 2 && byText('8.5×11').length === 2 && byText('1,000 qty').length === 1, 'quantity and size are pills too');
ok(pills.filter(x => x.t === 'LATE START' && /pill-late/.test(x.cls)).length === 1 && pills.some(x => x.t === 'start Wed, Oct 21' && /pill-plain/.test(x.cls)), 'the start date is a plain pill; a late start is a red one, on that job only', pills.filter(x => /start|START/.test(x.t)));
if (shot) await top.screenshot({ path: shot });
// analytics
const btn = await p.$('button:has-text("Analytics")');
ok(!!btn, 'the board offers an Analytics button');
await p.evaluate(() => window.scrollTo(0, 0)); await p.waitForTimeout(100);
await btn.click(); await p.waitForTimeout(300);
const fr = await p.evaluate(() => { const f = document.querySelector('iframe.imp'); return f ? f.src : null; });
ok(fr && /pps_missive_analytics=1/.test(fr) && /k=/.test(fr), 'it opens the analytics page in the panel frame, with the panel key', fr);
const calls = apiCalls;
await p.evaluate(() => window.Missive._h['change:conversations']([])); await p.waitForTimeout(300);
ok(apiCalls > calls && await p.evaluate(() => !!document.querySelector('iframe.imp')), 'new panel data does not close the analytics frame');
await p.click('button:has-text("Back to the panel")'); await p.waitForTimeout(200);
ok(await p.evaluate(() => !document.querySelector('iframe.imp') && document.querySelectorAll('.row').length === 3), 'Back returns to the board');
ok(await p.evaluate(() => { const r = document.querySelector('.pill-late').getBoundingClientRect(); return r.height < 30 && r.width < 200; }), 'a late-start pill is a small pill, not a full-screen overlay (Missive\'s own .alert class)');
// ship-to: the order card's box, change it, what is posted
const html2 = html.replace('"shipTo":""', '"shipTo":"https:\\/\\/site.test\\/wp-json\\/pps\\/v1\\/missive\\/ship-to"');
const answer2 = Object.assign({}, answer, { orders: [{ order: 87001, status: 'processing', admin_url: 'https://site.test/wp-admin/x', jobs: [], conv_ok: false, needs: 0, order_task: null, drive: null, ship: null,
  ship_to: { addr: { name: 'Ann Lee', company: '', street1: '1 Old Rd', street2: '', city: 'Mesa', state: 'AZ', zip: '85201', phone: '' }, line: 'Ann Lee, 1 Old Rd, Mesa AZ 85201',
    last: { at: '2026-10-18 10:00', who: 'Pat (order screen)', from: 'Ann Lee, 9 Elm, Mesa AZ 85201', shippo: 'stale', shippo_words: 'Shippo still shows the OLD address (x).' }, can_edit: true } }] });
const t2 = await b.newPage({ viewport: { width: 380, height: 900 } }); const posted = []; const dialogs = [];
t2.on('pageerror', e => errs.push('2: ' + e.message)); t2.on('dialog', d => { dialogs.push(d.message()); d.accept(); });
await t2.addInitScript(() => { if (window.parent === window) return; window.Missive = { _h: {}, on(ev, fn) { this._h[ev] = fn; fn([]); }, fetchConversations: () => Promise.resolve([]), search: () => Promise.resolve([]), openURL() {} }; });
await t2.route('https://integrations.missiveapp.com/**', r => r.fulfill({ contentType: r.request().url().endsWith('.css') ? 'text/css' : 'application/javascript', body: '' }));
await t2.route('https://site.test/**', async r => {
  const u = r.request().url();
  if (u.endsWith('/wrap')) return r.fulfill({ contentType: 'text/html', body: '<!doctype html><body style="margin:0"><iframe src="https://site.test/panel-page" style="border:0;width:380px;height:900px"></iframe>' });
  if (u.includes('/panel-page')) return r.fulfill({ contentType: 'text/html', body: html2 });
  if (u.includes('/missive/ship-to')) { const body = JSON.parse(r.request().postData() || '{}'); posted.push({ body, key: r.request().headers()['x-pps-panel-key'] });
    return r.fulfill({ contentType: 'application/json', body: JSON.stringify(body.check ? { ok: true, shippo: 'match', words: 'Shippo has the new address.' } : { ok: true, result: { items: 2, missive: { tasks: 1, posts: 1 } }, view: { last: { shippo_words: 'Shippo has the new address.' } } }) }); }
  return r.fulfill({ contentType: 'application/json', body: JSON.stringify(answer2) });
});
await t2.goto('https://site.test/wrap'); await t2.waitForTimeout(500);
const q = t2.frames().find(f => f.url().includes('/panel-page'));
const card = await q.evaluate(() => { const c = [...document.querySelectorAll('.card')].find(x => /^Ship to/.test(x.textContent)); return c ? c.innerText : ''; });
ok(/Ship to · Ann Lee, 1 Old Rd, Mesa AZ 85201/.test(card) && /Changed 2026-10-18 10:00 by Pat \(order screen\) — was Ann Lee, 9 Elm/.test(card) && /Shippo still shows the OLD address/.test(card) && /Check Shippo again/.test(card), 'the order card shows the ship-to, its last change and that Shippo is behind', card);
await q.click('a:has-text("Check Shippo again")'); await t2.waitForTimeout(200);
ok(posted.length === 1 && posted[0].body.check === 1 && posted[0].key === 'KEY123', 'Check Shippo again asks the site, with the panel key', posted);
await q.click('button:has-text("Change")');
await q.fill('input[placeholder="Street"]', '22 New Ave'); await q.fill('input[placeholder="City"]', 'Tempe'); await q.fill('input[placeholder="ZIP"]', '85281');
await q.fill('input[placeholder="Your name (for the note)"]', 'Sam');
await q.click('button:has-text("Save the new ship-to")'); await t2.waitForTimeout(300);
ok(dialogs.length === 1 && /Change the ship-to for order #87001 to:\nAnn Lee, 22 New Ave, Tempe, AZ 85281/.test(dialogs[0]) && /delivery date is not re-quoted/.test(dialogs[0]), 'saving asks first, naming the new address', dialogs);
const save = posted.find(x => x.body.address);
ok(save && save.body.order === 87001 && save.body.address.street1 === '22 New Ave' && save.body.address.name === 'Ann Lee' && save.body.who === 'Sam', 'it posts the order, the whole address and who changed it', save);
ok(await q.evaluate(() => /Saved\. Updated on 2 job\(s\), 1 subtask\(s\), note posted in 1 conversation\(s\)\. Shippo has the new address\./.test(document.body.innerText)), 'and says what followed');
ok(errs.length === 0, 'no script errors', errs);
await b.close();
console.log(JSON.stringify({ checks }));
