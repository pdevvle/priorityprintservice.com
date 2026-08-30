const PW='/opt/node22/lib/node_modules/playwright';
const {chromium}=await import(PW+'/index.mjs');
let fail=[];
const ck=(n,ok,d)=>{console.log((ok?'PASS ':'FAIL ')+n+(d?'  '+d:''));if(!ok)fail.push(n);};
const b=await chromium.launch();
const p=await (await b.newContext({viewport:{width:1500,height:1000}})).newPage();
const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,200)));
p.on('dialog',async d=>{await d.dismiss();});
await p.goto('http://127.0.0.1:8137/proof-ui-draft.html',{waitUntil:'domcontentloaded'});
await p.waitForTimeout(1200);
const go = m => p.evaluate(m=>[...document.querySelectorAll('#modes button')].find(b=>b.dataset.mode===m).click(), m);

ck('renders without errors', errs.length===0, errs[0]||'');
ck('approve disabled until agree', await p.evaluate(()=>document.getElementById('approveBtn').disabled===true));
await p.click('#agree'); await p.waitForTimeout(200);
ck('approve enables after agree', await p.evaluate(()=>document.getElementById('approveBtn').disabled===false));
ck('page rows rendered', await p.evaluate(()=>document.querySelectorAll('#rail .acc').length)===9, 'want 9 (whole file + 8 pages)');
ck('filmstrip groups', await p.evaluate(()=>document.querySelectorAll('#stripBottom .grp').length)===5);
ck('issue callout on p1', await p.evaluate(()=>/doesn.t fill bleed/.test(document.getElementById('issuePanel').innerText)));
ck('agree checkbox lives inside the magenta note', await p.evaluate(()=>!!document.querySelector('.note .agree input#agree')));

// ── revision 1: no "center spread" subheading anywhere under the thumbnails ──
ck('no spread group labels in the DOM', await p.evaluate(()=>document.querySelectorAll('.spreadlbl').length===0));
ck('no "center spread" caption under any thumbnail', await p.evaluate(()=>
  ![...document.querySelectorAll('#stripBottom .pg')].some(e=>/spread/i.test(e.textContent))));

// ── revision 2: every thumbnail cell the same size and shape ──
ck('all filmstrip cells identical box', await p.evaluate(()=>{
  const r=[...document.querySelectorAll('#stripBottom .pg')].map(e=>e.getBoundingClientRect());
  if(r.length!==8) return false;
  return r.every(x=>Math.abs(x.width-r[0].width)<0.6 && Math.abs(x.height-r[0].height)<0.6);
}), await p.evaluate(()=>{const r=document.querySelector('#stripBottom .pg').getBoundingClientRect();return Math.round(r.width)+'x'+Math.round(r.height);}));
ck('all thumbnail images identical box', await p.evaluate(()=>{
  const r=[...document.querySelectorAll('#stripBottom .pg .thumb')].map(e=>e.getBoundingClientRect());
  return r.length===8 && r.every(x=>Math.abs(x.width-r[0].width)<0.6 && Math.abs(x.height-r[0].height)<0.6);}));

// ── revision 5: legend is a horizontal strip beneath the view controls ──
ck('legend is a horizontal bar under the top controls', await p.evaluate(()=>{
  const lb=document.getElementById('legendBar'), tb=document.querySelector('.topbar');
  if(!lb||getComputedStyle(lb).display==='none') return false;
  const a=lb.getBoundingClientRect(), t=tb.getBoundingClientRect();
  const items=[...lb.querySelectorAll('.lgitem')].map(e=>e.getBoundingClientRect());
  const oneRow = items.every(x=>Math.abs(x.top-items[0].top)<3);
  return a.top >= t.bottom-1 && oneRow && a.height < 60 && items.length===4;}),
  await p.evaluate(()=>Math.round(document.getElementById('legendBar').getBoundingClientRect().height)+'px tall'));
