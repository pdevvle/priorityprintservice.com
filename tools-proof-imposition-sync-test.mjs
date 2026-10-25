/* The proofer and the imposition tool must agree about a file.
   docs/PROOFER_IMPOSITION_SYNC.md is the analysis this gates.

   The proofer (proof-ui-draft.html) is where the customer approves a file; the
   imposition tool (imposition-tool.html, its own branch and session) is where
   prepress turns that file into press sheets. Each has its own PDF reader and
   its own idea of what is wrong with a file, and nothing tied them together. This
   drives the REAL functions of both on the same files and checks the promises
   that have to hold across the hand-off:

     1. A file the proofer ships untouched — the approval bound to its bytes —
        imposes without an operator override.
     2. Every font the imposition tool refuses, the proofer has told the customer about.
     3. Every spot colour the imposition tool names, the proofer names.
     4. The proofer does not say annotations "do not print" when its own print
        file, and the imposition tool, both print them.
     5. A link alone (every Canva or InDesign export with a URL) does not cost
        the customer their vector file.
     6. The imposition tool can read the proofer's manifest.
     7. The imposition tool reads the proofer's print file at the ordered trim.

   First run, 2026-10-25, proofer 945cc78 against imposition v1.61 (223cc0f):
   every one of the seven fails. Each failure is a real disagreement, not a test
   artefact — see the doc.

   Run: needs the proofer harness's vendor dir (tools-proof-vendor.mjs) and the
   imposition tool's HTML. The imposition tool lives on its own branch, so point at it:
     git show origin/claude/imposition-tool-google-drive-185wyl:imposition-tool.html > /tmp/imp/imposition-tool.html
     PPS_IMPOSE_HTML=/tmp/imp/imposition-tool.html PPS_PROOF_VENDOR_DIR=… PPS_DEPS_DIR=… node tools-proof-imposition-sync-test.mjs
   The imposition tool loads React, Babel, pdf.js and pdf-lib from unpkg/cdnjs. */
import { createRequire } from 'module';
import { spawn } from 'child_process';
import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const REPO = path.dirname(fileURLToPath(import.meta.url));
const DEPS = process.env.PPS_DEPS_DIR || path.join(REPO, 'node_modules');
const IMPOSE = path.resolve(process.env.PPS_IMPOSE_HTML || path.join(REPO, 'imposition-tool.html'));
const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const require = createRequire(DEPS.replace(/\/?$/, '/'));
const { PDFDocument, PDFName, PDFString, rgb } = require('pdf-lib');

let fails = 0;
const ck = (name, ok, detail) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '\n       ' + detail : '')); if (!ok) fails++; };

/* Four bleed-sized pages for a 5.5" x 8.5" saddle book. Page 1 draws its art
   through a Form XObject — how InDesign and Illustrator place artwork — and the
   form uses a font that is only NAMED, and a PANTONE separation. Page 2 uses
   Helvetica, named only, which every RIP carries. withAnnots adds a hyperlink
   on page 3 and a visible stamp on page 4. */
