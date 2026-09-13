// "Built to the ordered size" must not cry wolf on a correct print file.
//
// A print file WITH bleed is trim + 2 × bleed, and most design tools never
// write a TrimBox — so the page size is all there is to measure. The check
// measured it against the trim alone and warned, which meant every correctly
// prepared file was called wrong. That matters more than a confusing sentence:
// this check feeds the acknowledgment gate, so warning on every good file
// teaches people to tick past the one gate standing between artwork and a
// press. Found on staging 2026-09-13 on a file built exactly to spec.
//
// The second half of the bug is why it was not obvious: pdf-lib answers
// getTrimBox() with the MediaBox when the file has no TrimBox, so `pg.trim` is
// never null and the check's own "carries no TrimBox" branch could never run.
// A declared trim is one that DIFFERS from the page.
//
// The allowance is deliberately narrow — it applies only where the page is
// standing in for a missing TrimBox. A file that declares a TrimBox has said
// where the cut goes, and that is what has to match.
//
// Needs the harness: node tools-proof-serve.mjs &   (see that file)
//
// Run: node tools-proof-size-check-test.mjs

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');

const BASE = process.env.PPS_PROOF_BASE || 'http://127.0.0.1:8137';
const PAGE = BASE + '/proof-ui-draft.html';

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '\n       ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});
const errors = [];

// Ordered 5.5 x 8.5 with an eighth of bleed, so the page of a correct file is
// 5.75 x 8.75.
const JOB = { calc:'saddle', trim:{ w:5.5, h:8.5 }, bleed:0.125, safety:0.125, pages:8,
              insideColor:'color', coverColor:'color' };

// Fixtures are built with the page's OWN pdf-lib: no binaries in the repo, and
// the boxes are written by the same library that reads them back.
async function sizeCheck(spec) {
  const page = await browser.newPage({ viewport:{ width:1400, height:900 } });
  page.on('pageerror', e => errors.push(String(e && e.message || e)));
  page.on('dialog', async d => { await d.dismiss(); });
  await page.route('**/*', route => {
    const u = route.request().url();
    if (u.startsWith(BASE) || u.startsWith('data:') || u.startsWith('blob:')) return route.continue();
    return route.fulfill({ status: 204, body: '' });
  });
  await page.addInitScript(() => {
    window.__posts = [];
    window.PPS_PROOF_HOST = m => window.__posts.push(m);
    window.PPS_LIB_BASE = '/vendor/';
  });
  await page.goto(PAGE, { waitUntil:'domcontentloaded' });
  await page.waitForFunction(() => window.__posts.some(m => m.type === 'pps-proof:ready'), null, { timeout:20000 });

  await page.evaluate(async ({ job, spec }) => {
    await ensureLibs();
    const { PDFDocument, rgb } = window.PDFLib;
    const doc = await PDFDocument.create();
    const W = spec.w * 72, H = spec.h * 72;
    for (let i = 0; i < 8; i++) {
      const pg = doc.addPage([W, H]);
      pg.drawRectangle({ x:0, y:0, width:W, height:H, color: rgb(0.2, 0.4, 0.8) });
      if (spec.trim) {
        pg.setTrimBox((spec.w - spec.trim[0]) / 2 * 72, (spec.h - spec.trim[1]) / 2 * 72,
                      spec.trim[0] * 72, spec.trim[1] * 72);
      }
    }
    const bytes = await doc.save();
    window.postMessage({ type:'pps-proof:job', job,
                         files:[new File([bytes], 'art.pdf', { type:'application/pdf' })] },
                       window.location.origin);
  }, { job: JOB, spec });

  await page.waitForFunction(() => uploads.size > 0, null, { timeout:40000 });
  const res = await page.evaluate(() => runFileChecks().find(c => c.id === 'size'));
  await page.close();
  return res;
}

const cases = [
  // label,                                                        page,            TrimBox,      expected
  ['trim + bleed with no TrimBox — the normal, correct print file', { w:5.75, h:8.75 },              null,  'pass'],
  ['trimmed to size with no bleed',                                 { w:5.5,  h:8.5  },              null,  'pass'],
  ['the ordered size sideways',                                     { w:8.5,  h:5.5  },              null,  'pass'],
  ['a page that is neither the trim nor the trim plus bleed',       { w:8.25, h:8.25 },              null,  'warn'],
  ['a TrimBox that says exactly what was ordered',                  { w:5.75, h:8.75, trim:[5.5,8.5] }, undefined, 'pass'],
  ['a TrimBox that says something else — the file decides the cut',  { w:5.75, h:8.75, trim:[5.0,7.0] }, undefined, 'warn'],
];

console.log('\n── ordered 5.5" × 8.5", bleed 0.125" ──');
for (const [label, spec, _t, want] of cases) {
  const r = await sizeCheck(spec);
  ok(label + '  →  ' + want.toUpperCase(), r.state === want,
     r.state.toUpperCase() + ': ' + r.text.slice(0, 130));
}

// The bleed allowance must not be a blanket tolerance: it is only for the case
// where the page stands in for a missing TrimBox.
{
  const r = await sizeCheck({ w:5.75, h:8.75 });
  ok('and the pass says WHY it passed, so nobody reads it as an exact match',
     /plus 0\.125" bleed/.test(r.text), r.text.slice(0, 130));
}
{
  const r = await sizeCheck({ w:5.5, h:8.5 });
  ok('the no-TrimBox note can actually fire now (it never could before)',
     /carry no TrimBox/.test(r.text), r.text.slice(0, 130));
}

ok('no page errors anywhere', errors.length === 0, errors.join(' | '));

await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> SIZE CHECK FAILED' : '>>> SIZE CHECK OK');
process.exit(failed ? 1 : 0);
