/* The proofer and the imposition tool must agree about a file — and where they
   do not, the imposition tool decides (owner, 2026-10-25).
   docs/PROOFER_IMPOSITION_SYNC.md is the analysis; docs/IMPOSITION_SESSION_BRIEF.md
   is what the imposition session was asked for.

   The proofer (proof-ui-draft.html) is where the customer approves a file; the
   imposition tool (imposition-tool.html, its own branch and session) is where
   prepress turns that file into press sheets. This drives the REAL functions of
   both on the same files and checks the promises that must hold across the
   hand-off:

     P. The PDF readers the proofer copied from the imposition tool are still
        the imposition tool's, function for function.
     1. A file the proofer ships untouched — the approval bound to its bytes —
        imposes without an operator override. A file the imposition tool refuses
        (encrypted, a named-only font) is never shipped untouched.
     2. Every font the imposition tool refuses, the proofer has named; a
        standard-14 font, which it only notes, is not a proofer failure.
     3. Every spot colour the imposition tool names, the proofer names.
     4. Annotations: what the imposition tool draws into the page, the proofer
        says prints, and its own print render shows; a link alone is nothing.
     5. What the imposition tool handles (a link, a stamp) does not cost the
        customer their vector file.
     6. The imposition tool reads the proofer's manifest.
     7. The imposition tool reads the proofer's rendered print file at the
        ordered trim.

   First run, 2026-10-25: proofer 945cc78 against imposition v1.61 (223cc0f)
   failed all seven promises. The proofer was then brought into line (5885db4's
   successor); everything here should pass against v1.61.

   Run: needs the proofer harness's vendor dir (tools-proof-vendor.mjs) and the
   imposition tool's HTML. The imposition tool lives on its own branch, so point at it:
     git show origin/claude/imposition-tool-google-drive-185wyl:imposition-tool.html > /tmp/imp/imposition-tool.html
     PPS_IMPOSE_HTML=/tmp/imp/imposition-tool.html PPS_PROOF_VENDOR_DIR=… PPS_DEPS_DIR=… node tools-proof-imposition-sync-test.mjs
   The imposition tool loads React, Babel, pdf.js and pdf-lib from unpkg/cdnjs. */
import { createRequire } from 'module';
import { spawn } from 'child_process';
import crypto from 'crypto';
import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const REPO = path.dirname(fileURLToPath(import.meta.url));
const DEPS = process.env.PPS_DEPS_DIR || path.join(REPO, 'node_modules');
const IMPOSE = path.resolve(process.env.PPS_IMPOSE_HTML || path.join(REPO, 'imposition-tool.html'));
const PROOFER = path.join(REPO, process.env.PPS_PROOF_PATH || 'proof-ui-draft.html');
const PW = process.env.PPS_PLAYWRIGHT || '/opt/node22/lib/node_modules/playwright';
const require = createRequire(DEPS.replace(/\/?$/, '/'));
const { PDFDocument, PDFName, PDFString, rgb } = require('pdf-lib');

let fails = 0;
const ck = (name, ok, detail) => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '\n       ' + detail : '')); if (!ok) fails++; };

// ── P. the copied readers ────────────────────────────────────────────────────
// A top-level `function NAME(` by brace matching, skipping strings and comments.
function extractFunction(src, name){
  const m = new RegExp('(^|\\n)((?:async\\s+)?function\\s+' + name + '\\s*\\()').exec(src);
  if (!m) return null;
  const start = m.index + m[1].length;
  let i = src.indexOf('{', start), depth = 0;
  for (; i < src.length; i++){
    const c = src[i], d = src[i + 1];
    if (c === '/' && d === '/'){ i = src.indexOf('\n', i); continue; }
    if (c === '/' && d === '*'){ i = src.indexOf('*/', i + 2) + 1; continue; }
    if (c === '"' || c === "'" || c === '`'){ for (i++; i < src.length && src[i] !== c; i++) if (src[i] === '\\') i++; continue; }
    if (c === '{') depth++;
    else if (c === '}' && --depth === 0) return src.slice(start, i + 1);
  }
  return null;
}
const extractConst = (src, name) => (new RegExp('(^|\\n)(const\\s+' + name + '\\s*=[^\\n]*;)').exec(src) || [])[2] || null;
// Whitespace and comments are not drift; code is.
const normalise = code => code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[\s;{}(),])\/\/[^\n]*/g, '$1').replace(/\s+/g, ' ').trim();
const sha = s => crypto.createHash('sha256').update(s).digest('hex').slice(0, 12);

