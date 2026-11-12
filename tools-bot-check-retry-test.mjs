// Uploads and Add to Order ride through the host's bot check (2026-11-12).
//
// Lifts uploadWithProgressOnce / uploadWithProgress / ppsFreshNonces / submitToWooCommerce
// out of each COMPILED calculator and runs them against a fake server that answers with the
// host's real "One moment, please..." page before letting a request through.
//
//   node tools-bot-check-retry-test.mjs [dir-with-calc-*.html]   (default: the repo root)
// PPS_BOT_FAST=1 shortens the waits (the code's own delays are kept by default).
import fs from 'fs';
import path from 'path';
import vm from 'vm';

const dir = process.argv[2] || '.';
const CALCS = ['calc-preview-test', 'calc-perfect-bound', 'calc-coupon-book', 'calc-brochure',
  'calc-postcard', 'calc-greeting-card', 'calc-letterhead', 'calc-sticker'];
const CHALLENGE = '<!DOCTYPE html>\n<html lang="en">\n<head>\n  <meta charset="utf8">\n  <title>One moment, please...</title></head><body></body></html>';
let pass = 0, fail = 0;
const ok = (c, m) => { if (c) { pass++; console.log('ok   ' + m); } else { fail++; console.log('FAIL ' + m); } };

function lift(src, head) {
  const i = src.indexOf(head);
  if (i < 0) return '';
  let d = 0, j = src.indexOf('{', i);
  for (; j < src.length; j++) { if (src[j] === '{') d++; else if (src[j] === '}' && --d === 0) break; }
  return src.slice(i, j + 1);
}

