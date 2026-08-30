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
const go  = m => p.evaluate(m=>[...document.querySelectorAll('#modes button')].find(b=>b.dataset.mode===m).click(), m);
const gob = m => p.evaluate(m=>[...document.querySelectorAll('#bookModes button')].find(b=>b.dataset.book===m).click(), m);
const openPage = n => p.evaluate(n=>{
  const a=[...document.querySelectorAll('#rail .acc')].find(a=>new RegExp('Page: '+n+'(\\D|$)').test(a.textContent));
  if(a && !a.classList.contains('open')) a.querySelector('.acchead').click();
}, n);
// average luminance of a rect on the proof canvas, as a placement probe
const probe = (x,y,w,h) => p.evaluate(([x,y,w,h])=>{
  const c=document.querySelector('#sheet canvas');
  const d=c.getContext('2d').getImageData(Math.round(x*c.width),Math.round(y*c.height),
                                          Math.max(1,Math.round(w*c.width)),Math.max(1,Math.round(h*c.height))).data;
  let s=0; for(let i=0;i<d.length;i+=4) s+=(d[i]+d[i+1]+d[i+2])/3;
  return Math.round(s/(d.length/4));
},[x,y,w,h]);

ck('renders without errors', errs.length===0, errs[0]||'');

// ── the shell, carried forward from earlier rounds ──
ck('approve disabled until agree', await p.evaluate(()=>document.getElementById('approveBtn').disabled===true));
await p.click('#agree'); await p.waitForTimeout(200);
ck('approve enables after agree', await p.evaluate(()=>document.getElementById('approveBtn').disabled===false));
ck('page rows rendered', await p.evaluate(()=>document.querySelectorAll('#rail .acc').length)===9, 'want 9 (whole file + 8 pages)');
ck('filmstrip groups', await p.evaluate(()=>document.querySelectorAll('#stripBottom .grp').length)===5);
ck('agree is NOT inside the disclaimer note', await p.evaluate(()=>!document.querySelector('.note .agree')));
ck('full disclaimer text is still present, unabridged', await p.evaluate(()=>{
  const t=document.querySelector('.note').innerText.replace(/\s+/g,' ');
  return /Online proof approval is best for expediency/.test(t)
      && /request and purchase a Hardcopy Proof/.test(t)
      && /unsure about the template markings/.test(t)
      && /request a Professional Digital Proof/.test(t);}));
ck('agree sits directly above APPROVE and close', await p.evaluate(()=>{
  const ar=document.querySelector('.approvewrap .agree').getBoundingClientRect();
  return ar.bottom<=document.getElementById('approveBtn').getBoundingClientRect().top+1;}));
ck('close button is the outermost element', await p.evaluate(()=>
  document.querySelector('.topbar').lastElementChild===document.querySelector('.approvewrap')
  && document.querySelector('.approverow').lastElementChild===document.getElementById('closeBtn')));
ck('no "center spread" caption under any thumbnail', await p.evaluate(()=>
  ![...document.querySelectorAll('#stripBottom .pg')].some(e=>/spread/i.test(e.textContent))));
ck('all filmstrip cells identical box', await p.evaluate(()=>{
  const r=[...document.querySelectorAll('#stripBottom .pg')].map(e=>e.getBoundingClientRect());
  return r.length===8 && r.every(x=>Math.abs(x.width-r[0].width)<0.6 && Math.abs(x.height-r[0].height)<0.6);}));
ck('undo reads "Undo" with an icon', await p.evaluate(()=>{
  const u=document.querySelector('.undo');
  return /^\s*\S\s*Undo\s*$/.test(u.textContent) && !!u.querySelector('.ic');}));

// ── the legend: no toggle of its own, as small as it can be ──
ck('no "Hide legend" control anywhere', await p.evaluate(()=>
  !document.getElementById('hideLegend') && !document.getElementById('showLegend')
  && !/hide legend/i.test(document.body.innerText)));
