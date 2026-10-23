// The page thumbnails, in both layouts under comparison (owner, 2026-10-23).
//
// "Page thumbnails beneath the viewer need to be a horizontally scrollable
// strip, not multirow, occluding the viewing window." Until then the strip
// under the stage wrapped, capped at 228px: on a long booklet two and a half
// rows of index took that height from the page being proofed. Pinned here:
//   - it is ONE row at every page count, one cell tall, and scrolls sideways;
//   - the stage is as tall at 64 pages as at 8 — the index no longer grows
//     into the view;
//   - a mouse wheel scrolls it, and lets go at either end;
//   - the selected page is brought into view when the selection moves, and a
//     re-render that does not move it leaves the strip where it was scrolled.
//
// "Alternative layout (make draft): embed the thumbnails in the page preflight
// sidebar headers, so the page 1 label appears with the page 1 thumbnail."
// The draft is switched from the yellow strip, or with ?thumbs=rail:
//   - the bottom strip is gone and the stage gets its height back;
//   - every page heading carries that page's own cell, with the upload chip,
//     the drop target and the "no file yet" dashing the strip cells have;
//   - clicking the cell selects the page and opens its section; the chip
//     opens the picker for that page without selecting it; a file dropped on
//     the heading lands on that page;
//   - a selection made elsewhere (the 3D view) scrolls its heading into view;
//   - 3D mode keeps its wrapping strip in the rail; a hosted job keeps the
//     layout the URL asked for; a phone does not scroll sideways.
//
// Needs the harness: node tools-proof-serve.mjs &
// Run: node tools-proof-thumbs-test.mjs
// PPS_PROOF_PATH points it at another build (how it was run against the
// proofer before this change, where the strip checks and every draft check fail).

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
const stripShape = page => page.evaluate(() => {
  const s = document.getElementById('stripBottom');
  const cells = [...s.querySelectorAll('.pg')];
  const tops = new Set(cells.map(c => Math.round(c.getBoundingClientRect().top)));
  const r = s.getBoundingClientRect();
  return { cells: cells.length, rows: tops.size, height: Math.round(r.height),
           cellH: cells[0] ? Math.round(cells[0].getBoundingClientRect().height) : 0,
           sideways: s.scrollWidth > s.clientWidth + 1, downways: s.scrollHeight > s.clientHeight + 1,
           stage: Math.round(document.querySelector('.stagewrap').getBoundingClientRect().height),
           sheet: Math.round(document.getElementById('sheet').getBoundingClientRect().height),
           display: getComputedStyle(s).display };
});
// is the cell for page n inside the strip's visible box?
const cellVisible = (page, n) => page.evaluate(n => {
  const s = document.getElementById('stripBottom');
  const c = [...s.querySelectorAll('.pg')].find(e => new RegExp('^P' + n + '(\\D|$)').test(e.querySelector('.n').textContent));
  if (!c) return false;
  const a = s.getBoundingClientRect(), b = c.getBoundingClientRect();
  return b.left >= a.left - 1 && b.right <= a.right + 1;
}, n);