async function fixture(withAnnots){
  const doc = await PDFDocument.create();
  const ctx = doc.context;
  const W = 5.75 * 72, H = 8.75 * 72;
  const fd = ctx.register(ctx.obj({ Type:'FontDescriptor', FontName:'ArialMT', Flags:32,
    FontBBox:[-665, -325, 2000, 1006], ItalicAngle:0, Ascent:905, Descent:-212, CapHeight:716, StemV:80 }));
  const arial = ctx.register(ctx.obj({ Type:'Font', Subtype:'TrueType', BaseFont:'ArialMT', FirstChar:32, LastChar:126,
    Widths:Array(95).fill(600), FontDescriptor:fd, Encoding:'WinAnsiEncoding' }));
  const tint = ctx.register(ctx.obj({ FunctionType:2, Domain:[0, 1], C0:[0, 0, 0, 0], C1:[0, 0.8, 0.6, 0], N:1 }));
  const sep = ctx.register(ctx.obj([PDFName.of('Separation'), PDFName.of('PANTONE 185 C'), PDFName.of('DeviceCMYK'), tint]));
  const form = ctx.register(ctx.stream('q /CS1 cs 1 scn 36 36 200 120 re f Q BT /F1 28 Tf 0 g 40 300 Td (Hello Arial) Tj ET',
    { Type:'XObject', Subtype:'Form', BBox:[0, 0, W, H],
      Resources:ctx.obj({ Font:ctx.obj({ F1:arial }), ColorSpace:ctx.obj({ CS1:sep }) }) }));
  const p1 = doc.addPage([W, H]);
  p1.node.set(PDFName.of('Resources'), ctx.obj({ XObject:ctx.obj({ Fx0:form }) }));
  p1.node.set(PDFName.of('Contents'), ctx.register(ctx.stream('q /Fx0 Do Q')));
  const helv = ctx.register(ctx.obj({ Type:'Font', Subtype:'Type1', BaseFont:'Helvetica', Encoding:'WinAnsiEncoding' }));
  const p2 = doc.addPage([W, H]);
  p2.node.set(PDFName.of('Resources'), ctx.obj({ Font:ctx.obj({ F1:helv }) }));
  p2.node.set(PDFName.of('Contents'), ctx.register(ctx.stream('BT /F1 28 Tf 0 g 40 300 Td (Hello Helvetica) Tj ET')));
  const p3 = doc.addPage([W, H]);
  p3.drawRectangle({ x:40, y:40, width:200, height:100, color:rgb(0.2, 0.4, 0.8) });
  const p4 = doc.addPage([W, H]);
  p4.drawRectangle({ x:40, y:40, width:200, height:100, color:rgb(0.8, 0.4, 0.2) });
  if (withAnnots){
    const link = ctx.register(ctx.obj({ Type:'Annot', Subtype:'Link', Rect:[40, 40, 240, 140], Border:[0, 0, 0],
      A:ctx.obj({ S:'URI', URI:PDFString.of('https://example.com') }) }));
    p3.node.set(PDFName.of('Annots'), ctx.obj([link]));
    const ap = ctx.register(ctx.stream('0 0 0 rg 0 0 144 72 re f', { Type:'XObject', Subtype:'Form', BBox:[0, 0, 144, 72] }));
    const stamp = ctx.register(ctx.obj({ Type:'Annot', Subtype:'Stamp', Rect:[100, 300, 244, 372], F:4, Name:'Approved', AP:ctx.obj({ N:ap }) }));
    p4.node.set(PDFName.of('Annots'), ctx.obj([stamp]));
  }
  return await doc.save({ useObjectStreams:false });
}
// Only a hyperlink, nothing else: the file is otherwise press-ready.
async function linkOnly(){
  const doc = await PDFDocument.create();
  for (let i = 0; i < 4; i++){
    const p = doc.addPage([5.75 * 72, 8.75 * 72]);
    p.drawRectangle({ x:40, y:40, width:200, height:100, color:rgb(0.2, 0.5, 0.3) });
    if (i === 3) p.node.set(PDFName.of('Annots'), doc.context.obj([doc.context.register(doc.context.obj({
      Type:'Annot', Subtype:'Link', Rect:[40, 40, 240, 140], Border:[0, 0, 0],
      A:doc.context.obj({ S:'URI', URI:PDFString.of('https://example.com') }) }))]));
  }
  return await doc.save({ useObjectStreams:false });
}

if (!fs.existsSync(IMPOSE)){ console.log('FAIL no imposition tool at ' + IMPOSE + ' (set PPS_IMPOSE_HTML)'); process.exit(1); }
const A = await fixture(true), B = await fixture(false), L = await linkOnly();

const proofSrv = spawn('node', ['tools-proof-serve.mjs'], { cwd:REPO, stdio:'ignore', env:process.env });
const impDir = path.dirname(IMPOSE), impName = path.basename(IMPOSE);
const impSrv = http.createServer((q, r) => {
  const f = path.join(impDir, decodeURIComponent(q.url.split('?')[0]));
  if (!f.startsWith(impDir) || !fs.existsSync(f) || fs.statSync(f).isDirectory()){ r.writeHead(404); return r.end(); }
  r.writeHead(200, { 'content-type': f.endsWith('.html') ? 'text/html' : 'application/octet-stream' });
  fs.createReadStream(f).pipe(r);
}).listen(8140, '127.0.0.1');
await new Promise(r => setTimeout(r, 1200));

