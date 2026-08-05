// Two checks: the dropdown offers all 50 states, and — the failure that actually
// happened — a saved config missing Georgia can no longer delete Georgia.
const PW='/opt/node22/lib/node_modules/playwright';
const {chromium}=await import(PW+'/index.mjs');
const fs=await import('fs');
const REPL=[['https://unpkg.com/react@18.3.1/umd/react.production.min.js','./node_modules/react/umd/react.production.min.js'],
['https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js','./node_modules/react-dom/umd/react-dom.production.min.js'],
['https://unpkg.com/@babel/standalone@7.26.9/babel.min.js','./node_modules/@babel/standalone/babel.min.js'],
['https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js','./node_modules/pdfjs-dist/build/pdf.min.js'],
['https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js','./node_modules/pdfjs-dist/build/pdf.worker.min.js'],
['https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js','./node_modules/jspdf/dist/jspdf.umd.min.js']];
const US='AL AK AZ AR CA CO CT DE FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY'.split(' ');
const T=process.argv[2]; const LABEL=T.split('/').pop();
let src=fs.readFileSync(T,'utf8'); for(const [a,x] of REPL) src=src.split(a).join(x);
const tmp='st-'+LABEL; fs.writeFileSync(tmp,src);
const fail=[]; const ok=(c,m)=>{ console.log((c?'  PASS  ':'  FAIL  ')+m); if(!c) fail.push(m); };
const b=await chromium.launch();

const read = async (injectBadConfig) => {
  const ctx=await b.newContext({viewport:{width:1400,height:1000}});
  const p=await ctx.newPage();
  if (injectBadConfig) {
    // The live config's shape: a full-looking map that happens to omit Georgia.
    await p.addInitScript(()=>{
      const noGA={AZ:1,CA:2,CO:2,ID:3,MT:3,NM:2,NV:2,OR:3,TX:3,UT:2,WA:3,WY:3,AL:4,AR:4,CT:6,DC:6,DE:6,FL:4,HI:7,IA:4,IL:4,IN:4,KS:4,KY:4,LA:4,MA:6,MD:6,ME:6,MI:4,MN:4,MO:4,MS:4,NC:5,ND:4,NE:4,NH:6,NJ:6,NY:6,OH:5,OK:4,PA:4,RI:7,SC:5,SD:4,TN:4,VA:5,VT:6,WI:4,WV:4,AK:7,MP:7,PR:7,VI:9};
      window.PPS_CONFIG=Object.assign({},window.PPS_CONFIG||{},{calc:{transit_days:noGA}});
    });
  }
  await p.goto('http://127.0.0.1:8137/'+tmp,{waitUntil:'domcontentloaded',timeout:120000});
  await p.waitForSelector('select',{timeout:45000}); await p.waitForTimeout(2600);
  // The state select lives inside the collapsed Shipping & Delivery section.
  await p.locator('button',{hasText:'Shipping & Delivery'}).first().click().catch(()=>{});
  await p.waitForTimeout(900);
  const opts=await p.evaluate(()=>{
    const sel=[...document.querySelectorAll('select')].find(s=>[...s.options].some(o=>o.value==='AZ'));
    return sel?[...sel.options].map(o=>o.value).filter(Boolean):[];
  });
  await ctx.close(); return opts;
};

console.log(`\n═══ ${LABEL} ═══`);
let opts = await read(false);
let miss = US.filter(s=>!opts.includes(s));
console.log(`  built-in config  : ${opts.length} options`);
ok(miss.length===0, `all 50 states offered${miss.length?' — missing '+miss.join(','):''}`);
ok(opts.includes('GA'), 'Georgia is selectable');

opts = await read(true);
miss = US.filter(s=>!opts.includes(s));
console.log(`  config WITHOUT GA: ${opts.length} options`);
ok(opts.includes('GA'), 'Georgia survives a saved config that omits it (the real failure)');
ok(miss.length===0, `still all 50${miss.length?' — missing '+miss.join(','):''}`);

console.log('\n'+(fail.length?`FAILED (${fail.length}):\n - `+fail.join('\n - '):'ALL CHECKS PASSED'));
await b.close(); fs.unlinkSync(tmp); process.exit(fail.length?1:0);
