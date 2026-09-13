// A page nobody uploaded must not be filled with our demo booklet.
//
// The proofer draws placeholder art — a fake Capoeira batizado programme, bars
// of fake body copy — on any page with no upload. Standalone that is the only
// way to exercise the shell. On a real job it is somebody else's artwork
// sitting on a customer's pages, and it does not stop at the screen:
// buildPackage renders EVERY page into PRINT_READY.pdf, the approval hash binds
// to those bytes, and the imposition tool would take it. Reported from staging
// on 2026-09-13, where an 8pp booklet with one page uploaded showed seven pages
// of the demo.
//
// A hosted job now gets white for an unsupplied page, one error-level finding
// per page saying so, and a BLANK PAGES section in the manifest. Standalone
// keeps the placeholder, because without it there is nothing to look at.
//
// Needs the harness: node tools-proof-serve.mjs &   (see that file)
//
// Run: node tools-proof-blank-pages-test.mjs

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

// Art on two pages only — the shape the report came in as.
async function deliver(page, slots) {
  await page.evaluate(async ({ job, slots }) => {
    const mk = async (name) => {
      const c = document.createElement('canvas');
      c.width = 1725; c.height = 2625;
      const x = c.getContext('2d');
      x.fillStyle = '#c9401b'; x.fillRect(0, 0, c.width, c.height);
      const blob = await new Promise(r => c.toBlob(r, 'image/png'));
      return new File([blob], name, { type:'image/png' });
    };
    const out = [];
    for (const n of slots) out.push({ page:n, file: await mk('page_' + n + '.png') });
    window.postMessage({ type:'pps-proof:job', job, files:[], slots:out }, window.location.origin);
  }, { job: JOB, slots });
  await page.waitForFunction((n) => uploads.size >= n, slots.length, { timeout:30000 });
}

// Is this page's source actually blank, or is there something drawn on it?
const sample = (page, n) => page.evaluate((n) => {
  const s = SRCget(n);
  const c = document.createElement('canvas');
  c.width = 40; c.height = 60;
  const x = c.getContext('2d');
  x.drawImage(s.el, 0, 0, c.width, c.height);
  const d = x.getImageData(0, 0, c.width, c.height).data;
  let min = 255, max = 0;
  for (let i = 0; i < d.length; i += 4) {
    for (let k = 0; k < 3; k++) { if (d[i+k] < min) min = d[i+k]; if (d[i+k] > max) max = d[i+k]; }
  }
  return { label: s.label, min, max, textBoxes: (s.text || []).length };
}, n);

// ── 1. a real job ───────────────────────────────────────────────────────────
console.log('\n── an order with art on two of eight pages ──');
{
  const page = await open();
  await deliver(page, [1, 5]);

  const blank = await sample(page, 3);
  ok('an unsupplied page is labelled as unsupplied, not as a placeholder',
     blank.label === 'no artwork supplied', JSON.stringify(blank));
  ok('and is genuinely blank — nothing of ours is drawn on it',
     blank.min === 255 && blank.max === 255, 'pixel range ' + blank.min + '-' + blank.max);
  ok('so it carries no type for the margin check to find', blank.textBoxes === 0);

  const supplied = await sample(page, 5);
  ok('a page that WAS supplied still shows its own art',
     supplied.label !== 'no artwork supplied' && supplied.max > 0, JSON.stringify(supplied));

  const flags = await page.evaluate(() => {
    const f = jobFlags();
    return { errors:f.errors, pages:f.pages,
             noart:f.all.filter(i => i.kind === 'noart').map(i => i.n),
             text:(f.all.find(i => i.kind === 'noart') || {}).text || '' };
  });
  ok('every empty page is raised as a problem',
     flags.noart.join(',') === '2,3,4,6,7,8', flags.noart.join(','));
  ok('and it is stated as one finding per page, not five consequences of one',
     flags.errors === 6, flags.errors + ' errors');
  ok('the wording says what will happen', /print blank/.test(flags.text), flags.text);

  const gated = await page.evaluate(() => {
    const agree = document.getElementById('agree');
    agree.checked = true; agree.dispatchEvent(new Event('change'));
    const before = document.getElementById('approveBtn').disabled;
    const ack = document.getElementById('ackIssues');
    ack.checked = true; ack.dispatchEvent(new Event('change'));
    return { before, after: document.getElementById('approveBtn').disabled,
             ackShown: !document.getElementById('ackBox').hidden };
  });
  ok('approving a book with holes in it needs the deliberate second click',
     gated.ackShown && gated.before === true && gated.after === false, JSON.stringify(gated));

  await page.click('#approveBtn');
  await page.waitForFunction(() => window.__posts.some(m => m.type === 'pps-proof:approved'
                                                        || m.type === 'pps-proof:approve-failed'),
                             null, { timeout:180000 });
  const man = await page.evaluate(async () => {
    const m = window.__posts.find(x => x.type === 'pps-proof:approved');
    if (!m) return null;
    const f = m.files.find(x => /MANIFEST/.test(x.name));
    return f ? await f.blob.text() : null;
  });
  ok('the approval still completes', !!man);
  ok('and the manifest names the blank pages outright',
     man && /BLANK PAGES/.test(man) && /pages 2, 3, 4, 6, 7, 8 — no artwork was supplied/.test(man),
     man ? (man.split('\n').find(l => /no artwork was supplied/.test(l)) || '(no line)') : '');
  ok('the acknowledgment is on the record too',
     man && /acknowledged yes/.test(man));
  await page.close();
}

// ── 2. standalone still demonstrates itself ─────────────────────────────────
console.log('\n── the prototype with no host ──');
{
  const page = await open();
  const s = await sample(page, 3);
  ok('with no job posted the placeholder booklet is still drawn',
     s.label === 'placeholder' && s.min < 255, JSON.stringify(s));
  ok('and it still carries type for the margin check to measure', s.textBoxes > 0);
  await page.close();
}

ok('no page errors anywhere', errors.length === 0, errors.join(' | '));

await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> BLANK PAGES FAILED' : '>>> BLANK PAGES OK');
process.exit(failed ? 1 : 0);
