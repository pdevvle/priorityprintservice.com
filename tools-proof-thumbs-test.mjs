// The page thumbnails: in the page headings (owner's pick, 2026-10-25).
//
// History, because the suite carried both for two days. On 2026-10-23 the
// thumbnails under the stage became one sideways row (they had wrapped, up to
// 228px, into the view), and a draft put each page's thumbnail in its heading
// in the Artwork Edit rail instead, switchable for comparison. The owner chose
// the headings ("I want the page thumbnails as they were in the draft
// alternative"), so the row, the switch and ?thumbs= went. Pinned here:
//   - nothing under the stage: it has the column's whole height, at any page
//     count, and no switch or URL can bring the row back;
//   - every page heading carries that page's own cell — upload chip, drop
//     target, dashed when it has no file — without the caption or issue dot,
//     which the heading already says;
//   - the cell selects its page and opens its section alone; the chip asks for
//     that page's file without selecting it; a file dropped on the heading
//     lands on that page;
//   - opening the proof leaves the rail at the top, Preflight in view;
//   - 3D keeps its wrapping strip; a page chosen there has its heading in view
//     back in Proof, and so does the page that was open before going to 3D;
//   - a hosted job gets the same headings, its empty pages flagged; a phone
//     does not scroll sideways.
//
// Needs the harness: node tools-proof-serve.mjs &
// Run: node tools-proof-thumbs-test.mjs
// PPS_PROOF_PATH points it at another build. Against 9f433ab (the row as the
// default, the headings behind ?thumbs=) 16 of the 21 checks fail; without the
// line that makes the rail forget its place in 3D, the second 3D check fails.

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');
const BASE = process.env.PPS_PROOF_BASE || 'http://127.0.0.1:8137';
const PAGE = BASE + (process.env.PPS_PROOF_PATH || '/proof-ui-draft.html');

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

const browser = await chromium.launch({
  executablePath: process.env.PPS_CHROME || '/opt/pw-browsers/chromium',
  args: ['--no-sandbox'],
});
const errors = [];

async function open(query, viewport, job){
  const ctx = await browser.newContext({ viewport: viewport || { width: 1440, height: 860 } });
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(String(e && e.message || e)));
  page.on('dialog', d => d.dismiss());
  await page.addInitScript(job => {
    window.PPS_LIB_BASE = '/vendor/';
    if (job) window.PPS_PROOF_JOB = job;
  }, job || null);
  await page.goto(PAGE + (query || ''), { waitUntil: 'load' });
  await page.waitForFunction(() => typeof MODEL !== 'undefined'
    && document.querySelectorAll('#rail .acc').length > 1, null, { timeout: 20000 });
  await page.waitForTimeout(400);
  return { page, ctx };
}
async function setPages(page, n){
  await page.selectOption('#pageCount', String(n));
  await page.waitForFunction(n => document.querySelectorAll('#rail .acc').length === n + 1, n, { timeout: 20000 });
  await page.waitForTimeout(500);
}
// a failing evaluate on another build reports, rather than ends the run
const ev = (page, fn, arg) => page.evaluate(fn, arg).catch(e => ({ error: String(e && e.message || e).slice(0, 140) }));
const layout = page => ev(page, () => {
  const m = document.querySelector('.main').getBoundingClientRect();
  const w = document.querySelector('.stagewrap').getBoundingClientRect();
  return { row: !!document.getElementById('stripBottom') && getComputedStyle(document.getElementById('stripBottom')).display !== 'none'
                && document.querySelectorAll('#stripBottom .pg').length > 0,
           switcher: !!document.getElementById('thumbsLayout'),
           main: Math.round(m.height), stage: Math.round(w.height),
           sheet: Math.round(document.getElementById('sheet').getBoundingClientRect().height),
           cells: document.querySelectorAll('#rail .pagehead .pg.mini').length };
});
// ═══ the layout ══════════════════════════════════════════════════════════
console.log('\n── thumbnails in the page headings, nothing under the stage ──');
{
  const { page, ctx } = await open('');
  await setPages(page, 8);
  const at8 = await layout(page);
  ok('no row of thumbnails under the stage', at8.row === false, JSON.stringify(at8));
  ok('the stage has its column\'s whole height', at8.stage >= at8.main - 40, at8.stage + ' of ' + at8.main + 'px');
  ok('every page heading carries a thumbnail', at8.cells === 8, at8.cells + ' of 8');
  ok('there is no layout switch left in the yellow strip', at8.switcher === false);
  await setPages(page, 64);
  const at64 = await layout(page);
  ok('64 pages: still nothing under the stage, still a thumbnail in every heading',
     at64.row === false && at64.cells === 64, JSON.stringify(at64));
  ok('and the page is as tall as at 8', Math.abs(at64.sheet - at8.sheet) <= 2, at8.sheet + ' → ' + at64.sheet + 'px');
  await ctx.close();

  const { page: p2, ctx: c2 } = await open('?thumbs=strip');
  const old = await layout(p2);
  ok('an old ?thumbs= link changes nothing', old.row === false && old.cells === 8, JSON.stringify(old));
  await c2.close();
}

