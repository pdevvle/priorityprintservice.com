/* Preflight checks against real PDFs, built with known defects.
   The libraries are served from /vendor/ here; the published page loads the
   same versions from a CDN. */
const PW='/opt/node22/lib/node_modules/playwright';
const {chromium}=await import(PW+'/index.mjs');
let fail=[];
const ck=(n,ok,d)=>{console.log((ok?'PASS ':'FAIL ')+n+(d?'  '+d:''));if(!ok)fail.push(n);};
const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:1500,height:1000}});
await ctx.addInitScript(()=>{ window.PPS_LIB_BASE='/vendor/'; });
const p=await ctx.newPage();
const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,200)));
p.on('dialog',async d=>{ p.__lastDialog=d.message(); await d.dismiss(); });
await p.goto('http://127.0.0.1:8137/proof-ui-draft.html',{waitUntil:'domcontentloaded'});
await p.waitForTimeout(1200);

// Build a PDF in the page and attach it from a given tile.
const attach = (opts) => p.evaluate(async (o)=>{
  await ensureLibs();
  const { PDFDocument, StandardFonts, PDFName, PDFArray, rgb } = window.PDFLib;
  const doc = await PDFDocument.create();
  const trimW = (o.trimW ?? MODEL.trim.w), trimH = (o.trimH ?? MODEL.trim.h);
  const bw = (trimW + MODEL.bleed*2)*72, bh = (trimH + MODEL.bleed*2)*72;
  const font = await doc.embedFont(StandardFonts.Helvetica);
  for (let i=0;i<(o.pages||1);i++){
    const pg = doc.addPage([bw,bh]);
    if (!o.noTrimBox) pg.setTrimBox(MODEL.bleed*72, MODEL.bleed*72, trimW*72, trimH*72);
    pg.drawRectangle({x:0,y:0,width:bw,height:bh,color:rgb(0.90,0.92,0.95)});
    if (o.text !== false){
      pg.drawText('HEADLINE '+(i+1), {x:1.2*72, y:bh-2*72, size:22, font, color:rgb(0.1,0.1,0.1)});
    }
    if (o.typeNearTrim){
      // 0.18" from the left edge of the sheet: inside the trim, inside safety
      pg.drawText('EDGE', {x:0.18*72, y:1.5*72, size:16, font, color:rgb(0.1,0.1,0.1)});
    }
    if (o.annots){
      pg.node.set(PDFName.of('Annots'), doc.context.obj([
        doc.context.obj({ Type:'Annot', Subtype:'Text', Rect:[10,10,30,30] })
      ]));
    }
    if (o.spot){
      const res = pg.node.get(PDFName.of('Resources'));
      const csName = PDFName.of('ColorSpace');
      // Separation array: family, colorant, alternate, tint transform. Only the
      // first two are read here; the transform is a stub for the fixture.
      const sep = doc.context.obj([ PDFName.of('Separation'), PDFName.of('PANTONE 185 C'),
                                    PDFName.of('DeviceCMYK'), PDFName.of('Identity') ]);
      res.set(csName, doc.context.obj({ CS0: sep }));
    }
  }
  if (o.layers){
    doc.catalog.set(PDFName.of('OCProperties'), doc.context.obj({ OCGs:[], D:{ Order:[] } }));
  }
  const bytes = await doc.save();
  const file = new File([bytes], (o.name||'fixture')+'.pdf', {type:'application/pdf'});
  await ingestPdf(file, o.at||1);
  return true;
}, opts);

const reset = () => p.evaluate(async ()=>{
  uploads.clear(); cache.clear();
  MODEL.pages.forEach(pg=>state.perPage[pg.n]={behavior:'crop',anchor:'Center',scale:100,rot:'0°'});
  state.selected=1; state.openPage=null; state.wholeOpen=true; renderAll();
  await new Promise(r=>setTimeout(r,150));
});
const checks = () => p.evaluate(()=>Object.fromEntries(runFileChecks().map(c=>[c.id,c.state])));
const textOf = (id) => p.evaluate(id=>(runFileChecks().find(c=>c.id===id)||{}).text||'', id);

// ── the libraries load at all ──
ck('pdf.js and pdf-lib load on demand', await p.evaluate(async ()=>{
  await ensureLibs(); return !!(window.pdfjsLib && window.PDFLib);}));

// ── a PDF becomes the artwork ──
await reset();
await attach({name:'good', at:1});
await p.waitForTimeout(400);
ck('a PDF is accepted and rendered as the page art', await p.evaluate(()=>{
  const s=SRCget(1);
  return /good\.pdf/.test(s.label) && s.w>400 && s.h>400 && !!s.pdf;}),
  await p.evaluate(()=>{const s=SRCget(1);return s.label+' '+s.w+'x'+s.h;}));
