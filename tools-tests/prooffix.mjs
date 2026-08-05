// The regression that matters: the customer's actual pitch book, through the actual
// upload flow, must show the blue block 3.11 dropped — and the preflight must name why
// it deserves a close look. A construct-free control must show no banner.
const PW='/opt/node22/lib/node_modules/playwright';
const {chromium}=await import(PW+'/index.mjs');
const fs=await import('fs');
const REPL=[['https://unpkg.com/react@18.3.1/umd/react.production.min.js','./node_modules/react/umd/react.production.min.js'],
['https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js','./node_modules/react-dom/umd/react-dom.production.min.js'],
['https://unpkg.com/@babel/standalone@7.26.9/babel.min.js','./node_modules/@babel/standalone/babel.min.js'],
['https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js','./node_modules/jspdf/dist/jspdf.umd.min.js'],
// the new loader's mirror prefixes -> vendored 4.10.38 legacy build
['https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/','./newpdf/node_modules/pdfjs-dist/legacy/build/'],
['https://unpkg.com/pdfjs-dist@4.10.38/legacy/build/','./newpdf/node_modules/pdfjs-dist/legacy/build/']];

const T=process.argv[2]||'/home/user/priorityprintservice.com/calc-preview-test.html';
let src=fs.readFileSync(T,'utf8'); for(const [a,x] of REPL) src=src.split(a).join(x);
fs.writeFileSync('pf-target.html',src);
const fail=[]; const ok=(c,m)=>{ console.log((c?'  PASS  ':'  FAIL  ')+m); if(!c) fail.push(m); };

const b=await chromium.launch();
const p=await (await b.newContext({viewport:{width:1500,height:1100}})).newPage();
const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,140)));
await p.goto('http://127.0.0.1:8137/pf-target.html',{waitUntil:'domcontentloaded',timeout:120000});
await p.waitForSelector('select',{timeout:45000}); await p.waitForTimeout(2600);

ok(await p.evaluate(()=>!!window.pdfjsLib && !!window.__ppsPdfJs), 'loader published window.pdfjsLib');
console.log('  pdf.js version:', await p.evaluate(()=>window.pdfjsLib && window.pdfjsLib.version));

const run = async (file, label) => {
  // Fork difference: preview-test UNMOUNTS closed sections, modern keeps them mounted
  // and hides them with a CSS var. So "the input exists" does not mean "the section is
  // visible" -- test visibility of the proof CTA, and open the section if it is hidden.
  if (!(await p.locator('input[type="file"][accept=".pdf,image/*"]').count())) {
    await p.locator('button',{hasText:'Artwork & Proofing'}).first().click();
    await p.waitForTimeout(800);
  }
  const inp=p.locator('input[type="file"][accept=".pdf,image/*"]').first();
  await inp.setInputFiles(file,{timeout:20000});
  await p.waitForTimeout(2500);
  if (!(await p.locator('button:visible',{hasText:/🔍 Proof|Proof Approved/}).count())) {
    await p.locator('button',{hasText:'Artwork & Proofing'}).first().click();
    await p.waitForTimeout(800);
  }
  await p.locator('button:visible',{hasText:/🔍 Proof|Proof Approved/}).first().waitFor({timeout:90000});
  await p.waitForTimeout(1200);
  const risks=await p.evaluate(()=>window.ppsPdfRisks());
  console.log(`  ${label}: risks = [${risks.join(', ')}]`);
  await p.locator('button',{hasText:/🔍 Proof|Proof Approved/}).first().click();
  await p.waitForTimeout(2500);
  const banner=await p.evaluate(()=>document.body.innerText.includes('effects a browser preview can render imperfectly'));
  // Sample the proof-modal render where the blue block lives (30%, 86% of the page area).
  const px=await p.evaluate(async()=>{
    // Proof pages render as <img> holding the extraction dataURL -- sampling that IS
    // sampling the render engine's output.
    const imgs=[...document.querySelectorAll('img')].filter(i=>/^data:image/.test(i.src)&&i.naturalWidth>300)
      .sort((a,b)=>b.naturalWidth*b.naturalHeight-a.naturalWidth*a.naturalHeight);
    if(!imgs.length) return null;
    const im=imgs[0];
    const c=document.createElement('canvas'); c.width=im.naturalWidth; c.height=im.naturalHeight;
    const x=c.getContext('2d'); x.drawImage(im,0,0);
    const d=x.getImageData(Math.round(c.width*0.30),Math.round(c.height*0.86),1,1).data;
    return {w:c.width,h:c.height,rgb:[d[0],d[1],d[2]]};
  });
  // close the modal for the next round
  await p.keyboard.press('Escape').catch(()=>{});
  await p.evaluate(()=>{const b=[...document.querySelectorAll('button')].find(x=>x.textContent.trim()==='✕'||x.textContent.trim()==='×'); if(b)b.click();});
  await p.waitForTimeout(600);
  return {risks, banner, px};
};

const pitch = await run('pitch.pdf','pitch.pdf');
ok(pitch.risks.length>0, `pitch: preflight found risks (${pitch.risks.join(', ')})`);
ok(pitch.banner, 'pitch: banner shown in proof modal');
console.log('  pitch: proof canvas', pitch.px ? `${pitch.px.w}x${pitch.px.h} @(30%,86%) rgb(${pitch.px.rgb})` : 'NOT FOUND');
ok(pitch.px && pitch.px.rgb[2]>90 && pitch.px.rgb[2]>pitch.px.rgb[0] && pitch.px.rgb[0]<140,
   'pitch: blue block PRESENT in proof render (was white under 3.11)');

const ctrl = await run('art-4pg.pdf','control');
ok(ctrl.risks.length===0, `control: no risks flagged (got [${ctrl.risks.join(', ')}])`);
ok(!ctrl.banner, 'control: no banner');

ok(errs.length===0, `no page errors (${errs.length})`); errs.slice(0,3).forEach(e=>console.log('   ',e));
await b.close();
console.log(fail.length?`\n${fail.length} FAILURE(S)`:'\nall checks passed');
process.exit(fail.length?1:0);
