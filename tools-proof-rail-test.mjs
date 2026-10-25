// The Artwork Edit rail: the condensed Preflight list, and the "!" flags that
// explain themselves (owner, 2026-10-24).
//
// "The preflight in the sidebar is crowded. Perhaps condense that info and
// make it collapsable." Pinned here:
//   - one line per check — a state dot and the check's name — with its
//     explanation folded until clicked, and still open after a re-render;
//   - the "What approving produces" box folded behind its own heading.
// "Organize file-wide preflight info by failed, then by passed. Use the terms
// passed and not passed." Then: "a new category of preflight: unverified. This
// will include the items that can't be checked on certain filetypes." Pinned:
//   - Not passed first (fail, then warn), then Unverified (what this file type
//     cannot answer), then Passed; the last two folded; the chip names "N not
//     passed" and "N unverified", or says "all passed"; the old state words are
//     gone; the reason Unverified shares is said once under its heading, seen
//     while folded, and a row keeps only what it adds.
// "The red ! icons should display tooltips or content when hovered." Pinned:
//   - hovering a page heading's flag, or a thumbnail's, shows that page's
//     findings — the same text the issues column shows — and leaving hides it;
//   - a tap on a touch screen shows it without opening the page under it, and
//     the next tap elsewhere closes it; keyboard focus on the heading shows it;
//   - it follows its flag while the rail scrolls, and goes when the flag does;
//   - the findings are in the heading's accessible text for a screen reader.
// And because the box that folded is where a failed approval writes, a failure
// (and, after two, the escape hatch) must still be seen: shown with a page's
// section open, and kept across a re-render. Before this change both were
// written into the panel once, so a page open hid them and any re-render
// wiped them.
//
// Needs the harness: node tools-proof-serve.mjs &
// Run: node tools-proof-rail-test.mjs
// Last, the standalone link: ?size=WxH&pages=N sizes the booklet when no host
// sent a job, a nonsense value is ignored, and a host's job is never resized.
//
// PPS_PROOF_PATH points it at another build. Against the first version of this
// work (f26e53b: two groups, no size link) 19 of the 46 checks fail; against
// the proofer before any of it, 25 of the 28 it could reach did.

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
async function open(query, opts){
  const ctx = await browser.newContext(Object.assign({ viewport: { width: 1440, height: 860 } }, opts || {}));
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(String(e && e.message || e)));
  page.on('dialog', d => d.dismiss());
  await page.addInitScript(() => { window.PPS_LIB_BASE = '/vendor/'; });
  await page.goto(PAGE + (query || ''), { waitUntil: 'load' });
  await page.waitForFunction(() => typeof MODEL !== 'undefined'
    && document.querySelectorAll('#rail .acc').length > 1, null, { timeout: 20000 });
  await page.waitForTimeout(400);
  return { page, ctx };
}
// pages 3 and 5 letterboxed, so they carry a finding and a flag
const flagPages = page => page.evaluate(async () => {
  state.perPage[3].behavior = 'fit'; state.perPage[5].behavior = 'fit';
  cache.clear(); renderAll();
  await new Promise(r => setTimeout(r, 600));
});
const ev = (page, fn, arg) => page.evaluate(fn, arg).catch(e => ({ error: String(e && e.message || e).slice(0, 140) }));
const tip = page => page.evaluate(() => {
  const t = document.getElementById('flagTip');
  if (!t) return { on: false, missing: true };
  const r = t.getBoundingClientRect();
  return { on: t.classList.contains('on') && getComputedStyle(t).visibility === 'visible',
           head: (t.querySelector('b') || {}).textContent || '',
           items: [...t.querySelectorAll('li')].map(li => li.textContent.replace(/^!/, '').trim()),
           top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right) };
});

