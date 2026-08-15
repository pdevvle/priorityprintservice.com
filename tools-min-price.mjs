// Lowest achievable price per calculator — measured, never derived.
//
//   node tools-min-price.mjs                          # embedded fallback config
//   node tools-min-price.mjs --config live-config.json  # the real production PCF
//   node tools-min-price.mjs --only calc-sticker      # one calculator
//
// ── Why a browser sweep and not arithmetic ──
//
// The pricing engine is JavaScript sealed inside an IIFE in each calculator, and
// pps-calculators.php deliberately contains no pricing logic (CLAUDE.md). So the
// only way to learn what a calculator can quote is to make it quote. This drives
// the real UI and reads the rendered quantity table, exactly like
// tools-pricing-matrix.mjs, for the same reason: it measures what a customer is
// actually shown rather than an internal we hope matches it.
//
// ── The safety property that makes the output usable ──
//
// This is a greedy coordinate descent over the size/paper axes, evaluated at the
// cheapest rung of the rendered quantity ladder — not an exhaustive search of the
// whole configuration space. Two consequences, both deliberate:
//
//   It can only ever come out HIGH. Every price it reports is a real quote the
//   engine produced for a real configuration, so a "from $X" built on it is
//   always achievable. The failure mode is being conservative, never overstating
//   what a customer can get.
//
//   It measures the lowest PROMOTED price, not the theoretical floor. The ladder
//   holds the quantities the calculator itself puts forward; a customer typing a
//   smaller quantity may go lower. For a "from" figure that is arguably the
//   number you want anyway — the cheapest realistic order rather than a
//   degenerate quantity-of-one edge case — but it is not the absolute minimum,
//   and the output says so.
//
// ── The config caveat that matters most ──
//
// Run without --config and you are measuring the fallback constants embedded in
// the HTML, NOT what production charges. The owner tunes pricing in Central
// Config (pps_calc_config), which is injected at runtime as PPS_CONFIG.calc. Pass
// --config with the output of pps_get_public_config() to get authoritative
// numbers. The fingerprint written into the results records which config was
// used, so a stale sweep can be detected rather than silently trusted.

import { readFileSync, writeFileSync, existsSync, unlinkSync } from 'fs';
import { createHash } from 'crypto';

const PW = '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');

const argv = process.argv.slice(2);
const argOf = (n, d = null) => { const i = argv.indexOf(n); return i >= 0 ? argv[i + 1] : d; };
const CONFIG_FILE = argOf('--config');
const ONLY = argOf('--only');
// Default output is gitignored on purpose: these are real pricing figures and
// the repo is public (CLAUDE.md). The numbers belong in wp_options, not git.
const OUT = argOf('--out', 'docs/min-prices.json');

// Same CDN→local swap the pricing-matrix tool uses; the sandbox has no egress
// once the page is loaded from disk.
const REPL = [
  ['https://unpkg.com/react@18.3.1/umd/react.production.min.js', './node_modules/react/umd/react.production.min.js'],
  ['https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js', './node_modules/react-dom/umd/react-dom.production.min.js'],
  ['https://unpkg.com/@babel/standalone@7.26.9/babel.min.js', './node_modules/@babel/standalone/babel.min.js'],
  ['https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', './node_modules/pdfjs-dist/build/pdf.min.js'],
  ['https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js', './node_modules/pdfjs-dist/build/pdf.worker.min.js'],
  ['https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', './node_modules/jspdf/dist/jspdf.umd.min.js'],
  ['https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js', './node_modules/jspdf/dist/jspdf.umd.min.js'],
];

// Which <select> indices are worth minimising over, per calculator. Sizes and
// papers dominate; the rest of the form is left at its default because turning
// options ON only ever adds cost.
const CALCS = [
  { f: 'calc-preview-test',  name: 'Saddle stitch booklet', axes: [0, 1, 2] },
  { f: 'calc-perfect-bound', name: 'Perfect bound booklet', axes: [0, 1] },
  { f: 'calc-coupon-book',   name: 'Coupon book',           axes: [0, 1] },
  { f: 'calc-brochure',      name: 'Brochure',              axes: [0, 1, 2] },
  { f: 'calc-postcard',      name: 'Postcard',              axes: [0, 1, 2] },
  { f: 'calc-greeting-card', name: 'Greeting card',         axes: [0, 1, 2] },
  { f: 'calc-letterhead',    name: 'Letterhead',            axes: [0, 1] },
  { f: 'calc-sticker',       name: 'Sticker',               axes: [0, 1] },
];

const liveConfig = CONFIG_FILE && existsSync(CONFIG_FILE)
  ? JSON.parse(readFileSync(CONFIG_FILE, 'utf8')) : null;

// A short, stable fingerprint of the pricing constants actually in play. Stored
// with the results so a later reader can tell whether the sweep still describes
// current pricing.
const configFingerprint = liveConfig
  ? createHash('sha256').update(JSON.stringify(liveConfig)).digest('hex').slice(0, 12)
  : 'embedded-fallback';

