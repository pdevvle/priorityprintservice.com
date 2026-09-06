// Round-corner "All 4" page cap — regression test for the saddle stitch calculator.
//
// The bindery cannot round all four corners of a thick saddle-stitched book: past
// a certain bulk the cutter can't hold the block square through four passes. The
// rule is a knob, PCF.rc_all4_max_pages (24, 0 disables), and the calculator has
// to honour it in three separate places or the customer can still buy it:
//
//   1. the dropdown must not offer an All 4 option above the cap
//   2. an All 4 option already chosen must clear itself when the page count rises
//   3. the price must not include cornering during the render tick between (2)
//      and the effect firing — otherwise a quote briefly charges for a finish
//      that will never be produced
//
// This drives the real, compiled UI rather than calling calculate() directly,
// because (1) and (2) only exist in the rendered form. Boundary is exercised on
// both sides: 24 pages still offers All 4, 28 does not. The Outside 2 options are
// the negative control — they must survive at every page count, so a test that
// passed by nuking the whole dropdown would fail here.
//
// Run: node tools-roundcorner-cap-test.mjs [path/to/calc-preview-test.html]
// Defaults to dist/. PPS_DEPS_DIR points at the node_modules holding the local
// react / pdf.js copies (the agent proxy blocks the CDNs these pages fetch from);
// PPS_PLAYWRIGHT overrides where playwright lives.

import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');

const HERE = path.dirname(new URL(import.meta.url).pathname);
const DEPS = process.env.PPS_DEPS_DIR || path.join(HERE, 'node_modules');
const PAGE = process.argv[2] || path.join(HERE, 'dist', 'calc-preview-test.html');
if (!existsSync(PAGE)) { console.error('no such page: ' + PAGE); process.exit(2); }
if (!existsSync(DEPS)) { console.error('no node_modules at ' + DEPS + ' — set PPS_DEPS_DIR'); process.exit(2); }

const rd = p => readFileSync(path.join(DEPS, p), 'utf8');
const REACT     = rd('react/umd/react.production.min.js');
const REACT_DOM = rd('react-dom/umd/react-dom.production.min.js');
const PDFJS     = rd('pdfjs-dist/legacy/build/pdf.min.mjs');
const PDFWORKER = rd('pdfjs-dist/legacy/build/pdf.worker.min.mjs');

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('  ok    ' + label); return; }
  failed++;
  console.log('  FAIL  ' + label + (detail ? '\n        ' + detail : ''));
};

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});
const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });

await page.route('**/*', async route => {
  const url = route.request().url();
  if (url.startsWith('file://')) return route.continue();
  if (url.includes('react-dom')) return route.fulfill({ contentType: 'application/javascript', body: REACT_DOM });
  if (/\/react@|\/react\./.test(url)) return route.fulfill({ contentType: 'application/javascript', body: REACT });
  if (url.includes('fonts.g'))    return route.fulfill({ contentType: 'text/css', body: '' });
  if (url.includes('jspdf'))      return route.fulfill({ contentType: 'application/javascript', body: 'window.jspdf={jsPDF:function(){}};' });
  if (url.includes('pdf.worker')) return route.fulfill({ contentType: 'text/javascript', body: PDFWORKER });
  if (url.includes('pdfjs-dist')) return route.fulfill({ contentType: 'text/javascript', body: PDFJS });
  return route.fulfill({ status: 204, body: '' });
});

const pageErrors = [];
page.on('pageerror', e => pageErrors.push(String(e && e.message || e)));

await page.goto('file://' + PAGE, { waitUntil: 'load' });
await page.waitForFunction(() => /\$[\d,]+\.\d{2}/.test(document.body.innerText), null, { timeout: 25000 });

// Both controls live inside accordion sections and are absent from the DOM
// while their section is shut: Pages under "Booklet", Round Cornering under
// "Finishing & Addons". Sections start in mixed states and the calculator has a
// solo mode where opening one shuts the rest, so toggle toward the control we
// want and stop the moment it exists rather than clicking blind.
// The corner select is identified by "No Round Cornering", the one option the
// cap can never remove — keying off "All 4" would make the locator itself
// disappear above the cap and turn a real failure into a lookup error.
const findSel = which => page.evaluate(key => {
  const preds = {
    corner: t => t.some(x => /No Round Cornering/i.test(x)),
    pages:  t => t.length > 3 && t.every(x => /^\d+\s+Pages$/i.test(x)),
  };
  return Array.from(document.querySelectorAll('select'))
    .findIndex(s => preds[key](Array.from(s.options).map(o => o.textContent.trim())));
}, which);