ck('hiding legend reveals "Show legend"', await p.evaluate(()=>{
  const cb=document.getElementById('hideLegend'); cb.checked=true; cb.dispatchEvent(new Event('change'));
  return getComputedStyle(document.getElementById('legendBar')).display==='none' && !document.getElementById('showLegend').hidden;}));
ck('"Show legend" restores it', await p.evaluate(()=>{
  document.getElementById('showLegend').click();
  return getComputedStyle(document.getElementById('legendBar')).display!=='none'
      && document.getElementById('showLegend').hidden
      && document.getElementById('hideLegend').checked===false;}));
ck('hiding guides hides legend and offers no restore', await p.evaluate(()=>{
  const g=document.getElementById('hideGuides'); g.checked=true; g.dispatchEvent(new Event('change'));
  return getComputedStyle(document.getElementById('legendBar')).display==='none' && document.getElementById('showLegend').hidden;}));
ck('restoring guides brings the legend back', await p.evaluate(()=>{
  const g=document.getElementById('hideGuides'); g.checked=false; g.dispatchEvent(new Event('change'));
  return getComputedStyle(document.getElementById('legendBar')).display!=='none';}));

// ── revision 4: APPROVE at the far right, close outermost ──
ck('approve sits at the far right of the top bar', await p.evaluate(()=>{
  const a=document.getElementById('approveBtn').getBoundingClientRect();
  const tb=document.querySelector('.topbar').getBoundingClientRect();
  const others=[...document.querySelectorAll('.topbar > *')].filter(e=>!e.contains(document.getElementById('approveBtn')) && e.id!=='closeBtn');
  return a.left > tb.left + tb.width*0.6 && others.every(e=>e.getBoundingClientRect().left < a.left);}));
ck('close button is the outermost element', await p.evaluate(()=>{
  const c=document.getElementById('closeBtn'), tb=document.querySelector('.topbar');
  return tb.lastElementChild===c
      && c.getBoundingClientRect().left > document.getElementById('approveBtn').getBoundingClientRect().left;}));
ck('undo reads "Undo" with an icon', await p.evaluate(()=>{
  const u=document.querySelector('.undo');
  return /^\s*\S\s*Undo\s*$/.test(u.textContent) && !!u.querySelector('.ic');}));

// ── revision 6: Scale is a 50–200% slider with 0.5% knurled controls ──
await p.evaluate(()=>{const h=[...document.querySelectorAll('#rail .acc .acchead')].find(b=>/Page: 1/.test(b.textContent)); if(!document.querySelector('#rail .acc.open .behavior')) h.click();});
await p.waitForTimeout(150);
const anchors = await p.evaluate(()=>[...document.querySelectorAll('.pills .pill')].map(b=>b.textContent));
ck('crop shows anchor pills', anchors.includes('Center')&&anchors.includes('Top'), JSON.stringify(anchors));
await p.selectOption('.behavior select','fill'); await p.waitForTimeout(150);
ck('fill shows anchor pills too', await p.evaluate(()=>[...document.querySelectorAll('.pills .pill')].map(b=>b.textContent).includes('Center')));
await p.selectOption('.behavior select','fit'); await p.waitForTimeout(150);
ck('fit shows a note, no pills', await p.evaluate(()=>!!document.querySelector('.subbox .subnote')&&document.querySelectorAll('.pills .pill').length===0));
await p.selectOption('.behavior select','stretch'); await p.waitForTimeout(150);
ck('stretch shows a note, no pills', await p.evaluate(()=>!!document.querySelector('.subbox .subnote')&&document.querySelectorAll('.pills .pill').length===0));
await p.selectOption('.behavior select','scale'); await p.waitForTimeout(200);
ck('scale renders a slider, not pills', await p.evaluate(()=>{
  const r=document.querySelector('.subbox input[type=range]');
  return !!r && document.querySelectorAll('.subbox .pill').length===0;}));
ck('slider range is 50–200 stepping 0.5', await p.evaluate(()=>{
  const r=document.querySelector('.subbox input[type=range]');
  return r.min==='50' && r.max==='200' && r.step==='0.5';}),
  await p.evaluate(()=>{const r=document.querySelector('.subbox input[type=range]');return r.min+'–'+r.max+' / '+r.step;}));
