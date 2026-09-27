// The shop closes, and every calculator has to know it.
//
// Found 2026-09-19 by hashing every named function across the eight calculators:
// the date engine split three-against-five. The five flats were closure-blind in
// two independent ways, either of which alone would have hidden the other:
//
//   1. SHOP_CLOSURES was read from _CFG.pcf.closures. Closures live at the TOP
//      LEVEL of the injected config, beside `pcf`, not inside it — pps_get_closures()
//      reads $cfg['closures'] and pps_get_public_config() treats $cfg['pcf'] as its
//      own sub-array. So the list resolved to undefined and fell back to [], empty
//      for the whole life of those files. That silently disabled the two places that
//      DID handle closures properly: the DatePicker's greying-out and the last-line
//      guard in quotedDeliveryYMD.
//
//   2. getShopToday, addBusinessDays and businessDaysBetween each inlined a bare
//      weekend test instead of calling isBusinessDay, so even a populated list would
//      have been ignored by the arithmetic.
//
// Christmas Day was a selectable, quotable, orderable delivery date on brochure,
// postcard, greeting card, letterhead and sticker. Turnaround ran short by one day
// per holiday in the window, rush was undercharged (a fatter denominator in
// freeDeliveryBizDays / bizDaysToDate), and `tooSoon` failed to trip — so the
// calculator accepted deadlines the shop cannot meet.
//
// This runs against the COMPILED builds, because those are what ship. It pins the
// config path statically and then actually executes the date engine out of each
// build against a known holiday, which is the half a grep cannot prove.
//
// Run: node tools-closure-engine-test.mjs

import { readFileSync } from 'node:fs';
import path from 'node:path';

const HERE = path.dirname(new URL(import.meta.url).pathname);
// PPS_DIST points at another set of compiled builds — how the pre-fix ones are checked.
const DIST = process.env.PPS_DIST || path.join(HERE, 'dist');
const FILES = [
  'calc-preview-test.html', 'calc-perfect-bound.html', 'calc-coupon-book.html',
  'calc-brochure.html', 'calc-postcard.html', 'calc-greeting-card.html',
  'calc-letterhead.html', 'calc-sticker.html',
];

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// Brace-match a function body from its declaration.
function extract(body, startIdx) {
  let i = body.indexOf('{', startIdx);
  if (i < 0) return null;
  let depth = 0, inStr = null, esc = false;
  for (let k = i; k < body.length; k++) {
    const c = body[k];
    if (esc) { esc = false; continue; }
    if (c === '\\') { esc = true; continue; }
    if (inStr) { if (c === inStr) inStr = null; continue; }
    if (c === '"' || c === "'" || c === '`') { inStr = c; continue; }
    if (c === '{') depth++;
    else if (c === '}') { depth--; if (depth === 0) return body.slice(i, k + 1); }
  }
  return null;
}
function fnSrc(body, name) {
  const re = new RegExp('\\bfunction\\s+' + name + '\\s*\\(', 'g');
  const m = re.exec(body);
  if (!m) return null;
  return body.slice(m.index, body.indexOf('{', m.index)) + extract(body, m.index);
}