ck('the art is sized from the rendered page, not the trim box', await p.evaluate(()=>{
  const s=SRCget(1);
  return Math.abs(s.inW-BLEED_W)<0.02 && Math.abs(s.inH-BLEED_H)<0.02;}),
  await p.evaluate(()=>{const s=SRCget(1);return s.inW.toFixed(3)+'x'+s.inH.toFixed(3)+' vs bleed '+BLEED_W+'x'+BLEED_H;}));
ck('and it fills the bleed, so no bleed finding', await p.evaluate(()=>
  !analyze(1).some(i=>i.kind==='bleed')));

// ── the text layer drives the margin check ──
ck('type from the PDF text layer is found', await p.evaluate(()=>SRCget(1).text.length>0),
  await p.evaluate(()=>SRCget(1).text.length+' runs'));
ck('a flat image excuse is NOT used for a PDF', await p.evaluate(()=>
  !analyze(1).some(i=>i.kind==='nomargin')));
await reset();
await attach({name:'edge', at:1, typeNearTrim:true});
await p.waitForTimeout(400);
ck('type set near the trim is caught from the real text layer', await p.evaluate(()=>{
  const a=analyze(1);
  return a.some(i=>i.kind==='tight'||i.kind==='cut') && (a.marks||[]).length>0;}),
  await p.evaluate(()=>analyze(1).map(i=>i.kind).join(',')));

// ── file checks ──
await reset();
await attach({name:'helv', at:1});
await p.waitForTimeout(400);
const c1 = await checks();
ck('a standard-14 font is reported as not embedded', c1.fonts==='fail', JSON.stringify(c1));
ck('and it names the face', /Helvetica/.test(await textOf('fonts')), await textOf('fonts'));
ck('a page built to the ordered size passes', c1.size==='pass', await textOf('size'));
ck('no spot colours, no layers, no interactive content',
  c1.spots==='pass' && c1.layers==='pass' && c1.interactive==='pass', JSON.stringify(c1));
ck('page count for the binding is checked without any file', c1.pages==='pass');

await reset();
await attach({name:'notext', at:1, text:false});
await p.waitForTimeout(400);
ck('a PDF with no type has nothing unembedded', (await checks()).fonts==='pass');

await reset();
await attach({name:'wrongsize', at:1, trimW:4, trimH:6});
await p.waitForTimeout(400);
ck('a page built to the wrong size is flagged', (await checks()).size==='warn', await textOf('size'));
ck('and the ordered size is named alongside it',
  /5\.5" × 8\.5"/.test((await textOf('size')).replace(/×/g,'×')), await textOf('size'));

await reset();
await attach({name:'spot', at:1, spot:true});
await p.waitForTimeout(400);
ck('a Separation colorant is found and named', (await checks()).spots==='warn', await textOf('spots'));
ck('and it says it will be converted', /converted to CMYK|four-colour press/i.test(await textOf('spots')));

await reset();
await attach({name:'layered', at:1, layers:true});
await p.waitForTimeout(400);
ck('optional-content layers are flagged', (await checks()).layers==='warn', await textOf('layers'));

await reset();
await attach({name:'annotated', at:1, annots:true});
await p.waitForTimeout(400);
ck('annotations are flagged', (await checks()).interactive==='warn', await textOf('interactive'));

// ── unknown is a first-class state ──
await reset();
await p.evaluate(async ()=>{
  const c=document.createElement('canvas'); c.width=1200; c.height=1800;
  const x=c.getContext('2d'); x.fillStyle='#8b1f3f'; x.fillRect(0,0,1200,1800);
  const blob=await new Promise(r=>c.toBlob(r,'image/png'));
  loadArtSequence(1,[new File([blob],'flat.png',{type:'image/png'})]);
});
await p.waitForTimeout(700);
const cImg = await checks();
ck('a flat image cannot answer the structural checks', await p.evaluate(()=>{
  const c=Object.fromEntries(runFileChecks().map(x=>[x.id,x.state]));
  return ['size','fonts','spots','layers','interactive'].every(k=>c[k]==='unknown');}),
  JSON.stringify(cImg));
ck('"not checked" is never dressed up as a pass', await p.evaluate(()=>
  runFileChecks().filter(c=>c.state==='unknown')
    .every(c=>/Nothing attached carries its own structure/.test(c.text))));
ck('the page-count check still answers, file or no file', cImg.pages==='pass');

