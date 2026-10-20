// The analytics frame page in a real browser (tools-analytics-test.php writes it with
// pps_an_frame_html() and reads the JSON this prints): every section folded on open, Open all /
// Close all, the labels fold fetched once on first open and its body shown, a failed fetch says
// so and retries on the next open, and a label link handed to the panel.
import { readFileSync } from 'fs'; import { execSync } from 'child_process'; import path from 'path';
const pw = await import(path.join(execSync('npm root -g').toString().trim(), 'playwright/index.mjs'));
const html = readFileSync(process.argv[2], 'utf8'), body = readFileSync(process.argv[3], 'utf8');
const checks = []; const ok = (c, w, g) => checks.push([!!c, w, g === undefined ? null : g]);
const b = await pw.chromium.launch(); const p = await (await b.newContext({ viewport: { width: 360, height: 900 } })).newPage();
const errs = []; p.on('pageerror', e => errs.push(e.message));
let hits = 0, fail = true;
await p.route('**/*', r => {
  const u = r.request().url();
  if (u.includes('part=labels')) { hits++; return fail ? r.fulfill({ status: 500, body: 'x' }) : r.fulfill({ status: 200, contentType: 'text/html', body }); }
  return r.fulfill({ status: 200, contentType: 'text/html', body: html });
});
await p.goto('https://site.test/?pps_missive_analytics=1&k=KEY9');
const st = () => p.$$eval('details[data-sec]', ds => ds.map(d => d.open));
ok((await st()).length >= 9 && (await st()).every(o => !o), 'every section is folded when the page opens', await st());
ok(await p.isVisible('.tiles'), 'the tiles show');
ok(hits === 0, 'nothing is fetched from Shippo until the labels fold is opened');
await p.click('details[data-sec="labels"] > summary'); await p.waitForTimeout(300);
ok(hits === 1 && /Could not load \(HTTP 500\)/.test(await p.textContent('details[data-sec="labels"]')), 'a failed fetch says so', await p.textContent('details[data-sec="labels"]'));
fail = false;
await p.click('details[data-sec="labels"] > summary'); await p.click('details[data-sec="labels"] > summary'); await p.waitForTimeout(300);
ok(hits === 2 && (await p.$$('details[data-sec="labels"] a')).length > 0, 'closing and opening again retries and shows the labels', hits);
await p.click('details[data-sec="labels"] > summary'); await p.click('details[data-sec="labels"] > summary'); await p.waitForTimeout(200);
ok(hits === 2, 'once loaded it is not fetched again');
const posted = []; await p.exposeFunction('__rec', u => posted.push(u));
await p.evaluate(() => { Object.defineProperty(window, 'parent', { value: { postMessage: m => window.__rec(m.ppsOpen) } }); });
await p.click('details[data-sec="labels"] a:has-text("Label PDF")');
ok(posted.length === 1 && /^https:\/\/deliver\.goshippo\.com\//.test(posted[0]), 'a label link in the fetched fold is handed to the panel', posted);
await p.click('[data-pps-an-all="1"]'); ok((await st()).every(o => o), 'Open all opens every section');
await p.click('[data-pps-an-all="0"]'); ok((await st()).every(o => !o), 'Close all folds them again');
ok(!errs.length, 'no script errors', errs);
await b.close();
console.log(JSON.stringify(checks));