console.log('── the closures list is read from the right place ──');
for (const f of FILES) {
  const src = readFileSync(path.join(DIST, f), 'utf8');
  const short = f.replace(/^calc-|\.html$/g, '');
  ok(`${short}: reads _CFG.closures`, /const SHOP_CLOSURES\s*=\s*_CFG\.closures/.test(src));
  ok(`${short}: never reads the pcf sub-array for it`,
     !/SHOP_CLOSURES\s*=\s*\(?_CFG\.pcf/.test(src));
}

console.log('\n── the date engine actually consults it ──');
for (const f of FILES) {
  const src = readFileSync(path.join(DIST, f), 'utf8');
  const short = f.replace(/^calc-|\.html$/g, '');
  ok(`${short}: no bare weekend test survives in the counting loops`,
     !/if \(d\.getDay\(\) !== 0 && d\.getDay\(\) !== 6\) (added|count)\+\+/.test(src));
  for (const fn of ['getShopToday', 'addBusinessDays', 'businessDaysBetween']) {
    const body = fnSrc(src, fn);
    ok(`${short}: ${fn} calls isBusinessDay`, !!body && /isBusinessDay\s*\(/.test(body));
  }
}

console.log('\n── and it behaves: a holiday is not a working day ──');
// Thanksgiving 2026-11-26 is a Thursday; 11-27 the Friday after. Using real
// closures in MM-DD form, the shape the config actually stores.
const CLOSURES = ['01-01', '07-04', '11-26', '11-27', '12-24', '12-25'];
for (const f of FILES) {
  const src = readFileSync(path.join(DIST, f), 'utf8');
  const short = f.replace(/^calc-|\.html$/g, '');
  const pieces = ['isBusinessDay', 'addBusinessDays', 'businessDaysBetween']
    .map(n => fnSrc(src, n)).filter(Boolean);
  if (pieces.length !== 3) { ok(`${short}: engine extractable`, false, 'could not find all three functions'); continue; }

  let api;
  try {
    api = new Function('SHOP_CLOSURES', pieces.join('\n') +
      '\nreturn { isBusinessDay, addBusinessDays, businessDaysBetween };')(CLOSURES);
  } catch (e) {
    ok(`${short}: engine evaluates`, false, String(e && e.message));
    continue;
  }

  const thanksgiving = new Date(2026, 10, 26);      // Thu 26 Nov 2026
  ok(`${short}: Thanksgiving is not a working day`, api.isBusinessDay(thanksgiving) === false);

  // Wed 25 Nov + 1 working day must skip Thu 26 and Fri 27 and land on Mon 30.
  const landed = api.addBusinessDays(new Date(2026, 10, 25), 1);
  ok(`${short}: one working day after Wed 25 Nov lands on Mon 30 Nov, not Thu 26`,
     landed.getMonth() === 10 && landed.getDate() === 30,
     landed.toDateString());

  // Mon 23 Nov → Mon 30 Nov spans 7 calendar days: Tue, Wed, Mon are working;
  // Thu/Fri are closures and Sat/Sun weekend. So 3, not 5.
  const span = api.businessDaysBetween(new Date(2026, 10, 23), new Date(2026, 10, 30));
  ok(`${short}: the Thanksgiving week counts 3 working days, not 5`, span === 3, 'got ' + span);
}

console.log('\n── a dated closure holds in any time zone, and the default list knows Thanksgiving ──');
// isBusinessDay built its YYYY-MM-DD with toISOString(), which is UTC: east of UTC a local
// midnight is the previous day there, so "2026-12-31" was tested against the 30th. And the
// fallback list carried Thanksgiving as "11-28"/"11-29", which misses it in 2026 (26th/27th).
function fourthThursday(y) { const f = new Date(y, 10, 1).getDay(); return 1 + ((4 - f + 7) % 7) + 21; }
for (const f of FILES) {
  const src = readFileSync(path.join(DIST, f), 'utf8');
  const short = f.replace(/^calc-|\.html$/g, '');
  const isBD = fnSrc(src, 'isBusinessDay');
  const savedTZ = process.env.TZ;
  try {
    process.env.TZ = 'Pacific/Auckland';
    const fn = new Function('SHOP_CLOSURES', isBD + '\nreturn isBusinessDay;')(['2026-12-31']);
    ok(`${short}: a dated closure is closed in Auckland too`, fn(new Date(2026, 11, 31)) === false && fn(new Date(2026, 11, 30)) === true);
  } catch (e) { ok(`${short}: isBusinessDay evaluates`, false, String(e.message)); }
  finally { if (savedTZ === undefined) delete process.env.TZ; else process.env.TZ = savedTZ; }

  const m = /const SHOP_CLOSURES\s*=\s*_CFG\.closures\s*\|\|\s*/.exec(src);
  const arr = m && extract(src.replace(/\[/g, '{').replace(/\]/g, '}'), m.index + m[0].length - 1);
  // extract() matches braces, so read the array span by position from the swapped copy.
  const lit = arr && src.substr(src.indexOf('[', m.index), arr.length);
  const helper = fnSrc(src, 'ppsThanksgivingClosures') || '';
  let list = null;
  try { list = new Function(helper + '\nreturn ' + lit + ';')(); } catch (e) { ok(`${short}: default closures evaluate`, false, String(e.message)); continue; }
  const y = new Date().getFullYear(), d = fourthThursday(y);
  const want = `${y}-11-${String(d).padStart(2, '0')}`, fri = `${y}-11-${String(d + 1).padStart(2, '0')}`;
  ok(`${short}: the fallback closures include this year's Thanksgiving and the day after (${want})`, list.includes(want) && list.includes(fri), JSON.stringify(list));
}

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> CLOSURE ENGINE FAILED' : '>>> CLOSURE ENGINE OK');
process.exit(failed ? 1 : 0);