const clickHeader = async pattern => {
  await page.evaluate(p => {
    const rx = new RegExp(p, 'i');
    const hit = Array.from(document.querySelectorAll('div,button'))
      .filter(e => e.childElementCount === 0 && rx.test(e.textContent || ''));
    if (!hit.length) throw new Error('section header not found: ' + p);
    let el = hit[0];
    for (let i = 0; i < 6 && el; i++) { el.click(); el = el.parentElement; }
  }, pattern);
  await page.waitForTimeout(450);
};
const ensure = async (which, header) => {
  for (let attempt = 0; attempt < 3; attempt++) {
    if (await findSel(which) >= 0) return await findSel(which);
    await clickHeader(header);
  }
  return await findSel(which);
};

const idx = {
  corner: await ensure('corner', 'Finishing\\s*&\\s*Addons'),
  pages:  await ensure('pages',  '^Booklet$'),
};
ok('found the Round Cornering select', idx.corner >= 0);
ok('found the Pages select',           idx.pages  >= 0);
if (idx.corner < 0 || idx.pages < 0) { await browser.close(); process.exit(1); }

// Opening one section can shut another, so re-resolve both indices after every
// interaction instead of trusting the pair captured above.
const sel = async which => {
  const i = await ensure(which, which === 'corner' ? 'Finishing\\s*&\\s*Addons' : '^Booklet$');
  if (i < 0) throw new Error('lost the ' + which + ' select');
  return i;
};

const setSel = async (index, value) => {
  await page.evaluate(({ index, value }) => {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
    const el = document.querySelectorAll('select')[index];
    setter.call(el, value);
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }, { index, value });
  await page.waitForTimeout(500);
};

const setPages  = async v => setSel(await sel('pages'),  String(v));
const setCorner = async v => setSel(await sel('corner'), String(v));

const cornerState = async () => page.evaluate(index => {
  const el = document.querySelectorAll('select')[index];
  return { value: el.value, options: Array.from(el.options).map(o => ({ v: o.value, t: o.textContent.trim() })) };
}, await sel('corner'));

// The grand total, read from the panel the customer actually looks at.
const total = () => page.evaluate(() => {
  const m = document.body.innerText.match(/\$[\d,]+\.\d{2}/g) || [];
  return m.map(s => Number(s.replace(/[$,]/g, ''))).filter(n => n > 0).sort((a, b) => b - a)[0] || 0;
});

const isAll4    = t => /all\s*4/i.test(t);
const isOutside = t => /outside\s*2/i.test(t);

// ── at or below the cap: All 4 is on offer and it costs money ────────────────
console.log('\n── 24 pages (at the cap): All 4 is available ──');
await setPages(24);
let st = await cornerState();
const all4 = st.options.filter(o => isAll4(o.t));
ok('both All 4 options offered at 24pp', all4.length === 2,
   'saw: ' + st.options.map(o => o.t).join(' / '));
ok('Outside 2 options offered at 24pp',  st.options.filter(o => isOutside(o.t)).length === 2);

await setCorner(st.options[0].v);
const base24 = await total();
await setCorner(all4[all4.length - 1].v);   // the ⅜" All 4
const with24 = await total();
ok('All 4 still prices as a real surcharge at 24pp', with24 > base24,
   `$${base24.toFixed(2)} -> $${with24.toFixed(2)}`);

// ── crossing the cap with All 4 selected: it must clear AND stop charging ────
console.log('\n── 28 pages (past the cap): All 4 withdraws itself ──');
await setPages(28);
st = await cornerState();
ok('no All 4 option remains at 28pp', st.options.filter(o => isAll4(o.t)).length === 0,
   'saw: ' + st.options.map(o => o.t).join(' / '));
ok('exactly the three survivors remain', st.options.length === 3,
   'saw ' + st.options.length + ': ' + st.options.map(o => o.t).join(' / '));
ok('Outside 2 survives the cap (negative control)',
   st.options.filter(o => isOutside(o.t)).length === 2);
ok('the stale All 4 selection cleared itself', st.value === st.options[0].v,
   'select still reads ' + st.value);

const cleared28 = await total();
await setCorner(st.options[0].v);
const base28 = await total();
ok('no cornering charge survives the page bump', Math.abs(cleared28 - base28) < 0.01,
   `$${cleared28.toFixed(2)} vs an explicit no-cornering $${base28.toFixed(2)}`);

