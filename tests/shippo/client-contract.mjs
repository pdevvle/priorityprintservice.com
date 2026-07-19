// Shippo client-wiring contract test (headless, no real network).
//
// The calculators are SUPPOSED to (once wired): debounce a POST to
// /wp-json/pps/v1/shipping/transit-estimate when a 5-digit ZIP is typed,
// swap the static state-map transit days for the response, fall back to the
// static map on any error, and never call at all when shippo_enabled=false.
//
// Modes:
//   node client-contract.mjs               → baseline: reports current behavior,
//                                            exits 0 even while unwired (documents state)
//   node client-contract.mjs --acceptance  → gate: fails unless the wiring behaves
//
// Setup (sandboxes block CDNs — vendor the libs and serve a rewritten copy):
//   see tests/shippo/README.md. Point CALC_URL at the served calculator.
//
// Env: CALC_URL (required), PLAYWRIGHT_DIR (default /opt/node22/lib/node_modules/playwright)

const CALC_URL = process.env.CALC_URL;
const PW = process.env.PLAYWRIGHT_DIR || '/opt/node22/lib/node_modules/playwright';
const ACCEPT = process.argv.includes('--acceptance');
if (!CALC_URL) { console.error('CALC_URL env var required (served calculator URL)'); process.exit(2); }

const { chromium } = await import(PW + '/index.mjs');

async function scenario(name, { enabled, respond }, expect) {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const calls = [];
  const errors = [];
  page.on('pageerror', e => errors.push(String(e).slice(0, 120)));
  await page.addInitScript(({ enabled, respond }) => {
    window.PPS_CONFIG = { calc: { pcf: { shippo_enabled: enabled } } };
    window.__shippoCalls = [];
    const origFetch = window.fetch ? window.fetch.bind(window) : null;
    window.fetch = (url, opts) => {
      if (String(url).includes('/shipping/transit-estimate')) {
        window.__shippoCalls.push({ url: String(url), body: opts && opts.body ? String(opts.body) : '' });
        if (respond.error) return Promise.resolve(new Response('err', { status: 500 }));
        return Promise.resolve(new Response(JSON.stringify(respond.json), { status: 200, headers: { 'Content-Type': 'application/json' } }));
      }
      return origFetch ? origFetch(url, opts) : Promise.reject(new Error('no fetch'));
    };
  }, { enabled, respond });
  await page.goto(CALC_URL, { waitUntil: 'load' });
  await page.waitForSelector('select', { timeout: 45000 });
  await page.waitForTimeout(500);
  // Type a NY ZIP into the shipping ZIP field
  const zip = page.locator('input[placeholder="ZIP"]').first();
  if (await zip.count()) {
    await zip.click();
    await zip.pressSequentially('10001', { delay: 60 });
  }
  await page.waitForTimeout(2000); // debounce window + swap
  const calls_ = await page.evaluate(() => window.__shippoCalls);
  const body = await page.evaluate(() => document.body.innerText);
  await browser.close();
  const got = { calls: calls_.length, zipInPayload: calls_.some(c => c.body.includes('10001')), errors: errors.length };
  const checks = [];
  if (expect.calls !== undefined) checks.push(['calls', got.calls === expect.calls, `got=${got.calls} want=${expect.calls}`]);
  if (expect.zipInPayload !== undefined) checks.push(['zipInPayload', got.zipInPayload === expect.zipInPayload, `got=${got.zipInPayload}`]);
  if (expect.bodyRe !== undefined) checks.push(['bodyRe', expect.bodyRe.test(body), String(expect.bodyRe)]);
  checks.push(['no JS errors', got.errors === 0, errors.join('; ') || 'none']);
  const pass = checks.every(c => c[1]);
  console.log(`--- ${name} --- ${pass ? 'PASS' : 'FAIL'}`);
  for (const [k, ok, d] of checks) console.log(`  ${ok ? 'PASS' : 'FAIL'} ${k}: ${d}`);
  return pass;
}

let all = true;
// 1. Wired + healthy server → exactly one debounced call carrying the ZIP; UI uses 3d (static NY would be 5d)
all &= await scenario('enabled, server says 3 days', { enabled: true, respond: { json: { transit_days: 3, carrier: 'UPS', service: 'Ground', domestic: true, cached: false } } },
  { calls: 1, zipInPayload: true, bodyRe: /\(3d[^)]*transit\)/i });
// 2. Wired + server error → zero UI breakage, static fallback (NY=5)
all &= await scenario('enabled, server 500 → static fallback', { enabled: true, respond: { error: true } },
  { calls: 1, bodyRe: /\(5d[^)]*transit\)/i });
// 3. Disabled → never calls
all &= await scenario('disabled → no calls', { enabled: false, respond: { json: {} } },
  { calls: 0 });

if (ACCEPT) {
  console.log(all ? '\nACCEPTANCE: ALL PASS — client wiring meets the contract' : '\nACCEPTANCE: FAIL');
  process.exit(all ? 0 : 1);
} else {
  console.log(all ? '\nBASELINE: contract already satisfied (wiring present)' : '\nBASELINE: client not wired yet (expected until the fetch lands) — informational only');
  process.exit(0);
}
