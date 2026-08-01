// Pricing matrix sweep — drives the real UI of every calculator and records what a
// customer is actually quoted. Deliberately NOT calling calculate() directly: the
// engine is sealed inside an IIFE now, and driving the UI is self-validating — it
// measures the rendered price, not an internal we hope matches it.
//
// Each page render yields 8 data points free, because the quantity-pricing table
// prices the whole quantity ladder at once. That is what makes a sweep this wide
// tractable at ~2.5s per render.
const PW='/opt/node22/lib/node_modules/playwright';
const {chromium}=await import(PW+'/index.mjs');
const fs=await import('fs');

const REPL=[['https://unpkg.com/react@18.3.1/umd/react.production.min.js','./node_modules/react/umd/react.production.min.js'],
['https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js','./node_modules/react-dom/umd/react-dom.production.min.js'],
['https://unpkg.com/@babel/standalone@7.26.9/babel.min.js','./node_modules/@babel/standalone/babel.min.js'],
['https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js','./node_modules/pdfjs-dist/build/pdf.min.js'],
['https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js','./node_modules/pdfjs-dist/build/pdf.worker.min.js'],
['https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js','./node_modules/jspdf/dist/jspdf.umd.min.js']];

// paperIdx: which select holds the paper, per calculator (probed, see probe-ui.mjs)
const CALCS=[
  {f:'calc-preview-test',  name:'Saddle stitch booklet', paperIdx:2, pagesSelect:true},
  {f:'calc-perfect-bound', name:'Perfect bound booklet', paperIdx:1, pagesSelect:false},
  {f:'calc-coupon-book',   name:'Coupon book',           paperIdx:1, pagesSelect:false},
  {f:'calc-brochure',      name:'Brochure',              paperIdx:2, pagesSelect:false, foldIdx:1},
  {f:'calc-postcard',      name:'Postcard',              paperIdx:2, pagesSelect:false, foldIdx:1},
  {f:'calc-greeting-card', name:'Greeting card',         paperIdx:2, pagesSelect:false, foldIdx:1},
  {f:'calc-letterhead',    name:'Letterhead',            paperIdx:1, pagesSelect:false},
  {f:'calc-sticker',       name:'Sticker',               paperIdx:1, pagesSelect:false},
];

const b=await chromium.launch();
const out={generated_at_note:'stamped after the run; Date.now() avoided inside sweeps', calculators:{}};

const readTable=p=>p.evaluate(()=>[...document.querySelectorAll('tbody tr')].map(tr=>{
  const c=[...tr.children].map(td=>td.textContent.trim());
  if(c.length<3) return null;
  const qty=Number(c[0].replace(/[^\d]/g,''));
  const unit=Number(c[1].replace(/[^0-9.]/g,''));
  const total=Number(c[2].replace(/[^0-9.]/g,''));
  return (qty&&total)?{qty,unit,total}:null;
}).filter(Boolean));

const optText=(p,i)=>p.locator('select').nth(i).locator('option').allTextContents();
const sel=async(p,i,label)=>{ await p.locator('select').nth(i).selectOption({label}); await p.waitForTimeout(700); };

for (const C of CALCS) {
  let s=fs.readFileSync(`/home/user/priorityprintservice.com/${C.f}.html`,'utf8');
  for(const [a,x] of REPL) s=s.split(a).join(x);
  fs.writeFileSync(`mx-${C.f}.html`,s);

  const p=await (await b.newContext({viewport:{width:1500,height:1300}})).newPage();
  const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,120)));
  await p.goto(`http://127.0.0.1:8137/mx-${C.f}.html`,{waitUntil:'domcontentloaded',timeout:120000});
  await p.waitForSelector('select',{timeout:45000}); await p.waitForTimeout(2600);

  const sizes=(await optText(p,0)).filter(t=>t.trim());
  const papers=(await optText(p,C.paperIdx)).filter(t=>t.trim());
  const pages=C.pagesSelect ? (await optText(p,1)).filter(t=>/Pages/.test(t)) : [];
  const rec={name:C.name, file:C.f+'.html', axes:{sizes:sizes.length, papers:papers.length, pages:pages.length||null},
             defaults:{}, by_size:{}, by_paper:{}, by_pages:{}};

  // Baseline: whatever the calculator opens with — the config most customers see first.
  rec.defaults={size:sizes[0]&&await p.locator('select').nth(0).inputValue().then(()=>null).catch(()=>null)};
  rec.defaults.size=await p.evaluate(()=>{const s=document.querySelectorAll('select')[0];return s.options[s.selectedIndex].textContent.trim();});
  rec.defaults.paper=await p.evaluate(i=>{const s=document.querySelectorAll('select')[i];return s.options[s.selectedIndex].textContent.trim();},C.paperIdx);
  if(C.pagesSelect) rec.defaults.pages=await p.evaluate(()=>{const s=document.querySelectorAll('select')[1];return s.options[s.selectedIndex].textContent.trim();});

  // Axis 1 — every size, at default paper/pages
  for (const size of sizes) {
    try { await sel(p,0,size); rec.by_size[size]=await readTable(p); }
    catch(e){ rec.by_size[size]={error:String(e).slice(0,80)}; }
  }
  await sel(p,0,rec.defaults.size).catch(()=>{});

  // Axis 2 — every paper, at the default size
  for (const paper of papers) {
    try { await sel(p,C.paperIdx,paper); rec.by_paper[paper]=await readTable(p); }
    catch(e){ rec.by_paper[paper]={error:String(e).slice(0,80)}; }
  }
  await sel(p,C.paperIdx,rec.defaults.paper).catch(()=>{});

  // Axis 3 — page count, booklets with a Pages select only
  for (const pg of pages) {
    try { await sel(p,1,pg); rec.by_pages[pg]=await readTable(p); }
    catch(e){ rec.by_pages[pg]={error:String(e).slice(0,80)}; }
  }

  rec.page_errors=errs.slice(0,3);
  const pts=Object.values(rec.by_size).concat(Object.values(rec.by_paper),Object.values(rec.by_pages))
    .reduce((n,v)=>n+(Array.isArray(v)?v.length:0),0);
  rec.data_points=pts;
  out.calculators[C.f]=rec;
  console.log(`${C.f.padEnd(20)} sizes=${sizes.length} papers=${papers.length} pages=${pages.length||'-'}  ->  ${pts} price points${errs.length?'  ERRORS:'+errs[0]:''}`);
  await p.close();
}
await b.close();
fs.writeFileSync('pricing-matrix.json', JSON.stringify(out,null,1));
const total=Object.values(out.calculators).reduce((n,c)=>n+c.data_points,0);
console.log(`\nTOTAL ${total} price points across ${Object.keys(out.calculators).length} calculators -> pricing-matrix.json`);
