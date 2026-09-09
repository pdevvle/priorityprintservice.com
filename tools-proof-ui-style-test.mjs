// Proof UI — does it still look like the calculator?
//
// The proofer is a separate document that deliberately wears the calculator's
// skin. Nothing enforced that, and it drifted in the way this class of thing
// always drifts: a panel added in a later session was written against
// --panel/--line/--ink/--dim/--accent, none of which this page defines, so every
// rule in it silently fell back to the browser default. No error, no warning —
// just an unstyled block in the middle of a finished proof.
//
// So this suite checks two things a screenshot cannot:
//
//   1. every custom property the stylesheet USES actually resolves, in both
//      themes. That is the general form of the bug above.
//   2. the shared tokens hold the same values as calc-preview-test.html, read
//      out of that file rather than copied here, so the two cannot drift apart
//      without this failing.
//
// Needs the harness: node tools-proof-serve.mjs &

import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';

const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const { chromium } = await import(PW + '/index.mjs');

const HERE = path.dirname(new URL(import.meta.url).pathname);
const BASE = process.env.PPS_PROOF_BASE || 'http://127.0.0.1:8137';
const CALC = process.env.PPS_CALC_FILE || path.join(HERE, 'calc-preview-test.html');

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

/** Pull `--name: value` pairs out of one :root-ish block. */
function tokensFrom(css, selector) {
  const i = css.indexOf(selector);
  if (i < 0) return {};
  const open = css.indexOf('{', i), close = css.indexOf('}', open);
  if (open < 0 || close < 0) return {};
  const out = {};
  for (const m of css.slice(open + 1, close).matchAll(/(--[a-z0-9-]+)\s*:\s*([^;]+)/g)) {
    out[m[1]] = m[2].trim();
  }
  return out;
}

// The calculator is the source of truth for the shared palette.
if (!existsSync(CALC)) { console.error('cannot find ' + CALC); process.exit(2); }
const calcCss = readFileSync(CALC, 'utf8');
const calcLight = tokensFrom(calcCss, ':root{\n    --bp-surface');
const calcDark  = tokensFrom(calcCss, ':root[data-pps-theme="dark"]');

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});
const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });
const errors = [];
page.on('pageerror', e => errors.push(String(e && e.message || e)));
await page.route('**/*', route =>
  route.request().url().startsWith(BASE) ? route.continue() : route.fulfill({ status: 204, body: '' }));
await page.addInitScript(() => { window.PPS_LIB_BASE = '/vendor/'; });
await page.goto(BASE + '/proof-ui-draft.html', { waitUntil: 'load' });
await page.waitForFunction(() => typeof MODEL !== 'undefined' && MODEL.pages.length > 0, null, { timeout: 20000 });

// ── 1. every token the stylesheet asks for actually exists ──────────────────
console.log('\n── no rule silently falls back to the browser default ──');
for (const theme of ['light', 'dark']) {
  await page.evaluate(t => {
    document.documentElement.setAttribute('data-pps-theme', t);
    if (typeof renderAll === 'function') renderAll();
  }, theme);

  const bad = await page.evaluate(() => {
    // Read the page's own rules, collect every var() reference, and resolve each
    // against :root. A var with a fallback — var(--x, 1px) — is fine by design.
    const css = Array.from(document.styleSheets)
      .filter(s => !s.href)
      .flatMap(s => { try { return Array.from(s.cssRules).map(r => r.cssText); } catch { return []; } })
      .join('\n');
    const used = new Set();
    for (const m of css.matchAll(/var\((--[a-z0-9-]+)\s*([,)])/g)) {
      if (m[2] === ')') used.add(m[1]);           // no fallback — must resolve
    }
    const root = getComputedStyle(document.documentElement);
    return [...used].filter(n => !root.getPropertyValue(n).trim());
  });
  ok(theme + ': every custom property resolves', bad.length === 0, bad.join(', '));
}
await page.evaluate(() => document.documentElement.setAttribute('data-pps-theme', 'light'));

