// Proof UI — the host seam.
//
// The other three suites prove the proofer works. This one proves it can be
// driven by something else: that a host can hand it a job it did not hardcode,
// that the job actually reaches the printed output rather than only the screen,
// that approval hands back bytes plus the hash those bytes actually have, and
// that the ways this can fail all fail safely.
//
// The transport is deliberately not the subject. post() abstracts it, so these
// drive the direct-mount path (PPS_PROOF_HOST) for the logic and then repeat
// the important half over a real same-origin iframe, which is what the
// calculator will use. If those two ever disagree the seam is wrong.
//
// Needs the harness: node tools-proof-serve.mjs &   (see that file)

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

// Everything this suite cares about is served from the harness. Web fonts and
// the like only add latency and, in a sandbox, proxy noise that reads like a
// failure — so anything off-origin is answered empty rather than attempted.
const localOnly = async page => {
  await page.route('**/*', route => {
    const u = route.request().url();
    if (u.startsWith(BASE) || u.startsWith('data:') || u.startsWith('blob:')) return route.continue();
    return route.fulfill({ status: 204, body: '' });
  });
};

const errors = [];

// A job deliberately unlike the demo (5.5x8.5, 8pp) in every field that
// matters, so anything still reading the default is visible as a wrong number
// rather than hiding behind a coincidence.
const JOB = { calc:'saddle', trim:{ w:8.5, h:11 }, bleed:0.25, safety:0.25, pages:12 };

async function open(job, opts = {}) {
  const page = await browser.newPage({ viewport:{ width:1500, height:1100 } });
  page.on('pageerror', e => errors.push(String(e && e.message || e)));
  await localOnly(page);
  await page.addInitScript(({ job, useGlobal }) => {
    window.__posts = [];
    window.PPS_PROOF_HOST = m => window.__posts.push(m);
    window.PPS_LIB_BASE = '/vendor/';
    if (useGlobal && job) window.PPS_PROOF_JOB = job;
  }, { job, useGlobal: opts.useGlobal !== false });
  await page.goto(PAGE, { waitUntil:'domcontentloaded' });
  await page.waitForFunction(() => window.__posts && window.__posts.some(m => m.type === 'pps-proof:ready'),
                             null, { timeout:20000 });
  return page;
}

const posts = page => page.evaluate(() => window.__posts.map(m => ({
  type: m.type, hash: m.hash, pageCount: m.pageCount, errors: m.errors,
  attempts: m.attempts, calc: m.calc, trim: m.trim,
  files: (m.files || []).map(f => ({ name:f.name, size:f.blob && f.blob.size })),
})));

// ── the job reaches the shell ───────────────────────────────────────────────
console.log('\n── a host-supplied job replaces the built-in one ──');
{
  const page = await open(JOB);
  const got = await page.evaluate(() => ({
    trim: MODEL.trim, bleed: MODEL.bleed, safety: MODEL.safety,
    pages: MODEL.pages.length, bleedW: BLEED_W, bleedH: BLEED_H,
    groups: MODEL.groups.length,
    countDisabled: document.getElementById('pageCount').disabled,
  }));
  ok('trim comes from the job', got.trim.w === 8.5 && got.trim.h === 11, JSON.stringify(got.trim));
  ok('bleed and safety come from the job', got.bleed === 0.25 && got.safety === 0.25);
  ok('page count comes from the job', got.pages === 12, String(got.pages));
  // 8.5 + 0.25*2 = 9.0 — a stale BLEED_W would still read 5.75 from the demo.
  ok('the derived bleed size followed the trim', got.bleedW === 9 && got.bleedH === 11.5,
     got.bleedW + ' x ' + got.bleedH);
  ok('spreads were rebuilt for 12 pages', got.groups === 7, String(got.groups) + ' groups');
  ok('the page count cannot be changed against the order', got.countDisabled === true);
  await page.close();
}

// ── a job that is not printable is refused, not rendered ────────────────────
console.log('\n── an undescribable job is refused before anything is drawn ──');
for (const [why, bad, expect] of [
  ['zero width',        { calc:'saddle', trim:{ w:0, h:11 }, pages:8 },  /width/i],
  ['negative height',   { calc:'saddle', trim:{ w:8.5, h:-1 }, pages:8 }, /height/i],
  ['odd page count',    { calc:'saddle', trim:{ w:8.5, h:11 }, pages:7 }, /even|multiple/i],
  ['not a multiple of 4', { calc:'saddle', trim:{ w:8.5, h:11 }, pages:10 }, /multiple of 4/i],
  ['too few pages',     { calc:'saddle', trim:{ w:8.5, h:11 }, pages:2 },  /at least 4/i],
]) {
  const page = await open(bad);
  const p = await posts(page);
  const err = p.find(m => m.type === 'pps-proof:error');
  ok('refused: ' + why, !!err && expect.test((err.errors || []).join(' ')),
     err ? (err.errors || []).join(' / ') : 'no error posted');
  ok('refused: ' + why + ' — nothing approved', !p.some(m => m.type === 'pps-proof:approved'));
  const shown = await page.evaluate(() => !!document.getElementById('jobRefusal'));
  ok('refused: ' + why + ' — the customer is told', shown);
  await page.close();
}

