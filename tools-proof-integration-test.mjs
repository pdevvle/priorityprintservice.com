// Calculator ⇄ proofer, end to end.
//
// tools-proof-embed-test.mjs proves the proofer honours a host. This proves the
// calculator IS that host: that the job it sends describes the piece the
// customer is actually buying, that the approval comes back, and — the part
// most likely to rot silently — that the proofer's human-readable file names
// become the names the order pipeline and the imposition tool look for.
//
// Both documents are served from one origin by tools-proof-serve.mjs, because
// the handshake is same-origin by design. The calculator is served out of
// dist/, so this exercises the compiled artifact that actually ships.
//
//   node tools-proof-serve.mjs &
//   node tools-proof-integration-test.mjs

import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');

const HERE = path.dirname(new URL(import.meta.url).pathname);
const DEPS = process.env.PPS_DEPS_DIR || path.join(HERE, 'node_modules');
const BASE = process.env.PPS_PROOF_BASE || 'http://127.0.0.1:8137';
const CALC = BASE + '/dist/calc-preview-test.html';

const rd = p => readFileSync(path.join(DEPS, p), 'utf8');
const REACT     = rd('react/umd/react.production.min.js');
const REACT_DOM = rd('react-dom/umd/react-dom.production.min.js');
const PDFJS     = rd('pdfjs-dist/legacy/build/pdf.min.mjs');
const PDFWORKER = rd('pdfjs-dist/legacy/build/pdf.worker.min.mjs');

if (!existsSync(path.join(HERE, 'dist', 'calc-preview-test.html'))) {
  console.error('compile first: BABEL_DIR=<deps> node tools-compile-calcs.mjs');
  process.exit(2);
}

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
const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });
page.on('pageerror', e => errors.push(String(e && e.message || e)));

// The calculator's own CDN deps come from the local copies; anything served by
// the harness (the proofer, its vendored libs) must go through untouched.
await page.route('**/*', async route => {
  const url = route.request().url();
  if (url.startsWith(BASE)) return route.continue();
  if (url.includes('react-dom')) return route.fulfill({ contentType: 'application/javascript', body: REACT_DOM });
  if (/\/react@|\/react\./.test(url)) return route.fulfill({ contentType: 'application/javascript', body: REACT });
  if (url.includes('fonts.g'))    return route.fulfill({ contentType: 'text/css', body: '' });
  if (url.includes('jspdf'))      return route.fulfill({ contentType: 'application/javascript', body: 'window.jspdf={jsPDF:function(){}};' });
  if (url.includes('pdf.worker')) return route.fulfill({ contentType: 'text/javascript', body: PDFWORKER });
  if (url.includes('pdfjs-dist')) return route.fulfill({ contentType: 'text/javascript', body: PDFJS });
  return route.fulfill({ status: 204, body: '' });
});

// proofUrl is the switch that turns the new surface on for a site. The frame
// needs the local library base too, and addInitScript reaches every frame.
await page.addInitScript(() => {
  window.PPS_CONFIG = Object.assign({}, window.PPS_CONFIG, { proofUrl: '/proof-ui-draft.html' });
  window.PPS_LIB_BASE = '/vendor/';
});

await page.goto(CALC, { waitUntil: 'load' });
await page.waitForFunction(() => /\$[\d,]+\.\d{2}/.test(document.body.innerText), null, { timeout: 30000 });

// ── the naming contract, tested directly ────────────────────────────────────
// This is the seam that breaks quietly: rename a file on either side and the
// order still completes, just without the deliverable anything downstream
// looks for. The manifest matters most — it is what marks an order approved.
console.log('\n── the proofer\'s names become the order\'s names ──');
{
  const r = await page.evaluate(() => {
    const mk = n => ({ name: n, blob: new Blob(['x'], { type: 'text/plain' }) });
    const out = window.__ppsProof.mapFiles(
      [mk('PRINT_READY.pdf'), mk('PREVIEW_p01.jpg'), mk('PREVIEW_p07.jpg'),
       mk('MANIFEST.txt'), mk('something-else.bin')],
      'myjob');
    return { names: out.files.map(f => f.name), printReadyName: out.printReadyName };
  });
  ok('the print file gets the pipeline name',
     r.names.includes('myjob_print-ready.pdf'), r.names.join(', '));
  ok('and is reported so the hash can be tied to it',
     r.printReadyName === 'myjob_print-ready.pdf', String(r.printReadyName));
  ok('the manifest keeps the name the approval gate looks for',
     r.names.includes('myjob_manipulation_manifest.txt'));
  ok('previews are renumbered to three digits',
     r.names.includes('myjob_preview_page_001.jpg') && r.names.includes('myjob_preview_page_007.jpg'),
     r.names.join(', '));
  ok('nothing unrecognised is smuggled into the order',
     !r.names.some(n => /something-else/.test(n)), r.names.join(', '));
}

// ── the switch ──────────────────────────────────────────────────────────────
console.log('\n── the new surface is off unless the site is configured ──');
{
  const on = await page.evaluate(() => !!window.__ppsProof.urlFor());
  ok('proofUrl configured here, so it is on', on);
  const off = await page.evaluate(() => {
    const keep = window.PPS_CONFIG.proofUrl;
    window.PPS_CONFIG.proofUrl = '';
    const v = window.__ppsProof.urlFor();
    window.PPS_CONFIG.proofUrl = keep;
    return v;
  });
  ok('blank means off, so this ships dark', off === null, String(off));

  // A cross-origin proof URL cannot complete the handshake — the proofer only
  // answers same-origin messages — so it must fall back to the built-in proof
  // rather than opening a frame that hangs forever.
  const cross = await page.evaluate(() => {
    const keep = window.PPS_CONFIG.proofUrl;
    window.PPS_CONFIG.proofUrl = 'https://example.com/proof-ui-draft.html';
    const v = window.__ppsProof.urlFor();
    window.PPS_CONFIG.proofUrl = keep;
    return v;
  });
  ok('an off-site proof URL is refused, not framed', cross === null, String(cross));
}