// ═══ the default: one row under the stage ═══════════════════════════════
console.log('\n── under the page: one row that scrolls sideways ──');
{
  const { page, ctx } = await open('');
  await setPages(page, 8);
  const at8 = await stripShape(page);
  ok('the strip is shown by default', at8.display !== 'none' && at8.cells === 8, JSON.stringify(at8));
  await setPages(page, 64);
  const at64 = await stripShape(page);
  ok('64 pages: every cell on one row', at64.cells === 64 && at64.rows === 1, at64.rows + ' rows of ' + at64.cells);
  ok('64 pages: the strip is one cell tall', at64.height <= at64.cellH + 30, at64.height + 'px for a ' + at64.cellH + 'px cell');
  ok('64 pages: it scrolls sideways, not down', at64.sideways && !at64.downways, JSON.stringify(at64));
  // within a scrollbar's height: 8 pages fit without one, 64 need it. The
  // wrapping strip cost 130px here.
  ok('the stage is as tall at 64 pages as at 8 — the index no longer grows into the view',
     Math.abs(at64.stage - at8.stage) <= 20 && Math.abs(at64.sheet - at8.sheet) <= 20,
     'stage ' + at8.stage + ' → ' + at64.stage + 'px, sheet ' + at8.sheet + ' → ' + at64.sheet + 'px');

  // a vertical wheel scrolls it sideways, and lets go at the ends
  const wheel = await page.evaluate(() => {
    const s = document.getElementById('stripBottom');
    s.scrollLeft = 0;
    const send = dy => { const e = new WheelEvent('wheel', { deltaY: dy, bubbles: true, cancelable: true });
                         s.dispatchEvent(e); return e.defaultPrevented; };
    const tookIt = send(120), after = s.scrollLeft;
    const backAtStart = (s.scrollLeft = 0, send(-120));
    s.scrollLeft = s.scrollWidth;
    const pastEnd = send(120);
    return { tookIt, after, backAtStart, pastEnd };
  });
  ok('a mouse wheel over the strip scrolls it sideways', wheel.tookIt && wheel.after > 0, JSON.stringify(wheel));
  ok('and lets go at either end, so it cannot trap the page', !wheel.backAtStart && !wheel.pastEnd, JSON.stringify(wheel));

  // the selection is kept in view when it moves from somewhere else
  await page.evaluate(() => { document.getElementById('stripBottom').scrollLeft = 0; });
  await page.evaluate(() => {
    const a = [...document.querySelectorAll('#rail .acc')].find(a => /Page: 50(\D|$)/.test(a.textContent));
    a.querySelector('.acchead').click();
  });
  await page.waitForTimeout(400);
  ok('choosing page 50 in the rail brings its thumbnail into view', await cellVisible(page, 50),
     await page.evaluate(() => 'scrollLeft ' + Math.round(document.getElementById('stripBottom').scrollLeft)));

  // and a re-render that does not move it leaves the strip alone
  const kept = await page.evaluate(async () => {
    const s = document.getElementById('stripBottom');
    s.scrollLeft = 600; const was = s.scrollLeft;
    document.getElementById('mag').click();              // renders; selection unchanged
    await new Promise(r => setTimeout(r, 250));
    document.getElementById('mag').click();
    await new Promise(r => setTimeout(r, 250));
    return { was, now: s.scrollLeft };
  });
  ok('a re-render that does not move the selection keeps the strip where it was scrolled',
     Math.abs(kept.now - kept.was) <= 1, JSON.stringify(kept));

  // clicking a cell far along does not throw the strip back to the start
  const far = await page.evaluate(async () => {
    const s = document.getElementById('stripBottom');
    s.scrollLeft = s.scrollWidth;
    await new Promise(r => setTimeout(r, 100));
    const cells = [...s.querySelectorAll('.pg')];
    cells[cells.length - 2].click();
    await new Promise(r => setTimeout(r, 300));
    return { left: Math.round(s.scrollLeft), sel: state.selected };
  });
  ok('clicking a thumbnail far along keeps the strip there', far.left > 0 && far.sel === 63, JSON.stringify(far));
  ok('and that page is the one shown', await cellVisible(page, 63));
  await ctx.close();
}

