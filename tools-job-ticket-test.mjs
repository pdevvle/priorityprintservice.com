// The Job Ticket: one block, first on the order, that production runs the job from.
//
// Until 2026-09-22 a job was spread across Order Summary, PPS-Spec, Add-ons,
// Estimated Delivery, order notes, the Drive folder and a JSON blob. The coating
// question on order 87171 was answered by reading JSON; the hardcopy proof for
// order 87198 had no address anywhere; order 87202 said "cardstock" on a
// self-cover job because a server guessed a label from a number.
//
// Two halves:
//   1. pps_job_ticket() is extracted from the plugin and RUN under php: a calculator
//      ticket comes through in order with the server's facts appended (art status,
//      files, dates, rush, ship-to, shipment); a legacy order with no ticket still
//      gets a block from its summary; a prepress-review order says NOT APPROVED.
//   2. buildTicket() is extracted from every COMPILED calculator with the option
//      tables it reads and run on sample configs: the booklets name binding, inside
//      and cover; the flats name paper, print and fold; every one names the
//      finishing, the proof type and, for a hardcopy proof, where it goes.
//
// Run: node tools-job-ticket-test.mjs        (needs php on PATH for half 1)

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
ok('php: pps_job_ticket() exists', /function pps_job_ticket\(/.test(php));
ok('php: the ticket is written as visible "Job Ticket" meta', /add_meta_data\( 'Job Ticket', \$ticket, true \)/.test(php));
ok('php: the ticket is written BEFORE Estimated Delivery, so it reads first', php.indexOf("add_meta_data( 'Job Ticket'") < php.indexOf("add_meta_data( 'Estimated Delivery'"));
ok('php: Job Ticket is not on the internal-only list (the customer gets the same block)', !/pps_internal_item_meta_keys\(\) \{\s*return array\([^)]*'Job Ticket'/.test(php));

function phpFunction(src, name) {
  const m = new RegExp('function ' + name + '\\(').exec(src); if (!m) return null;
  let i = src.indexOf('{', m.index), d = 0;
  for (let k = i; k < src.length; k++) { if (src[k] === '{') d++; else if (src[k] === '}') { d--; if (!d) return src.slice(m.index, k + 1); } }
  return null;
}
try {
  const script = `
function sanitize_text_field($s){ return trim(strip_tags((string)$s)); }
function sanitize_file_name($s){ return preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$s); }
${phpFunction(php, 'pps_order_addons')}
${phpFunction(php, 'pps_job_ticket')}
$d = new DateTime('2026-09-30', new DateTimeZone('America/Phoenix'));
$full = array(
  'ticket' => array(array('Product','Brochure / Flat Print'), array('Quantity','250'), array('Size','21×7" custom · Trifold (3 Panel)'), array('Paper','100lb Matte Factory Coated'), array('Finishing','None'), array('Proof','Hardcopy proof, mailed before printing'), array('Proof ship-to','Kelley Curry, 1 Main St, Phoenix AZ 85001'), array('<b>x</b>', ''), 'junk'),
  'proof' => 3.01, 'productionStartDate' => '2026-09-24', 'mustShipByDate' => '2026-09-26', 'rushCost' => 0, 'requestedBizDays' => 7,
  'shipAddr' => array('name' => 'Peter Huang', 'company' => 'Yew Chung International School', 'street1' => '310 EASY ST', 'city' => 'MOUNTAIN VIEW', 'zip' => '94043'), 'shipState' => 'CA',
  'estWeightLb' => 18.1, 'estCartons' => 1,
);
$vals = array('pps_artwork_files' => array(array('path'=>'pps-artwork/a.pdf','name'=>'ECE Trifold.pdf'), array('path'=>'pps-artwork/b.pdf','name'=>'ECE Trifold_print-ready.pdf')), 'pps_proof_hash' => str_repeat('a', 64));
$out = array();
$out['full'] = pps_job_ticket($full, $vals, $d, '');
$out['prepress'] = pps_job_ticket(array('proof' => 0), array('pps_artwork_path' => 'pps-artwork/x.pdf'), $d, 'yes');
$out['selfok'] = pps_job_ticket(array('proof' => 0), array('pps_proof_hash' => str_repeat('b', 64)), $d, '');
$out['legacy'] = pps_job_ticket(array('rushCost' => 12), array('pps_summary' => "500 × 8.5×11 · Trifold\\nPaper: 100lb Gloss\\nFront: Full Color · Back: Full Color\\nCoating: UV Gloss (both sides)\\nUpload Art with Order\\nRush: 3 business days\\nShip to: AZ 85001"), $d, '');
echo json_encode($out, JSON_UNESCAPED_UNICODE);
`;
  const out = JSON.parse(execFileSync('php', ['-r', script], { encoding: 'utf8' }));
  const full = out.full.split('\n');
  ok('php: the calculator pairs come through in order, first', full.slice(0, 3).join('|') === 'Product: Brochure / Flat Print|Quantity: 250|Size: 21×7" custom · Trifold (3 Panel)', full.slice(0, 3).join(' | '));
  ok('php: a blank or malformed pair is dropped, tags stripped', !out.full.includes('<b>') && !out.full.includes('junk'));
  ok('php: hardcopy proof → awaiting approval, and the proof ship-to line survives', /Proof ship-to: Kelley Curry, 1 Main St, Phoenix AZ 85001/.test(out.full) && /Art status: Awaiting hardcopy proof approval/.test(out.full), out.full);
  ok('php: files, both dates, delivery with rush flag and days', /Artwork files: 2 uploaded \(ECE-Trifold\.pdf, ECE-Trifold_print-ready\.pdf\)/.test(out.full) && /Production start: Thu, Sep 24, 2026/.test(out.full) && /Must ship by: Sat, Sep 26, 2026/.test(out.full) && /Delivery: Wednesday, Sep 30, 2026 — standard \(7 business days\)/.test(out.full), out.full);
  ok('php: ship-to reads as one line with state and zip; shipment estimate present', /Ship to: Peter Huang, Yew Chung International School, 310 EASY ST, MOUNTAIN VIEW CA 94043/.test(out.full) && /Shipment: 18\.1 lb · 1 carton \(estimate\)/.test(out.full), out.full);
  ok('php: prepress review → NOT APPROVED, one file counted from the path', /Art status: NOT APPROVED/.test(out.prepress) && /Artwork files: 1 uploaded/.test(out.prepress), out.prepress);
  ok('php: self-approved with a hash says so', /Art status: Self-approved online \(approval bound to the print file\)/.test(out.selfok), out.selfok);
  ok('php: a legacy order still gets a block — job line, its summary lines, finishing, rush flag', /^Job: 500 × 8\.5×11 · Trifold/.test(out.legacy) && /Paper: 100lb Gloss/.test(out.legacy) && /Finishing: Coating: UV Gloss \(both sides\); Upload Art with Order/.test(out.legacy) && /— RUSH/.test(out.legacy) && !/Ship to: AZ 85001\n/.test(out.legacy + '\n'), out.legacy);
} catch (e) { ok('php: pps_job_ticket() runs', false, String(e.message || e).slice(0, 400)); }

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
const get = (pairs, k) => { const p = pairs.find(x => x[0] === k); return p ? p[1] : undefined; };

const CALCS = {
  'calc-brochure.html': { tables: ['COATINGS', 'BUNDLING', 'PERF_OPTS', 'CORNERS', 'FOLD_TYPES', 'ART_OPTS', 'BLEED_OPTS'], run(build) {
    const t = build({ jobName: '', qty: 250, sizeMode: 'custom', sizeLabel: '4.25×5.5', longEdge: 21, shortEdge: 7, foldType: 'trifold', frontColor: 'color', backColor: 'color', sides: 2,
      paper: { label: '100lb Matte Factory Coated' }, vivid: false, coating: 0, coatSides: 1, bundling: 0, perforation: 0, roundCorner: 0, artwork: 0.01, bleed: 0, proof: 0 }, {});
    ok('brochure: size names the custom inches and the fold, not the stale preset label', /^21×7" custom · Trifold/.test(get(t, 'Size') || ''), get(t, 'Size'));
    ok('brochure: paper, print and finishing lines', get(t, 'Paper') === '100lb Matte Factory Coated' && get(t, 'Print') === '2 sides · Front Full Color · Back Full Color' && get(t, 'Finishing') === 'None', JSON.stringify(t));
    ok('brochure: artwork option and proof type are words', get(t, 'Artwork') === 'Upload Art with Order' && /^Self-approved online/.test(get(t, 'Proof') || ''), JSON.stringify(t));
    const h = build({ qty: 100, sizeMode: 'preset', sizeLabel: '8.5×11', foldType: 'trifold', frontColor: 'bw', sides: 1, paper: { label: 'X' }, coating: 750, coatSides: 2, bundling: 0, perforation: 0, roundCorner: 0, artwork: 0.02, bleed: 1, proof: 3.01, proofAddrSame: false, proofAddr: { name: 'Kelley Curry', street: '1 Main St', city: 'Phoenix', state: 'AZ', zip: '85001' } }, {});
    ok('brochure: hardcopy proof carries its own ship-to; bleed answer and finishing carried', get(h, 'Proof ship-to') === 'Kelley Curry, 1 Main St, Phoenix AZ 85001' && /^Hardcopy/.test(get(h, 'Proof')) && get(h, 'Bleed') === "I don't have bleeds" && get(h, 'Finishing') === 'Coating: UV Gloss (both sides)', JSON.stringify(h));
  } },
  'calc-postcard.html':      { tables: ['COATINGS', 'BUNDLING', 'PERF_OPTS', 'CORNERS', 'FOLD_TYPES', 'ART_OPTS', 'BLEED_OPTS'], run(build) {
    const t = build({ qty: 500, sizeMode: 'preset', sizeLabel: '4×6', foldType: 'none', frontColor: 'color', backColor: 'bw', sides: 2, paper: { label: '14pt C2S' }, coating: 510, coatSides: 1, bundling: 750, perforation: 0, roundCorner: 0, artwork: 0.01, bleed: 0, proof: 0.01 }, {});
    ok('postcard: product, print and finishing', get(t, 'Product') === 'Postcard' && get(t, 'Print') === '2 sides · Front Full Color · Back Black & White' && /^Coating: UV Matte; Bundling: /.test(get(t, 'Finishing') || '') && /^Digital proof/.test(get(t, 'Proof') || ''), JSON.stringify(t));
  } },
  'calc-greeting-card.html': { tables: ['COATINGS', 'BUNDLING', 'PERF_OPTS', 'CORNERS', 'FOLD_TYPES', 'ART_OPTS', 'BLEED_OPTS', 'SIZE_PRESETS'], run(build) {
    const t = build({ qty: 50, sizeMode: 'preset', sizeLabel: '5×7', foldType: 'half', frontColor: 'color', backColor: 'color', sides: 2, paper: { label: '110lb Cover' }, coating: 0, bundling: 0, perforation: 0, roundCorner: 0, envelopes: false, artwork: 0.04, bleed: 0, proof: 0, canvaLink: 'https://canva.com/x' }, {});
    ok('greeting card: a Canva order names the link', get(t, 'Artwork') === 'I have a design in Canva' && get(t, 'Canva link') === 'https://canva.com/x', JSON.stringify(t));
  } },
  'calc-letterhead.html':    { tables: ['PERF_OPTS', 'ART_OPTS', 'BLEED_OPTS'], run(build) {
    const t = build({ qty: 1000, sizeMode: 'preset', sizeLabel: '8.5×11', frontColor: 'color', backColor: 'bw', sides: 1, paper: { label: '70lb Linen' }, perforation: 2000, perfDir: 'width', artwork: 0.01, bleed: 0, proof: 0 }, {});
    ok('letterhead: size without a fold; perforation as finishing', get(t, 'Size') === '8.5×11' && /^Perforation: .*\(width\)$/.test(get(t, 'Finishing') || '') && get(t, 'Print') === '1 side · Full Color', JSON.stringify(t));
  } },
  'calc-sticker.html':       { tables: ['COATINGS', 'ART_OPTS', 'BLEED_OPTS'], run(build) {
    const t = build({ qty: 200, sizeMode: 'custom', longEdge: 3, shortEdge: 2, frontColor: 'color', paper: { label: 'White Vinyl' }, coating: 750, artwork: 0.01, bleed: 0, proof: 0 }, {});
    ok('sticker: one side, custom size, coating', get(t, 'Product') === 'Stickers' && get(t, 'Size') === '3×2" custom' && get(t, 'Print') === '1 side · Full Color' && get(t, 'Finishing') === 'Coating: UV Gloss', JSON.stringify(t));
  } },
  'calc-preview-test.html':  { tables: ['COATINGS', 'BUNDLING', 'CORNERS', 'ART_OPTS', 'BLEED_OPTS'], run(build) {
    const t = build({ sizeLabel: 'Custom Size', customShort: 4, customLong: 4, sets: [{ qty: 500, pages: 12, name: 'Fall Program' }], insidePaper: { label: '100lb Gloss Text' }, insideColor: 'color', coverMode: 'same', coverPaper: { label: '100lb Gloss Cover' }, coverColor: 'color', twoStaple: false, coating: 0, bundling: 0, roundCorner: 0, vividPrint: false, artwork: 0.01, bleed: 0, proof: 0 }, { twoAuto: true });
    ok('saddle: custom size named, sets, staples from the auto rule, self-cover says so — never the cover stock', get(t, 'Size') === 'Custom 4×4"' && get(t, 'Sets') === '500 × 12 pages "Fall Program"' && get(t, 'Binding') === 'Saddle stitch · two staples' && get(t, 'Inside') === '100lb Gloss Text · Full Color' && get(t, 'Cover') === 'Self-cover (same stock as inside)' && get(t, 'Job name') === 'Fall Program', JSON.stringify(t));
  } },
  'calc-perfect-bound.html': { tables: ['COATINGS', 'BUNDLING', 'CORNERS', 'OUTFOLD_OPTS', 'PERF_OPTS', 'ART_OPTS', 'BLEED_OPTS'], run(build) {
    const t = build({ sizeLabel: '6×9', sets: [{ qty: 200, pages: 120 }], insidePaper: { label: '60lb Offset' }, insideColor: 'bw', coverMode: 'cover', coverPaper: { label: '12pt C1S' }, coverColor: 'color', coating: 750, bundling: 0, roundCorner: 0, outfold: 1000, perforation: 0, vividPrint: false, artwork: 0.01, bleed: 0, proof: 3.01, proofAddrSame: true }, {});
    ok('perfect bound: binding, inside/cover stock and colour, outfold in finishing, hardcopy to the order address', get(t, 'Binding') === 'Perfect bound' && get(t, 'Inside') === '60lb Offset · Black & White' && get(t, 'Cover') === '12pt C1S · Full Color' && /^Coating: UV Gloss; Outfold: 1 Fold-Out/.test(get(t, 'Finishing') || '') && get(t, 'Proof ship-to') === 'Same as the order ship-to', JSON.stringify(t));
  } },
  'calc-coupon-book.html':   { tables: ['COATINGS', 'BUNDLING', 'CORNERS', 'OUTFOLD_OPTS', 'PERF_OPTS', 'MAGNET_OPTS', 'BIND_STYLE_OPTS', 'ART_OPTS', 'BLEED_OPTS'], run(build) {
    const t = build({ sizeLabel: '3.5×8.5', sets: [{ qty: 1000, pages: 20 }], insidePaper: { label: '70lb Offset' }, insideColor: 'color', frontColor: 'color', backColor: 'bw', sidesPrinted: 'double', bindStyle: 'wraparound', coverMode: 'cover', coverPaper: { label: '12pt C1S' }, coverColor: 'color', coating: 0, bundling: 0, roundCorner: 0, outfold: 0, perforation: 2000, magnetBacker: 1, vividPrint: false, artwork: 0.01, bleed: 0, proof: 0 }, {});
    ok('coupon book: bind style by name, coupon sheets front/back, perforation and magnet in finishing', get(t, 'Binding') === 'Wraparound Cover with Perforation(s)' && get(t, 'Coupon sheets') === '70lb Offset · Front Full Color · Back Black & White' && /^Perforation: 1 Perforation Line; Magnetic Backer: /.test(get(t, 'Finishing') || ''), JSON.stringify(t));
  } },
};
for (const [f, spec] of Object.entries(CALCS)) {
  const short = f.replace(/^calc-|\.html$/g, '');
  let src;
  try { src = readFileSync(path.join(DIST, f), 'utf8'); } catch { ok(`${short}: compiled build present`, false); continue; }
  ok(`${short}: the metadata posts ticket: buildTicket(config, result)`, /ticket:\s*buildTicket\(config,\s*result\)/.test(src));
  const parts = [fnSrc(src, 'ppsFmtAddr'), fnSrc(src, 'buildAddons'), fnSrc(src, 'buildTicket')];
  ok(`${short}: buildTicket, buildAddons and ppsFmtAddr are in the shipped build`, parts.every(Boolean));
  if (!parts.every(Boolean)) continue;
  const tables = spec.tables.map(n => constSrc(src, n));
  if (tables.some(t => !t)) { ok(`${short}: option tables extractable`, false, spec.tables.filter((n, i) => !tables[i]).join(',')); continue; }
  let build;
  try { build = new Function('_CFG', tables.join('\n') + '\n' + parts.join('\n') + '\nreturn buildTicket;')({}); }
  catch (e) { ok(`${short}: buildTicket evaluates`, false, String(e.message)); continue; }
  try { spec.run(build); } catch (e) { ok(`${short}: sample configs run`, false, String(e.stack || e).slice(0, 300)); }
}

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> JOB TICKET FAILED' : '>>> JOB TICKET OK');
process.exit(failed ? 1 : 0);