if (!fs.existsSync(IMPOSE)){ console.log('FAIL no imposition tool at ' + IMPOSE + ' (set PPS_IMPOSE_HTML)'); process.exit(1); }
{
  const imp = fs.readFileSync(IMPOSE, 'utf8'), prf = fs.readFileSync(PROOFER, 'utf8');
  const block = (prf.match(/\/\* pps-imposition-copy:begin[\s\S]*?pps-imposition-copy:end \*\//) || [''])[0];
  ck('P. the proofer carries a marked block copied from the imposition tool', block.length > 0);
  for (const f of ['asDict', 'pageResources', 'getPageBox', 'inspectFonts']){
    const a = extractFunction(imp, f), b = extractFunction(block, f);
    ck('P. ' + f + '() is the imposition tool\'s, unchanged', a && b && normalise(a) === normalise(b),
       !a ? 'missing in the imposition tool' : !b ? 'missing in the proofer' : 'imposition ' + sha(normalise(a)) + ' · proofer ' + sha(normalise(b)));
  }
  const a = extractConst(imp, 'STD14'), b = extractConst(block, 'STD14');
  ck('P. STD14 is the imposition tool\'s', a && a === b, (a || '(none)') + ' · ' + (b || '(none)'));
}

// ── fixtures ─────────────────────────────────────────────────────────────────
/* Four bleed-sized pages for a 5.5" x 8.5" saddle book.
   namedFont: page 1 draws its art through a Form XObject — how InDesign and
   Illustrator place artwork — using a font that is only NAMED, and a PANTONE.
   helvetica: page 2 uses Helvetica, named only, which every RIP carries.
   link / stamp: a hyperlink on page 3, a visible stamp on page 4. */
async function fixture({ namedFont, helvetica, link, stamp }){
  const doc = await PDFDocument.create();
  const ctx = doc.context;
  const W = 5.75 * 72, H = 8.75 * 72;
  const p1 = doc.addPage([W, H]);
  if (namedFont){
    const fd = ctx.register(ctx.obj({ Type:'FontDescriptor', FontName:'ArialMT', Flags:32,
      FontBBox:[-665, -325, 2000, 1006], ItalicAngle:0, Ascent:905, Descent:-212, CapHeight:716, StemV:80 }));
    const arial = ctx.register(ctx.obj({ Type:'Font', Subtype:'TrueType', BaseFont:'ArialMT', FirstChar:32, LastChar:126,
      Widths:Array(95).fill(600), FontDescriptor:fd, Encoding:'WinAnsiEncoding' }));
    const tint = ctx.register(ctx.obj({ FunctionType:2, Domain:[0, 1], C0:[0, 0, 0, 0], C1:[0, 0.8, 0.6, 0], N:1 }));
    const sep = ctx.register(ctx.obj([PDFName.of('Separation'), PDFName.of('PANTONE 185 C'), PDFName.of('DeviceCMYK'), tint]));
    const form = ctx.register(ctx.stream('q /CS1 cs 1 scn 36 36 200 120 re f Q BT /F1 28 Tf 0 g 40 300 Td (Hello Arial) Tj ET',
      { Type:'XObject', Subtype:'Form', BBox:[0, 0, W, H],
        Resources:ctx.obj({ Font:ctx.obj({ F1:arial }), ColorSpace:ctx.obj({ CS1:sep }) }) }));
    p1.node.set(PDFName.of('Resources'), ctx.obj({ XObject:ctx.obj({ Fx0:form }) }));
    p1.node.set(PDFName.of('Contents'), ctx.register(ctx.stream('q /Fx0 Do Q')));
  } else {
    p1.drawRectangle({ x:40, y:40, width:200, height:100, color:rgb(0.3, 0.6, 0.3) });
  }
  const p2 = doc.addPage([W, H]);
  if (helvetica){
    const helv = ctx.register(ctx.obj({ Type:'Font', Subtype:'Type1', BaseFont:'Helvetica', Encoding:'WinAnsiEncoding' }));
    p2.node.set(PDFName.of('Resources'), ctx.obj({ Font:ctx.obj({ F1:helv }) }));
    p2.node.set(PDFName.of('Contents'), ctx.register(ctx.stream('BT /F1 28 Tf 0 g 40 300 Td (Hello Helvetica) Tj ET')));
  } else p2.drawRectangle({ x:40, y:40, width:200, height:100, color:rgb(0.6, 0.3, 0.3) });
  const p3 = doc.addPage([W, H]);
  p3.drawRectangle({ x:40, y:40, width:200, height:100, color:rgb(0.2, 0.4, 0.8) });
  const p4 = doc.addPage([W, H]);
  p4.drawRectangle({ x:40, y:40, width:200, height:100, color:rgb(0.8, 0.4, 0.2) });
  if (link){
    const a = ctx.register(ctx.obj({ Type:'Annot', Subtype:'Link', Rect:[40, 40, 240, 140], Border:[0, 0, 0],
      A:ctx.obj({ S:'URI', URI:PDFString.of('https://example.com') }) }));
    p3.node.set(PDFName.of('Annots'), ctx.obj([a]));
  }
  if (stamp){
    const ap = ctx.register(ctx.stream('0 0 0 rg 0 0 144 72 re f', { Type:'XObject', Subtype:'Form', BBox:[0, 0, 144, 72] }));
    const a = ctx.register(ctx.obj({ Type:'Annot', Subtype:'Stamp', Rect:[100, 300, 244, 372], F:4, Name:'Approved', AP:ctx.obj({ N:ap }) }));
    p4.node.set(PDFName.of('Annots'), ctx.obj([a]));
  }
  return await doc.save({ useObjectStreams:false });
}

/* Owner-password-only (Standard security handler, V1 R2, RC4 40-bit): opens
   with no password in every viewer, pdf.js included, but its streams are
   encrypted on disk — the shape the imposition tool refuses at load. Built by
   hand because nothing here can encrypt a PDF. */
function encryptedPdf(pages = 4, w = 414, h = 630){
  const PAD = Buffer.from('28bf4e5e4e758a4164004e56fffa01082e2e00b6d0683e802f0ca9fe6453697a', 'hex');
  const rc4 = (key, data) => {
    const s = [...Array(256).keys()]; let j = 0;
    for (let i = 0; i < 256; i++){ j = (j + s[i] + key[i % key.length]) & 255; [s[i], s[j]] = [s[j], s[i]]; }
    const out = Buffer.alloc(data.length); let i = 0; j = 0;
    for (let k = 0; k < data.length; k++){ i = (i + 1) & 255; j = (j + s[i]) & 255; [s[i], s[j]] = [s[j], s[i]]; out[k] = data[k] ^ s[(s[i] + s[j]) & 255]; }
    return out;
  };
  const md5 = b => crypto.createHash('md5').update(b).digest();
  const O = rc4(md5(Buffer.concat([Buffer.from('owner-secret'), PAD]).subarray(0, 32)).subarray(0, 5), PAD);
  const P = -44, Pb = Buffer.alloc(4); Pb.writeInt32LE(P);
  const ID = crypto.randomBytes(16);
  const key = md5(Buffer.concat([PAD, O, Pb, ID])).subarray(0, 5);
  const U = rc4(key, PAD);
  const objKey = num => md5(Buffer.concat([key, Buffer.from([num & 255, (num >> 8) & 255, (num >> 16) & 255, 0, 0])])).subarray(0, 10);
  const objs = [], kids = [];
  objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
  let n = 3;
  const encNum = 3 + pages * 2;
  for (let p = 0; p < pages; p++){
    const pageNum = n++, contNum = n++;
    kids.push(pageNum + ' 0 R');
    objs[pageNum] = `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${w} ${h}] /Contents ${contNum} 0 R /Resources << >> >>`;
    const enc = rc4(objKey(contNum), Buffer.from(`0.2 0.4 0.8 rg 40 40 200 100 re f 0.8 0.3 0.2 rg 60 ${300 + p * 40} 150 30 re f`));
    objs[contNum] = Buffer.concat([Buffer.from(`<< /Length ${enc.length} >>\nstream\n`, 'latin1'), enc, Buffer.from('\nendstream', 'latin1')]);
  }
  objs[2] = `<< /Type /Pages /Kids [${kids.join(' ')}] /Count ${pages} >>`;
  objs[encNum] = `<< /Filter /Standard /V 1 /R 2 /O <${O.toString('hex')}> /U <${U.toString('hex')}> /P ${P} >>`;
  const parts = [Buffer.from('%PDF-1.4\n%\xe2\xe3\xcf\xd3\n', 'latin1')], offsets = [];
  let len = parts[0].length;
  for (let i = 1; i < objs.length; i++){
    offsets[i] = len;
    const body = Buffer.isBuffer(objs[i]) ? objs[i] : Buffer.from(objs[i], 'latin1');
    const chunk = Buffer.concat([Buffer.from(`${i} 0 obj\n`, 'latin1'), body, Buffer.from('\nendobj\n', 'latin1')]);
    parts.push(chunk); len += chunk.length;
  }
  const xref = ['xref', `0 ${objs.length}`, '0000000000 65535 f '];
  for (let i = 1; i < objs.length; i++) xref.push(String(offsets[i]).padStart(10, '0') + ' 00000 n ');
  const id = ID.toString('hex');
  parts.push(Buffer.from(xref.join('\n') + `\ntrailer\n<< /Size ${objs.length} /Root 1 0 R /Encrypt ${encNum} 0 R /ID [<${id}> <${id}>] >>\nstartxref\n${len}\n%%EOF\n`, 'latin1'));
  return new Uint8Array(Buffer.concat(parts));
}

const FX = {
  A: { name:'named-font.pdf', bytes:await fixture({ namedFont:true, helvetica:true, link:true, stamp:true }) },
  B: { name:'named-font-plain.pdf', bytes:await fixture({ namedFont:true, helvetica:true }) },
  C: { name:'helvetica-stamp.pdf', bytes:await fixture({ helvetica:true, link:true, stamp:true }) },
  L: { name:'link-only.pdf', bytes:await fixture({ link:true }) },
  E: { name:'protected.pdf', bytes:encryptedPdf() },
};

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
  const pErr = []; p.on('pageerror', e => pErr.push(String(e).slice(0, 200)));
  p.on('dialog', d => d.dismiss());
  const proof = async (size, bytes, name) => {
    await p.goto('http://127.0.0.1:8137/' + path.basename(PROOFER) + '?size=' + size + '&pages=4', { waitUntil:'domcontentloaded' });
    await p.waitForTimeout(1000);
    return p.evaluate(async ({ arr, name }) => {
      await ensureLibs();
      uploads.clear(); cache.clear();
      MODEL.pages.forEach(pg => state.perPage[pg.n] = { behavior:'crop', anchor:'Center', scale:100, rot:'0°' });
      if (arr){
        await ingestPdf(new File([new Uint8Array(arr)], name, { type:'application/pdf' }), 1);
      } else {
        // An image job: the print file is always rendered.
        const c = document.createElement('canvas'); c.width = 1350; c.height = 1725;
        const x = c.getContext('2d'); x.fillStyle = '#c33'; x.fillRect(0, 0, 1350, 1725);
        const blob = await new Promise(r => c.toBlob(r, 'image/png'));
        await loadArt(1, new File([blob], 'art.png', { type:'image/png' }));
      }
      renderAll();
      const checks = Object.fromEntries(runFileChecks().map(c => [c.id, { state:c.state, text:c.text || '' }]));
      const untouched = untouchedOriginal(MODEL.pages.map(pg => pg.n));
      // The print render of page 4, as the package draws it: how dark is the stamp?
      const r = await renderPrintPage(4);
      const cv = r.canvas, k = PRINT_DPI / 72;
      const box = cv.getContext('2d').getImageData(Math.round(130 * k), Math.round((BLEED_H * 72 - 360) * k), Math.round(80 * k), Math.round(45 * k)).data;
      let dark = 0, n = 0; for (let i = 0; i < box.length; i += 4){ n++; if (box[i] + box[i + 1] + box[i + 2] < 120) dark++; }
      cv.width = 0;
      const pkg = await buildPackage();
      const man = pkg.files.find(f => f.name === 'MANIFEST.txt');
      const pr = pkg.files.find(f => f.name === 'PRINT_READY.pdf');
      return { checks, untouched:!!untouched.bytes, why:untouched.why || '', stampDark:dark / n, hash:pkg.printHash,
               manifest:await man.blob.text(), printReady:Array.from(new Uint8Array(await pr.blob.arrayBuffer())) };
    }, { arr:bytes ? Array.from(bytes) : null, name });
  };
  const P = {};
  for (const [k, f] of Object.entries(FX)) P[k] = await proof('5.5x8.5', f.bytes, f.name);
  P.Q = await proof('4.25x5.5', null, null);

  // ── the imposition tool ──
  const q = await browser.newPage();
  await q.goto('http://127.0.0.1:8140/' + impName, { waitUntil:'domcontentloaded' });
  await q.waitForFunction(() => window.__ppsImpose && window.PDFLib && window.pdfjsLib, null, { timeout:90000 });
  const impose = (bytes, w, h) => q.evaluate(async ({ arr, w, h }) => {
    const I = window.__ppsImpose, bytes = new Uint8Array(arr);
    let notes = '', refused = null, trim = null;
    try {
      const doc = await I.loadPdf(bytes.slice(), 'file');
      notes = (doc.__ppsPressNotes || []).join('\n');
      const t = I.detectTrim(doc, 0.125); trim = { w:t.w, h:t.h, method:t.method };
    } catch (e){ refused = e.message; }
    if (!refused){
      try {
        const res = await I.imposePdf(bytes.slice(), null, { calc:'saddle', trimLong:w, trimShort:h, sides:2, qty:10, sheetFlip:'short', backRotate:'auto', marks:true });
        notes += '\n' + ((res.parts || [res]).flatMap(x => x.warnings || []).join('\n'));
      } catch (e){ refused = e.message; }
    }
    return { notes, refused, trim };
  }, { arr:Array.from(bytes), w, h });
  const I = {};
  for (const [k, f] of Object.entries(FX)) I[k] = await impose(f.bytes, 5.5, 8.5);
  const IQ = await impose(new Uint8Array(P.Q.printReady), 4.25, 5.5);
  const parsed = await q.evaluate(t => window.__ppsImpose.parseManifest(t), P.C.manifest);

  // 1. Untouched means imposable; refused means rendered.
  for (const k of Object.keys(FX)){
    ck('1. ' + FX[k].name + ': shipped untouched only if the imposition tool takes those bytes as they are',
       !(P[k].untouched && I[k].refused),
       'proofer: ' + (P[k].untouched ? 'UNTOUCHED' : 'rendered — ' + P[k].why) + ' · imposition: ' + (I[k].refused ? 'REFUSED — ' + I[k].refused.slice(0, 80) + '…' : 'imposes it'));
  }
  ck('1b. the encrypted file is refused by the imposition tool and rendered by the proofer',
     !!I.E.refused && /encrypted/i.test(I.E.refused) && !P.E.untouched && /password/.test(P.E.why), P.E.why);
  // 2. Fonts.
  const refusedFonts = ((I.A.refused || '').match(/FONTS NOT EMBEDDED in [^:]+: ([^.]+)\./) || [, ''])[1].split(/,\s*/).filter(Boolean);
  ck('2. every font the imposition tool refuses is one the proofer failed and named',
     refusedFonts.length > 0 && P.A.checks.fonts.state === 'fail' && refusedFonts.every(f => P.A.checks.fonts.text.includes(f)),
     'imposition refuses: ' + (refusedFonts.join(', ') || '(none)') + ' · proofer: ' + P.A.checks.fonts.state + ' — ' + P.A.checks.fonts.text.slice(0, 90));
  ck('2b. a standard-14 font alone is a pass that names it (the imposition tool only notes it)',
     !I.C.refused && P.C.checks.fonts.state === 'pass' && /Helvetica/.test(P.C.checks.fonts.text), P.C.checks.fonts.state + ' — ' + P.C.checks.fonts.text.slice(0, 90));
  // 3. Spots.
  ck('3. a spot colour inside placed artwork: named by both',
     /PANTONE 185 C/.test(I.A.notes) && P.A.checks.spots.state === 'warn' && /PANTONE 185 C/.test(P.A.checks.spots.text),
     'proofer: ' + P.A.checks.spots.state + ' — ' + P.A.checks.spots.text.slice(0, 70));
  // 4. Annotations.
  ck('4. a stamp: the imposition tool draws it, the proofer says it prints, and its print render shows it',
     /drawn into the page/.test(I.C.notes) && P.C.checks.interactive.state === 'warn' && /print/.test(P.C.checks.interactive.text)
       && !/do not print/i.test(P.C.checks.interactive.text) && P.C.stampDark > 0.5,
     'proofer: ' + P.C.checks.interactive.state + ' — ' + P.C.checks.interactive.text.slice(0, 80) + ' · render ' + Math.round(P.C.stampDark * 100) + '% dark');
  ck('4b. a link alone is not a finding (neither tool prints it)', P.L.checks.interactive.state === 'pass', P.L.checks.interactive.text);
  // 5. Vector kept.
  ck('5. a file with a link and a stamp ships untouched (the imposition tool handles both)', P.C.untouched, P.C.why);
  ck('5b. a file with only a link ships untouched', P.L.untouched, P.L.why);
  // 6. Manifest.
  const jobLine = (P.C.manifest.match(/^PPS-JOB (\{.*\})$/m) || [])[1];
  let job = null; try { job = JSON.parse(jobLine); } catch (e){}
  ck('6. the imposition tool reads the proofer\'s manifest: product, width × height, file name',
     parsed && parsed.calc === 'saddle' && Math.abs((parsed.rawW || 0) - 5.5) < 0.01 && Math.abs((parsed.rawH || 0) - 8.5) < 0.01 && parsed.jobName === FX.C.name,
     'parseManifest → ' + JSON.stringify(parsed));
  ck('6b. the PPS-JOB line is one line of JSON naming the print file, its hash and whether it is untouched',
     job && job.v === 1 && job.printFile && job.printFile.sha256 === P.C.hash && job.printFile.untouched === true && job.pages === 4,
     jobLine ? jobLine.slice(0, 120) + '…' : '(no PPS-JOB line)');
  // 7. Trim.
  ck('7. the imposition tool reads a rendered print file at the ordered trim (4.25" x 5.5")',
     IQ.trim && Math.abs(IQ.trim.w - 4.25) < 0.01 && Math.abs(IQ.trim.h - 5.5) < 0.01 && !IQ.refused,
     IQ.trim ? 'detectTrim → ' + IQ.trim.w + '" x ' + IQ.trim.h + '" (' + IQ.trim.method + ')' : IQ.refused);
  // The rendered print files the proofer made in place of a refused original are imposable.
  for (const k of ['A', 'B', 'E']){
    const r = await impose(new Uint8Array(P[k].printReady), 5.5, 8.5);
    ck('1c. ' + FX[k].name + ': the proofer\'s rendered print file imposes without an override', !r.refused, r.refused || '');
  }
  ck('no page errors in the proofer', pErr.length === 0, pErr.slice(0, 2).join(' | '));
} finally {
  await browser.close(); proofSrv.kill(); impSrv.close();
}
console.log(fails ? fails + ' failed' : 'all passed');
process.exit(fails ? 1 : 0);