ck('knurled − steps down exactly 0.5%', await p.evaluate(()=>{
  const k=[...document.querySelectorAll('.subbox .knurl')]; k[0].click();
  return document.querySelector('.scaleval').textContent.trim()==='99.5%';}),
  await p.evaluate(()=>document.querySelector('.scaleval').textContent.trim()));
ck('knurled + steps back up 0.5%', await p.evaluate(()=>{
  const k=[...document.querySelectorAll('.subbox .knurl')]; k[1].click();
  return document.querySelector('.scaleval').textContent.trim()==='100.0%';}));
ck('slider clamps at both ends', await p.evaluate(()=>{
  const k=[...document.querySelectorAll('.subbox .knurl')];
  for(let i=0;i<140;i++) k[0].click();
  const lo=document.querySelector('.scaleval').textContent.trim();
  for(let i=0;i<400;i++) k[1].click();
  const hi=document.querySelector('.scaleval').textContent.trim();
  return lo==='50.0%' && hi==='200.0%';}));
await p.selectOption('.behavior select','rotate'); await p.waitForTimeout(150);
ck('rotate shows deg pills + apply all', await p.evaluate(()=>{const t=[...document.querySelectorAll('.pills .pill')].map(b=>b.textContent);return t.includes('90°')&&t.some(x=>/all pages/.test(x));}));

// ── revision 3: in 3D the thumbnails move into the right panel ──
await go('book'); await p.waitForTimeout(400);
ck('3D disables view controls', await p.evaluate(()=>document.getElementById('viewGroup').classList.contains('disabledish')));
ck('3D shows mockup disclaimer', await p.evaluate(()=>/not the print surface/.test(document.getElementById('issuePanel').innerText)));
ck('3D hides the legend', await p.evaluate(()=>
  getComputedStyle(document.getElementById('legendBar')).display==='none' && document.getElementById('showLegend').hidden));
ck('3D hides the artwork-edit panel', await p.evaluate(()=>
  getComputedStyle(document.getElementById('railEdit')).display==='none'));
ck('3D puts the thumbnails in the right rail', await p.evaluate(()=>
  document.querySelectorAll('#stripRail .pg').length===8
  && document.querySelectorAll('#stripBottom .pg').length===0
  && !document.getElementById('railStripWrap').hidden));
ck('rail thumbnails are inside the rail box', await p.evaluate(()=>{
  const r=document.getElementById('railPanel').getBoundingClientRect();
  return [...document.querySelectorAll('#stripRail .pg')].every(e=>{
    const x=e.getBoundingClientRect(); return x.left>=r.left-1 && x.right<=r.right+1;});}));
ck('3D rail cells stay the same size as proof cells', await p.evaluate(()=>{
  const x=document.querySelector('#stripRail .pg').getBoundingClientRect();
  return Math.abs(x.width-84)<0.6 && Math.abs(x.height-96)<0.6;}));
ck('3D selects the whole spread in the strip', await p.evaluate(()=>{
  const p4=[...document.querySelectorAll('#stripRail .pg')].find(b=>/P4/.test(b.textContent)); p4.click();
  return [...document.querySelectorAll('#stripRail .pg.sel')].length===2;}));
await go('proof'); await p.waitForTimeout(300);
ck('back in proof: edit panel returns, thumbnails go back below', await p.evaluate(()=>
  getComputedStyle(document.getElementById('railEdit')).display!=='none'
  && document.getElementById('railStripWrap').hidden
  && document.querySelectorAll('#stripBottom .pg').length===8
  && document.querySelectorAll('#stripRail .pg').length===0));

ck('no horizontal page scroll', await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
ck('no page errors overall', errs.length===0, errs[0]||'');
await b.close();
console.log(fail.length?('>>> '+fail.length+' FAILURE(S)'):'>>> PROTOTYPE OK');
process.exit(fail.length?1:0);