// ═══ the Preflight list ══════════════════════════════════════════════════
console.log('\n── Preflight: not passed, then unverified, then passed ──');
{
  const { page, ctx } = await open('');
  // No file: one check answers (page count); five cannot run without a PDF.
  const real = await ev(page, () => {
    const wf = document.querySelector('#rail .acc');
    const groups = [...wf.querySelectorAll('.chk-group')].map(g => ({
      cls: g.className, head: g.querySelector('.chk-grouphead').textContent.replace('⌄', '').trim(),
      note: (g.querySelector('.chk-groupnote') || {}).textContent || '',
      noteShown: !!g.querySelector('.chk-groupnote') && g.querySelector('.chk-groupnote').offsetHeight > 0,
      rows: [...g.querySelectorAll('.chk-row')].map(r => ({
        label: r.querySelector('.chk-label').textContent, state: r.className.split(' ')[1],
        visible: r.offsetHeight > 0, control: !!r.querySelector('button.chk-sum'),
        text: (r.querySelector('.chk-text') || {}).textContent || '',
        textShown: !!r.querySelector('.chk-text') && !r.querySelector('.chk-text').hidden })) }));
    return { chip: wf.querySelector('.acchead .chip').textContent, groups,
             height: Math.round(wf.getBoundingClientRect().height),
             visible: wf.querySelector('.checklist').innerText };
  });
  const G = real.groups || [];
  const U = G.find(g => /unverified/.test(g.cls)) || { rows: [] };
  ok('the chip says what could not be checked, and nothing failed', real.chip === '5 unverified', real.chip || JSON.stringify(real));
  ok('Unverified, then Passed', G.length === 2
     && /^Unverified · 5$/.test(G[0].head) && /^Passed · 1$/.test(G[1].head),
     G.map(g => g.head).join(' | '));
  ok('the checks that need a PDF are Unverified; the one that answered is in Passed',
     G.length === 2 && /unverified/.test(G[0].cls) && G[0].rows.every(r => r.state === 'unknown') && G[1].rows[0]?.state === 'pass'
     && /Page count/.test(G[1].rows[0]?.label), JSON.stringify(G.map(g => g.rows.map(r => r.state))));
  ok('the reason is said once, under the heading, and seen while folded',
     /Send a PDF and this can be answered/.test(U.note) && U.noteShown
     && U.rows.every(r => !/Nothing attached carries its own structure/.test(r.text)), U.note);
  ok('a row with nothing to add is a plain line; one with more opens', U.rows.length === 5
     && U.rows.filter(r => r.control).map(r => r.label).join() === 'Color'
     && /CMYK/.test(U.rows.find(r => r.control)?.text || ''), JSON.stringify(U.rows.map(r => [r.label, r.control])));
  ok('Unverified and Passed start folded', G.length === 2 && G.every(g => g.rows.every(r => !r.visible)));
  ok('one line per check: every explanation folded', G.length > 0 && G.every(g => g.rows.every(r => !r.textShown)));
  ok('the old state words are gone', typeof real.visible === 'string' && !/\b(PROBLEM|NOT CHECKED|OK)\b/.test(real.visible)
     && !/all checks ran/.test(real.visible), String(real.visible || real.error).replace(/\s+/g, ' ').slice(0, 140));
  ok('the open section is short enough to see the pages under it', real.height < 380, real.height + 'px');

  // Every state at once, through the real renderer.
  const mixed = await ev(page, () => {
    const keep = window.runFileChecks;
    window.runFileChecks = () => [
      { id:'a', label:'A passes',   state:'pass',    text:'fine' },
      { id:'b', label:'B unknown',  state:'unknown', text:'could not run' },
      { id:'c', label:'C fails',    state:'fail',    text:'broken' },
      { id:'d', label:'D warns',    state:'warn',    text:'look' },
      { id:'e', label:'E passes',   state:'pass',    text:'fine' },
      { id:'f', label:'F fails',    state:'fail',    text:'broken' } ];
    renderAll();
    const wf = document.querySelector('#rail .acc');
    const out = { chip: wf.querySelector('.acchead .chip').textContent,
                  chipCls: wf.querySelector('.acchead .chip').className,
                  heads: [...wf.querySelectorAll('.chk-grouphead')].map(h => h.textContent.replace('⌄', '').trim()),
                  order: [...wf.querySelectorAll('.chk-group')].map(g => [...g.querySelectorAll('.chk-label')].map(l => l.textContent[0]).join('')),
                  sr: [...wf.querySelectorAll('.chk-row')].map(r => r.querySelector('.chk-state').textContent) };
    window.runFileChecks = () => [ { id:'a', label:'A', state:'pass', text:'' }, { id:'e', label:'E', state:'pass', text:'' } ];
    renderAll();
    const wf2 = document.querySelector('#rail .acc');
    out.allChip = wf2.querySelector('.acchead .chip').textContent;
    out.allCls = wf2.querySelector('.acchead .chip').className;
    out.allGroups = [...wf2.querySelectorAll('.chk-group')].map(g => g.className);
    window.runFileChecks = keep; renderAll();
    return out;
  });
  ok('Not passed first, then Unverified, then Passed', (mixed.heads || []).join(' | ') === 'Not passed · 3 | Unverified · 1 | Passed · 2',
     (mixed.heads || [mixed.error]).join(' | '));
  ok('worst first inside Not passed: failed, then warned', (mixed.order || [])[0] === 'CFD', (mixed.order || []).join(' | '));
  ok('a check that could not run is not counted as not passed', (mixed.order || [])[1] === 'B' && (mixed.order || [])[2] === 'AE');
  ok('the chip names both, coloured by the worst', mixed.chip === '3 not passed · 1 unverified' && /\bfail\b/.test(mixed.chipCls),
     mixed.chip + ' / ' + mixed.chipCls);
  ok('each dot says its group to a screen reader',
     (mixed.sr || []).join(',') === 'Not passed,Not passed,Not passed,Unverified,Passed,Passed', (mixed.sr || []).join(','));
  ok('when everything passed it says so, and there is only a Passed group',
     mixed.allChip === 'all passed' && /\bpass\b/.test(mixed.allCls) && (mixed.allGroups || []).length === 1
     && /\bpassed\b/.test(mixed.allGroups[0]) && !/notpassed|unverified/.test(mixed.allGroups[0]), JSON.stringify(mixed));

  // Unfolding: an explanation, the folded groups, the deliverables.
  const unfold = await ev(page, async () => {
    const keep = window.runFileChecks;
    window.runFileChecks = () => [
      { id:'c', label:'C fails', state:'fail', text:'broken' },
      { id:'b', label:'B unknown', state:'unknown', text:'could not run' },
      { id:'a', label:'A passes', state:'pass', text:'fine' } ];
    renderAll();
    const q = s => document.querySelector('#rail .acc ' + s);
    const row = () => q('.chk-group.notpassed .chk-row');
    row().querySelector('.chk-sum').click();
    const opened = !row().querySelector('.chk-text').hidden && row().querySelector('.chk-sum').getAttribute('aria-expanded') === 'true';
    document.getElementById('mag').click(); await new Promise(r => setTimeout(r, 200));   // a full re-render
    document.getElementById('mag').click(); await new Promise(r => setTimeout(r, 200));
    const survived = !row().querySelector('.chk-text').hidden;
    row().querySelector('.chk-sum').click();
    const closed = row().querySelector('.chk-text').hidden;
    q('.chk-group.unverified .chk-grouphead').click();
    const unverifiedShown = [...document.querySelectorAll('#rail .chk-group.unverified .chk-row')].every(r => r.offsetHeight > 0);
    q('.chk-group.passed .chk-grouphead').click();
    const passedShown = [...document.querySelectorAll('#rail .chk-group.passed .chk-row')].every(r => r.offsetHeight > 0);
    const delivBefore = document.getElementById('deliv').hidden;
    q('.delivtoggle').click();
    const d = document.getElementById('deliv');
    const out = { opened, survived, closed, unverifiedShown, passedShown, delivBefore, delivAfter: !d.hidden && d.offsetHeight > 0,
                  delivText: /flattened at 300 DPI/.test(d.innerText), pkg: !!document.getElementById('pkgBtn') };
    window.runFileChecks = keep; renderAll();
    return out;
  });
  ok('clicking a check opens its explanation under it', unfold.opened, JSON.stringify(unfold));
  ok('and a re-render keeps it open', unfold.survived);
  ok('clicking again folds it', unfold.closed);
  ok('the Unverified group unfolds from its heading', unfold.unverifiedShown);
  ok('the Passed group unfolds from its heading', unfold.passedShown);
  ok('"What approving produces" starts folded and opens from its heading',
     unfold.delivBefore && unfold.delivAfter && unfold.delivText && unfold.pkg, JSON.stringify(unfold));
  await ctx.close();
}

