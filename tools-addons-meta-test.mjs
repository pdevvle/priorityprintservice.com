// Add-ons reach the order — as words, on their own line, from every calculator.
//
// Found 2026-09-21: the only route from a finishing choice to the job ticket was the
// summary text, and the PPS-Spec builder skipped every summary line with a colon in
// it — meant to drop Inside:/Cover:/Rush:/Ship to:, it also dropped every flat's
// "Coating: UV Gloss (both sides)", "Perforation: …", "Bundling: …" and "Round
// Corner: …". Perfect bound and coupon book never wrote outfold, perforation or the
// magnetic backer into the summary at all. So a coating lived only as `coating: 750`
// inside a JSON blob that nobody opens unprompted.
//
// Three halves:
//   1. pps-calculators.php reads the calculator's `addons` list, falls back to the
//      summary with the finishing categories kept, and writes a visible "Add-ons" line.
//      pps_order_addons() is extracted from the plugin and RUN under php.
//   2. Every COMPILED calculator posts `addons: buildAddons(config)`, and buildAddons —
//      extracted from the build together with the option tables it reads — turns
//      sample configs into the expected labels.
//   3. Perfect bound and coupon book's summaries now carry outfold/perforation/magnet.
//
// Run: node tools-addons-meta-test.mjs        (needs php on PATH for half 1)