// ── 2. the shared palette matches the calculator, token for token ───────────
console.log('\n── the palette is the calculator\'s, not an approximation ──');
{
  const shared = Object.keys(calcLight).filter(k => k.startsWith('--bp-'));
  ok('the calculator still declares a palette to compare against', shared.length >= 8,
     shared.length + ' tokens read from ' + path.basename(CALC));

  const mine = await page.evaluate(names => {
    const r = getComputedStyle(document.documentElement);
    return Object.fromEntries(names.map(n => [n, r.getPropertyValue(n).trim()]));
  }, shared);

  const drift = shared.filter(n => mine[n] && mine[n] !== calcLight[n]);
  ok('light tokens agree', drift.length === 0,
     drift.map(n => n + ': proof ' + mine[n] + ' vs calc ' + calcLight[n]).join('; '));

  // --bp-on-dark is the calculator's muted-white-on-dark text color. The proof
  // has no white-on-dark text of its own, and carrying a token nothing uses is
  // how a palette starts collecting fossils — so it is exempt, by name, rather
  // than by weakening the check.
  const EXEMPT = ['--bp-on-dark'];
  const absent = shared.filter(n => !mine[n] && !EXEMPT.includes(n));
  ok('the proof carries every shared token it needs', absent.length === 0, absent.join(', '));
}

// ── 3. the controls actually wear the calculator's treatments ───────────────
console.log('\n── the controls read as the same product ──');
{
  const seen = await page.evaluate(() => {
    const cs = el => el ? getComputedStyle(el) : null;
    const seg = document.querySelector('.modes');
    const segOn = document.querySelector('.modes button.on');
    // The CTA is disabled at rest, and disabled deliberately drops the gradient
    // for a flat fill — reading it in that state would test the wrong rule.
    const approve = document.getElementById('approveBtn');
    document.getElementById('agree').checked = true;
    if (!document.getElementById('ackBox').hidden) document.getElementById('ackIssues').checked = true;
    renderApproval();
    const close = document.getElementById('closeBtn');
    return {
      // segmented control: an inset track with a rounded thumb, not butted buttons
      segInset: /inset/.test(cs(seg).boxShadow),
      segRadius: parseFloat(cs(seg).borderRadius),
      segOnBrand: cs(segOn) ? cs(segOn).backgroundColor : '',
      // the approve CTA is the brand gradient, not a flat fill
      approveGradient: /gradient/.test(cs(approve).backgroundImage),
      approveUpper: cs(approve).textTransform,
      approveNudge: approve.classList.contains('nudge'),
      // close is the calculator's round nav button
      closeRadius: cs(close).borderRadius,
      closeSquare: Math.round(close.getBoundingClientRect().width) === Math.round(close.getBoundingClientRect().height),
      // display face on headings, UI face on body
      bodyFont: cs(document.body).fontFamily,
      headFont: cs(document.querySelector('.acchead')).fontFamily,
    };
  });
  ok('the view switch is an inset track like the calculator\'s segmented control',
     seen.segInset && seen.segRadius >= 8, JSON.stringify({ inset: seen.segInset, r: seen.segRadius }));
  ok('its selected thumb is brand cyan', seen.segOnBrand === 'rgb(0, 126, 255)', seen.segOnBrand);
  ok('APPROVE wears the brand gradient CTA once it is pressable', seen.approveGradient);
  ok('and rings for attention only then', seen.approveNudge, String(seen.approveNudge));
  ok('and is uppercase like the calculator\'s', seen.approveUpper === 'uppercase', seen.approveUpper);
  ok('close is the round nav button', seen.closeRadius === '50%' && seen.closeSquare,
     seen.closeRadius);
  ok('body uses the UI face', /Work Sans/.test(seen.bodyFont), seen.bodyFont);
  ok('headings use the display face', /Montserrat/.test(seen.headFont), seen.headFont);
}