// A failed approval writes its message, and after two the escape hatch, into
// that box. Folded, or behind an open page, or wiped by the next re-render,
// they would be there and unseen — and the escape hatch is the only way past a
// proof that cannot be built.
console.log('\n── a failed approval is seen, folded box or not ──');
{
  const { page, ctx } = await open('');
  await ev(page, () => {
    window.buildPackage = async () => { throw new Error('synthetic failure'); };
    state.wholeOpen = false; state.openPage = 4; state.selected = 4; renderAll();   // a page open, box folded
    document.getElementById('agree').checked = true; renderApproval();
    if (!document.getElementById('ackBox').hidden) document.getElementById('ackIssues').checked = true;
    renderApproval();
  });
  const seen = async () => ev(page, () => {
    const vis = el => !!el && el.offsetHeight > 0 && el.getClientRects().length > 0;
    const err = document.querySelector('#pkgOut .pkgerr'), esc = document.getElementById('proofEscapeBtn');
    return { err: vis(err) ? err.textContent : '', esc: vis(esc), escDisabled: !!esc && esc.disabled };
  });
  await page.click('#approveBtn'); await page.waitForTimeout(400);
  const one = await seen();
  ok('the first failure\'s message is on screen, with a page open and the box folded before',
     /could not prepare your proof: synthetic failure/.test(one.err) && !one.esc, JSON.stringify(one));
  await page.click('#approveBtn'); await page.waitForTimeout(400);
  const two = await seen();
  ok('after the second, the escape hatch is on screen too', two.esc && !two.escDisabled, JSON.stringify(two));
  await ev(page, async () => { document.getElementById('mag').click(); await new Promise(r => setTimeout(r, 200));
                               document.getElementById('mag').click(); await new Promise(r => setTimeout(r, 200)); });
  const after = await seen();
  ok('and a re-render keeps both', /synthetic failure/.test(after.err) && after.esc, JSON.stringify(after));
  if (after.esc){
    await page.click('#proofEscapeBtn'); await page.waitForTimeout(200);
    await ev(page, () => renderAll());
    const taken = await ev(page, () => ({ disabled: document.getElementById('proofEscapeBtn').disabled,
                                          note: document.getElementById('pkgNote').textContent }));
    ok('taking it is remembered across a re-render', taken.disabled && /Sent to prepress/.test(taken.note), JSON.stringify(taken));
  }
  await ctx.close();
}