async function run(file) {
  const src = fs.readFileSync(path.join(dir, file + '.html'), 'utf8');
  const patched = src.includes('function uploadWithProgressOnce(');
  const heads = patched ? ['function uploadWithProgressOnce(', 'async function uploadWithProgress(']
                        : ['function uploadWithProgress('];
  const reports = src.includes('function ppsReportUploadFail(');
  const code = [...heads, ...(reports ? ['function ppsReportUploadFail('] : []), 'async function ppsFreshNonces(', 'async function submitToWooCommerce('].map(h => lift(src, h)).filter(Boolean);
  const has = s => code.some(c => c.startsWith(s));
  ok(patched && has('async function uploadWithProgress('), `${file}: upload is retried around one attempt`);

  // A fake server: `plan` is the list of answers, in order, for each action.
  const mk = (plan) => {
    const log = [], alerts = [], beacons = [];
    const answer = (action) => {
      const q = plan[action] || [];
      const a = q.length ? q.shift() : 'json';
      log.push(action + ':' + a);
      if (a === 'challenge') return { status: 200, text: CHALLENGE };
      if (action === 'pps_upload_artwork') return { status: 200, text: JSON.stringify({ success: true, data: { path: 'pps-artwork/2026/11/x.pdf' } }) };
      return { status: 200, text: JSON.stringify({ success: true, data: {} }) };
    };
    class XHR {
      open() {} send(fd) { const r = answer(fd.get('action')); this.status = r.status; this.responseText = r.text; setTimeout(() => this.onload(), 1); }
      get upload() { return {}; }
    }
    const fast = process.env.PPS_BOT_FAST === '1';
    const ctx = {
      window: { PPS_CONFIG: { ajaxUrl: 'https://x/wp-admin/admin-ajax.php', uploadNonce: 'u', cartNonce: 'c', productId: 1, cartUrl: '/cart/', maxUpload: 31457280 }, location: {} },
      XMLHttpRequest: XHR, FormData, File, Blob, JSON, Promise, Math, Date, console, encodeURIComponent,
      setTimeout: (f, ms) => setTimeout(f, fast ? 1 : ms),
      fetch: async (url, o) => { const r = answer(o && o.body && o.body.get ? o.body.get('action') : 'get'); return { ok: true, status: r.status, text: async () => r.text, json: async () => JSON.parse(r.text) }; },
      alert: m => alerts.push(m),
      location: { pathname: '/product/test/' },
      navigator: { sendBeacon: (u, fd) => { beacons.push(JSON.parse(fd.get('data'))); return true; } },
    };
    vm.createContext(ctx);
    vm.runInContext(code.join('\n') + '\nthis.submit=submitToWooCommerce;', ctx);
    return { ctx, log, alerts, beacons };
  };
  const files = () => ({ type: 'files', list: [new File([new Uint8Array(10)], 'art.pdf'), new File([new Uint8Array(10)], 'art_print-ready.pdf'), new File([new Uint8Array(10)], 'art_manipulation_manifest.txt')] });
  const meta = JSON.stringify({ requestedBizDays: 5 });

  // 1. The bot check answers the 2nd upload twice and Add to Order once: the order still goes through, every file arrives.
  {
    const f = mk({ pps_upload_artwork: ['json', 'challenge', 'challenge'], pps_add_to_cart: ['challenge'] });
    await f.ctx.submit(10, 's', meta, 0, files(), null, '', []);
    ok(f.alerts.length === 0, `${file}: no error shown when the bot check lets go (${f.alerts[0] || ''})`);
    ok(f.log.filter(x => x === 'pps_upload_artwork:json').length === 3, `${file}: all three files arrive`);
    ok(f.log.filter(x => x === 'pps_add_to_cart:json').length === 1 && f.ctx.window.location.href === '/cart/', `${file}: added to the cart once, then to the cart page`);
    if (reports) ok(f.beacons.length === 1 && f.beacons[0].code === 'challenge' && f.beacons[0].file === 'art_print-ready.pdf' && !f.beacons[0].final,
      `${file}: the bot check is reported once for the file it hit, not as a lost order (${JSON.stringify(f.beacons)})`);
  }
  // 2. The bot check never lets go of the customer's file: a sentence that says what happened.
  {
    const f = mk({ pps_upload_artwork: Array(9).fill('challenge') });
    await f.ctx.submit(10, 's', meta, 0, files(), null, '', []);
    ok(f.log.length === 5 && /security check/.test(f.alerts[0] || '') && !/Invalid server response/.test(f.alerts[0] || ''), `${file}: five attempts, then a plain explanation`);
    ok(!f.log.some(x => x.startsWith('pps_add_to_cart')), `${file}: nothing added to the cart`);
    if (reports) ok(f.beacons.some(b => b.code === 'challenge' && b.final && b.file === 'art.pdf' && /security check/.test(b.reason)) && f.beacons[0].page === '/product/test/',
      `${file}: the customer's file is reported as given up, with the page`);
  }
  // 3. It never lets go of Add to Order: said so, nothing else claimed.
  {
    const f = mk({ pps_add_to_cart: Array(9).fill('challenge') });
    await f.ctx.submit(10, 's', meta, 0, files(), null, '', []);
    ok(f.log.filter(x => x.startsWith('pps_add_to_cart')).length === 5 && /security check/.test(f.alerts[0] || '') && /nothing was charged/.test(f.alerts[0] || ''), `${file}: Add to Order tried five times, then explained`);
    if (reports) ok(f.beacons.length === 1 && f.beacons[0].code === 'cart' && f.beacons[0].final && /challenge/.test(f.beacons[0].reason), `${file}: the failed Add to Order is reported`);
  }
  if (reports) {
    // 4. A file over the limit is stopped in the browser: reported, never sent.
    const f = mk({});
    f.ctx.window.PPS_CONFIG.maxUpload = 5;
    await f.ctx.submit(10, 's', meta, 0, files(), null, '', []);
    ok(!f.log.some(x => x.startsWith('pps_upload')) && f.beacons.length === 1 && f.beacons[0].code === 'toolarge' && f.beacons[0].final && f.beacons[0].size === 10,
      `${file}: an over-limit file is reported as given up and never sent`);
    // 5. A normal order sends no report.
    const g = mk({});
    await g.ctx.submit(10, 's', meta, 0, files(), null, '', []);
    ok(g.beacons.length === 0 && g.alerts.length === 0, `${file}: a clean order reports nothing`);
  }
}

for (const c of CALCS) await run(c);
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
