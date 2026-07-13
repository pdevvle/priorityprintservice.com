// Extract the live imp functions from the calculator HTMLs and the tool, then
// sweep sizes to prove they can never disagree.
import fs from 'fs';
const REPO = new URL("../..", import.meta.url).pathname;
const read = f => fs.readFileSync(`${REPO}/${f}`, 'utf8');

function extractSrc(src, name) {
  const i = src.indexOf(`function ${name}(`);
  if (i < 0) throw new Error(`${name} not found`);
  let depth = 0, j = src.indexOf('{', i);
  for (let k = j; k < src.length; k++) {
    if (src[k] === '{') depth++;
    else if (src[k] === '}' && --depth === 0) { j = k + 1; break; }
  }
  return src.slice(i, j);
}
function extractFn(src, name) { return eval(`(${extractSrc(src, name)})`); }

const tool = read('imposition-tool.html');
const toolFlat = extractFn(tool, 'flatImp');
const toolGrid = extractFn(tool, 'flatGrid');
// flatImp in the tool calls flatGrid — rebuild with closure
const toolFlatImp = (l, s, L) => { const LL = L || 18.5; return Math.max(toolGrid(l, s, LL), toolGrid(s, l, LL)); };
const stickerConst = tool.match(/const STICKER = \{[^}]+\};/)[0];
const toolSticker = eval(`(() => { ${stickerConst}\n${extractSrc(tool, 'stickerGrid')}\n${extractSrc(tool, 'stickerImp')}\nreturn stickerImp; })()`);

const calcs = [
  ['calc-brochure.html', 'calcBrochureImp'],
  ['calc-postcard.html', 'calcPostcardImp'],
  ['calc-letterhead.html', 'calcLetterheadImp'],
  ['calc-greeting-card.html', 'calcGreetingImp'],
];

let checks = 0, fails = 0;
for (const [file, fn] of calcs) {
  const live = extractFn(read(file), fn);
  for (let l = 2; l <= 27.5; l += 0.25) {
    for (let s = 2; s <= Math.min(l, 13); s += 0.25) {
      for (const L of [18.5, 27]) {
        checks++;
        const a = live(l, s, L), b = toolFlatImp(l, s, L);
        if (a !== b) { fails++; if (fails < 8) console.log(`MISMATCH ${fn}(${l},${s},${L}): live=${a} tool=${b}`); }
      }
    }
  }
}
const liveSticker = extractFn(read('calc-sticker.html'), 'calcStickerImp');
for (let l = 1; l <= 17.5; l += 0.25) {
  for (let s = 1; s <= Math.min(l, 11.5); s += 0.25) {
    checks++;
    const a = liveSticker(l, s), b = toolSticker(l, s);
    if (a !== b) { fails++; if (fails < 8) console.log(`MISMATCH sticker(${l},${s}): live=${a} tool=${b}`); }
  }
}
console.log(`parity: ${checks} checks, ${fails} mismatches ${fails === 0 ? '— PASS ✓' : '— FAIL ✗'}`);

// Physical-fit audit: every priced count must be placeable by bestGridForCount
const SHEETS = { f: { usableLong: 18.5, usableShort: 12.5 }, o: { usableLong: 27, usableShort: 12.5 } };
const bg = extractFn(tool, 'bestGridForCount');
const fits = extractFn(tool, 'fits');
globalThis.fits = fits; globalThis.GUTTERS = [0.25, 0.125, 0]; globalThis.EPS = 1e-6;
let unplaceable = [];
for (let l = 2; l <= 27; l += 0.25) {
  for (let s = 2; s <= Math.min(l, 12.5); s += 0.25) {
    let imp = toolFlatImp(l, s), sheet = SHEETS.f;
    if (imp < 1) { imp = toolFlatImp(l, s, 27); sheet = SHEETS.o; }
    if (imp < 1) continue;
    if (!bg(Math.round(imp), l, s, sheet)) unplaceable.push(`${l}x${s} priced ${imp}-up`);
  }
}
console.log(`physical-fit audit: ${unplaceable.length} priced layouts unplaceable`);
unplaceable.slice(0, 12).forEach(u => console.log('  cannot place: ' + u));