console.log('\n── each heading\'s thumbnail is that page\'s own ──');
{
  const { page, ctx } = await open('');
  await setPages(page, 16);
  ok('opening the proof leaves the rail at the top, Preflight in view', await ev(page, () =>
     document.getElementById('rail').scrollTop === 0) === true);
  const heads = await ev(page, () => [...document.querySelectorAll('#rail .acc')].slice(1).map(a => {
    const cell = a.querySelector('.pagehead .pg.mini');
    return { title: a.querySelector('.acchead').textContent,
             chip: cell ? cell.querySelector('.pgup').title : '',
             thumb: cell ? /^url\("data:image\/png/.test(cell.querySelector('.thumb').style.backgroundImage) : false,
             cap: cell ? !!cell.querySelector('.cap') : true, dot: cell ? !!cell.querySelector('.dot') : true,
             dashed: cell ? !cell.classList.contains('hasart') : false };
  }));
  const H = Array.isArray(heads) ? heads : [];
  ok('page N\'s heading, page N\'s thumbnail', H.length === 16 && H.every((h, i) => h.thumb
     && new RegExp('Page: ' + (i + 1) + '(\\D|$)').test(h.title) && new RegExp('page ' + (i + 1) + '$').test(h.chip)),
     H.slice(0, 2).map(h => h.title + ' / ' + h.chip).join(' · ') || JSON.stringify(heads));
  ok('no caption or issue dot on it — the heading says both', H.length > 0 && H.every(h => !h.cap && !h.dot));
  ok('a page with no file is marked as such', H.length > 0 && H.every(h => h.dashed));

  const picked = await ev(page, async () => {
    const a = [...document.querySelectorAll('#rail .acc')].find(a => /Page: 9(\D|$)/.test(a.textContent));
    a.querySelector('.pg.mini').click();
    await new Promise(r => setTimeout(r, 300));
    const open = [...document.querySelectorAll('#rail .acc.open')].map(a => a.querySelector('.acchead').textContent);
    return { sel: state.selected, open, cellSel: !!document.querySelector('#rail .acc.open .pg.mini.sel') };
  });
  ok('clicking the thumbnail selects that page and opens its section, alone',
     picked.sel === 9 && (picked.open || []).length === 1 && /Page: 9/.test(picked.open[0]) && picked.cellSel, JSON.stringify(picked));

  const chip = await ev(page, async () => {
    let asked = null; const keep = window.pickFileFor;
    window.pickFileFor = n => { asked = n; };
    const a = [...document.querySelectorAll('#rail .acc')].find(a => /Page: 12(\D|$)/.test(a.textContent));
    a.querySelector('.pg.mini .pgup').click();
    await new Promise(r => setTimeout(r, 100));
    window.pickFileFor = keep;
    return { asked, sel: state.selected };
  });
  ok('its upload chip asks for page 12\'s file and leaves the selection alone',
     chip.asked === 12 && chip.sel === 9, JSON.stringify(chip));

  const dropped = await ev(page, async () => {
    const c = document.createElement('canvas'); c.width = 600; c.height = 900;
    const x = c.getContext('2d'); x.fillStyle = '#1d4ed8'; x.fillRect(0, 0, 600, 900);
    const blob = await new Promise(r => c.toBlob(r, 'image/png'));
    const dt = new DataTransfer(); dt.items.add(new File([blob], 'page-five.png', { type: 'image/png' }));
    const a = [...document.querySelectorAll('#rail .acc')].find(a => /Page: 5(\D|$)/.test(a.textContent));
    const head = a.querySelector('.pagehead');
    head.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }));
    const lit = head.classList.contains('dropping');
    head.querySelector('.acchead').dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
    for (let i = 0; i < 60 && !uploads.has(5); i++) await new Promise(r => setTimeout(r, 100));
    await new Promise(r => setTimeout(r, 300));
    const a2 = [...document.querySelectorAll('#rail .acc')].find(a => /Page: 5(\D|$)/.test(a.textContent));
    return { lit, has5: uploads.has(5), others: [...uploads.keys()].filter(k => k !== 5).length,
             marked: a2.querySelector('.pg.mini').classList.contains('hasart') };
  });
  ok('a file dropped on page 5\'s heading lands on page 5, and nowhere else',
     dropped.lit && dropped.has5 && dropped.others === 0 && dropped.marked, JSON.stringify(dropped));
  await ctx.close();
}