// ── coming back under the cap restores the option ────────────────────────────
console.log('\n── back to 24 pages: the option returns ──');
await setPages(24);
st = await cornerState();
ok('All 4 is offered again below the cap', st.options.filter(o => isAll4(o.t)).length === 2);
ok('it comes back deselected, not silently re-applied', !isAll4(
   (st.options.find(o => o.v === st.value) || { t: '' }).t));

// ── Outside 2 is unaffected above the cap ───────────────────────────────────
console.log('\n── Outside 2 keeps working above the cap ──');
await setPages(64);
st = await cornerState();
const out2 = st.options.filter(o => isOutside(o.t));
ok('Outside 2 offered at 64pp', out2.length === 2);
await setCorner(st.options[0].v);
const base64 = await total();
await setCorner(out2[out2.length - 1].v);
const outs64 = await total();
ok('Outside 2 still prices at 64pp', outs64 > base64,
   `$${base64.toFixed(2)} -> $${outs64.toFixed(2)}`);


// ═══════════════════════════════════════════════════════════════════════════
// The case the checks above cannot reach: production does not use the CORNERS
// baked into the HTML. It replaces them wholesale from wp_options, and the
// cap shipped on 2026-09-06 keyed off the label "All 4" — so on a site whose
// labels had been reworded, every option survived the cap and the change did
// nothing. Everything above still passed, because it ran against the file's
// own fallback list. These scenarios inject a config override the way PHP
// does, which is the only way to catch that class of failure.
// ═══════════════════════════════════════════════════════════════════════════

const scenario = async (title, corners, expect, pcf) => {
  console.log('\n── ' + title + ' ──');
  const p2 = await browser.newPage({ viewport: { width: 1500, height: 1100 } });
  p2.on('pageerror', e => pageErrors.push(String(e && e.message || e)));
  await p2.route('**/*', async route => {
    const url = route.request().url();
    if (url.startsWith('file://')) return route.continue();
    if (url.includes('react-dom')) return route.fulfill({ contentType: 'application/javascript', body: REACT_DOM });
    if (/\/react@|\/react\./.test(url)) return route.fulfill({ contentType: 'application/javascript', body: REACT });
    if (url.includes('fonts.g'))    return route.fulfill({ contentType: 'text/css', body: '' });
    if (url.includes('jspdf'))      return route.fulfill({ contentType: 'application/javascript', body: 'window.jspdf={jsPDF:function(){}};' });
    if (url.includes('pdf.worker')) return route.fulfill({ contentType: 'text/javascript', body: PDFWORKER });
    if (url.includes('pdfjs-dist')) return route.fulfill({ contentType: 'text/javascript', body: PDFJS });
    return route.fulfill({ status: 204, body: '' });
  });
  // Mirrors how the plugin injects config: set before any page script runs.
  await p2.addInitScript(({ c, p }) => {
    const calc = { corners: c };
    if (p) calc.pcf = p;
    window.PPS_CONFIG = Object.assign({}, window.PPS_CONFIG, { calc });
  }, { c: corners, p: pcf || null });
  await p2.goto('file://' + PAGE, { waitUntil: 'load' });
  await p2.waitForFunction(() => /\$[\d,]+\.\d{2}/.test(document.body.innerText), null, { timeout: 25000 });

  const open = async pattern => {
    await p2.evaluate(pt => {
      const rx = new RegExp(pt, 'i');
      const hit = Array.from(document.querySelectorAll('div,button'))
        .filter(e => e.childElementCount === 0 && rx.test(e.textContent || ''));
      let el = hit[0];
      for (let i = 0; i < 6 && el; i++) { el.click(); el = el.parentElement; }
    }, pattern);
    await p2.waitForTimeout(450);
  };
  const injected = corners.map(c => c.label);
  const locate = key => p2.evaluate(({ k, injected }) => {
    const preds = {
      corner: t => t.length >= 1 && t.every(x => injected.includes(x)),
      pages:  t => t.length > 3 && t.every(x => /^\d+\s+Pages$/i.test(x)),
    };
    return Array.from(document.querySelectorAll('select'))
      .findIndex(s => preds[k](Array.from(s.options).map(o => o.textContent.trim())));
  }, { k: key, injected });
  const need = async (key, header) => {
    for (let i = 0; i < 3; i++) { if (await locate(key) >= 0) break; await open(header); }
    return locate(key);
  };
  const setP = async v => {
    const i = await need('pages', '^Booklet$');
    await p2.evaluate(({ i, v }) => {
      const set = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
      const el = document.querySelectorAll('select')[i];
      set.call(el, String(v));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }, { i, v });
    await p2.waitForTimeout(500);
  };
  const labels = async () => {
    const i = await need('corner', 'Finishing\\s*&\\s*Addons');
    if (i < 0) return [];
    return p2.evaluate(i => Array.from(document.querySelectorAll('select')[i].options)
      .map(o => o.textContent.trim()), i);
  };

  await setP(24);
  const at24 = await labels();
  await setP(28);
  const at28 = await labels();
  await p2.close();

  ok(title + ': offered at 24pp', at24.length === corners.length,
     'saw ' + at24.length + ' of ' + corners.length + ': ' + at24.join(' / '));
  ok(title + ': withdrawn at 28pp', at28.length === corners.length - expect.hidden,
     'expected ' + (corners.length - expect.hidden) + ', saw ' + at28.length + ': ' + at28.join(' / '));
  for (const keep of expect.keeps) {
    ok(title + ': kept "' + keep + '"', at28.some(t => t === keep),
       'survivors: ' + at28.join(' / '));
  }
};