// ── approval hands back real bytes and a hash of those bytes ────────────────
console.log('\n── approving returns the package to the host ──');
{
  const page = await open(JOB);
  await page.evaluate(async () => {
    // A real raster page, so composition has something to bake.
    const c = document.createElement('canvas');
    c.width = 850; c.height = 1100;
    const x = c.getContext('2d');
    x.fillStyle = '#cfe3ff'; x.fillRect(0, 0, c.width, c.height);
    x.fillStyle = '#0f172a'; x.fillRect(80, 80, 300, 60);
    const blob = await new Promise(r => c.toBlob(r, 'image/png'));
    await loadArtSequence(1, [new File([blob], 'art.png', { type:'image/png' })]);
  });
  await page.evaluate(() => { document.getElementById('agree').checked = true; renderApproval(); });
  await page.click('#approveBtn');
  await page.waitForFunction(
    () => window.__posts.some(m => m.type === 'pps-proof:approved' || m.type === 'pps-proof:approve-failed'),
    null, { timeout:120000 });

  const p = await posts(page);
  const app = p.find(m => m.type === 'pps-proof:approved');
  ok('the host is told the art was approved', !!app,
     app ? '' : JSON.stringify(p.find(m => m.type === 'pps-proof:approve-failed')));
  if (app) {
    ok('a SHA-256 comes back with it', /^[0-9a-f]{64}$/.test(app.hash || ''), app.hash);
    ok('the job travels with the approval', app.calc === 'saddle' && app.trim.w === 8.5);
    ok('one print file', (app.files || []).filter(f => f.name === 'PRINT_READY.pdf').length === 1);
    ok('a manifest', (app.files || []).some(f => f.name === 'MANIFEST.txt'));
    ok('a preview per page of THIS job',
       (app.files || []).filter(f => /^PREVIEW_p\d+\.jpg$/.test(f.name)).length === 12,
       String((app.files || []).filter(f => /^PREVIEW_p/.test(f.name)).length));
    ok('the print file is not empty',
       ((app.files || []).find(f => f.name === 'PRINT_READY.pdf') || {}).size > 1000);
    ok('approval reports this job\'s page count', app.pageCount === 12, String(app.pageCount));
  }

  // The hash must be of the bytes the host was handed — not of anything else.
  const hashMatches = await page.evaluate(async () => {
    const m = window.__posts.find(x => x.type === 'pps-proof:approved');
    const f = m.files.find(x => x.name === 'PRINT_READY.pdf');
    const buf = await f.blob.arrayBuffer();
    const d = await crypto.subtle.digest('SHA-256', buf);
    return Array.from(new Uint8Array(d)).map(b => b.toString(16).padStart(2, '0')).join('') === m.hash;
  });
  ok('the hash is of the bytes the host received', hashMatches);

  // The whole point of passing a job: the printed page must be the job's size.
  // 8.5 + 0.25*2 = 9in = 648pt, 11 + 0.5 = 11.5in = 828pt.
  const size = await page.evaluate(async () => {
    const m = window.__posts.find(x => x.type === 'pps-proof:approved');
    const f = m.files.find(x => x.name === 'PRINT_READY.pdf');
    const doc = await window.PDFLib.PDFDocument.load(await f.blob.arrayBuffer());
    const p0 = doc.getPage(0);
    return { n: doc.getPageCount(), w: Math.round(p0.getWidth()), h: Math.round(p0.getHeight()) };
  });
  ok('the print file is the job\'s bleed size, not the demo\'s',
     size.w === 648 && size.h === 828, size.w + 'x' + size.h + 'pt');
  ok('the print file has this job\'s page count', size.n === 12, String(size.n));
  await page.close();
}