const browser = await chromium.launch();

/** Lowest total anywhere in the rendered quantity ladder. */
const readMinTotal = (page) => page.evaluate(() => {
  const rows = [...document.querySelectorAll('tbody tr')].map((tr) => {
    const c = [...tr.children].map((td) => td.textContent.trim());
    if (c.length < 3) return null;
    const qty = Number(c[0].replace(/[^\d]/g, ''));
    const total = Number(c[2].replace(/[^0-9.]/g, ''));
    return (qty && total > 0) ? { qty, total } : null;
  }).filter(Boolean);
  if (!rows.length) return null;
  return rows.reduce((a, b) => (b.total < a.total ? b : a));
});

const results = {};

for (const calc of CALCS) {
  if (ONLY && calc.f !== ONLY) continue;

  const src = `dist/${calc.f}.html`;
  if (!existsSync(src)) { console.log(`  ! ${calc.f}: no compiled build, skipped`); continue; }

  let html = readFileSync(src, 'utf8');
  for (const [from, to] of REPL) html = html.split(from).join(to);

  // Inject the live pricing config, if given, ahead of the app's own boot.
  if (liveConfig) {
    html = html.replace('<body', '<script>window.PPS_CONFIG=Object.assign({},window.PPS_CONFIG,'
      + JSON.stringify({ calc: liveConfig }) + ');<\/script><body');
  }

  // Written into the repo root, not /tmp: the CDN swaps above are relative
  // paths into ./node_modules, so the page has to sit where they resolve.
  const tmp = `.minprice-${calc.f}.html`;
  writeFileSync(tmp, html);

  const page = await browser.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push(String(e).slice(0, 140)));
  await page.goto('file://' + process.cwd() + '/' + tmp);
  await page.waitForSelector('tbody tr', { timeout: 30000 }).catch(() => {});
  await page.waitForTimeout(900);

  let best = await readMinTotal(page);
  if (!best) {
    console.log(`  ! ${calc.f}: no quantity table rendered${errs.length ? ' — ' + errs[0] : ''}`);
    await page.close();
    continue;
  }

  const chosen = {};
  // Coordinate descent: minimise one axis at a time, repeat until a full pass
  // changes nothing. Two passes is normally enough; the cap stops a pathological
  // oscillation from running forever.
  for (let pass = 0; pass < 3; pass++) {
    let improved = false;

    for (const axis of calc.axes) {
      const n = await page.locator('select').count();
      if (axis >= n) continue;

      const opts = await page.locator('select').nth(axis).locator('option').allTextContents();
      const before = await page.locator('select').nth(axis).inputValue().catch(() => null);
      let bestLabel = null;

      for (const label of opts) {
        if (!label.trim()) continue;
        try {
          await page.locator('select').nth(axis).selectOption({ label });
        } catch { continue; }
        await page.waitForTimeout(650);
        const got = await readMinTotal(page);
        if (got && got.total < best.total - 0.001) { best = got; bestLabel = label; improved = true; }
      }

      // Restore the winner (or the original) — the loop above leaves the select
      // on whatever it tried last.
      if (bestLabel !== null) {
        await page.locator('select').nth(axis).selectOption({ label: bestLabel });
        chosen[axis] = bestLabel;
      } else if (before !== null) {
        await page.locator('select').nth(axis).selectOption(before).catch(() => {});
      }
      await page.waitForTimeout(650);
    }

    if (!improved) break;
  }

  results[calc.f] = {
    name: calc.name,
    min_total: Number(best.total.toFixed(2)),
    at_qty: best.qty,
    configuration: chosen,
    note: 'Cheapest rung of the RENDERED quantity ladder, after a greedy search '
        + 'over the size/paper axes. Excludes shipping. This is the lowest '
        + 'PROMOTED price — a customer typing a smaller quantity than the ladder '
        + 'offers may go lower still, so treat it as a conservative "from" '
        + 'figure. Every value is a real quote the engine produced.',
  };
  console.log(`  ${calc.f.padEnd(20)} $${best.total.toFixed(2)} at qty ${best.qty}`);

  await page.close();
  try { unlinkSync(tmp); } catch {}
}

await browser.close();

writeFileSync(OUT, JSON.stringify({
  config_fingerprint: configFingerprint,
  config_source: liveConfig ? CONFIG_FILE : 'embedded fallback constants — NOT production pricing',
  authoritative: Boolean(liveConfig),
  calculators: results,
}, null, 2) + '\n');

console.log(`\nWrote ${OUT} (fingerprint ${configFingerprint})`);
if (!liveConfig) {
  console.log('WARNING: no --config given, so these are the fallback constants embedded in');
  console.log('the HTML, not what production charges. Do not publish these numbers.');
}