// ── the round trip ──────────────────────────────────────────────────────────
console.log('\n── the calculator opens the proofer and gets an approval back ──');
{
  // Give the calculator artwork, the way a customer does.
  const uploaded = await page.evaluate(async () => {
    const c = document.createElement('canvas');
    c.width = 1650; c.height = 2550;
    const x = c.getContext('2d');
    x.fillStyle = '#e8f0ff'; x.fillRect(0, 0, c.width, c.height);
    x.fillStyle = '#111827'; x.fillRect(200, 300, 900, 120);
    const blob = await new Promise(r => c.toBlob(r, 'image/png'));
    const file = new File([blob], 'my-artwork.png', { type: 'image/png' });
    const inputs = Array.from(document.querySelectorAll('input[type=file]'));
    for (const inp of inputs) {
      const dt = new DataTransfer();
      dt.items.add(file);
      inp.files = dt.files;
      inp.dispatchEvent(new Event('change', { bubbles: true }));
    }
    return inputs.length;
  });
  ok('the calculator has a file input to upload through', uploaded > 0, String(uploaded) + ' inputs');
  await page.waitForTimeout(2500);

  // Open the proof the way the customer does.
  // Match the magnifier button specifically. "Proof" alone also matches the
  // "Artwork & Proofing" section header, which sits earlier in the document —
  // clicking that just collapses a section and looks exactly like a product
  // failure.
  const opened = await page.evaluate(() => {
    const b = Array.from(document.querySelectorAll('button'))
      .find(x => /\u{1F50D}\s*Proof/u.test(x.textContent || ''));
    if (!b) return false;
    b.click();
    return true;
  });
  ok('the Proof button is there to click', opened);

  await page.waitForSelector('.pps-proof-overlay iframe', { timeout: 15000 }).catch(() => {});
  ok('clicking Proof opens the standalone surface, not the old modal',
     await page.evaluate(() => !!document.querySelector('.pps-proof-overlay iframe')));

  const frame = await page.waitForFunction(
    () => window.frames.length > 0, null, { timeout: 15000 }
  ).then(() => page.frames().find(f => f !== page.mainFrame())).catch(() => null);
  ok('the proofer frame is reachable', !!frame);

  if (frame) {
    await frame.waitForFunction(() => typeof MODEL !== 'undefined' && MODEL.pages.length > 0,
                                null, { timeout: 20000 }).catch(() => {});
    const job = await frame.evaluate(() => ({
      calc: MODEL.calc, trim: MODEL.trim, pages: MODEL.pages.length,
      locked: document.getElementById('pageCount').disabled,
    }));
    // The calculator's defaults are 5.5x8.5 / 8pp; the point is that these came
    // across the boundary rather than being the proofer's own defaults by luck,
    // which the embed suite already pins with a deliberately different job.
    ok('the proofer is proofing THIS job', job.calc === 'saddle' && job.pages >= 4,
       JSON.stringify(job));
    ok('the trim came from the calculator', job.trim.w > 0 && job.trim.h > 0,
       job.trim.w + 'x' + job.trim.h);
    ok('the page count is locked to the order', job.locked === true);

    // Approve, and let the calculator receive it.
    await frame.evaluate(() => { document.getElementById('agree').checked = true; renderApproval(); });
    await frame.evaluate(() => document.getElementById('approveBtn').click());

    const closed = await page.waitForFunction(
      () => !document.querySelector('.pps-proof-overlay'), null, { timeout: 180000 }
    ).then(() => true).catch(() => false);
    ok('approving closes the proof and hands control back', closed);

    if (closed) {
      // Approval does not relabel the Proof button — it replaces that whole
      // region with the green approved panel, so the button legitimately
      // disappears. The panel also sits inside the "Artwork & Proofing"
      // accordion, which the calculator closes as it advances a step, so it
      // has to be reopened before innerText can see it. Asserting on
      // textContent instead would pass even if the panel were display:none,
      // which is the failure worth catching.
      await page.evaluate(() => {
        const h = Array.from(document.querySelectorAll('button'))
          .find(b => /Artwork\s*&\s*Proofing/i.test(b.textContent || ''));
        if (h) h.click();
      });
      await page.waitForTimeout(400);

      const seen = await page.evaluate(() => ({
        approvedPanel: /Artwork Approved/i.test(document.body.innerText),
        proofButtonGone: !Array.from(document.querySelectorAll('button'))
          .some(b => /\u{1F50D}\s*Proof/u.test(b.textContent || '')),
        stillRequired: /\brequired\b/i.test(
          (Array.from(document.querySelectorAll('button'))
            .find(b => /\u{1F50D}\s*Proof/u.test(b.textContent || '')) || {}).textContent || ''),
      }));
      ok('the calculator shows the art as approved', seen.approvedPanel, JSON.stringify(seen));
      ok('and stops asking for a proof', seen.proofButtonGone && !seen.stillRequired);
    }
  }
}

ok('no uncaught page errors', errors.length === 0, errors.slice(0, 4).join(' | '));

console.log('\n' + checks + ' checks, ' + failed + ' failed');
await browser.close();
console.log(failed ? '>>> INTEGRATION FAILED' : '>>> INTEGRATION OK');
process.exit(failed ? 1 : 0);