// Vals intact, wording changed — the exact shape that defeated the first fix.
await scenario('relabelled, vals intact', [
  { label: 'No Round Cornering',            val: 0,   price: 0 },
  { label: '1/4" Round Corners — Outside 2', val: 216, price: 0.2 },
  { label: '3/8" Round Corners — Outside 2', val: 215, price: 0.15 },
  { label: '1/4" 4 Round Corners',           val: 108, price: 0.1 },
  { label: '3/8" 4 Round Corners',           val: 107, price: 0.075 },
], { hidden: 2, keeps: ['1/4" Round Corners — Outside 2', '3/8" Round Corners — Outside 2'] });

// The false-positive guard: an Outside-2 row worded so a naive /4.*corner/
// would catch it. Vals are intact, so wording must be ignored entirely.
await scenario('Outside 2 worded like a 4-corner option', [
  { label: 'No Round Cornering',              val: 0,   price: 0 },
  { label: '1/4 Round Corners — Outside 2',   val: 216, price: 0.2 },
  { label: 'Four Corner Look — Outside 2',    val: 215, price: 0.15 },
  { label: 'All 4',                           val: 108, price: 0.1 },
], { hidden: 1, keeps: ['1/4 Round Corners — Outside 2', 'Four Corner Look — Outside 2'] });

// Vals repointed as well: nothing authoritative left, so fall back to wording
// rather than failing open.
await scenario('vals repointed, wording is all that is left', [
  { label: 'No Round Cornering',       val: 0,  price: 0 },
  { label: 'Round — Outside 2',        val: 91, price: 0.2 },
  { label: 'Round — All Four Corners', val: 92, price: 0.1 },
], { hidden: 1, keeps: ['Round — Outside 2'] });

// The code list is human-edited text. A trailing comma parses to Number("") === 0,
// and 0 is "No Round Cornering" — the first version of the parser would have
// hidden the none option and left All 4 on offer. Every one of these must behave
// exactly like the clean list.
const STOCK = [
  { label: 'No Round Cornering',      val: 0,   price: 0 },
  { label: '1/4" Round — Outside 2',  val: 216, price: 0.2 },
  { label: '3/8" Round — Outside 2',  val: 215, price: 0.15 },
  { label: '1/4" Round — All 4',      val: 108, price: 0.1 },
  { label: '3/8" Round — All 4',      val: 107, price: 0.075 },
];
const STOCK_KEEP = { hidden: 2, keeps: ['No Round Cornering', '1/4" Round — Outside 2', '3/8" Round — Outside 2'] };
await scenario('code list with a trailing comma',   STOCK, STOCK_KEEP, { rc_all4_vals: '107,108,' });
await scenario('code list with spaces',             STOCK, STOCK_KEEP, { rc_all4_vals: ' 107 , 108 ' });
await scenario('code list emptied (label fallback)', STOCK, STOCK_KEEP, { rc_all4_vals: '' });

ok('no uncaught page errors', pageErrors.length === 0, pageErrors.join(' | '));

console.log(`\n${checks} checks, ${failed} failed`);
await browser.close();
process.exit(failed ? 1 : 0);
