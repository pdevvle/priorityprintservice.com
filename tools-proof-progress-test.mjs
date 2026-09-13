// Approving must say what it is doing.
//
// Approve is not a click, it is a job: a 300-DPI render of every page, a JPEG
// encode each, a PDF assembly, then a preview per page. Nearly all of it blocks
// the main thread. Until 2026-09-13 the button said PREPARING… and then the
// page stopped responding — for tens of seconds on a long booklet — which is
// indistinguishable from a crash. Reported from staging the first time a real
// customer-shaped file went through it.
//
// The fix is only half a progress readout; the other half is yielding a frame
// BEFORE each long block so the readout actually paints. Text set immediately
// ahead of a synchronous render never reaches the screen. That half is what
// this suite is really pinning: it records every value #bpStep ever held, so a
// readout that is updated but never drawn fails here exactly as a missing one
// would.
//
// Needs the harness: node tools-proof-serve.mjs &   (see that file)
//
// Run: node tools-proof-progress-test.mjs

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');

const BASE = process.env.PPS_PROOF_BASE || 'http://127.0.0.1:8137';
const PAGE = BASE + '/proof-ui-draft.html';
const PAGES = 8;

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

const JOB = { calc:'saddle', trim:{ w:5.5, h:8.5 }, bleed:0.125, safety:0.125, pages:PAGES,
              insideColor:'color', coverColor:'color' };