const { chromium } = await import(PW + '/index.mjs');
const browser = await chromium.launch({ executablePath:process.env.PPS_CHROME || '/opt/pw-browsers/chromium' });
try {
  // ── the proofer, standalone, sized to the job ──
  const ctxP = await browser.newContext({ viewport:{ width:1400, height:950 } });
  await ctxP.addInitScript(() => { window.PPS_LIB_BASE = '/vendor/'; });
  const p = await ctxP.newPage();
  p.on('dialog', d => d.dismiss());
  const proof = async (size, bytes, name) => {
    await p.goto('http://127.0.0.1:8137/proof-ui-draft.html?size=' + size + '&pages=4', { waitUntil:'domcontentloaded' });
    await p.waitForTimeout(1000);
    return p.evaluate(async ({ arr, name }) => {
      await ensureLibs();
      uploads.clear(); cache.clear();
      MODEL.pages.forEach(pg => state.perPage[pg.n] = { behavior:'crop', anchor:'Center', scale:100, rot:'0°' });
      if (arr){
        await ingestPdf(new File([new Uint8Array(arr)], name, { type:'application/pdf' }), 1);
      } else {
        // An image job: the print file is rendered, never the customer's bytes.
        const c = document.createElement('canvas'); c.width = 600; c.height = 800;
        const x = c.getContext('2d'); x.fillStyle = '#c33'; x.fillRect(0, 0, 600, 800);
        const blob = await new Promise(r => c.toBlob(r, 'image/png'));
        await loadArt(1, new File([blob], 'art.png', { type:'image/png' }));
      }
      renderAll();
      const checks = Object.fromEntries(runFileChecks().map(c => [c.id, { state:c.state, text:c.text || '' }]));
      const untouched = untouchedOriginal(MODEL.pages.map(pg => pg.n));
      // The print render of page 4, as the package draws it: how dark is the stamp?
      const r = await renderPrintPage(4);
      const cv = r.canvas, k = cv.width / (cv.width / 300 * 72);
      const box = cv.getContext('2d').getImageData(Math.round(130 * k), Math.round((8.75 * 72 - 360) * k), Math.round(80 * k), Math.round(45 * k)).data;
      let dark = 0, n = 0; for (let i = 0; i < box.length; i += 4){ n++; if (box[i] + box[i + 1] + box[i + 2] < 120) dark++; }
      cv.width = 0;
      const pkg = await buildPackage();
      const man = pkg.files.find(f => f.name === 'MANIFEST.txt');
      const pr = pkg.files.find(f => f.name === 'PRINT_READY.pdf');
      return { checks, untouched:!!untouched.bytes, why:untouched.why || '', stampDark:dark / n,
               manifest:await man.blob.text(), printReady:Array.from(new Uint8Array(await pr.blob.arrayBuffer())) };
    }, { arr:bytes ? Array.from(bytes) : null, name });
  };
  const pA = await proof('5.5x8.5', A, 'with-annots.pdf');
  const pB = await proof('5.5x8.5', B, 'plain.pdf');
  const pL = await proof('5.5x8.5', L, 'link-only.pdf');
  const pQ = await proof('4.25x5.5', null, null);

  // ── the imposition tool ──
  const q = await browser.newPage();
  await q.goto('http://127.0.0.1:8140/' + impName, { waitUntil:'domcontentloaded' });
  await q.waitForFunction(() => window.__ppsImpose && window.PDFLib && window.pdfjsLib, null, { timeout:90000 });
  const impose = (bytes, w, h) => q.evaluate(async ({ arr, w, h }) => {
    const I = window.__ppsImpose, bytes = new Uint8Array(arr);
    const doc = await I.loadPdf(bytes.slice(), 'file');
    const notes = (doc.__ppsPressNotes || []).join('\n');
    let refused = null;
    try { await I.imposePdf(bytes.slice(), null, { calc:'saddle', trimLong:w, trimShort:h, sides:2, qty:10, sheetFlip:'short', backRotate:'auto', marks:true }); }
    catch (e){ refused = e.message; }
    const t = I.detectTrim(await I.loadPdf(bytes.slice(), 'file'), 0.125);
    return { notes, refused, trim:{ w:t.w, h:t.h, method:t.method } };
  }, { arr:Array.from(bytes), w, h });
  const iA = await impose(A, 5.5, 8.5), iB = await impose(B, 5.5, 8.5);
  const iQ = await impose(new Uint8Array(pQ.printReady), 4.25, 5.5);
  const parsed = await q.evaluate(t => window.__ppsImpose.parseManifest(t), pB.manifest);

  // 1. Untouched means imposable.
  ck('1. a file the proofer ships untouched imposes without an override',
     !(pB.untouched && iB.refused),
     pB.untouched ? 'proofer: shipped untouched; imposition: ' + (iB.refused ? 'REFUSED — ' + iB.refused.slice(0, 90) + '…' : 'imposed') : 'proofer rendered it: ' + pB.why);
  // 2. Fonts: what imposition refuses, the proofer named.
  const refusedFonts = ((iA.refused || '').match(/FONTS NOT EMBEDDED in [^:]+: ([^.]+)\./) || [, ''])[1].split(/,\s*/).filter(Boolean);
  const named = pA.checks.fonts.text;
  ck('2. every font the imposition tool refuses is one the proofer flagged',
     refusedFonts.length > 0 && refusedFonts.every(f => named.includes(f)),
     'imposition refuses: ' + (refusedFonts.join(', ') || '(none)') + ' · proofer says: ' + pA.checks.fonts.state + ' — ' + named.slice(0, 80));
  ck('2b. a standard-14 font (every RIP has it) is not a proofer failure',
     !(pA.checks.fonts.state === 'fail' && /Helvetica/.test(named) && !refusedFonts.some(f => named.includes(f))),
     'proofer: ' + pA.checks.fonts.state + ' — ' + named.slice(0, 80));
  // 3. Spots.
  const impSpot = /PANTONE 185 C/.test(iA.notes);
  ck('3. a spot colour the imposition tool names, the proofer names', !impSpot || /PANTONE 185 C/.test(pA.checks.spots.text),
     'imposition names PANTONE 185 C: ' + impSpot + ' · proofer: ' + pA.checks.spots.state + ' — ' + pA.checks.spots.text.slice(0, 60));
  // 4. Annotations print.
  const saysNoPrint = /do not print/i.test(pA.checks.interactive.text);
  const impDraws = /drawn into the page/.test(iA.notes);
  ck('4. the proofer does not say annotations "do not print" when they print',
     !(saysNoPrint && (pA.stampDark > 0.5 || impDraws)),
     'proofer: "' + pA.checks.interactive.text.slice(0, 70) + '…" · its own print render: stamp ' + Math.round(pA.stampDark * 100) +
     '% dark · imposition draws it: ' + impDraws);
  // 5. A link alone keeps the vector file.
  ck('5. a hyperlink alone does not stop the customer\'s file shipping untouched', pL.untouched,
     pL.untouched ? '' : 'proofer rendered the whole book to 300 DPI JPEGs because: ' + pL.why);
  // 6. Manifest.
  ck('6. the imposition tool reads the proofer\'s manifest (product, trim)',
     parsed && parsed.calc === 'saddle' && Math.abs((parsed.rawW || 0) - 5.5) < 0.01 && Math.abs((parsed.rawH || 0) - 8.5) < 0.01,
     'parseManifest → ' + JSON.stringify(parsed));
  // 7. Trim.
  ck('7. the imposition tool reads a proofer print file at the ordered trim (4.25" x 5.5")',
     Math.abs(iQ.trim.w - 4.25) < 0.01 && Math.abs(iQ.trim.h - 5.5) < 0.01,
     'detectTrim → ' + iQ.trim.w + '" x ' + iQ.trim.h + '" (' + iQ.trim.method + ')');
} finally {
  await browser.close(); proofSrv.kill(); impSrv.close();
}
console.log(fails ? fails + ' failed' : 'all passed');
process.exit(fails ? 1 : 0);