// ═══ the flags ═══════════════════════════════════════════════════════════
console.log('\n── the "!" flags explain themselves ──');
{
  const { page, ctx } = await open('');
  await flagPages(page);
  await page.evaluate(() => { state.wholeOpen = false; renderAll(); });
  await page.waitForTimeout(200);
  let want = await ev(page, () => analyze(3).map(i => i.text.replace(/<[^>]*>/g, '')));
  if (!Array.isArray(want)) want = [];
  const flag = await page.$('#rail .acchead .bang[data-page="3"]');
  ok('a flagged page\'s heading carries the flag', !!flag);
  if (flag){
    await flag.hover(); await page.waitForTimeout(250);
    const t = await tip(page);
    ok('hovering it shows that page\'s findings', t.on && /^Page 3\b/.test(t.head)
       && t.items.length === want.length && t.items.every((x, i) => x === want[i]), JSON.stringify(t).slice(0, 220));
    ok('beside the flag, inside the window', t.right <= 1440 && t.left >= 0);
    // A tip opened by a tap or the keyboard follows its flag while the rail
    // scrolls. (Under a resting mouse a scroll moves the flag out from under
    // the pointer, which ends the hover — that is mouseout, and correct.)
    await page.mouse.move(300, 400); await page.waitForTimeout(150);
    const moved = await ev(page, async () => {
      const r = document.getElementById('rail'), t = document.getElementById('flagTip');
      const b = () => r.querySelector('.acchead .bang[data-page="3"]');
      const rr = r.getBoundingClientRect(), bb = b().getBoundingClientRect();
      r.scrollTop += Math.round(bb.top - (rr.top + rr.height / 2));      // flag mid-rail
      await new Promise(res => setTimeout(res, 150));
      showFlagTip(b());
      await new Promise(res => setTimeout(res, 250));          // past its fade-in nudge
      const before = t.getBoundingClientRect().top;
      r.scrollTop += 30; await new Promise(res => setTimeout(res, 150));
      return { on: t.classList.contains('on'), delta: Math.round(before - t.getBoundingClientRect().top) };
    });
    ok('a tip opened by tap or keyboard follows its flag while the rail scrolls',
       moved.on && Math.abs(moved.delta - 30) <= 2, JSON.stringify(moved));
    const gone = await ev(page, async () => {
      const r = document.getElementById('rail'); r.scrollTop += 2000;
      await new Promise(res => setTimeout(res, 150));
      return document.getElementById('flagTip').classList.contains('on');
    });
    ok('and goes when the flag scrolls out of sight', !gone);
    await page.evaluate(() => { document.getElementById('rail').scrollTop = 0; });
    const f2 = await page.$('#rail .acchead .bang[data-page="3"]');
    await f2.hover(); await page.waitForTimeout(200);
    await page.mouse.move(300, 400); await page.waitForTimeout(250);
    ok('moving off it hides it', !(await tip(page)).on);
  }
  ok('a page with nothing found has no flag', await page.evaluate(() =>
     !document.querySelector('#rail .bang[data-page="2"]') && analyze(2).length === 0));
  ok('the findings are in the heading\'s text for a screen reader', await page.evaluate(want => {
    const h = [...document.querySelectorAll('#rail .acc')].find(a => /Page: 3(\D|$)/.test(a.textContent)).querySelector('.acchead');
    return want.every(w => h.textContent.includes(w.slice(0, 40)));
  }, want));
  await ctx.close();
}
{
  // the 3D strip's flag, and the keyboard
  const { page, ctx } = await open('');
  await flagPages(page);
  await ev(page, () => [...document.querySelectorAll('#modes button')].find(b => b.dataset.mode === 'book').click());
  await page.waitForTimeout(500);
  const dot = await page.$('#stripRail .pg .dot[data-page="5"]');
  ok('a thumbnail\'s flag in the 3D strip is one too', !!dot);
  if (dot){
    await dot.hover(); await page.waitForTimeout(250);
    const t = await tip(page);
    ok('hovering it shows page 5\'s findings, inside the window', t.on && /^Page 5\b/.test(t.head) && t.items.length > 0
       && t.left >= 0 && t.right <= 1440, JSON.stringify(t).slice(0, 160));
    await page.mouse.move(300, 400); await page.waitForTimeout(200);
  }
  await ev(page, () => [...document.querySelectorAll('#modes button')].find(b => b.dataset.mode === 'proof').click());
  await page.waitForTimeout(400);
  // the heading's own button comes after its thumbnail's upload chip
  const kb = await ev(page, async () => {
    state.wholeOpen = false; state.openPage = null; renderAll();
    await new Promise(r => setTimeout(r, 150));
    const heads = [...document.querySelectorAll('#rail .acc')];
    heads.find(a => /Page: 3(\D|$)/.test(a.textContent)).querySelector('.pg.mini .pgup').focus();
    return true;
  });
  await page.keyboard.press('Tab'); await page.waitForTimeout(200);
  const k = await tip(page);
  ok('tabbing to a flagged heading shows its findings', kb && k.on && /^Page 3\b/.test(k.head), JSON.stringify(k).slice(0, 120));
  await page.keyboard.press('Escape'); await page.waitForTimeout(150);
  ok('and Escape puts it away', !(await tip(page)).on);
  await ctx.close();
}
{
  // a touch screen has no hover
  const { page, ctx } = await open('', { viewport: { width: 834, height: 1112 }, hasTouch: true, isMobile: true });
  await flagPages(page);
  await page.evaluate(() => { state.wholeOpen = false; renderAll(); });
  await page.waitForTimeout(200);
  const flag = await page.$('#rail .acchead .bang[data-page="3"]');
  if (flag){
    await flag.scrollIntoViewIfNeeded(); await page.waitForTimeout(200);
    await flag.tap(); await page.waitForTimeout(300);
    const t = await tip(page);
    const opened = await page.evaluate(() => state.openPage);
    ok('a tap on the flag shows the findings', t.on && /^Page 3\b/.test(t.head), JSON.stringify(t).slice(0, 120));
    ok('without opening the page beneath it', opened !== 3, 'openPage ' + opened);
    await page.tap('#sheet'); await page.waitForTimeout(300);
    ok('the next tap elsewhere closes it', !(await tip(page)).on);
  } else ok('a flagged heading on a touch screen', false, 'no flag');
  await ctx.close();
}