console.log('\n── the 3D view, and back ──');
{
  const { page, ctx } = await open('');
  await setPages(page, 64);
  const go = m => ev(page, m => [...document.querySelectorAll('#modes button')].find(b => b.dataset.mode === m).click(), m);
  const inView = () => ev(page, () => {
    const r = document.getElementById('rail'), cell = r.querySelector('.pg.mini.sel');
    if (!cell) return { sel: state.selected, found: false };
    const a = r.getBoundingClientRect(), b = cell.getBoundingClientRect();
    return { sel: state.selected, found: true, inView: b.top >= a.top - 1 && b.bottom <= a.bottom + 1 };
  });
  await go('book'); await page.waitForTimeout(500);
  const in3d = await ev(page, () => ({ strip: document.querySelectorAll('#stripRail .pg').length,
                                       groups: document.querySelectorAll('#stripRail .grp').length }));
  ok('3D keeps its wrapping strip in the rail, by spread', in3d.strip === 64 && in3d.groups === 33, JSON.stringify(in3d));
  await ev(page, () => [...document.querySelectorAll('#stripRail .pg')].find(e => /^P41(\D|$)/.test(e.querySelector('.n').textContent)).click());
  await page.waitForTimeout(400);
  await go('proof'); await page.waitForTimeout(500);
  const back = await inView();
  ok('a page chosen in 3D has its heading in view back in Proof', back.found && back.inView, JSON.stringify(back));
  // the open page, scrolled away from, through 3D and back unchanged
  await ev(page, () => { document.getElementById('rail').scrollTop = 0; });
  await go('book'); await page.waitForTimeout(400);
  await go('proof'); await page.waitForTimeout(500);
  const again = await inView();
  ok('so does the page that was open before going to 3D', again.found && again.inView && again.sel === back.sel, JSON.stringify(again));
  await ctx.close();
}

console.log('\n── inside the calculator, and on a phone ──');
{
  const job = { calc: 'saddle', trim: { w: 5.5, h: 8.5 }, bleed: 0.125, safety: 0.125, pages: 12 };
  const { page, ctx } = await open('', null, job);
  const hosted = await ev(page, () => ({ hosted: MODEL.hosted, pages: MODEL.pages.length,
    cells: document.querySelectorAll('#rail .pagehead .pg.mini').length,
    flagged: [...document.querySelectorAll('#rail .acc')].slice(1).filter(a => a.querySelector('.acchead .bang')).length }));
  ok('a hosted job gets a thumbnail in every heading', hosted.hosted && hosted.cells === 12, JSON.stringify(hosted));
  ok('and its empty pages are flagged in their headings', hosted.flagged === 12, hosted.flagged + ' of 12');
  await ctx.close();

  const { page: phone, ctx: pctx } = await open('', { width: 390, height: 844 });
  const ph = await ev(phone, () => ({ wide: document.documentElement.scrollWidth, vw: window.innerWidth,
    cells: document.querySelectorAll('#rail .pagehead .pg.mini').length,
    head: (() => { const h = document.querySelector('#rail .pagehead'); return h ? Math.round(h.getBoundingClientRect().width) : 0; })() }));
  ok('a phone does not scroll sideways, and every heading has its thumbnail',
     ph.wide <= ph.vw + 1 && ph.cells === 8 && ph.head <= ph.vw, JSON.stringify(ph));
  await pctx.close();
}

ok('no uncaught page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ' + failed + ' FAILURE(S)' : '>>> PROOF THUMBS OK');
process.exit(failed ? 1 : 0);
