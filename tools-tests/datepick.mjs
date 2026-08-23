// #14: "clicking a rush-zone date closed the calendar WITHOUT populating the field".
// Drives the real component rather than reasoning about it: fill a destination, open
// the picker, click a yellow (rush-zone) cell, read the trigger back.
const PW='/opt/node22/lib/node_modules/playwright';
const {chromium}=await import(PW+'/index.mjs');
const fs=await import('fs');
const REPL=[['https://unpkg.com/react@18.3.1/umd/react.production.min.js','./node_modules/react/umd/react.production.min.js'],
['https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js','./node_modules/react-dom/umd/react-dom.production.min.js'],
['https://unpkg.com/@babel/standalone@7.26.9/babel.min.js','./node_modules/@babel/standalone/babel.min.js'],
['https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js','./node_modules/pdfjs-dist/build/pdf.min.js'],
['https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js','./node_modules/pdfjs-dist/build/pdf.worker.min.js'],
['https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js','./node_modules/jspdf/dist/jspdf.umd.min.js']];

const T=process.argv[2]; const LABEL=T.split('/').pop();
let src=fs.readFileSync(T,'utf8'); for(const [a,x] of REPL) src=src.split(a).join(x);
const tmp='dp-'+LABEL; fs.writeFileSync(tmp,src);
const fail=[]; const ok=(c,m)=>{ console.log((c?'  PASS  ':'  FAIL  ')+m); if(!c) fail.push(m); };

const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:1400,height:1100}});
const p=await ctx.newPage();
p.on('pageerror', e=>console.log('  [pageerror]', String(e).slice(0,160)));
await p.goto('http://127.0.0.1:8137/'+tmp,{waitUntil:'domcontentloaded',timeout:120000});
await p.waitForSelector('select',{timeout:45000}); await p.waitForTimeout(2600);

// Shipping section is collapsed on load; the date picker lives inside it.
await p.locator('button',{hasText:'Shipping & Delivery'}).first().click().catch(()=>{});
await p.waitForTimeout(900);

// A complete destination — the picker's earliest/free dates come from the engine, and
// the engine now needs a ZIP before it will produce them at all.
const setInput=async(ph,val)=>{
  const el=p.locator(`input[placeholder*="${ph}" i]`).first();
  if(await el.count()) { await el.fill(val); return true; }
  return false;
};
await setInput('street','500 W Main St');
await setInput('city','Austin');
await p.evaluate(()=>{ const s=[...document.querySelectorAll('select')].find(x=>[...x.options].some(o=>o.value==='TX')); if(s){s.focus();} });
await p.selectOption('select >> nth=' + await p.evaluate(()=>{
  const all=[...document.querySelectorAll('select')];
  return all.indexOf(all.find(x=>[...x.options].some(o=>o.value==='TX')));
}), 'TX').catch(()=>{});
await setInput('ZIP','78701');
await p.waitForTimeout(1400);

// Open the picker. Keep a handle to the trigger so the readback afterwards is the same
// element, not a fresh query that may match nothing and pass vacuously.
const trigHandle=await p.evaluateHandle(()=>{
  // Some forks pre-fill the field with the free-delivery date, so the placeholder is
  // not a reliable handle. Match the empty state OR a formatted date.
  const span=[...document.querySelectorAll('span')].find(s=>{
    const t=(s.textContent||'').trim();
    return t==='Select a date...' || /^[A-Z][a-z]{2}, [A-Z][a-z]{2} \d{1,2}$/.test(t);
  });
  return span ? span.closest('div') : null;
});
ok(!!(await trigHandle.evaluate(el=>!!el)), 'date picker trigger present');
const before=await trigHandle.evaluate(el=>el ? el.textContent.trim() : '');
console.log('  trigger before:', JSON.stringify(before));
await trigHandle.evaluate(el=>el && el.click());
await p.waitForTimeout(700);

// Day cells: divs in the 7-column grid, digits only, with a pointer cursor.
const cells=await p.evaluate(()=>{
  const out=[];
  document.querySelectorAll('div').forEach(d=>{
    if(d.children.length) return;
    if(!/^\d{1,2}$/.test((d.textContent||'').trim())) return;
    const cs=getComputedStyle(d);
    if(cs.cursor!=='pointer') return;
    out.push({n:d.textContent.trim(), bg:cs.backgroundColor, color:cs.color});
  });
  return out;
});
console.log(`  ${cells.length} clickable day cell(s)`);
const byBg={}; cells.forEach(c=>{ byBg[c.bg]=(byBg[c.bg]||0)+1; });
console.log('  backgrounds:', JSON.stringify(byBg));

// The rush zone is the yellow-tinted band between earliest and free.
const greenish=b=>{const m=b.match(/\d+/g); return m && +m[1]>+m[0] && +m[1]>+m[2];};
const rush=cells.filter(c=>c.bg!=='rgba(0, 0, 0, 0)' && !greenish(c.bg));
ok(rush.length>0, `found ${rush.length} rush-zone cell(s)`);

if (rush.length) {
  const target=rush[Math.floor(rush.length/2)].n;
  await p.evaluate(n=>{
    const d=[...document.querySelectorAll('div')].find(e=>!e.children.length
      && (e.textContent||'').trim()===n && getComputedStyle(e).cursor==='pointer');
    if(d) d.click();
  }, target);
  await p.waitForTimeout(900);
  const after=await trigHandle.evaluate(el=>el ? el.textContent.trim() : '<trigger gone>');
  console.log('  trigger after: ', JSON.stringify(after));
  ok(after !== before && !/Select a date/.test(after),
     `clicking rush-zone day ${target} populates the field`);
  const stillOpen=await p.evaluate(()=>!![...document.querySelectorAll('div')].find(d=>/^◀$/.test((d.textContent||'').trim())));
  console.log('  calendar still open:', stillOpen);
}

await ctx.close(); await b.close();
console.log(fail.length ? `\n${LABEL}: ${fail.length} FAILURE(S)` : `\n${LABEL}: all checks passed`);
process.exit(fail.length?1:0);
