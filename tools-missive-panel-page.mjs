// The Missive panel page in a real browser, for tools-missive-panel-test.php (which writes the
// page with pps_mp_page_html() and reads the JSON this prints). The panel API answers with a
// small board; checks the job specs render as pills with one fixed colour per kind (paper,
// print, pages, qty, size), red with white text only for what is unusual (paper not in stock,
// add-ons, rush, late start), the late-start pill, and the Analytics button opening
// the analytics page in the panel's frame without a data refresh closing it.
import { readFileSync } from 'fs'; import { execSync } from 'child_process'; import path from 'path';
const pw = await import(path.join(execSync('npm root -g').toString().trim(), 'playwright/index.mjs'));
const html = readFileSync(process.argv[2], 'utf8'); const shot = process.argv[3] || '';
const checks = []; const ok = (c, w, g) => checks.push([!!c, w, g === undefined ? null : g]);
const errs = [];
const row = (o) => Object.assign({ order: 87001, item: 1, customer: 'Ann Lee', what: 'Menu', qty: '500', size: '8.5×11', start: '2026-10-21', must_ship: '2026-10-23', delivery: '2026-10-28',
  rush: false, printed: false, source: 'calculator', finishing: [], addons: [], paper: '100lb Gloss Text', sheet: '13x19', paper_key: 'g', sheets: 250,
  print: 'Color 2-sided', pages: '', papers: [{ label: '100lb Gloss Text', sheet: '13x19', sheets: 250, key: 'g', in_stock: true }], task: null, due_mismatch: false, drive: '' }, o);