ck('legend is a single thin row', await p.evaluate(()=>{
  const lb=document.getElementById('legendBar');
  const items=[...lb.querySelectorAll('.lgitem')].map(e=>e.getBoundingClientRect());
  return items.length===4 && items.every(x=>Math.abs(x.top-items[0].top)<3)
      && lb.getBoundingClientRect().height<=18;}),
  await p.evaluate(()=>Math.round(document.getElementById('legendBar').getBoundingClientRect().height)+'px tall'));
ck('hiding guides still hides the legend', await p.evaluate(()=>{
  const g=document.getElementById('hideGuides'); g.checked=true; g.dispatchEvent(new Event('change'));
  const hid=document.getElementById('legendBar').hidden===true;
  g.checked=false; g.dispatchEvent(new Event('change'));
  return hid && document.getElementById('legendBar').hidden===false;}));

// ── composition: the transforms actually recompose the art ──
await openPage(1); await p.waitForTimeout(300);
ck('the proof surface is a composed canvas, not an <img>', await p.evaluate(()=>
  !!document.querySelector('#sheet canvas') && !document.querySelector('#sheet img')));
const cropCorner = await probe(0.003,0.003,0.012,0.008);
ck('Crop places art at native size, so paper shows in the bleed', cropCorner>230,
  'top-left luminance '+cropCorner+' (paper is 255)');
ck('and that is reported as a bleed issue, measured not scripted', await p.evaluate(()=>
  /doesn.t fill bleed/.test(document.getElementById('issuePanel').innerText)));

await p.selectOption('.behavior select','fill'); await p.waitForTimeout(350);
const fillCorner = await probe(0.003,0.003,0.012,0.008);
ck('Fill covers the bleed, so the paper corner is gone', fillCorner<230,
  'top-left luminance '+fillCorner);
ck('and the bleed issue clears', await p.evaluate(()=>
  !/doesn.t fill bleed/.test(document.getElementById('issuePanel').innerText)));

// the anchor actually moves the art
await p.selectOption('.behavior select','crop'); await p.waitForTimeout(300);
const centred = { top: await probe(0.4,0.003,0.2,0.008), bottom: await probe(0.4,0.989,0.2,0.008) };
ck('Crop centred leaves paper at BOTH top and bottom', centred.top>230 && centred.bottom>230,
  JSON.stringify(centred));
await p.evaluate(()=>[...document.querySelectorAll('.anchorgrid .apt')].find(e=>e.title==='Top').click());
await p.waitForTimeout(350);
const topAnchored = { top: await probe(0.4,0.003,0.2,0.008), bottom: await probe(0.4,0.989,0.2,0.008) };
ck('anchoring Top moves the art up: ink at the top, paper still at the bottom',
  topAnchored.top<centred.top-8 && topAnchored.bottom>230, JSON.stringify(topAnchored));
await p.evaluate(()=>[...document.querySelectorAll('.anchorgrid .apt')].find(e=>e.title==='Bottom Right').click());
await p.waitForTimeout(350);
const brAnchored = { top: await probe(0.4,0.003,0.2,0.008), left: await probe(0.003,0.4,0.012,0.2) };
ck('anchoring Bottom Right leaves paper at top and left', brAnchored.top>230 && brAnchored.left>230,
  JSON.stringify(brAnchored));
await p.evaluate(()=>[...document.querySelectorAll('.anchorgrid .apt')].find(e=>e.title==='Center').click());
await p.waitForTimeout(300);