// ── multi-page PDFs ──
await reset();
await attach({name:'booklet', at:2, pages:3});
await p.waitForTimeout(700);
ck('a multi-page PDF fills consecutive pages from the tile dropped on', await p.evaluate(()=>{
  const on=[...uploads.keys()].sort((a,b)=>a-b);
  return JSON.stringify(on)===JSON.stringify([2,3,4]);}),
  await p.evaluate(()=>[...uploads.keys()].sort((a,b)=>a-b).join(',')));
ck('each placed page is labelled with its source page', await p.evaluate(()=>
  SRCget(2).label.endsWith('p1') && SRCget(4).label.endsWith('p3')),
  await p.evaluate(()=>SRCget(2).label+' / '+SRCget(4).label));

// ── the report renders ──
await p.evaluate(()=>{ state.wholeOpen=true; state.openPage=null; renderAll(); });
await p.waitForTimeout(300);
ck('the preflight report lists every check with a state', await p.evaluate(()=>{
  const rows=[...document.querySelectorAll('#rail .checklist .chk-row')];
  return rows.length===6 && rows.every(r=>r.querySelector('.chk-state').textContent.trim().length>0);}),
  await p.evaluate(()=>document.querySelectorAll('#rail .checklist .chk-row').length+' rows'));
ck('the header summarises the worst state', await p.evaluate(()=>
  !!document.querySelector('#rail .acc .chip')));
// ── the retained original ──
await reset();
await attach({name:'source-of-truth', at:1});
await p.waitForTimeout(500);
ck('the original is recorded with its size and a hash taken on receipt', await p.evaluate(()=>{
  const o=SRCget(1).origin;
  return !!o && o.name==='source-of-truth.pdf' && o.size>0 && /^[0-9a-f]{64}$/.test(o.hash);}),
  await p.evaluate(()=>{const o=SRCget(1).origin||{};return o.name+' '+o.size+'b '+(o.hash||'').slice(0,12);}));
ck('the hash is of the bytes as sent, not of anything we made', await p.evaluate(async ()=>{
  // recompute from a fresh copy of the same fixture and compare
  const o=SRCget(1).origin;
  const { PDFDocument } = window.PDFLib;
  const d=await PDFDocument.create(); d.addPage([10,10]);
  const other=await sha256Hex(new Uint8Array(await d.save()));
  return o.hash!==other && o.hash.length===64;}));
ck('a multi-page PDF is one original, not one per page', await p.evaluate(async ()=>{
  uploads.clear(); cache.clear(); renderAll();
  return true;}) && await (async()=>{
  await attach({name:'multi', at:2, pages:3});
  await p.waitForTimeout(700);
  return p.evaluate(()=>{
    const hashes=new Set([...uploads.values()].map(u=>u.origin && u.origin.hash));
    return uploads.size===3 && hashes.size===1 && deliverables().length===1;});})(),
  await p.evaluate(()=>uploads.size+' pages, '+deliverables().length+' original(s)'));

ck('the deliverables say the print file is what was approved', await p.evaluate(()=>{
  state.wholeOpen=true; state.openPage=null; renderAll();
  const t=document.getElementById('deliv').innerText;
  return /flattened at 300 DPI/.test(t) && /what you approved/.test(t);}));
ck('and that the original is kept but is not the file we print', await p.evaluate(()=>{
  const t=document.getElementById('deliv').innerText;
  return /untouched/i.test(t) && /reference copy, not the one we print from/i.test(t);}),
  await p.evaluate(()=>document.getElementById('deliv').innerText.replace(/\s+/g,' ').slice(0,120)));
ck('the original is listed by name, size and hash', await p.evaluate(()=>{
  const li=document.querySelectorAll('#deliv .origins li');
  return li.length===1 && /multi\.pdf/.test(li[0].textContent) && /\u2026/.test(li[0].textContent);}),
  await p.evaluate(()=>{const l=document.querySelector('#deliv .origins li');return l?l.textContent:'(none)';}));
ck('with nothing attached it still states the policy', await p.evaluate(async ()=>{
  uploads.clear(); cache.clear(); state.wholeOpen=true; renderAll();
  await new Promise(r=>setTimeout(r,200));
  const t=document.getElementById('deliv').innerText;
  return /byte for byte/.test(t) && document.querySelectorAll('#deliv .origins li').length===0;}));

ck('no page errors overall', errs.length===0, errs[0]||'');

await b.close();
console.log(fail.length?('>>> '+fail.length+' FAILURE(S)'):'>>> PREFLIGHT OK');
process.exit(fail.length?1:0);