import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const DIST = path.join(HERE, 'dist');
let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// ── 1. PHP ──
const php = readFileSync(path.join(HERE, 'pps-calculators.php'), 'utf8');
ok('php: pps_order_addons() exists', /function pps_order_addons\(/.test(php));
ok('php: the spec builder takes its add-ons from pps_order_addons()', /\$addons = pps_order_addons\( \$full, /.test(php));
ok('php: the colon filter that ate "Coating: …" is gone', !/if \( strpos\( \$line, ':' \) !== false \) continue;/.test(php));
ok('php: a visible Add-ons line is written next to PPS-Spec', /add_meta_data\( 'Add-ons', implode\( '; ', \$addons \), true \)/.test(php));
ok('php: Add-ons is NOT on the internal-only list (the customer sees it)', !/'Add-ons'[^\n]*\n[^\n]*pps_internal_item_meta_keys|pps_internal_item_meta_keys[\s\S]{0,200}'Add-ons'/.test(php));

function phpFunction(src, name) {
  const m = new RegExp('function ' + name + '\\(').exec(src); if (!m) return null;
  let i = src.indexOf('{', m.index), d = 0;
  for (let k = i; k < src.length; k++) { if (src[k] === '{') d++; else if (src[k] === '}') { d--; if (!d) return src.slice(m.index, k + 1); } }
  return null;
}
try {
  const fn = phpFunction(php, 'pps_order_addons');
  const script = `<?php
function sanitize_text_field($s){ return trim(strip_tags((string)$s)); }
${fn}
$cases = array(
  'list'   => pps_order_addons(array('addons' => array('Coating: UV Gloss (both sides)', array('label' => 'Perforation: 1 Perforation Line (length)'), '', 'Coating: UV Gloss (both sides)', '<b>Bundling: Bundle in 25s</b>')), ''),
  'legacy' => pps_order_addons(array(), "250 × 21×7\\" custom · Trifold (3 Panel)\\nPaper: 100lb Matte\\nFront: Full Color · Back: Full Color\\nEnhanced Vivid Print\\nCoating: UV Gloss (both sides)\\nPerforation: 1 Line (length)\\nRound Corner: ¼\\" Round\\nUpload Art with Order\\nRush: 3 business days\\nShip to: CA 94043"),
  'empty'  => pps_order_addons(array('addons' => array()), "headline\\nPaper: X\\nShip to: CA"),
);
echo json_encode($cases, JSON_UNESCAPED_UNICODE);
`;
  const out = JSON.parse(execFileSync('php', ['-r', script.replace(/^<\?php\s*/, '')], { encoding: 'utf8' }));
  ok('php: the calculator list is used verbatim, de-duplicated, tags stripped, blanks dropped',
     JSON.stringify(out.list) === JSON.stringify(['Coating: UV Gloss (both sides)', 'Perforation: 1 Perforation Line (length)', 'Bundling: Bundle in 25s']), JSON.stringify(out.list));
  ok('php: a legacy summary keeps the finishing lines and drops paper / colour / rush / ship-to',
     JSON.stringify(out.legacy) === JSON.stringify(['Enhanced Vivid Print', 'Coating: UV Gloss (both sides)', 'Perforation: 1 Line (length)', 'Round Corner: ¼" Round', 'Upload Art with Order']), JSON.stringify(out.legacy));
  ok('php: an empty calculator list means no add-ons, not a fallback to the summary', JSON.stringify(out.empty) === '[]', JSON.stringify(out.empty));
} catch (e) { ok('php: pps_order_addons() runs', false, String(e.message || e).slice(0, 300)); }

// ── 2. The calculators ──
function extractBlock(src, startIdx, open, close) {
  let i = src.indexOf(open, startIdx), d = 0, q = null, esc = false;
  for (let k = i; k < src.length; k++) {
    const c = src[k];
    if (esc) { esc = false; continue; }
    if (c === '\\') { esc = true; continue; }
    if (q) { if (c === q) q = null; continue; }
    if (c === '"' || c === "'" || c === '`') { q = c; continue; }
    if (c === open) d++; else if (c === close) { d--; if (!d) return src.slice(i, k + 1); }
  }
  return null;
}
function fnSrc(src, name) {
  const m = new RegExp('\\bfunction\\s+' + name + '\\s*\\(').exec(src); if (!m) return null;
  return src.slice(m.index, src.indexOf('{', m.index)) + extractBlock(src, m.index, '{', '}');
}
function constSrc(src, name) {
  const m = new RegExp('\\bconst\\s+' + name + '\\s*=').exec(src); if (!m) return null;
  const arr = extractBlock(src, m.index, '[', ']');
  return arr ? 'const ' + name + ' = ' + arr + ';' : null;
}

const CALCS = {
  'calc-brochure.html':      { tables: ['COATINGS', 'BUNDLING', 'PERF_OPTS', 'CORNERS'], cases: [
    [{ coating: 750, coatSides: 2, bundling: 0, perforation: 2000, perfDir: 'length', roundCorner: 0, vivid: true }, ['Coating: UV Gloss (both sides)', /^Perforation: .*\(length\)$/, 'Enhanced Vivid Print']],
    [{ coating: 0, coatSides: 1, bundling: 0, perforation: 0, roundCorner: 0, vivid: false }, []] ] },
  'calc-postcard.html':      { tables: ['COATINGS', 'BUNDLING', 'PERF_OPTS', 'CORNERS'], cases: [
    [{ coating: 510, coatSides: 1, bundling: 750, perforation: 0, roundCorner: 216, vivid: false }, ['Coating: UV Matte', /^Bundling: /, /^Round Corner: /]] ] },
  'calc-greeting-card.html': { tables: ['COATINGS', 'BUNDLING', 'PERF_OPTS', 'CORNERS', 'SIZE_PRESETS'], cases: [
    [{ coating: 750, coatSides: 1, bundling: 0, perforation: 0, roundCorner: 0, vivid: false, envelopes: false }, ['Coating: UV Gloss']] ] },
  'calc-letterhead.html':    { tables: ['PERF_OPTS'], cases: [
    [{ perforation: 2000, perfDir: 'width' }, [/^Perforation: .*\(width\)$/]], [{ perforation: 0 }, []] ] },
  'calc-sticker.html':       { tables: ['COATINGS'], cases: [
    [{ coating: 750 }, ['Coating: UV Gloss']], [{ coating: 0 }, []] ] },
  'calc-preview-test.html':  { tables: ['COATINGS', 'BUNDLING', 'CORNERS'], cases: [
    [{ coating: 750, bundling: 1500, roundCorner: 0, vividPrint: true }, ['Coating: UV Gloss', /^Bundling: /, 'Enhanced Vivid Print']] ] },
  'calc-perfect-bound.html': { tables: ['COATINGS', 'BUNDLING', 'CORNERS', 'OUTFOLD_OPTS', 'PERF_OPTS'], cases: [
    [{ coating: 0, bundling: 0, roundCorner: 0, outfold: 1000, perforation: 2000, vividPrint: false }, [/^Outfold: 1 Fold-Out/, /^Perforation: 1 Perforation Line/]] ] },
  'calc-coupon-book.html':   { tables: ['COATINGS', 'BUNDLING', 'CORNERS', 'OUTFOLD_OPTS', 'PERF_OPTS', 'MAGNET_OPTS'], cases: [
    [{ coating: 0, bundling: 0, roundCorner: 0, outfold: 0, perforation: 2000, magnetBacker: 1, vividPrint: false }, [/^Perforation: 1 Perforation Line/, /^Magnetic Backer: /]] ] },
};
for (const [f, spec] of Object.entries(CALCS)) {
  const short = f.replace(/^calc-|\.html$/g, '');
  let src;
  try { src = readFileSync(path.join(DIST, f), 'utf8'); } catch { ok(`${short}: compiled build present`, false); continue; }
  ok(`${short}: the metadata posts addons: buildAddons(config)`, /addons:\s*buildAddons\(config\)/.test(src));
  const fn = fnSrc(src, 'buildAddons');
  ok(`${short}: buildAddons is in the shipped build`, !!fn);
  if (!fn) continue;
  const tables = spec.tables.map(n => constSrc(src, n));
  if (tables.some(t => !t)) { ok(`${short}: option tables extractable`, false, spec.tables.filter((n, i) => !tables[i]).join(',')); continue; }
  let build;
  try { build = new Function('_CFG', tables.join('\n') + '\n' + fn + '\nreturn buildAddons;')({}); }
  catch (e) { ok(`${short}: buildAddons evaluates`, false, String(e.message)); continue; }
  spec.cases.forEach(([cfg, expect], i) => {
    const got = build(cfg);
    const match = got.length === expect.length && expect.every((e, k) => e instanceof RegExp ? e.test(got[k]) : got[k] === e);
    ok(`${short}: case ${i + 1} → ${JSON.stringify(expect.map(String))}`, match, 'got ' + JSON.stringify(got));
  });
}

// ── 3. The two booklets that never told the summary ──
for (const f of ['calc-perfect-bound.html', 'calc-coupon-book.html']) {
  const src = readFileSync(path.join(DIST, f), 'utf8');
  const sum = fnSrc(src, 'buildSummary') || '';
  ok(`${f.replace(/^calc-|\.html$/g, '')}: the summary lists outfold and perforation`, /OUTFOLD_OPTS\.find/.test(sum) && /PERF_OPTS\.find/.test(sum));
  if (/coupon/.test(f)) ok('coupon-book: the summary lists the magnetic backer', /MAGNET_OPTS\.find/.test(sum));
}

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ADDONS META FAILED' : '>>> ADDONS META OK');
process.exit(failed ? 1 : 0);