// ── one engine feeds every surface ──
ck('thumbnails come from the same composition', await p.evaluate(()=>
  /^url\("data:image\/png/.test(document.querySelector('#stripBottom .pg .thumb').style.backgroundImage)));
ck('changing a transform changes that page thumbnail', await p.evaluate(async ()=>{
  const get=()=>document.querySelector('#stripBottom .pg .thumb').style.backgroundImage;
  const before=get();
  const sel=document.querySelector('.behavior select');
  sel.value='stretch'; sel.dispatchEvent(new Event('change'));
  await new Promise(r=>setTimeout(r,300));
  return get()!==before;}));
await p.selectOption('.behavior select','crop'); await p.waitForTimeout(300);

// ── resolution readout, derived from the placement ──
ck('DPI badge reports the effective resolution', await p.evaluate(()=>
  /^\d+ DPI/.test(document.querySelector('#sheet .badge').textContent)),
  await p.evaluate(()=>document.querySelector('#sheet .badge').textContent));
await p.selectOption('.behavior select','scale'); await p.waitForTimeout(300);
const dpiAt100 = await p.evaluate(()=>parseInt(document.querySelector('#sheet .badge').textContent));
await p.evaluate(()=>{ const k=[...document.querySelectorAll('.subbox .knurl')];
  for(let i=0;i<210;i++) k[1].click(); });
await p.waitForTimeout(450);
const dpiAt200 = await p.evaluate(()=>parseInt(document.querySelector('#sheet .badge').textContent));
ck('scaling up drops the effective DPI', dpiAt200<dpiAt100, dpiAt100+' DPI at 100% -> '+dpiAt200+' DPI at 200%');
ck('and that low resolution is called out', await p.evaluate(()=>
  /DPI/.test(document.getElementById('issuePanel').innerText)));
ck('scale keeps an anchor, because scaled-up art crops', await p.evaluate(()=>
  document.querySelectorAll('.subbox .anchorgrid .apt').length===9));

// ── undo ──
ck('undo reverts the last transform', await p.evaluate(async ()=>{
  const sel=document.querySelector('.behavior select');
  sel.value='stretch'; sel.dispatchEvent(new Event('change'));
  await new Promise(r=>setTimeout(r,250));
  const changed=document.querySelector('.behavior select').value;
  document.getElementById('undoBtn').click();
  await new Promise(r=>setTimeout(r,250));
  return changed==='stretch' && document.querySelector('.behavior select').value!=='stretch';}));
ck('undo empties out and then disables itself', await p.evaluate(async ()=>{
  for(let i=0;i<80;i++){
    const u=document.getElementById('undoBtn');
    if(u.disabled) return true;
    u.click(); await new Promise(r=>setTimeout(r,12));
  }
  return document.getElementById('undoBtn').disabled;}));

// ── zoom ──
await openPage(1); await p.waitForTimeout(250);
ck('zoom changes the rendered sheet size', await p.evaluate(async ()=>{
  const w=()=>document.getElementById('sheet').getBoundingClientRect().width;
  const z=document.getElementById('zoom');
  z.value='fit'; z.dispatchEvent(new Event('change')); await new Promise(r=>setTimeout(r,180));
  const fit=w();
  z.value='200'; z.dispatchEvent(new Event('change')); await new Promise(r=>setTimeout(r,180));
  const big=w();
  z.value='25'; z.dispatchEvent(new Event('change')); await new Promise(r=>setTimeout(r,180));
  const small=w();
  z.value='fit'; z.dispatchEvent(new Event('change')); await new Promise(r=>setTimeout(r,180));
  return big>fit && small<fit;}));
ck('the stage scrolls rather than the page', await p.evaluate(()=>
  getComputedStyle(document.getElementById('stage')).overflow==='auto'));

// ── magnifier ──
ck('no lens until the magnifier is switched on', await p.evaluate(()=>!document.getElementById('lens')));
ck('magnifier shows a lens that follows the cursor', await p.evaluate(()=>{
  document.getElementById('mag').checked=true;
  const s=document.getElementById('sheet'), b=s.getBoundingClientRect();
  s.dispatchEvent(new MouseEvent('mousemove',{clientX:b.left+b.width*0.4,clientY:b.top+b.height*0.4,bubbles:true}));
  const l1=document.getElementById('lens'); if(!l1) return false;
  const x1=l1.style.left;
  s.dispatchEvent(new MouseEvent('mousemove',{clientX:b.left+b.width*0.7,clientY:b.top+b.height*0.6,bubbles:true}));
  const l2=document.getElementById('lens');
  return !!l2 && !!l2.querySelector('canvas') && l2.style.left!==x1;}));
ck('the lens leaves with the cursor', await p.evaluate(()=>{
  document.getElementById('sheet').dispatchEvent(new MouseEvent('mouseleave',{bubbles:true}));
  return !document.getElementById('lens');}));
ck('turning the magnifier off removes the lens', await p.evaluate(()=>{
  const s=document.getElementById('sheet'), b=s.getBoundingClientRect();
  s.dispatchEvent(new MouseEvent('mousemove',{clientX:b.left+b.width*0.4,clientY:b.top+b.height*0.4,bubbles:true}));
  const had=!!document.getElementById('lens');
  const m=document.getElementById('mag'); m.checked=false; m.dispatchEvent(new Event('change'));
  return had && !document.getElementById('lens');}));

// ── approval is a statement about the proof, not the mockup ──
await p.evaluate(()=>{ const a=document.getElementById('agree'); if(!a.checked){a.checked=true;a.dispatchEvent(new Event('change'));} });
await go('book'); await p.waitForTimeout(450);
ck('3D disables the agree checkbox', await p.evaluate(()=>document.getElementById('agree').disabled===true));
ck('3D disables APPROVE even though agree is ticked', await p.evaluate(()=>
  document.getElementById('agree').checked===true && document.getElementById('approveBtn').disabled===true));
ck('3D says why the gate is unavailable', await p.evaluate(()=>
  /proof view only/i.test(document.getElementById('agreeReq').textContent)));
ck('returning to Proof restores the gate', await p.evaluate(async ()=>{
  [...document.querySelectorAll('#modes button')].find(b=>b.dataset.mode==='proof').click();
  await new Promise(r=>setTimeout(r,300));
  return document.getElementById('agree').disabled===false
      && document.getElementById('approveBtn').disabled===false;}));

// ── 3D rendering ──
await go('book'); await p.waitForTimeout(450);
await gob('closed'); await p.waitForTimeout(350);
ck('3D swaps the view controls for the book controls', await p.evaluate(()=>
  document.getElementById('viewGroup').hidden===true && document.getElementById('bookGroup').hidden===false));
ck('3D hides the legend and the artwork-edit panel', await p.evaluate(()=>
  document.getElementById('legendBar').hidden===true
  && getComputedStyle(document.getElementById('railEdit')).display==='none'));
ck('3D puts the thumbnails in the right rail', await p.evaluate(()=>
  document.querySelectorAll('#stripRail .pg').length===8
  && document.querySelectorAll('#stripBottom .pg').length===0));
ck('closed book builds all six faces', await p.evaluate(()=>{
  const f=[...document.querySelectorAll('#bpv .bpface')];
  return f.length===6 && ['front','back','topedge','bottomedge','foreedge','spineedge']
    .every(c=>f.some(e=>e.classList.contains(c)));}));
ck('the cover face is a composed page, not the raw source', await p.evaluate(()=>
  /url\("data:image\/png/.test(document.querySelector('#bpv .bpface.front').style.backgroundImage)));
ck('saddle depth comes from the page count, no spine face', await p.evaluate(()=>{
  const half=parseFloat(document.querySelector('#bpv .bpclosed').style.getPropertyValue('--half-depth'));
  return Math.abs(half-(Math.max(2,8*0.3)/2))<0.001 && !document.querySelector('#bpv .bpface.spine');}));
ck('viewport has perspective and the scene is 3D', await p.evaluate(()=>{
  const v=getComputedStyle(document.getElementById('bpv'));
  const sc=getComputedStyle(document.getElementById('bpscene'));
  return v.perspective!=='none' && sc.transformStyle==='preserve-3d' && sc.transform!=='none';}));
ck('drag rotates the book', await p.evaluate(()=>{
  const vp=document.getElementById('bpv');
  vp.dispatchEvent(new MouseEvent('mousedown',{clientX:400,clientY:300,bubbles:true,cancelable:true}));
  window.dispatchEvent(new MouseEvent('mousemove',{clientX:500,clientY:340,bubbles:true}));
  const t=document.getElementById('bpscene').style.transform;
  window.dispatchEvent(new MouseEvent('mouseup',{bubbles:true}));
  return /rotateY\(15deg\)/.test(t) && /rotateX\(-27deg\)/.test(t);}));
ck('pitch is clamped, yaw is free', await p.evaluate(()=>{
  const vp=document.getElementById('bpv');
  vp.dispatchEvent(new MouseEvent('mousedown',{clientX:0,clientY:0,bubbles:true,cancelable:true}));
  window.dispatchEvent(new MouseEvent('mousemove',{clientX:2000,clientY:-4000,bubbles:true}));
  const t=document.getElementById('bpscene').style.transform;
  window.dispatchEvent(new MouseEvent('mouseup',{bubbles:true}));
  return /rotateX\(60deg\)/.test(t) && !/rotateY\(60deg\)/.test(t);}));
ck('"Reset view" returns the starting angle', await p.evaluate(()=>{
  document.getElementById('resetView').click();
  return document.getElementById('bpscene').style.transform==='rotateX(-15deg) rotateY(-25deg)';}));
await gob('open'); await p.waitForTimeout(400);
ck('Pages view shows two leaves with a gutter', await p.evaluate(()=>
  document.querySelectorAll('#bpv .bppage').length===2 && !!document.querySelector('#bpv .bpspineshadow')));
ck('covers never appear as a spread leaf', await p.evaluate(()=>{
  const seen=new Set();
  for(let i=0;i<6;i++){
    [...document.querySelectorAll('#bpv .bppagenum')].forEach(e=>seen.add(e.textContent));
    const n=document.getElementById('nextSpread'); if(n.disabled) break; n.click();
  }
  return !seen.has('1') && !seen.has('8') && seen.size===6;}));
await p.evaluate(()=>[...document.querySelectorAll('#stripRail .pg')].find(e=>/P2/.test(e.textContent)).click());
await p.waitForTimeout(300);
ck('nav reads the spread and back is disabled at the start', await p.evaluate(()=>
  document.querySelector('.navlbl').textContent.replace(/–/g,'-')==='Pages 2-3'
  && document.getElementById('prevSpread').disabled===true));
ck('arrow keys step spreads', await p.evaluate(()=>{
  window.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true}));
  const fwd=[...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',');
  window.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowLeft',bubbles:true}));
  const back=[...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',');
  return fwd==='4,5' && back==='2,3';}));
ck('stepping keeps the rail thumbnails in step', await p.evaluate(()=>{
  document.getElementById('nextSpread').click();
  return [...document.querySelectorAll('#stripRail .pg.sel')].map(e=>e.textContent.match(/P(\d)/)[1]).join(',')==='4,5';}));
ck('clicking a cover thumbnail returns to the closed book', await p.evaluate(()=>{
  [...document.querySelectorAll('#stripRail .pg')].find(e=>/P8/.test(e.textContent)).click();
  return !!document.querySelector('#bpv .bpclosed') && !document.querySelector('#bpv .bpopen');}));
ck('drag handlers do not stack across re-renders', await p.evaluate(()=>{
  const gb=b=>[...document.querySelectorAll('#bookModes button')].find(x=>x.dataset.book===b).click();
  for(let i=0;i<6;i++){ gb('open'); gb('closed'); }
  document.getElementById('resetView').click();
  const vp=document.getElementById('bpv');
  vp.dispatchEvent(new MouseEvent('mousedown',{clientX:0,clientY:0,bubbles:true,cancelable:true}));
  window.dispatchEvent(new MouseEvent('mousemove',{clientX:100,clientY:0,bubbles:true}));
  const t=document.getElementById('bpscene').style.transform;
  window.dispatchEvent(new MouseEvent('mouseup',{bubbles:true}));
  return /rotateY\(15deg\)/.test(t);}));

await go('proof'); await p.waitForTimeout(350);
ck('no horizontal page scroll', await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
ck('no page errors overall', errs.length===0, errs[0]||'');
await b.close();
console.log(fail.length?('>>> '+fail.length+' FAILURE(S)'):'>>> PROTOTYPE OK');
process.exit(fail.length?1:0);