const answer = { today: '2026-10-19', orders: [], missing: [], missive: { connected: false }, board: [
  row({ order: 87001, finishing: ['Coating', 'Fold'], addons: ['Coating: UV Gloss (both sides)', 'Fold: Tri-fold'] }),
  row({ order: 87002, item: 2, customer: 'Bo Ray', start: '2026-10-15', qty: '1000', size: '6×9', print: 'Inside Color · Cover Color', pages: '16 pp', rush: true, papers: [{ label: '80lb Matte Cardstock', sheet: '13x19', sheets: 90, key: 'm', in_stock: false }] }),
  row({ order: 87003, item: 3, customer: 'Cy Doe', addons: ['Coating: Aqueous'], finishing: ['Coating'] }),
  row({ order: 87400, item: 0, customer: 'Dee Fox', what: 'Reprint flyers', qty: 0, size: '', start: '2026-10-30', must_ship: '2026-10-30', delivery: '', paper: '', sheet: '', sheets: 0, papers: [], source: 'missive',
        task: { id: 'tx', title: 'Reprint flyers', state: 'todo', due: '2026-10-30', started: '', conversation: 'c1' }, missive_only: true, reason: 'Order #87400 is completed', order_status: 'completed' }) ] };
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
const styles = await p.evaluate(() => [...document.querySelectorAll('.row .pill')].map(e => { const c = getComputedStyle(e); return { t: e.textContent, cls: e.className, bg: c.backgroundColor, fg: c.color }; }));
const st = (t) => styles.filter(x => x.t === t), RED = 'rgb(127, 29, 29)', LATE = 'rgb(198, 40, 40)', WHITE = 'rgb(255, 255, 255)';
const gl = st('100lb Gloss Text · 13×19 · 250');
ok(gl.length === 2 && gl.every(x => /k-paper/.test(x.cls) && x.bg === gl[0].bg && x.bg !== RED), 'an inventoried paper is the paper colour on every row', gl);
const mt = styles.find(x => x.t.startsWith('80lb Matte Cardstock'));
ok(mt && /NOT STOCKED/.test(mt.t) && mt.bg === RED && mt.fg === WHITE, 'a paper not in stock is dark red with white text and says so', mt);
const pr = styles.filter(x => /k-print/.test(x.cls));
ok(pr.length === 3 && new Set(pr.map(x => x.bg)).size === 1 && st('Inside Color · Cover Color').length === 1, 'print specs are one colour whatever they say', pr);
ok(st('16 pp').length === 1 && /k-pages/.test(st('16 pp')[0].cls) && styles.filter(x => /k-pages/.test(x.cls)).length === 1, 'a booklet shows its page count as its own pill; flats have none');
const kinds = ['k-paper', 'k-print', 'k-pages', 'k-qty', 'k-size'].map(k => (styles.find(x => x.cls.split(' ').includes(k)) || {}).bg);
ok(kinds.every(Boolean) && new Set(kinds).size === 5 && !kinds.includes(RED) && !kinds.includes(LATE), 'each kind has its own colour, none of them red', kinds);
ok(byText('Coating: UV Gloss (both sides)').length === 1 && byText('Fold: Tri-fold').length === 1, 'each add-on is its own pill, in the words the customer chose');
ok(['Coating: UV Gloss (both sides)', 'Fold: Tri-fold', 'Coating: Aqueous'].every(t => st(t)[0] && st(t)[0].bg === RED && st(t)[0].fg === WHITE), 'add-ons are dark red with white text');
ok(st('RUSH').length === 1 && st('RUSH')[0].bg === RED && st('RUSH')[0].fg === WHITE, 'rush is a dark red pill, on the rush job only', st('RUSH'));
ok(styles.filter(x => x.bg === RED).every(x => /NOT STOCKED|Coating|Fold|RUSH/.test(x.t)), 'dark red is used for nothing else', styles.filter(x => x.bg === RED).map(x => x.t));
ok(byText('500 qty').length === 2 && byText('8.5×11').length === 2 && byText('1,000 qty').length === 1, 'quantity and size are pills too');
ok(pills.filter(x => x.t === 'LATE START' && /pill-late/.test(x.cls)).length === 1 && st('LATE START')[0].bg === LATE && st('LATE START')[0].fg === WHITE && pills.some(x => x.t === 'start Wed, Oct 21' && /pill-plain/.test(x.cls)), 'the start date is a plain pill; a late start is a red one, on that job only', pills.filter(x => /start|START/.test(x.t)));
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
ok(await p.evaluate(() => !document.querySelector('iframe.imp') && document.querySelectorAll('.row').length === 4), 'Back returns to the board');
ok(await p.evaluate(() => { const r = document.querySelector('.pill-late').getBoundingClientRect(); return r.height < 30 && r.width < 200; }), 'a late-start pill is a small pill, not a full-screen overlay (Missive\'s own .alert class)');
// a Missive task that is on no open job (owner 2026-10-20) — and the filters that hide rows, said
const mrow = await p.evaluate(() => { const r = [...document.querySelectorAll('.row')].find(x => /Reprint flyers/.test(x.textContent)); return r ? r.textContent : null; });
ok(mrow && /due Fri, Oct 30/.test(mrow) && /#87400/.test(mrow) && /Missive task only/.test(mrow) && /Order #87400 is completed/.test(mrow) && /Find in Missive/.test(mrow), 'a Missive task on no open job shows as its own row: due date, order, why it is not on a job', mrow);
ok(await p.evaluate(() => /Missive-only tasks \(1\)/.test(document.querySelector('.tools').textContent)), 'it can be hidden with a tick that counts them');
await p.selectOption('.tools select >> nth=0', 'week').catch(() => {});
const when = await p.evaluate(() => { const s = [...document.querySelectorAll('.tools select')].find(x => [...x.options].some(o => o.value === 'week')); if (s) { s.value = 'week'; s.dispatchEvent(new Event('change')); } return !!s; });
await p.waitForTimeout(150);
const readout = await p.evaluate(() => [...document.querySelectorAll('.tools .muted.small')].map(e => e.textContent).find(t => / of \d+ jobs/.test(t)) || '');
ok(when && /hidden by: ships within 7 days/.test(readout) && !/Reprint flyers/.test(await p.evaluate(() => document.body.textContent.split('jobs')[1] || '')), 'a ship-date filter (remembered in the browser) is named in the count line, so a week cap is visible', readout);
await p.click('.tools a:has-text("reset")'); await p.waitForTimeout(150);
ok(await p.evaluate(() => document.querySelectorAll('.row').length === 4), 'reset shows every row again');
// ship-to: the order card's box, change it, what is posted
const html2 = html.replace('"shipTo":""', '"shipTo":"https:\\/\\/site.test\\/wp-json\\/pps\\/v1\\/missive\\/ship-to"').replace('"ship":""', '"ship":"https:\\/\\/site.test\\/?pps_missive_ship=1"').replace('"stateApi":""', '"stateApi":"https:\\/\\/site.test\\/wp-json\\/pps\\/v1\\/missive\\/task-state"');
const job2 = (o) => Object.assign({ order: 87001, item: 11, job: 'Menu', product: 'Brochure', qty: '500', size: '8.5×11', print: 'Color 2-sided', pages: '', start: '2026-10-21', must_ship: '2026-10-23', delivery: '2026-10-28',
  window: ['2026-10-21', '2026-10-23'], rush: false, printed: false, source: 'calculator', addons: 'Coating: UV Gloss (both sides); Fold: Tri-fold', ticket: [], batch: { mates: [], combined: [] },
  papers: [{ label: '100lb Gloss Text', sheet: '13×19', sheets: 250, role: 'stock', in_stock: true, key: 'g' }], task: null, own_task: false }, o);
const answer2 = Object.assign({}, answer, { missive: { connected: true, can_change: true, can_create: false }, orders: [{ order: 87001, status: 'processing', admin_url: 'https://site.test/wp-admin/x',
  jobs: [ job2({ task: { id: 't1', title: '#87001 · Menu · 500 × 8.5×11 · start Wed Oct 21 · ship Fri Oct 23 · deliver Wed Oct 28', state: 'in_progress', due: '2026-10-21', started: '', conversation: 'c1' }, own_task: true }),
          job2({ item: 12, job: 'Booklet', qty: '200', size: '5.5×8.5', print: 'Inside Color · Cover Color', pages: '16 pp', rush: true, addons: '', start: '2026-10-15',
                 papers: [{ label: '80lb Matte Cardstock', sheet: '13×19', sheets: 90, role: 'cover', in_stock: false, key: 'm' }] }) ],
  conv_ok: false, needs: 1, order_task: null, drive: null, ship: { labels: 2, last: 'UPS Ground', tracking: '1Z2', track_url: 'https://t.test/1Z2', tracks: [ { tracking: '1Z1', url: 'https://t.test/1Z1', carrier: 'UPS', service: 'Ground', from: 'panel' }, { tracking: '1Z2', url: 'https://t.test/1Z2', carrier: 'UPS', service: 'Ground', from: 'panel' }, { tracking: '9400WEB', url: '', carrier: 'USPS', service: 'Ground Advantage', from: 'shippo' } ] },
  ship_to: { addr: { name: 'Ann Lee', company: '', street1: '1 Old Rd', street2: '', city: 'Mesa', state: 'AZ', zip: '85201', phone: '' }, line: 'Ann Lee, 1 Old Rd, Mesa AZ 85201',
    last: { at: '2026-10-18 10:00', who: 'Pat (order screen)', from: 'Ann Lee, 9 Elm, Mesa AZ 85201', shippo: 'stale', shippo_words: 'Shippo still shows the OLD address (x).' }, can_edit: true } }] });
const t2 = await b.newPage({ viewport: { width: 380, height: 900 } }); const posted = []; const dialogs = [];
await t2.addInitScript(() => { try { Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: (t) => { window.__copied = t; return Promise.resolve(); } } }); } catch (e) {} });
t2.on('pageerror', e => errs.push('2: ' + e.message)); t2.on('dialog', d => { dialogs.push(d.message()); d.accept(); });
await t2.addInitScript(() => { if (window.parent === window) return; window.Missive = { _h: {}, on(ev, fn) { this._h[ev] = fn; fn([]); }, fetchConversations: () => Promise.resolve([]), search: () => Promise.resolve([]), openURL() {} }; });
await t2.route('https://integrations.missiveapp.com/**', r => r.fulfill({ contentType: r.request().url().endsWith('.css') ? 'text/css' : 'application/javascript', body: '' }));
await t2.route('https://site.test/**', async r => {
  const u = r.request().url();
  if (u.endsWith('/wrap')) return r.fulfill({ contentType: 'text/html', body: '<!doctype html><body style="margin:0"><iframe src="https://site.test/panel-page" style="border:0;width:380px;height:900px"></iframe>' });
  if (u.includes('/panel-page')) return r.fulfill({ contentType: 'text/html', body: html2 });
  if (u.includes('/missive/task-state')) { const body = JSON.parse(r.request().postData() || '{}'); posted.push({ body, key: r.request().headers()['x-pps-panel-key'], state: true });
    return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true }) }); }
  if (u.includes('/missive/ship-to')) { const body = JSON.parse(r.request().postData() || '{}'); posted.push({ body, key: r.request().headers()['x-pps-panel-key'] });
    return r.fulfill({ contentType: 'application/json', body: JSON.stringify(body.check ? { ok: true, shippo: 'match', words: 'Shippo has the new address.' } : { ok: true, result: { items: 2, missive: { tasks: 1, posts: 1 } }, view: { last: { shippo_words: 'Shippo has the new address.' } } }) }); }
  return r.fulfill({ contentType: 'application/json', body: JSON.stringify(answer2) });
});
await t2.goto('https://site.test/wrap'); await t2.waitForTimeout(500);
const q = t2.frames().find(f => f.url().includes('/panel-page'));
const strip = await q.evaluate(() => { const oc = document.querySelector('details.oc'); const s = oc && oc.querySelector('.subs');
  const firstCard = oc && oc.querySelector('.card');
  return s ? { first: firstCard === s, text: s.innerText, rows: [...s.querySelectorAll('.row')].map(r => [...r.querySelectorAll('.pill')].map(x => { const c = getComputedStyle(x); return { t: x.textContent, cls: x.className, bg: c.backgroundColor, fg: c.color }; })) } : null; });