// ═══ the standalone preview link ═════════════════════════════════════════
// "A link that I can preview the proofer without going through the calc." The
// page always opened on its own; the link now also sizes the booklet, so a
// real file can be tried at its own size.
console.log('\n── the standalone link sizes the booklet ──');
{
  const { page, ctx } = await open('?size=8.5x11&pages=12');
  const s = await ev(page, () => {
    const r = document.getElementById('sheet').getBoundingClientRect();
    return { trim: MODEL.trim, pages: MODEL.pages.length, sel: document.getElementById('pageCount').value,
             hosted: !!MODEL.hosted, aspect: +(r.width / r.height).toFixed(3),
             want: +((8.5 + 2 * MODEL.bleed) / (11 + 2 * MODEL.bleed)).toFixed(3) };
  });
  ok('?size=8.5x11&pages=12 opens an 8.5 × 11, 12-page booklet', s.trim?.w === 8.5 && s.trim?.h === 11
     && s.pages === 12 && s.sel === '12', JSON.stringify(s));
  ok('and the sheet on screen has that shape', Math.abs(s.aspect - s.want) < 0.01, s.aspect + ' vs ' + s.want);
  ok('it stays a standalone preview, not a customer job', s.hosted === false);
  await ctx.close();
  const { page: p2, ctx: c2 } = await open('?size=99x1&pages=7');
  const bad = await ev(p2, () => ({ trim: MODEL.trim, pages: MODEL.pages.length }));
  ok('a size or page count that makes no sense is ignored', bad.trim?.w === 5.5 && bad.trim?.h === 8.5 && bad.pages === 8,
     JSON.stringify(bad));
  await c2.close();
  // a host's job always wins over the link
  const ctx4 = await browser.newContext({ viewport: { width: 1440, height: 860 } });
  const p4 = await ctx4.newPage();
  p4.on('pageerror', e => errors.push(String(e && e.message || e)));
  await p4.addInitScript(() => { window.PPS_LIB_BASE = '/vendor/';
    window.PPS_PROOF_JOB = { calc:'saddle', trim:{ w:6, h:9 }, bleed:0.125, safety:0.125, pages:16 }; });
  await p4.goto(PAGE + '?size=8.5x11&pages=12', { waitUntil: 'load' });
  await p4.waitForFunction(() => typeof MODEL !== 'undefined' && MODEL.pages.length > 0, null, { timeout: 20000 });
  const hosted = await ev(p4, () => ({ trim: MODEL.trim, pages: MODEL.pages.length }));
  ok('a host\'s job is never resized by the link', hosted.trim?.w === 6 && hosted.trim?.h === 9 && hosted.pages === 16,
     JSON.stringify(hosted));
  await ctx4.close();
}

ok('no uncaught page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ' + failed + ' FAILURE(S)' : '>>> PROOF RAIL OK');
process.exit(failed ? 1 : 0);