// ═══ the draft: thumbnails in the page headings ═════════════════════════
console.log('\n── draft: the thumbnail in each page heading (?thumbs=rail) ──');
{
  const { page: ref, ctx: refCtx } = await open('');
  await setPages(ref, 16);
  const stripStage = (await stripShape(ref)).stage;
  await refCtx.close();

  const { page, ctx } = await open('?thumbs=rail');
  await setPages(page, 16);
  const shape = await stripShape(page);
  ok('the bottom strip is gone', shape.display === 'none' && shape.cells === 0, JSON.stringify(shape));
  ok('and the stage has its height back', shape.stage > stripStage + 60, stripStage + ' → ' + shape.stage + 'px');
  ok('the yellow strip says which layout this is', await page.evaluate(() =>
     (document.getElementById('thumbsLayout') || {}).value === 'rail'));

  const heads = await page.evaluate(() => [...document.querySelectorAll('#rail .acc')].slice(1).map(a => {
    const cell = a.querySelector('.pagehead .pg.mini');
    return { title: a.querySelector('.acchead').textContent,
             chip: cell ? cell.querySelector('.pgup').title : '',
             thumb: cell ? /^url\("data:image\/png/.test(cell.querySelector('.thumb').style.backgroundImage) : false,
             cap: cell ? !!cell.querySelector('.cap') : true, dot: cell ? !!cell.querySelector('.dot') : true,
             dashed: cell ? !cell.classList.contains('hasart') : false };
  }));
  ok('every page heading carries a thumbnail', heads.length === 16 && heads.every(h => h.thumb), heads.length + ' headings');
  ok('and it is that page\'s own: page N\'s heading, page N\'s cell', heads.every((h, i) =>
     new RegExp('Page: ' + (i + 1) + '(\\D|$)').test(h.title) && new RegExp('page ' + (i + 1) + '$').test(h.chip)),
     heads.slice(0, 2).map(h => h.title + ' / ' + h.chip).join(' · '));
  ok('no caption or issue dot on it — the heading says both', heads.every(h => !h.cap && !h.dot));
  ok('a page with no file is marked as such, as in the strip', heads.every(h => h.dashed));

  // the cell selects the page and opens its section
  const picked = await page.evaluate(async () => {
    const a = [...document.querySelectorAll('#rail .acc')].find(a => /Page: 9(\D|$)/.test(a.textContent));
    const cell = a.querySelector('.pg.mini');
    if (!cell) return { sel: state.selected, open: [], cellSel: false, missing: 'no thumbnail in the heading' };
    cell.click();
    await new Promise(r => setTimeout(r, 300));
    const open = [...document.querySelectorAll('#rail .acc.open')].map(a => a.querySelector('.acchead').textContent);
    return { sel: state.selected, open, cellSel: !!document.querySelector('#rail .acc.open .pg.mini.sel') };
  });
  ok('clicking the thumbnail selects that page and opens its section, alone',
     picked.sel === 9 && picked.open.length === 1 && /Page: 9/.test(picked.open[0]) && picked.cellSel, JSON.stringify(picked));

  // the chip opens the picker for its own page, without selecting it
  const chip = await page.evaluate(async () => {
    let asked = null; const keep = window.pickFileFor;
    window.pickFileFor = n => { asked = n; };
    const a = [...document.querySelectorAll('#rail .acc')].find(a => /Page: 12(\D|$)/.test(a.textContent));
    const up = a.querySelector('.pg.mini .pgup');
    if (up) up.click();
    await new Promise(r => setTimeout(r, 100));
    window.pickFileFor = keep;
    return { asked, sel: state.selected };
  });
  ok('its upload chip asks for page 12\'s file and leaves the selection alone',
     chip.asked === 12 && chip.sel === 9, JSON.stringify(chip));

  // a file dropped on the heading lands on that page
  const dropped = await page.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 600; c.height = 900;
    const x = c.getContext('2d'); x.fillStyle = '#1d4ed8'; x.fillRect(0, 0, 600, 900);
    const blob = await new Promise(r => c.toBlob(r, 'image/png'));
    const dt = new DataTransfer(); dt.items.add(new File([blob], 'page-five.png', { type: 'image/png' }));
    const a = [...document.querySelectorAll('#rail .acc')].find(a => /Page: 5(\D|$)/.test(a.textContent));
    const head = a.querySelector('.pagehead');
    if (!head) return { missing: 'no thumbnail heading to drop on' };
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

  // a selection made in 3D brings its heading into view on the way back
  await setPages(page, 64);
  await page.evaluate(() => { document.getElementById('rail').scrollTop = 0; });
  await page.evaluate(() => [...document.querySelectorAll('#modes button')].find(b => b.dataset.mode === 'book').click());
  await page.waitForTimeout(500);
  const in3d = await page.evaluate(() => ({
    rail: document.querySelectorAll('#stripRail .pg').length,
    heads: document.querySelectorAll('#rail .pg.mini').length }));
  ok('3D keeps its wrapping strip in the rail', in3d.rail === 64, JSON.stringify(in3d));
  await page.evaluate(() => [...document.querySelectorAll('#stripRail .pg')].find(e => /^P41(\D|$)/.test(e.querySelector('.n').textContent)).click());
  await page.waitForTimeout(400);
  await page.evaluate(() => [...document.querySelectorAll('#modes button')].find(b => b.dataset.mode === 'proof').click());
  await page.waitForTimeout(500);
  const back = await page.evaluate(() => {
    const r = document.getElementById('rail');
    const cell = r.querySelector('.pg.mini.sel');
    if (!cell) return { sel: state.selected, found: false };
    const a = r.getBoundingClientRect(), b = cell.getBoundingClientRect();
    return { sel: state.selected, found: true, inView: b.top >= a.top - 1 && b.bottom <= a.bottom + 1,
             scrollTop: Math.round(r.scrollTop) };
  });
  ok('a page chosen in 3D has its heading in view back in Proof', back.found && back.inView, JSON.stringify(back));

  // switching back restores the strip
  await page.evaluate(() => {
    const s = document.getElementById('thumbsLayout');
    if (s){ s.value = 'strip'; s.dispatchEvent(new Event('change', { bubbles: true })); }
  });
  await page.waitForTimeout(500);
  const restored = await page.evaluate(() => ({
    strip: document.querySelectorAll('#stripBottom .pg').length,
    mini: document.querySelectorAll('#rail .pg.mini').length,
    attr: document.documentElement.getAttribute('data-thumbs') }));
  ok('switching back in the yellow strip restores the row under the page',
     restored.strip === 64 && restored.mini === 0 && restored.attr === 'strip', JSON.stringify(restored));
  ok('with the chosen page already in view', await cellVisible(page, (await page.evaluate(() => state.selected))));
  await ctx.close();
}

console.log('\n── draft: inside the calculator and on a phone ──');
{
  // The calculator frames the proofer by URL, so a site tries the draft by
  // setting New Proof URL to ...proof-ui-draft.html?thumbs=rail.
  const job = { calc: 'saddle', trim: { w: 5.5, h: 8.5 }, bleed: 0.125, safety: 0.125, pages: 12 };
  const { page, ctx } = await open('?thumbs=rail', null, job);
  const hosted = await page.evaluate(() => ({ hosted: MODEL.hosted, pages: MODEL.pages.length,
    mini: document.querySelectorAll('#rail .pagehead .pg.mini').length,
    strip: document.querySelectorAll('#stripBottom .pg').length,
    flagged: [...document.querySelectorAll('#rail .acc')].slice(1).filter(a => a.querySelector('.acchead .bang')).length }));
  ok('a hosted job keeps the layout its URL asked for', hosted.hosted && hosted.mini === 12 && hosted.strip === 0, JSON.stringify(hosted));
  ok('and its empty pages are still flagged in their headings', hosted.flagged === 12, hosted.flagged + ' of 12');
  await ctx.close();

  const { page: phone, ctx: pctx } = await open('?thumbs=rail', { width: 390, height: 844 });
  const ph = await phone.evaluate(() => ({ wide: document.documentElement.scrollWidth, vw: window.innerWidth,
    mini: document.querySelectorAll('#rail .pg.mini').length,
    head: (() => { const h = document.querySelector('#rail .pagehead'); return h ? Math.round(h.getBoundingClientRect().width) : 0; })() }));
  ok('a phone in the draft layout does not scroll sideways', ph.wide <= ph.vw + 1 && ph.mini > 0 && ph.head <= ph.vw, JSON.stringify(ph));
  await pctx.close();
}

ok('no uncaught page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ' + failed + ' FAILURE(S)' : '>>> PROOF THUMBS OK');
process.exit(failed ? 1 : 0);