// ── 4. the panel that was invisible ────────────────────────────────────────
console.log('\n── the deliverables panel is actually styled ──');
{
  const pkg = await page.evaluate(() => {
    // Render it without approving anything.
    const out = document.getElementById('pkgOut');
    if (!out) return null;
    out.hidden = false;
    out.innerHTML = '<h4>x</h4><div class="pkgfile"><span class="pf">a</span><a href="#">b</a></div>'
                  + '<div class="pkghash">h</div>';
    const cs = el => getComputedStyle(el);
    const o = cs(out), f = cs(out.querySelector('.pkgfile')), a = cs(out.querySelector('a'));
    return {
      border: o.borderTopWidth, radius: parseFloat(o.borderTopLeftRadius),
      bg: o.backgroundColor,
      rowRule: f.borderBottomWidth,
      link: a.color,
      heading: cs(out.querySelector('h4')).textTransform,
    };
  });
  ok('the panel has a real border', pkg && pkg.border !== '0px', pkg && pkg.border);
  ok('and the calculator\'s card radius', pkg && pkg.radius >= 10, pkg && String(pkg.radius));
  ok('it sits on a surface, not on nothing',
     pkg && pkg.bg !== 'rgba(0, 0, 0, 0)', pkg && pkg.bg);
  ok('file rows are separated by a hairline', pkg && pkg.rowRule !== '0px', pkg && pkg.rowRule);
  ok('download links are brand cyan', pkg && pkg.link === 'rgb(0, 126, 255)', pkg && pkg.link);
  ok('its heading is the uppercase micro-label', pkg && pkg.heading === 'uppercase', pkg && pkg.heading);
}

// ── 5. dark actually changes ───────────────────────────────────────────────
console.log('\n── the dark skin is a different skin ──');
{
  const pair = await page.evaluate(() => {
    const read = () => {
      const r = getComputedStyle(document.documentElement);
      return ['--bp-surface', '--bp-ink', '--bp-line', '--bp-btn']
        .map(n => r.getPropertyValue(n).trim()).join('|');
    };
    document.documentElement.setAttribute('data-pps-theme', 'light');
    const light = read();
    document.documentElement.setAttribute('data-pps-theme', 'dark');
    const dark = read();
    const body = getComputedStyle(document.body).backgroundColor;
    document.documentElement.setAttribute('data-pps-theme', 'light');
    return { light, dark, body };
  });
  ok('every surface token flips', pair.light !== pair.dark, pair.light + '  vs  ' + pair.dark);
  ok('and the page itself goes dark', pair.body !== 'rgb(246, 247, 249)', pair.body);

  // "Agreed" lightens the note. The obvious implementation — reuse --bp-surface
  // — is lighter than the pink tint in the light skin and DARKER than it in the
  // dark one, which is the same bug in reverse and invisible unless both are
  // measured.
  const lift = await page.evaluate(async () => {
    const lum = c => { const m = c.match(/[\d.]+/g).map(Number); return (m[0]+m[1]+m[2])/3; };
    const n = document.getElementById('note'), a = document.getElementById('agree');
    // The fill is transitioned, so reading it the same tick returns a colour
    // part-way between the two and the comparison is meaningless.
    const settle = () => new Promise(r => setTimeout(r, 340));
    const read = async () => {
      a.checked = false; renderApproval(); await settle();
      const off = getComputedStyle(n).backgroundColor;
      a.checked = true;  renderApproval(); await settle();
      const on  = getComputedStyle(n).backgroundColor;
      a.checked = false; renderApproval(); await settle();
      return { off, on, lifts: lum(on) > lum(off) };
    };
    document.documentElement.setAttribute('data-pps-theme', 'light');
    const light = await read();
    document.documentElement.setAttribute('data-pps-theme', 'dark');
    const dark = await read();
    document.documentElement.setAttribute('data-pps-theme', 'light');
    return { light, dark };
  });
  ok('agreeing lightens the note in light', lift.light.lifts,
     lift.light.off + ' -> ' + lift.light.on);
  ok('and in dark too', lift.dark.lifts, lift.dark.off + ' -> ' + lift.dark.on);
}

ok('no uncaught page errors', errors.length === 0, errors.slice(0, 3).join(' | '));

console.log('\n' + checks + ' checks, ' + failed + ' failed');
await browser.close();
console.log(failed ? '>>> STYLE FAILED' : '>>> STYLE OK');
process.exit(failed ? 1 : 0);