async function open() {
  const page = await browser.newPage({ viewport:{ width:1400, height:1000 } });
  page.on('pageerror', e => errors.push(String(e && e.message || e)));
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

// The fixture is built with the page's OWN pdf-lib, so this suite carries no
// binary and no node-side PDF dependency of its own. Plain colour pages: the
// point here is the number of them, not what is on them.
async function deliver(page, job) {
  await page.evaluate(async ({ job, n }) => {
    await ensureLibs();
    const { PDFDocument, rgb } = window.PDFLib;
    const doc = await PDFDocument.create();
    const W = 5.75 * 72, H = 8.75 * 72;
    for (let i = 0; i < n; i++) {
      const pg = doc.addPage([W, H]);
      pg.drawRectangle({ x:0, y:0, width:W, height:H,
                         color: rgb(0.1 + (i % 3) * 0.2, 0.35, 0.75) });
    }
    const bytes = await doc.save();
    const file = new File([bytes], 'progress.pdf', { type:'application/pdf' });
    window.postMessage({ type:'pps-proof:job', job, files:[file] }, window.location.origin);
  }, { job, n: PAGES });
  await page.waitForFunction((n) => uploads.size >= n, PAGES, { timeout:40000 });
}

// Record every value the readout ever holds. A MutationObserver fires between
// the blocked stretches, so it sees what a poll from outside the page would miss.
async function watch(page) {
  await page.evaluate(() => {
    window.__steps = [];
    window.__widths = [];
    window.__btn = [];
    const step = document.getElementById('bpStep');
    const fill = document.getElementById('bpFill');
    const btn  = document.getElementById('approveBtn');
    const snap = () => {
      const t = step.textContent.trim();
      if (t && window.__steps[window.__steps.length - 1] !== t) window.__steps.push(t);
      const w = fill.style.width;
      if (w && window.__widths[window.__widths.length - 1] !== w) window.__widths.push(w);
      const b = btn.textContent.trim();
      if (b && window.__btn[window.__btn.length - 1] !== b) window.__btn.push(b);
    };
    new MutationObserver(snap).observe(step, { childList:true, characterData:true, subtree:true });
    new MutationObserver(snap).observe(btn,  { childList:true, characterData:true, subtree:true });
    new MutationObserver(snap).observe(document.getElementById('bpProg'), { attributes:true });
  });
}

async function unlockAndApprove(page) {
  await page.evaluate(() => {
    const agree = document.getElementById('agree');
    if (agree && !agree.checked) { agree.checked = true; agree.dispatchEvent(new Event('change')); }
    const ack = document.getElementById('ackIssues');
    if (ack && !document.getElementById('ackBox').hidden && !ack.checked) {
      ack.checked = true; ack.dispatchEvent(new Event('change'));
    }
  });
  const disabled = await page.evaluate(() => document.getElementById('approveBtn').disabled);
  ok('the approve button is reachable once the gates are met', !disabled);
  if (disabled) return false;
  await page.click('#approveBtn');
  return true;
}

// ── 1. a normal approval reports itself ─────────────────────────────────────
console.log('\n── what the customer sees while approving ──');
{
  const page = await open();
  await deliver(page, JOB);
  await watch(page);

  const visibleDuring = [];
  // Sample the bar's visibility from outside too: hidden the whole way through
  // would mean the readout exists but nobody ever sees it.
  const poll = setInterval(async () => {
    try { visibleDuring.push(await page.evaluate(() => !document.getElementById('bpProg').hidden)); }
    catch (e) { /* page closing */ }
  }, 120);

  if (await unlockAndApprove(page)) {
    await page.waitForFunction(() => window.__posts.some(m => m.type === 'pps-proof:approved'
                                                          || m.type === 'pps-proof:approve-failed'),
                               null, { timeout:180000 });
  }
  clearInterval(poll);

  const got = await page.evaluate(() => ({
    steps: window.__steps, widths: window.__widths, btn: window.__btn,
    approved: window.__posts.some(m => m.type === 'pps-proof:approved'),
    failed: (window.__posts.find(m => m.type === 'pps-proof:approve-failed') || {}).message || '',
    hiddenAfter: document.getElementById('bpProg').hidden,
    label: document.getElementById('approveBtn').textContent.trim(),
  }));

  ok('the approval completed', got.approved, got.failed);
  ok('the readout was drawn, not merely set',
     got.steps.length >= 5, got.steps.length + ' distinct values: ' + got.steps.slice(0, 3).join(' | '));
  ok('it counts the pages it is rendering',
     got.steps.some(s => /Rendering page \d+ of 8/.test(s)),
     got.steps.find(s => /Rendering/.test(s)) || '(none)');
  ok('and names the later stages too',
     got.steps.some(s => /Assembling/i.test(s)) && got.steps.some(s => /Preview \d+ of 8/.test(s))
     && got.steps.some(s => /manifest/i.test(s)));
  ok('the bar advances rather than sitting still',
     got.widths.length >= 5 && got.widths[got.widths.length - 1] === '100%',
     got.widths.join(' '));
  const pcts = got.widths.map(w => parseInt(w, 10));
  ok('and never goes backwards', pcts.every((v, i) => i === 0 || v >= pcts[i - 1]), got.widths.join(' '));
  ok('the button carries the percentage as well',
     got.btn.some(b => /PREPARING… \d+%/.test(b)), got.btn.slice(0, 3).join(' | '));
  ok('the bar was actually on screen while it ran', visibleDuring.some(Boolean),
     visibleDuring.length + ' samples');
  ok('and is put away once approved', got.hiddenAfter && /APPROVED/.test(got.label), got.label);
  await page.close();
}

// ── 2. a failure says where it stopped ──────────────────────────────────────
console.log('\n── when it breaks part way ──');
{
  const page = await open();
  await deliver(page, JOB);
  // Break the third page only: a failure at the very first step would not prove
  // the step is reported, because the first step is also the default state.
  await page.evaluate(() => {
    const real = window.renderPrintPage || renderPrintPage;
    let seen = 0;
    window.renderPrintPage = renderPrintPage = (n) => {
      if (++seen === 3) throw new Error('synthetic render failure');
      return real(n);
    };
  });
  if (await unlockAndApprove(page)) {
    await page.waitForFunction(() => window.__posts.some(m => m.type === 'pps-proof:approve-failed'),
                               null, { timeout:120000 });
  }
  const got = await page.evaluate(() => {
    const m = window.__posts.find(x => x.type === 'pps-proof:approve-failed') || {};
    return { step: m.step || '', message: m.message || '',
             shown: (document.querySelector('#pkgOut .pkgerr') || {}).textContent || '',
             hidden: document.getElementById('bpProg').hidden,
             label: document.getElementById('approveBtn').textContent.trim() };
  });
  ok('the host is told which step failed', /Rendering page 3 of 8/.test(got.step), got.step);
  ok('and the customer is shown it too', /stopped at: Rendering page 3 of 8/.test(got.shown), got.shown);
  ok('the readout is cleared rather than left frozen at 25%', got.hidden);
  ok('and the button goes back to being pressable', /APPROVE/.test(got.label) && !/%/.test(got.label), got.label);
  await page.close();
}

ok('no page errors anywhere', errors.length === 0, errors.join(' | '));

await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> PROOF PROGRESS FAILED' : '>>> PROOF PROGRESS OK');
process.exit(failed ? 1 : 0);
