// Per-page uploads have to reach the proof the customer approves.
//
// The saddle calculator lets someone build a booklet page by page — drop a file
// on page 5, another on page 2 — and keeps those in slotFilesRef, separate from
// the whole-file upload in rawFilesRef. The host handed the proofer rawFilesRef
// and nothing else, so a customer who worked that way was shown a proof of art
// they had not supplied, or of nothing at all, and approved it (2026-09-13).
//
// The job message now carries `slots: [{ page, file }]`. This suite pins the
// three things that have to be true of them:
//   - a slots-only job puts art on exactly those pages (the empty-proof case);
//   - slots are applied AFTER the whole-file sequence, so where both cover a
//     page the customer's per-page choice is the one they see;
//   - a slot outside the page range is ignored rather than throwing, because a
//     page count that changed under the customer must not break the proof.
//
// Needs the harness: node tools-proof-serve.mjs &   (see that file)
//
// Run: node tools-proof-slots-test.mjs

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');

const BASE = process.env.PPS_PROOF_BASE || 'http://127.0.0.1:8137';
const PAGE = BASE + '/proof-ui-draft.html';

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});
const errors = [];

const JOB = { calc:'saddle', trim:{ w:5.5, h:8.5 }, bleed:0.125, safety:0.125, pages:8,
              insideColor:'color', coverColor:'color' };

async function open() {
  const page = await browser.newPage({ viewport:{ width:1400, height:1000 } });
  page.on('pageerror', e => errors.push(String(e && e.message || e)));
  // Off-origin requests only add latency and proxy noise in a sandbox.
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
  return page;
}

// Solid-colour PNGs, one colour per file, so which file landed on which page is
// readable off the pixels and not merely off a filename.
const COLOURS = { whole:'#1b6ac9', slotA:'#c9401b', slotB:'#00a05a' };
async function deliver(page, job, spec) {
  await page.evaluate(async ({ job, spec, COLOURS }) => {
    const mk = async (name, colour) => {
      const c = document.createElement('canvas');
      c.width = 660; c.height = 1020;
      const x = c.getContext('2d');
      x.fillStyle = colour; x.fillRect(0, 0, c.width, c.height);
      const blob = await new Promise(r => c.toBlob(r, 'image/png'));
      return new File([blob], name, { type:'image/png' });
    };
    const files = [];
    for (const n of (spec.files || [])) files.push(await mk(n, COLOURS.whole));
    const slots = [];
    for (const s of (spec.slots || [])) {
      slots.push({ page: s.page, file: await mk(s.name, COLOURS[s.colour]) });
    }
    window.postMessage({ type:'pps-proof:job', job, files, slots }, window.location.origin);
  }, { job, spec, COLOURS });
}

const centre = (page, n) => page.evaluate((n) => {
  const u = uploads.get(n);
  if (!u) return null;
  const c = document.createElement('canvas');
  c.width = 8; c.height = 8;
  const x = c.getContext('2d');
  x.drawImage(u.el, 0, 0, 8, 8);
  const d = x.getImageData(3, 3, 1, 1).data;
  return { label: u.label, rgb: '#' + [d[0], d[1], d[2]].map(v => v.toString(16).padStart(2, '0')).join('') };
}, n);

// ── 1. slots only: the empty-proof case ─────────────────────────────────────
console.log('\n── a customer who uploaded pages one at a time ──');
{
  const page = await open();
  await deliver(page, JOB, { files: [], slots: [ { page:5, name:'page_005_back.png', colour:'slotA' },
                                                 { page:2, name:'page_002_intro.png', colour:'slotB' } ] });
  await page.waitForFunction(() => uploads.has(5) && uploads.has(2), null, { timeout:20000 });
  const p5 = await centre(page, 5), p2 = await centre(page, 2);
  ok('page 5 carries the file dropped on page 5', p5 && p5.label === 'page_005_back.png' && p5.rgb === COLOURS.slotA, JSON.stringify(p5));
  ok('page 2 carries the file dropped on page 2', p2 && p2.label === 'page_002_intro.png' && p2.rgb === COLOURS.slotB, JSON.stringify(p2));
  ok('and no other page was given art it was not sent',
     await page.evaluate(() => [...uploads.keys()].sort((a, b) => a - b).join(',')) === '2,5');
  ok('the proofer reported no error', !(await page.evaluate(() => window.__posts.some(m => m.type === 'pps-proof:error'))));
  await page.close();
}

// ── 2. both shapes: the slot wins where they overlap ────────────────────────
console.log('\n── a whole file plus per-page replacements ──');
{
  const page = await open();
  await deliver(page, JOB, { files: ['booklet.png'],
                             slots: [ { page:1, name:'page_001_cover.png', colour:'slotA' } ] });
  await page.waitForFunction(() => uploads.has(1) && uploads.get(1).label === 'page_001_cover.png',
                             null, { timeout:20000 });
  const p1 = await centre(page, 1);
  ok('the page-1 replacement is what the customer sees, not the whole-file art',
     p1 && p1.label === 'page_001_cover.png' && p1.rgb === COLOURS.slotA, JSON.stringify(p1));
  await page.close();
}

// ── 3. a slot beyond the book ───────────────────────────────────────────────
console.log('\n── a slot outside the page count ──');
{
  const page = await open();
  await deliver(page, JOB, { files: ['booklet.png'],
                             slots: [ { page:99, name:'page_099_stray.png', colour:'slotB' },
                                      { page:3,  name:'page_003_ok.png',    colour:'slotA' } ] });
  await page.waitForFunction(() => uploads.has(3), null, { timeout:20000 });
  ok('the out-of-range slot is ignored', !(await page.evaluate(() => uploads.has(99))));
  ok('and the valid one beside it still lands',
     (await centre(page, 3)).label === 'page_003_ok.png');
  ok('no error was posted and nothing threw', !(await page.evaluate(() => window.__posts.some(m => m.type === 'pps-proof:error'))));
  await page.close();
}

ok('no page errors anywhere', errors.length === 0, errors.join(' | '));

await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> PROOF SLOTS FAILED' : '>>> PROOF SLOTS OK');
process.exit(failed ? 1 : 0);