// ── failure is survivable ───────────────────────────────────────────────────
console.log('\n── approval that cannot succeed still leaves a way out ──');
{
  const page = await open(JOB);
  // Break package building the way a bad file would, without needing one.
  await page.evaluate(() => {
    window.buildPackage = async () => { throw new Error('synthetic failure'); };
    document.getElementById('agree').checked = true; renderApproval();
  });
  for (let i = 0; i < 2; i++) {
    await page.click('#approveBtn');
    await page.waitForTimeout(400);
  }
  const p = await posts(page);
  const fails = p.filter(m => m.type === 'pps-proof:approve-failed');
  ok('the host hears about each failure', fails.length === 2, String(fails.length));
  ok('nothing is reported as approved', !p.some(m => m.type === 'pps-proof:approved'));
  ok('the escape hatch appears after the second failure',
     await page.evaluate(() => !!document.getElementById('proofEscapeBtn')));
  ok('the button is still usable, not stuck disabled',
     await page.evaluate(() => !document.getElementById('approveBtn').disabled));

  await page.click('#proofEscapeBtn');
  await page.waitForTimeout(200);
  const esc = (await posts(page)).find(m => m.type === 'pps-proof:escape');
  ok('taking the escape hatch tells the host, with the attempt count',
     !!esc && esc.attempts === 2);
  await page.close();
}

// ── close ───────────────────────────────────────────────────────────────────
console.log('\n── closing is reported rather than alerted ──');
{
  const page = await open(JOB);
  await page.click('#closeBtn');
  await page.waitForTimeout(150);
  ok('the host is told to close', (await posts(page)).some(m => m.type === 'pps-proof:close'));
  await page.close();
}

// ── the same seam over a real iframe ────────────────────────────────────────
console.log('\n── the same handshake across a same-origin iframe ──');
{
  const page = await browser.newPage({ viewport:{ width:1500, height:1100 } });
  page.on('pageerror', e => errors.push('host: ' + String(e && e.message || e)));
  await localOnly(page);
  // addInitScript reaches every frame, so the framed proofer gets the local
  // library base before it runs — setting it on contentWindow after load is
  // already too late.
  await page.addInitScript(() => { window.PPS_LIB_BASE = '/vendor/'; });
  await page.goto(BASE + '/proof-ui-draft.html', { waitUntil:'domcontentloaded' });

  // The host is a same-origin document that frames the proofer, the way the
  // calculator's modal will. Reusing the proofer's own URL for the shell keeps
  // the origins identical without the harness needing a second page.
  await page.evaluate(async ({ job }) => {
    document.body.replaceChildren();
    window.__posts = [];
    window.addEventListener('message', e => {
      if (e.origin !== window.location.origin) return;
      window.__posts.push(e.data);
    });
    const f = document.createElement('iframe');
    f.id = 'proofFrame';
    f.style.cssText = 'width:1400px;height:900px;border:0';
    f.src = '/proof-ui-draft.html';
    document.body.appendChild(f);
    await new Promise(r => f.addEventListener('load', r, { once:true }));
    f.contentWindow.postMessage({ type:'pps-proof:job', job }, window.location.origin);
  }, { job: JOB });

  await page.waitForFunction(() => window.__posts.some(m => m.type === 'pps-proof:ready'),
                             null, { timeout:20000 });

  // MODEL is a top-level `const`, which is a lexical binding and never becomes
  // a property of the frame's window — so it has to be read from inside the
  // frame's own scope, not off contentWindow.
  const frame = page.frames().find(fr => fr !== page.mainFrame());
  ok('the frame is there to talk to', !!frame);
  await frame.waitForFunction(() => typeof MODEL !== 'undefined' && MODEL.pages.length === 12,
                              null, { timeout:10000 }).catch(() => {});
  const framed = await frame.evaluate(() => ({
    pages: MODEL.pages.length, trimW: MODEL.trim.w, bleedW: BLEED_W,
    countDisabled: document.getElementById('pageCount').disabled,
  }));
  ok('the framed proofer adopted the posted job',
     framed.pages === 12 && framed.trimW === 8.5 && framed.bleedW === 9,
     JSON.stringify(framed));
  ok('and locked the page count to it', framed.countDisabled === true);

  await frame.evaluate(() => document.getElementById('closeBtn').click());
  await page.waitForTimeout(200);
  ok('messages cross the frame boundary to the host',
     await page.evaluate(() => window.__posts.some(m => m.type === 'pps-proof:close')));
  await page.close();
}

ok('no uncaught page errors', errors.length === 0, errors.slice(0, 4).join(' | '));

console.log('\n' + checks + ' checks, ' + failed + ' failed');
await browser.close();
console.log(failed ? '>>> EMBED FAILED' : '>>> EMBED OK');
process.exit(failed ? 1 : 0);
