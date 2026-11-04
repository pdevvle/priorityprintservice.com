/**
 * The quote page's international shipping, driven in a real browser.
 *
 * The country swap and the total arithmetic live in JavaScript, which no PHP
 * suite can reach: tools-tests/quoteintl.php proves the server decides the
 * right amount, and this proves the page shows it, posts one region field, and
 * REFUSES TO LET ANYONE PAY when the rate could not be had. That last one is
 * the whole safety property -- a submit button left enabled on a failed lookup
 * charges the domestic price for a foreign address, which is the shop silently
 * paying the freight.
 *
 * Every lookup is answered locally, so nothing leaves the machine and no
 * carrier quota is spent.
 *
 * Run: node tools-tests/quoteintl-browser.mjs
 * (renders the pages itself by running the PHP suite with PPS_DUMP_DIR)
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import { mkdtempSync } from 'fs';
import { tmpdir } from 'os';
import path from 'path';
const require_ = createRequire('/opt/node-tools/');
let chromium;
try { ({ chromium } = require_('playwright')); }
catch (e) { ({ chromium } = createRequire(import.meta.url)('playwright')); }

const dir = mkdtempSync(path.join(tmpdir(), 'pps-quoteintl-'));
const suite = new URL('./quoteintl.php', import.meta.url).pathname;
execFileSync('php', [suite], { env: { ...process.env, PPS_DUMP_DIR: dir }, stdio: 'pipe' });
const file = path.join(dir, 'page.html');
let pass = 0, fail = 0;
const ok = (label, got, want) => {
  if (JSON.stringify(got) === JSON.stringify(want)) { pass++; return; }
  fail++; console.log(`FAIL ${label}: got ${JSON.stringify(got)} want ${JSON.stringify(want)}`);
};

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const page = await browser.newPage();
// Every lookup is answered by us, so nothing leaves the machine and each case
// is exact.
await page.addInitScript(() => {
  window.__reply = { ok: true, domestic: false, amount: 188.40, currency: 'USD',
                     carrier: 'UPS', service: 'Worldwide Saver', transit_days: 3 };
  window.__asked = [];
  window.fetch = (url, opts) => {
    const body = JSON.parse(opts.body || '{}');
    window.__asked.push({ url: String(url), body });
    // The domestic transit endpoint is a different URL and must not be hit for
    // a foreign address at all.
    if (String(url).indexOf('transit-estimate') !== -1) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ transit_days: 4 }) });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve(window.__reply) });
  };
});
await page.goto('file://' + file);

const st = async () => page.evaluate(() => ({
  usDisabled:    document.getElementById('q-state').disabled,
  intlDisabled:  document.getElementById('q-state-intl').disabled,
  intlRequired:  document.getElementById('q-state-intl').required,
  zipRequired:   document.getElementById('q-zip').required,
  zipMode:       document.getElementById('q-zip').getAttribute('inputmode'),
  regionLabel:   document.querySelector('label[for="q-state"] .pps-q-region-text').textContent,
  zipLabel:      document.querySelector('label[for="q-zip"] .pps-q-zip-text').textContent,
  intlHidden:    document.getElementById('q-intl').hidden,
  errHidden:     document.getElementById('q-intl-err').hidden,
  submitOff:     document.getElementById('q-submit').disabled,
  incl:          document.querySelector('.js-q-incl').textContent.trim(),
  total:         (document.getElementById('q-intl-total').textContent || '').trim(),
  base:          (document.getElementById('q-intl-base').textContent || '').trim(),
  ship:          (document.getElementById('q-intl-ship').textContent || '').trim(),
  svc:           (document.getElementById('q-intl-svc').textContent || '').trim(),
}));

// ── as rendered: the US form, exactly as it behaved before any of this ──
let s = await st();
ok('US: state select live',   s.usDisabled, false);
ok('US: twin disabled',       s.intlDisabled, true);
ok('US: zip required',        s.zipRequired, true);
ok('US: zip is numeric',      s.zipMode, 'numeric');
ok('US: label says State',    s.regionLabel, 'State');
ok('US: label says ZIP',      s.zipLabel, 'ZIP');
ok('US: no summary',          s.intlHidden, true);
ok('US: can submit',          s.submitOff, false);
ok('US: delivery included',   s.incl, 'Delivery included — this is the total.');

// ── Canada: a province is required, the ZIP is not ──────────────────────
await page.fill('#q-city', 'Toronto');
await page.selectOption('#q-country', 'CA');
await page.waitForTimeout(1400);
s = await st();
ok('CA: state select off',    s.usDisabled, true);
ok('CA: twin live',           s.intlDisabled, false);
ok('CA: province required',   s.intlRequired, true);
ok('CA: postcode optional',   s.zipRequired, false);
ok('CA: not numeric',         s.zipMode, null);
ok('CA: label retitled',      s.regionLabel, 'Province / region');
ok('CA: zip retitled',        s.zipLabel, 'Postal code');
// The money. 250.00 printing + 188.40 shipping = 438.40.
ok('CA: summary shown',       s.intlHidden, false);
ok('CA: printing line',       s.base, '$250.00');
ok('CA: shipping line',       s.ship, '$188.40');
ok('CA: total adds up',       s.total, '$438.40');
ok('CA: names the service',   s.svc, 'UPS Worldwide Saver — about 3 days');
ok('CA: can submit',          s.submitOff, false);
ok('CA: top line is honest',  s.incl,
   'Delivery is included for US addresses. Shipping to this country is added below.');
// The domestic transit endpoint must NOT have been asked about a foreign
// address: it is hardwired to country US and would answer with a US estimate.
const urls = await page.evaluate(() => window.__asked.map(a => a.url));
ok('no US transit lookup',    urls.filter(u => u.indexOf('transit-estimate') !== -1).length, 0);
ok('asked our endpoint',      urls.filter(u => u.indexOf('ship-quote') !== -1).length > 0, true);
const asked = await page.evaluate(() => window.__asked[window.__asked.length - 1].body);
ok('sent the token',          asked.token, 'tok123');
ok('sent the country',        asked.country, 'CA');
ok('sent the city',           asked.city, 'Toronto');

// ── Singapore: a region is optional, because it has none ────────────────
await page.selectOption('#q-country', 'SG');
await page.waitForTimeout(1400);
s = await st();
ok('SG: region optional',     s.intlRequired, false);
ok('SG: still quotes',        s.intlHidden, false);

// ── a refusal must stop the sale, not pass the domestic price ───────────
await page.evaluate(() => { window.__reply = { ok: false, error: 'intl_unservable', message: 'We could not get a shipping price for that address.' }; });
await page.fill('#q-city', 'Pago Pago');
await page.waitForTimeout(1400);
s = await st();
ok('refused: summary hidden', s.intlHidden, true);
ok('refused: error shown',    s.errHidden, false);
ok('refused: CANNOT submit',  s.submitOff, true);
const msg = await page.evaluate(() => document.querySelector('#q-intl-err div').textContent.trim());
ok('refused: says why',       msg, 'We could not get a shipping price for that address.');

// ── back to the US: everything restored, and payable again ──────────────
await page.selectOption('#q-country', 'US');
await page.waitForTimeout(1400);
s = await st();
ok('US again: no summary',    s.intlHidden, true);
ok('US again: no error',      s.errHidden, true);
ok('US again: can submit',    s.submitOff, false);
ok('US again: incl restored', s.incl, 'Delivery included — this is the total.');
ok('US again: state live',    s.usDisabled, false);
ok('US again: zip required',  s.zipRequired, true);

// ── only one region field ever posts ───────────────────────────────────
await page.selectOption('#q-country', 'CA');
await page.fill('#q-state-intl', 'Ontario');
await page.waitForTimeout(100);
const posted = await page.evaluate(() => {
  const fd = new FormData(document.getElementById('pps-q-form'));
  return fd.getAll('state');
});
ok('one state value posts',   posted, ['Ontario']);
await page.selectOption('#q-country', 'US');
await page.selectOption('#q-state', 'AZ');
await page.waitForTimeout(100);
const posted2 = await page.evaluate(() => {
  const fd = new FormData(document.getElementById('pps-q-form'));
  return fd.getAll('state');
});
ok('US posts the code',       posted2, ['AZ']);

// ── a multi-tier link: its own wording must survive a round trip abroad ──
// The page says "the price shown is the total" where a single-price page says
// "this is the total". A single hardcoded restore string rewrote one into the
// other, which is a sentence about a quantity dropdown appearing where there is
// no dropdown.
const page2 = await browser.newPage();
await page2.addInitScript(() => {
  window.__reply = { ok: true, domestic: false, amount: 100, currency: 'USD',
                     carrier: 'UPS', service: 'Worldwide Saver', transit_days: 3 };
  window.fetch = (u, o) => Promise.resolve({ ok: true, json: () => Promise.resolve(
    String(u).indexOf('transit-estimate') !== -1 ? { transit_days: 4 } : window.__reply) });
});
await page2.goto('file://' + file.replace('page.html', 'page-tiers.html'));
const incl2 = () => page2.evaluate(() => document.querySelector('.js-q-incl').textContent.trim());
const original = await incl2();
ok('tiers: own wording',      original, 'Delivery included — the price shown is the total.');
await page2.fill('#q-city', 'Toronto');
await page2.selectOption('#q-country', 'CA');
await page2.waitForTimeout(1400);
ok('tiers: foreign wording',  await incl2(),
   'Delivery is included for US addresses. Shipping to this country is added below.');
// The printing half follows the quantity dropdown; the shipping half is already
// known and is not asked for again.
ok('tiers: total at tier 0',  await page2.evaluate(() => document.getElementById('q-intl-total').textContent.trim()), '$350.00');
await page2.selectOption('#q-tier', '1');
await page2.waitForTimeout(200);
ok('tiers: total at tier 1',  await page2.evaluate(() => document.getElementById('q-intl-total').textContent.trim()), '$520.00');
await page2.selectOption('#q-country', 'US');
await page2.waitForTimeout(1400);
ok('tiers: wording restored', await incl2(), original);

await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
