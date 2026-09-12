// Two ways an order used to go wrong without a word to the customer.
//
// 1. "Upload Art with Order" with nothing attached went straight to checkout:
//    the only artwork gate was "a file awaiting approval", so a customer whose
//    file never read (a TIFF that hung, a HEIC the browser could not decode, a
//    picker they cancelled) placed the order and production got nothing —
//    9 of 45 recent orders arrived that way (audit 2026-09-12).
// 2. On a phone, any pricing error emptied the bottom bar: the compact Panel
//    returned null, the error list lives in the desktop rail, so the price
//    vanished and Add to Order went grey with no explanation.
//
// Drives the compiled saddle calculator in the parity harness (standalone mode,
// shipping pre-filled through PPS_CONFIG.reorder so the address check passes).
// Setup is the parity harness: parity-saddle.html served on :8137 (see the
// header of tools-parity-saddle.mjs).
//
// Run: node tools-order-gates-test.mjs parity-saddle.html

const PW = '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const file = process.argv[2] || 'parity-saddle.html';

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

const reorder = Buffer.from(JSON.stringify({ shipState: 'AZ', shipAddr: { name: 'Test', street1: '1 Main St', city: 'Phoenix', zip: '85087' } }))
  .toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

async function open(b, viewport) {
  const ctx = await b.newContext({ viewport, acceptDownloads: true });
  const p = await ctx.newPage();
  const dialogs = [];
  p.on('dialog', async d => { dialogs.push(d.message()); await d.dismiss(); });
  const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0, 200)));
  await p.addInitScript((r) => { window.PPS_CONFIG = { reorder: r }; }, reorder);
  await p.goto('http://127.0.0.1:8137/' + file, { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('select'); await p.waitForTimeout(2500);
  return { p, dialogs, errs };
}

const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });

// ── 1. the artwork gate ─────────────────────────────────────────────────────
{
  const { p, dialogs, errs } = await open(b, { width: 1400, height: 1000 });
  const clickAdd = () => p.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter(x => /^Add to Order$/.test((x.textContent || '').trim()));
    const b = btns.find(x => x.offsetParent !== null) || btns[0];
    if (!b) return false; b.click(); return !b.disabled;
  });
  ok('the Add to Order button is enabled with the address pre-filled', await clickAdd());
  await p.waitForTimeout(800);
  ok('"Upload Art with Order" with no file is stopped, and the customer is told what to do',
     dialogs.length === 1 && /Please add your artwork file/.test(dialogs[0]) && /Email Art After Order/.test(dialogs[0]),
     dialogs.join(' || '));

  // Switch the artwork option to "Email Art After Order": the same click must now
  // go through (standalone mode downloads the summary and says so).
  const picked = await p.evaluate(() => {
    const opts = [...document.querySelectorAll('option')].find(o => /Email Art After Order/.test(o.textContent || ''));
    if (opts) {
      const sel = opts.parentElement;
      const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set;
      setter.call(sel, opts.value); sel.dispatchEvent(new Event('change', { bubbles: true })); return 'select';
    }
    const pill = [...document.querySelectorAll('button')].find(x => /Email Art After Order/.test(x.textContent || ''));
    if (pill) { pill.click(); return 'pill'; }
    return null;
  });
  ok('the artwork option can be switched to "Email Art After Order"', !!picked, String(picked));
  await p.waitForTimeout(600);
  await clickAdd();
  await p.waitForTimeout(1500);
  ok('with that option the order proceeds (standalone mode reports the download)',
     dialogs.length === 2 && /Standalone mode/.test(dialogs[1]), dialogs.join(' || '));
  ok('no page errors', errs.length === 0, errs.join(' | '));
  await p.context().close();
}

// ── 2. the mobile bar says why ──────────────────────────────────────────────
{
  const { p, errs } = await open(b, { width: 390, height: 844 });
  // Capacity trips above 100,000 press sheets; Quantity is clamped at 99,999, so
  // it takes a thick book too: 96 pages × 99,999 copies is far past it.
  const set = await p.evaluate(() => {
    const type = (labelRe, val) => {
      const lab = [...document.querySelectorAll('label')].find(l => labelRe.test((l.textContent || '').trim()));
      const inp = lab && lab.closest('div').querySelector('input');
      if (!inp) return null;
      inp.focus();
      const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
      setter.call(inp, val); inp.dispatchEvent(new Event('input', { bubbles: true }));
      inp.blur();
      return inp.value;
    };
    // Pages is a <select>: pick its largest option.
    const pages = (() => {
      const lab = [...document.querySelectorAll('label')].find(l => /^Pages/.test((l.textContent || '').trim()));
      const sel = lab && lab.closest('div').querySelector('select');
      if (!sel) return null;
      const opt = [...sel.options].sort((a, b) => Number(b.value) - Number(a.value))[0];
      const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set;
      setter.call(sel, opt.value); sel.dispatchEvent(new Event('change', { bubbles: true }));
      return opt.value;
    })();
    return { pages, qty: type(/^Quantity/, '99999') };
  });
  ok('a job far beyond capacity was entered', set && set.qty === '99999' && set.pages, JSON.stringify(set));
  await p.waitForTimeout(1200);
  const bar = await p.evaluate(() => {
    // The mobile bar is the fixed element docked to the bottom of the viewport.
    const host = [...document.querySelectorAll('div')].find(d => { const cs = getComputedStyle(d); return cs.position === 'fixed' && cs.bottom === '0px' && /Add to Order/.test(d.textContent || ''); });
    const cta = host && [...host.querySelectorAll('button')].find(x => /Add to Order/.test(x.textContent || ''));
    return { disabled: !!(cta && cta.disabled), text: host ? (host.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 300) : '' };
  });
  ok('the bottom-bar button is disabled', bar.disabled, JSON.stringify(bar));
  ok('and the bar shows the reason instead of nothing', /exceeds our online ordering capacity/.test(bar.text), bar.text);
  ok('no page errors', errs.length === 0, errs.join(' | '));
  await p.context().close();
}

await b.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ORDER GATES FAILED' : '>>> ORDER GATES OK');
process.exit(failed ? 1 : 0);
