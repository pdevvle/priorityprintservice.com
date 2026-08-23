// #37: typing a page count must say the quote is stale. #38: a silent round-up to an
// even count reads as the field ignoring you. #40: a cross-sell link must not point at
// the page it is on.
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
const tmp='pc-'+LABEL; fs.writeFileSync(tmp,src);
const fail=[]; const ok=(c,m)=>{ console.log((c?'  PASS  ':'  FAIL  ')+m); if(!c) fail.push(m); };

const b=await chromium.launch();
const ctx=await b.newContext({viewport:{width:1500,height:1100}});
const p=await ctx.newPage();
p.on('pageerror', e=>console.log('  [pageerror]', String(e).slice(0,160)));
await p.goto('http://127.0.0.1:8137/'+tmp,{waitUntil:'domcontentloaded',timeout:120000});
await p.waitForSelector('select',{timeout:45000}); await p.waitForTimeout(2600);

const bodyHas = t => p.evaluate(s=>document.body.innerText.includes(s), t);

// ── #40: no cross-sell link may resolve to this very page ────────────────────
const links=await p.evaluate(()=>[...document.querySelectorAll('a')]
  .filter(a=>/^Try as /.test((a.textContent||'').trim()))
  .map(a=>({text:a.textContent.trim(), href:a.href})));
console.log(`  ${links.length} "Try as" link(s)`);
const here=await p.evaluate(()=>location.origin+location.pathname);
const selfRef=links.filter(l=>l.href.split('?')[0].replace(/\/+$/,'')===here.replace(/\/+$/,''));
ok(selfRef.length===0, `no cross-sell link points at the current page (${selfRef.length} self-referential)`);

// Simulate the WordPress case: a pretty product URL where the filename swap is a no-op.
await p.goto('http://127.0.0.1:8137/'+tmp+'?pretty=1',{waitUntil:'domcontentloaded'});
await p.waitForSelector('select',{timeout:45000}); await p.waitForTimeout(2400);
const wpLinks=await p.evaluate(()=>{
  // Force the fallback branch to produce the current path, as it does on /product/<slug>/.
  return [...document.querySelectorAll('a')].filter(a=>/^Try as /.test((a.textContent||'').trim())).length;
});
console.log('  links with querystring present:', wpLinks);

// ── #37 / #38: page count staleness and adjustment ───────────────────────────
const pageInput=await p.evaluateHandle(()=>{
  const labels=[...document.querySelectorAll('label')];
  const l=labels.find(x=>/Total Pages/i.test(x.textContent||''));
  return l ? l.parentElement.querySelector('input[inputmode="decimal"]') : null;
});
const found=await pageInput.evaluate(el=>!!el);
ok(found, 'found the Total Pages input');

if (found) {
  const applied=await pageInput.evaluate(el=>el.value);
  console.log('  applied page count:', applied);

  await pageInput.evaluate(el=>{
    const set=Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set;
    set.call(el,'48'); el.dispatchEvent(new Event('input',{bubbles:true}));
  });
  await p.waitForTimeout(400);
  ok(await bodyHas('press Apply or Enter'), '#37 typing a new count says the quote is stale');
  ok(await bodyHas('Quote still shows '+applied+' pages'), `#37 the stale notice names the applied count (${applied})`);

  // Commit an odd count and check the adjustment is stated rather than silent.
  // Focus first: blur() on an element that never had focus fires nothing, which made
  // this read as "the round-up never happened" when the commit simply never ran.
  await pageInput.evaluate(el=>{
    el.focus();
    const set=Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set;
    set.call(el,'47'); el.dispatchEvent(new Event('input',{bubbles:true}));
  });
  await p.waitForTimeout(200);
  await p.keyboard.press('Enter');
  await p.waitForTimeout(700);
  const now=await pageInput.evaluate(el=>el.value);
  console.log('  after committing 47 the field reads:', now);
  ok(now==='48', '47 is rounded up to an even 48');
  ok(await bodyHas('Adjusted 47'), '#38 the round-up is stated, not silent');
  ok(!(await bodyHas('press Apply or Enter')), 'the stale notice clears once applied');
}

await ctx.close(); await b.close();
console.log(fail.length ? `\n${LABEL}: ${fail.length} FAILURE(S)` : `\n${LABEL}: all checks passed`);
process.exit(fail.length?1:0);
