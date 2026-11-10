// Add-on and paper lead days ON TOP of the minimum turnaround (owner 2026-11-10). Loads the
// live build and the patched build of each calculator in Chromium, captures calculate() and
// the page's own config, and compares production days across in-stock / factory paper and
// small / large runs. Rules: a job with no add-on days is unchanged; a job whose print days
// already reach the minimum is unchanged; a small job on factory paper gains exactly the
// paper's days (it used to be swallowed by the minimum).
//   NODE_PATH=$(npm root -g) node tools-addon-days-test.mjs <live dir> <patched dir>
import { createRequire } from 'module'; import http from 'http'; import fs from 'fs';
const { chromium } = createRequire(process.env.HOME + '/')('playwright');
const [oldDir, newDir] = process.argv.slice(2);
const CALCS = ['calc-letterhead', 'calc-brochure', 'calc-postcard', 'calc-greeting-card', 'calc-sticker', 'calc-preview-test', 'calc-perfect-bound', 'calc-coupon-book'];
const BOOK = new Set(['calc-preview-test', 'calc-perfect-bound', 'calc-coupon-book']);
let body = '';
const srv = http.createServer((q, r) => { r.setHeader('content-type', 'text/html'); r.end(body); }).listen(8211);
const b = await chromium.launch();
const hook = s => { const a = 'function calculate(c){'; if (s.split(a).length !== 2) throw new Error('hook'); return s.replace(a, a.replace('{', '{if(!window.__calc)window.__calc=calculate;if(!window.__cfg)window.__cfg=c;')); };
async function run(dir, name) {
  body = hook(fs.readFileSync(`${dir}/${name}.html`, 'utf8'));
  const p = await b.newPage(); await p.clock.setFixedTime(new Date('2026-11-10T17:00:00Z'));
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.goto('http://127.0.0.1:8211/'); await p.waitForFunction(() => window.__calc && window.__cfg, null, { timeout: 30000 });
  const out = await p.evaluate(book => {
    const C = window.__calc, base = window.__cfg, res = {};
    const paperKey = book ? 'insidePaper' : 'paper';
    const stock = { ...base[paperKey], val: 0.001, days: 0, factory: false };
    const factory = { ...base[paperKey], val: 2.003, days: 2, factory: true };
    for (const [pn, paper] of [['stock', stock], ['factory', factory]]) for (const q of [100, 500, 2000, 20000, 60000]) {
      const cfg = { ...base, [paperKey]: paper, needByDate: null };
      if (book) { cfg.sets = [{ qty: q, pages: (base.sets && base.sets[0] && base.sets[0].pages) || 8, name: '' }]; cfg.coverMode = 'same'; } else cfg.qty = q;
      const r = C(cfg); res[pn + ':' + q] = r && !(r.error && r.error.length) ? r.days : null;
    }
    return res;
  }, BOOK.has(name));
  await p.close(); return { out, errs };
}
let fail = 0; const ok = (c, m) => { console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) fail++; };
for (const name of CALCS) {
  const o = await run(oldDir, name), n = await run(newDir, name);
  ok(!o.errs.length && !n.errs.length, `${name}: loads without errors ${JSON.stringify(n.errs)}`);
  const rows = Object.keys(o.out).map(k => `${k} ${o.out[k]}→${n.out[k]}`).join('  ');
  console.log('     ' + rows);
  for (const k of Object.keys(o.out)) {
    const a = o.out[k], c = n.out[k]; if (a === null || c === null) { ok(a === c, `${name} ${k}: same refusal`); continue; }
    if (k.startsWith('stock')) ok(a === c, `${name} ${k}: in-stock paper unchanged (${a}→${c})`);
    else ok(c >= a && c <= a + 2, `${name} ${k}: factory paper never shorter, at most +2 (${a}→${c})`);
  }
  ok(n.out['factory:100'] === n.out['stock:100'] + 2, `${name}: a small factory-paper job takes exactly 2 days more than in-stock (${n.out['stock:100']}→${n.out['factory:100']})`);
}
await b.close(); srv.close();
console.log(fail ? `\n>>> ADD-ON DAYS FAILED (${fail})` : '\n>>> ADD-ON DAYS OK'); process.exit(fail ? 1 : 0);