ok(strip && strip.first && /Subtasks in this conversation/.test(strip.text), 'the open conversation\'s subtasks are the first thing in its order card', strip && strip.text);
ok(strip && /#87001 · Menu · 500 × 8\.5×11 · start Wed Oct 21 · ship Fri Oct 23 · deliver Wed Oct 28/.test(strip.text) && /In progress/.test(strip.text) && /No subtask/.test(strip.text), 'each job shows its subtask (title and state), or that it has none', strip && strip.text);
const pr1 = strip ? strip.rows[0] : [], pr2 = strip ? strip.rows[1] : [];
ok(pr1.some(x => /k-paper/.test(x.cls) && x.t === '100lb Gloss Text · 13×19 · 250') && pr1.some(x => /k-print/.test(x.cls)) && pr1.some(x => /k-qty/.test(x.cls)) && pr1.some(x => /k-size/.test(x.cls))
  && pr1.filter(x => /pill-flag/.test(x.cls) && x.bg === RED && x.fg === WHITE).map(x => x.t).join('|') === 'Coating: UV Gloss (both sides)|Fold: Tri-fold', 'with the board\'s colour-coded pills: kinds in their colours, each add-on dark red', pr1);
ok(pr2.some(x => /NOT STOCKED/.test(x.t) && /\(cover\)/.test(x.t) && x.bg === RED) && pr2.some(x => x.t === '16 pp' && /k-pages/.test(x.cls)) && pr2.some(x => x.t === 'RUSH' && x.bg === RED) && pr2.some(x => x.t === 'LATE START' && x.bg === LATE), 'a cover paper not in stock, the page count, rush and a late start on the second job', pr2);
if (shot) await t2.screenshot({ path: shot.replace(/\.png$/, '-order.png') });
const due = await q.$('.subs button.pill.due');
ok(due && /^due Wed, Oct 21 ✎$/.test(await due.textContent()), 'the subtask\'s due date is a pill that can be clicked to change it', due && await due.textContent());
if (due) { await due.click(); await t2.waitForTimeout(50); await q.fill('.subs .duebox input[type=date]', '2026-10-27'); await q.click('.subs .duebox button:has-text("Save")'); await t2.waitForTimeout(250); }
const dp = posted.filter(x => x.state);
ok(dp.length === 1 && dp[0].body.task === 't1' && dp[0].body.due === '2026-10-27' && dp[0].key === 'KEY123' && !('state' in dp[0].body), 'Save sends the new due date for that subtask only, with the panel key', dp);
posted.length = 0;
const cpb = await q.$('button:has-text("Copy tracking")');
ok(cpb && (await cpb.textContent()) === 'Copy tracking (3)', 'a shipped order\'s Shipping box has a Copy tracking button naming how many', cpb && await cpb.textContent());
if (cpb) { await cpb.click(); await t2.waitForTimeout(150); }
const copied = await q.evaluate(() => window.__copied || '');
ok(copied === 'Box 1: https://t.test/1Z1\nBox 2: https://t.test/1Z2\nBox 3: 9400WEB' && cpb && /Copied/.test(await cpb.textContent()), 'it copies every tracking link (the number where there is no link) and says so', copied);
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
