// Headless proof of the export engine. No browser, no UI.
//
//   node --experimental-strip-types test/export.test.mjs
//   (or: npm test)
//
// Writes out/spike.pdf so you can open it, and prints a preflight report.
// This is the file to re-run after ANY change to src/export/pdf.ts.

import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

import { exportPdf } from '../src/export/pdf.ts';
import { preflight } from '../src/export/preflight.ts';
import { newDoc, makeRect, makeEllipse, makeText, makeImage } from '../src/model/doc.ts';
import { productByKey } from '../src/model/products.ts';
import { inch } from '../src/model/units.ts';
import { cmyk, RICH_BLACK } from '../src/model/color.ts';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.join(HERE, '..');
const OUT = path.join(ROOT, 'out');

// --- minimal PNG encoder (dependency-free, so the test needs no fixtures) ---
function crc32(buf) {
  let c, crc = 0xffffffff;
  for (let n = 0; n < buf.length; n++) {
    c = (crc ^ buf[n]) & 0xff;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    crc = (crc >>> 8) ^ c;
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function chunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type, 'latin1'), data]);
  const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}
function makePng(w, h, pixel) {
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; ihdr[9] = 2; // 8-bit, truecolour RGB
  const raw = Buffer.alloc(h * (1 + w * 3));
  let o = 0;
  for (let y = 0; y < h; y++) {
    raw[o++] = 0; // filter: none
    for (let x = 0; x < w; x++) {
      const [r, g, b] = pixel(x, y);
      raw[o++] = r; raw[o++] = g; raw[o++] = b;
    }
  }
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(raw)),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

// A 200x200 gradient with a hard corner marker, so orientation is obvious.
const png = makePng(200, 200, (x, y) =>
  x < 24 && y < 24 ? [255, 0, 0] : [Math.round(x * 1.27), Math.round(y * 1.27), 140]);
const pngDataUrl = `data:image/png;base64,${png.toString('base64')}`;

const loadFont = async (id) =>
  new Uint8Array(fs.readFileSync(path.join(ROOT, 'public', 'fonts', `${id}.ttf`)));

// --- build a document that exercises every code path ------------------------
const product = productByKey('brochure-trifold-8.5x11');
const doc = newDoc(product);

const B = product.bleed;
// Full-bleed background: starts at 0,0 (the bleed box), not at the trim.
doc.pages[0].items.push(
  Object.assign(makeRect(0, 0, product.trimW + B * 2, product.trimH + B * 2, cmyk(0.08, 0, 0.02, 0)), { name: 'Bleed background' }),
);
doc.pages[0].items.push(
  Object.assign(makeRect(B + inch(0.4), B + inch(0.4), inch(2.8), inch(1.2), RICH_BLACK), { name: 'Rich black bar' }),
);
doc.pages[0].items.push(
  Object.assign(makeEllipse(B + inch(4.2), B + inch(1.0), inch(2.0), inch(2.0), cmyk(0, 0.85, 0.9, 0)), { name: 'Warm circle' }),
);
doc.pages[0].items.push(
  Object.assign(makeImage(B + inch(7.9), B + inch(0.5), inch(2.6), inch(2.6), pngDataUrl, 200, 200), { name: 'Photo', fit: 'fill' }),
);
const headline = makeText(B + inch(0.4), B + inch(2.1), inch(3.0), inch(1.4),
  'Press-ready by construction.');
Object.assign(headline, { name: 'Headline', size: 22, leading: 25, fontId: 'LiberationSans-Bold', color: cmyk(1, 0.72, 0, 0.12) });
doc.pages[0].items.push(headline);

const body = makeText(B + inch(0.4), B + inch(4.0), inch(3.0), inch(3.2),
  'This paragraph exists to exercise greedy word wrap, leading and CMYK text fill. '
  + 'It is deliberately long enough to run to several lines so the overset check has something to measure.');
Object.assign(body, { name: 'Body copy', size: 10, leading: 13 });
doc.pages[0].items.push(body);

const rotated = makeText(B + inch(4.4), B + inch(4.4), inch(2.4), inch(0.5), 'rotated 15° + tracked');
Object.assign(rotated, { name: 'Rotated text', rot: 15, tracking: 0.6, size: 12, color: cmyk(0, 0, 0, 1) });
doc.pages[0].items.push(rotated);

doc.pages[1].items.push(
  Object.assign(makeRect(B, B, product.trimW, product.trimH, cmyk(0, 0, 0, 0.06)), { name: 'Back tint' }),
);
const back = makeText(B + inch(0.5), B + inch(0.5), inch(4), inch(1), 'Back panel');
Object.assign(back, { name: 'Back headline', size: 18, fontId: 'LiberationSerif-Regular' });
doc.pages[1].items.push(back);

// --- export + preflight -----------------------------------------------------
const t0 = Date.now();
const { bytes, fontsUsed, warnings } = await exportPdf(doc, {
  loadFont,
  cropMarks: true,
  outputIntent: { identifier: 'CGATS TR 001', info: 'U.S. Web Coated (SWOP) v2' },
  title: 'PPS Designer export spike',
});
const ms = Date.now() - t0;

fs.mkdirSync(OUT, { recursive: true });
fs.writeFileSync(path.join(OUT, 'spike.pdf'), bytes);

const report = await preflight(bytes, doc);

// --- report -----------------------------------------------------------------
const line = (s = '') => console.log(s);
line();
line('='.repeat(66));
line(`  PPS Designer — export spike     (${ms} ms, ${(bytes.length / 1024).toFixed(1)} KB)`);
line('='.repeat(66));
line(`  Product : ${product.label}`);
line(`  Output  : out/spike.pdf`);
line();
for (const [k, v] of Object.entries(report.facts)) line(`  ${k.padEnd(20)} ${v}`);
line();
line(`  Fonts requested by doc : ${fontsUsed.join(', ')}`);

if (warnings.length) {
  line();
  line('  EXPORT NOTES');
  for (const w of warnings) line(`    · ${w}`);
}
if (report.warnings.length) {
  line();
  line('  PREFLIGHT WARNINGS');
  for (const w of report.warnings) line(`    · ${w}`);
}
if (report.errors.length) {
  line();
  line('  PREFLIGHT ERRORS');
  for (const e of report.errors) line(`    ✗ ${e}`);
}
line();

// --- assertions -------------------------------------------------------------
const checks = [
  ['reloads and passes preflight', report.ok],
  ['page count matches', report.facts['Pages'] === String(doc.pages.length)],
  ['fonts embedded', report.facts['Fonts embedded'] !== '(none)'],
  ['all embedded fonts are subset', (() => {
    const m = /^(\d+) of (\d+)/.exec(report.facts['Fonts subset'] || '');
    return !!m && m[1] === m[2] && Number(m[2]) > 0;
  })()],
  ['image embedded', Number(report.facts['Embedded images']) > 0],
  ['CMYK reached the content stream', /CMYK/.test(report.facts['Colour operators'] || '')],
];

let failed = 0;
line('  RESULT');
for (const [name, pass] of checks) {
  line(`    ${pass ? '✓' : '✗'} ${name}`);
  if (!pass) failed++;
}
line();
line('='.repeat(66));
line();
process.exit(failed === 0 ? 0 : 1);
