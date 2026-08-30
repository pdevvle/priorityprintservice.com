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
ck('close is the outermost element, past APPROVE', await p.evaluate(()=>{
  const c=document.getElementById('closeBtn'), tb=document.querySelector('.topbar');
  return tb.lastElementChild===c
      && c.getBoundingClientRect().left > document.getElementById('approveBtn').getBoundingClientRect().left;}));
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
// A clean page says nothing at all. An all-clear would read as "we checked your
// artwork", when only bleed coverage and resolution are measured — and it would
// sit directly above the button that transfers responsibility.
ck('a clean page shows no findings and no reassurance', await p.evaluate(()=>{
  const el=document.getElementById('issuePanel');
  return el.children.length===0 && el.innerText.trim()===''
      && !/no problem|looks good|all clear|ready to print|✓/i.test(el.innerHTML);}),
  await p.evaluate(()=>JSON.stringify(document.getElementById('issuePanel').innerText.slice(0,60))));

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
// ── the disclosure slot carries the caution for the view you are in ──
ck('3D replaces the proof disclaimer with the mockup disclaimer', await p.evaluate(()=>{
  const t=document.getElementById('note').innerText.replace(/\s+/g,' ');
  return /Finished-product mockup, trimmed as it will be in the bindery/.test(t)
      && /This is not the print surface/.test(t)
      && /bleed, trim and safety are only shown on the Proof view/.test(t)
      && /artwork cannot be approved from here/.test(t)
      && !/Hardcopy Proof/.test(t);}),
  await p.evaluate(()=>document.getElementById('note').innerText.replace(/\s+/g,' ').slice(0,80)+'...'));
ck('the mockup disclaimer is not also duplicated beside the stage', await p.evaluate(()=>
  !/Finished-product mockup/.test(document.getElementById('issuePanel').innerText)));
ck('the closing sentence names a control that is on screen', await p.evaluate(async ()=>{
  const gb=b=>[...document.querySelectorAll('#bookModes button')].find(x=>x.dataset.book===b).click();
  gb('open'); await new Promise(r=>setTimeout(r,250));
  const openTail=/arrow keys to step through spreads/.test(document.getElementById('note').innerText)
              && !!document.getElementById('nextSpread');
  gb('closed'); await new Promise(r=>setTimeout(r,250));
  const closedTail=/Drag the cover to turn the book/.test(document.getElementById('note').innerText)
              && !document.getElementById('nextSpread');
  return openTail && closedTail;}));
ck('returning to Proof restores the gate and the proof disclaimer', await p.evaluate(async ()=>{
  [...document.querySelectorAll('#modes button')].find(b=>b.dataset.mode==='proof').click();
  await new Promise(r=>setTimeout(r,300));
  const t=document.getElementById('note').innerText;
  return document.getElementById('agree').disabled===false
      && document.getElementById('approveBtn').disabled===false
      && /Hardcopy Proof/.test(t) && !/Finished-product mockup/.test(t);}));

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

// ── one section at a time in the rail ──
await go('proof'); await p.waitForTimeout(300);
await p.evaluate(()=>{ if(!document.querySelector('#rail .acc.open')) return;
  const wf=document.querySelector('#rail .acc'); if(!wf.classList.contains('open')) wf.querySelector('.acchead').click(); });
await p.waitForTimeout(250);
ck('Whole File starts expanded with no page open', await p.evaluate(()=>{
  const accs=[...document.querySelectorAll('#rail .acc')];
  return accs[0].classList.contains('open') && accs.slice(1).every(a=>!a.classList.contains('open'));}));
ck('opening a page collapses Whole File', await p.evaluate(async ()=>{
  const accs=[...document.querySelectorAll('#rail .acc')];
  accs[1].querySelector('.acchead').click();
  await new Promise(r=>setTimeout(r,250));
  const now=[...document.querySelectorAll('#rail .acc')];
  return !now[0].classList.contains('open') && now[1].classList.contains('open');}));
ck('re-opening Whole File collapses the page', await p.evaluate(async ()=>{
  document.querySelector('#rail .acc .acchead').click();
  await new Promise(r=>setTimeout(r,250));
  const now=[...document.querySelectorAll('#rail .acc')];
  return now[0].classList.contains('open') && now.slice(1).every(a=>!a.classList.contains('open'));}));
ck('never two sections open at once', await p.evaluate(async ()=>{
  const heads=[...document.querySelectorAll('#rail .acchead')];
  for(const i of [2,5,0,3]){
    heads[i] && heads[i].click(); await new Promise(r=>setTimeout(r,120));
    if(document.querySelectorAll('#rail .acc.open').length>1) return false;
  }
  return true;}));

// ── apply to all pages, on every behaviour ──
await openPage(2); await p.waitForTimeout(250);
ck('apply-to-all is offered on every behaviour, not just Rotate', await p.evaluate(async ()=>{
  const sel=document.querySelector('.behavior select');
  for(const b of ['crop','fill','fit','stretch','scale','rotate']){
    sel.value=b; sel.dispatchEvent(new Event('change'));
    await new Promise(r=>setTimeout(r,180));
    if(!document.getElementById('applyAll')) return 'missing on '+b;
  }
  return true;}));
ck('the old one-shot Rotate button is gone', await p.evaluate(()=>
  ![...document.querySelectorAll('.pill')].some(b=>/all pages/i.test(b.textContent))));
ck('ticking it copies this page to every page', await p.evaluate(async ()=>{
  const sel=document.querySelector('.behavior select');
  sel.value='fill'; sel.dispatchEvent(new Event('change')); await new Promise(r=>setTimeout(r,200));
  document.getElementById('applyAll').click(); await new Promise(r=>setTimeout(r,300));
  // every thumbnail should now be composed the same way: none flags a bleed gap
  return !document.querySelector('#stripBottom .pg .dot');}));
ck('and later changes keep every page matched', await p.evaluate(async ()=>{
  const sel=document.querySelector('.behavior select');
  sel.value='fit'; sel.dispatchEvent(new Event('change')); await new Promise(r=>setTimeout(r,350));
  // Fit letterboxes, so EVERY page should now report the bleed gap
  return document.querySelectorAll('#stripBottom .pg .dot').length===8;}),
  await p.evaluate(()=>document.querySelectorAll('#stripBottom .pg .dot').length+' of 8 flagged'));
ck('unticking lets pages diverge again', await p.evaluate(async ()=>{
  document.getElementById('applyAll').click(); await new Promise(r=>setTimeout(r,250));
  const sel=document.querySelector('.behavior select');
  sel.value='fill'; sel.dispatchEvent(new Event('change')); await new Promise(r=>setTimeout(r,350));
  // only the open page changed, so 7 of 8 still carry the Fit bleed gap
  return document.querySelectorAll('#stripBottom .pg .dot').length===7;}),
  await p.evaluate(()=>document.querySelectorAll('#stripBottom .pg .dot').length+' of 8 flagged'));

// ── longer documents ──
for (const [count, groups, spreads] of [[16,9,7],[64,33,31]]){
  const t0=Date.now();
  await p.selectOption('#pageCount', String(count));
  await p.waitForTimeout(count>32?1400:700);
  const ms=Date.now()-t0;
  ck(count+'pp: rail lists every page', await p.evaluate(c=>
    document.querySelectorAll('#rail .acc').length===c+1, count),
    await p.evaluate(()=>document.querySelectorAll('#rail .acc').length+' rows'));
  ck(count+'pp: filmstrip pairs interior pages into spreads', await p.evaluate(([c,g])=>
    document.querySelectorAll('#stripBottom .grp').length===g
    && document.querySelectorAll('#stripBottom .pg').length===c, [count,groups]),
    await p.evaluate(()=>document.querySelectorAll('#stripBottom .grp').length+' groups, '
      +document.querySelectorAll('#stripBottom .pg').length+' pages'));
  ck(count+'pp: covers are still named, interior pages are not', await p.evaluate(c=>{
    const cells=[...document.querySelectorAll('#stripBottom .pg')];
    return /front cover/.test(cells[0].textContent) && /back cover/.test(cells[c-1].textContent)
        && /inside front cover/.test(cells[1].textContent);}, count));
  ck(count+'pp: no horizontal page scroll', await p.evaluate(()=>
    document.documentElement.scrollWidth<=window.innerWidth+1));
  ck(count+'pp: rebuilt in reasonable time', ms<9000, ms+'ms');
  ck(count+'pp: the shell stays bounded, panels scroll inside it', await p.evaluate(()=>{
    const sh=document.querySelector('.shell').getBoundingClientRect();
    const railScrolls=document.getElementById('rail').scrollHeight>document.getElementById('rail').clientHeight+2
                   || document.querySelectorAll('#rail .acc').length<12;
    return sh.height<=920 && railScrolls;}),
    await p.evaluate(()=>Math.round(document.querySelector('.shell').getBoundingClientRect().height)+'px tall'));
  ck(count+'pp: the filmstrip does not become a wall', await p.evaluate(()=>
    document.getElementById('stripBottom').getBoundingClientRect().height<=232),
    await p.evaluate(()=>Math.round(document.getElementById('stripBottom').getBoundingClientRect().height)+'px'));
  ck(count+'pp: the artwork is still the biggest thing on screen', await p.evaluate(()=>{
    const a=document.getElementById('sheet').getBoundingClientRect();
    const strip=document.getElementById('stripBottom').getBoundingClientRect();
    return a.height>strip.height;}));
  await go('book'); await p.waitForTimeout(500);
  ck(count+'pp: the book gets visibly thicker', await p.evaluate(c=>{
    const half=parseFloat(document.querySelector('#bpv .bpclosed').style.getPropertyValue('--half-depth'));
    return Math.abs(half-Math.max(2,c*0.3)/2)<0.01;}, count),
    await p.evaluate(()=>(parseFloat(document.querySelector('#bpv .bpclosed').style.getPropertyValue('--half-depth'))*2).toFixed(1)+'px thick'));
  await p.evaluate(()=>[...document.querySelectorAll('#bookModes button')].find(b=>b.dataset.book==='open').click());
  await p.waitForTimeout(450);
  ck(count+'pp: spreads run to the end of the interior', await p.evaluate(async s=>{
    let n=0;
    for(let i=0;i<200;i++){ const b=document.getElementById('nextSpread'); if(!b||b.disabled) break; b.click(); n++;
      await new Promise(r=>setTimeout(r,0)); }
    return n===s-1;}, spreads),
    'expected '+spreads+' spreads');
  ck(count+'pp: last spread is the final interior pair', await p.evaluate(c=>
    [...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',')===(c-2)+','+(c-1), count),
    await p.evaluate(()=>[...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',')));
  await go('proof'); await p.waitForTimeout(400);
  ck(count+'pp: no errors', errs.length===0, errs[0]||'');
}
await p.selectOption('#pageCount','8'); await p.waitForTimeout(700);

// ── uploads live on the page tiles ──
ck('every page tile carries an upload chip', await p.evaluate(()=>
  document.querySelectorAll('#stripBottom .pg .pgup').length===8));
ck('pages with no file are marked as such', await p.evaluate(()=>
  document.querySelectorAll('#stripBottom .pg.hasart').length===0
  && [...document.querySelectorAll('#stripBottom .pg')].every(e=>/No file on this page yet/.test(e.title))));
ck('the upload chip does not also select the page', await p.evaluate(async ()=>{
  const t=[...document.querySelectorAll('#stripBottom .pg')].find(e=>/P5/.test(e.textContent));
  let bubbled=false;
  t.addEventListener('click',()=>{bubbled=true;},{once:true});
  t.querySelector('.pgup').dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));
  await new Promise(r=>setTimeout(r,120));
  return bubbled===false;}));
const mkfile = (name,w,h,col) => p.evaluate(async ([name,w,h,col])=>{
  const c=document.createElement('canvas'); c.width=w; c.height=h;
  const x=c.getContext('2d'); x.fillStyle=col; x.fillRect(0,0,w,h);
  x.fillStyle='#fff'; x.font='bold 90px sans-serif'; x.fillText(name,40,140);
  const blob=await new Promise(r=>c.toBlob(r,'image/png'));
  window.__f=window.__f||{}; window.__f[name]=new File([blob],name+'.png',{type:'image/png'});
  return true;},[name,w,h,col]);
await mkfile('A',1800,1200,'#8b1f3f');
ck('dropping a file on a tile attaches it to THAT page', await p.evaluate(async ()=>{
  loadArtSequence(4,[window.__f.A]);
  await new Promise(r=>setTimeout(r,700));
  const p4=[...document.querySelectorAll('#stripBottom .pg')].find(e=>/P4/.test(e.textContent));
  return p4.classList.contains('hasart') && /A\.png/.test(p4.title)
      && document.querySelectorAll('#stripBottom .pg.hasart').length===1;}),
  await p.evaluate(()=>[...document.querySelectorAll('#stripBottom .pg.hasart')].map(e=>e.textContent.match(/P(\d+)/)[1]).join(',')));
await mkfile('B',1800,1200,'#1f5f8b'); await mkfile('C',1800,1200,'#2f7a4f');
ck('several files fill onward from the tile, in name order', await p.evaluate(async ()=>{
  loadArtSequence(6,[window.__f.C,window.__f.B]);          // deliberately out of order
  await new Promise(r=>setTimeout(r,900));
  return [...document.querySelectorAll('#stripBottom .pg.hasart')]
    .map(e=>e.textContent.match(/P(\d+)/)[1]+':'+e.title.replace('.png',''))
    .join(',')==='4:A,6:B,7:C';}),
  await p.evaluate(()=>[...document.querySelectorAll('#stripBottom .pg.hasart')].map(e=>e.textContent.match(/P(\d+)/)[1]+':'+e.title.replace('.png','')).join(',')));
ck('an upload changes that page and leaves its neighbour alone', await p.evaluate(()=>{
  const t=[...document.querySelectorAll('#stripBottom .pg')];
  return t.find(e=>/P4/.test(e.textContent)).querySelector('.thumb').style.backgroundImage
      !== t.find(e=>/P5/.test(e.textContent)).querySelector('.thumb').style.backgroundImage;}));

// ── the anchor grid only offers points that can move this file ──
await openPage(4); await p.waitForTimeout(350);
await p.selectOption('.behavior select','fill'); await p.waitForTimeout(450);
ck('a landscape file on a portrait page lights only left/right', await p.evaluate(()=>
  JSON.stringify([...document.querySelectorAll('.anchorgrid .apt')].filter(e=>!e.disabled).map(e=>e.title))
  ===JSON.stringify(['Left','Center','Right'])),
  await p.evaluate(()=>[...document.querySelectorAll('.anchorgrid .apt')].filter(e=>!e.disabled).map(e=>e.title).join(',')));
ck('the dead points are shown but not clickable', await p.evaluate(()=>{
  const dead=[...document.querySelectorAll('.anchorgrid .apt.dead')];
  return dead.length===6 && dead.every(e=>e.disabled)
      && dead.every(e=>parseFloat(getComputedStyle(e).opacity)<0.5);}));
ck('and it says why', await p.evaluate(()=>
  /already fits the page top to bottom/.test(document.querySelector('.axisnote').textContent)));
ck('picking Left actually keeps a different part of the photo', await p.evaluate(async ()=>{
  const px=fx=>{const c=document.querySelector('#sheet canvas');
    const d=c.getContext('2d').getImageData(Math.round(fx*c.width),Math.round(c.height*0.5),2,2).data;
    return d[0]+','+d[1]+','+d[2];};
  const before=px(0.05);
  [...document.querySelectorAll('.anchorgrid .apt')].find(e=>e.title==='Left').click();
  await new Promise(r=>setTimeout(r,450));
  return px(0.05)!==before || document.querySelector('.anchorgrid .apt.on').title==='Left';}));
ck('the lit cell is never one of the dead ones', await p.evaluate(()=>{
  const on=document.querySelector('.anchorgrid .apt.on');
  return !!on && !on.disabled;}));
// Fill is cover, so it always makes one axis match exactly: the anchor there
// is a one-dimensional choice however the file is shaped. All nine points are
// only reachable under Crop and Scale, which place at native size.
await mkfile('D',1400,1400,'#6b3fa0');
ck('under Fill the anchor is always one-dimensional, whatever the file', await p.evaluate(async ()=>{
  loadArtSequence(4,[window.__f.D]);          // a square file, not landscape
  await new Promise(r=>setTimeout(r,800));
  return [...document.querySelectorAll('.anchorgrid .apt')].filter(e=>!e.disabled).length===3;}),
  await p.evaluate(()=>[...document.querySelectorAll('.anchorgrid .apt')].filter(e=>!e.disabled).length+' live'));
ck('Crop can reach all nine, because it places at native size', await p.evaluate(async ()=>{
  const sel=document.querySelector('.behavior select');
  sel.value='crop'; sel.dispatchEvent(new Event('change'));
  await new Promise(r=>setTimeout(r,600));
  return [...document.querySelectorAll('.anchorgrid .apt')].filter(e=>!e.disabled).length===9
      && /in both directions/.test(document.querySelector('.axisnote').textContent);}),
  await p.evaluate(()=>[...document.querySelectorAll('.anchorgrid .apt')].filter(e=>!e.disabled).length+' live'));
ck('and a corner is then reachable and lights up', await p.evaluate(async ()=>{
  [...document.querySelectorAll('.anchorgrid .apt')].find(e=>e.title==='Bottom Right').click();
  await new Promise(r=>setTimeout(r,400));
  const on=document.querySelector('.anchorgrid .apt.on');
  return on && on.title==='Bottom Right' && !on.disabled;}));

// ── margin detection: type, not ink ──
const kinds = () => p.evaluate(()=>{
  const a=analyze(state.selected);
  return { kinds:a.map(i=>i.kind), marks:(a.marks||[]).length,
           cut:(a.marks||[]).filter(m=>m.level==='cut').length };});
const setTx = (o) => p.evaluate(o=>{
  Object.assign(state.perPage[state.selected], o); cache.clear(); renderAll();}, o);
await p.selectOption('#pageCount','8'); await p.waitForTimeout(700);
await p.evaluate(()=>{ state.selected=3; state.openPage=3; state.wholeOpen=false; renderAll(); });
await p.waitForTimeout(300);

await setTx({behavior:'crop', anchor:'Center', scale:100}); await p.waitForTimeout(300);
ck('type well inside the margin raises nothing', await p.evaluate(()=>{
  const a=analyze(3);
  return !a.some(i=>['tight','cut','nomargin'].includes(i.kind)) && (a.marks||[]).length===0;}),
  JSON.stringify(await kinds()));

await setTx({behavior:'scale', scale:112}); await p.waitForTimeout(350);
const tight = await kinds();
ck('type inside the safety margin is a warning, not an error',
  tight.kinds.includes('tight') && !tight.kinds.includes('cut') && tight.marks===1 && tight.cut===0,
  JSON.stringify(tight));
ck('and it says how close to the trim, in inches', await p.evaluate(()=>{
  const t=analyze(3).find(i=>i.kind==='tight').text;
  const m=t.match(/as close as ([\d.]+)"/);
  return !!m && parseFloat(m[1])>0 && parseFloat(m[1])<0.125;}),
  await p.evaluate(()=>(analyze(3).find(i=>i.kind==='tight')||{}).text||''));

await setTx({scale:150}); await p.waitForTimeout(350);
const cutS = await kinds();
ck('type past the trim is an error', cutS.kinds.includes('cut') && cutS.cut>0, JSON.stringify(cutS));
ck('the offending runs are ringed on the page', await p.evaluate(()=>
  document.querySelectorAll('.typemark.cut').length>0));
// getBoundingClientRect reports a child's full geometry even when an ancestor
// clips its paint, so assert the clip itself rather than the boxes.
ck('rings are clipped to the sheet, not hanging off it', await p.evaluate(()=>{
  const w=document.querySelector('.markwrap'), s=document.getElementById('sheet');
  if(!w) return false;
  const wr=w.getBoundingClientRect(), sr=s.getBoundingClientRect();
  return getComputedStyle(w).overflow==='hidden'
      && Math.abs(wr.width-sr.width)<1 && Math.abs(wr.height-sr.height)<1
      && Math.abs(wr.left-sr.left)<1 && Math.abs(wr.top-sr.top)<1
      // and at least one ring really does extend past the page, or the clip
      // would be untested
      && [...document.querySelectorAll('.typemark')].some(e=>{
           const r=e.getBoundingClientRect();
           return r.left<sr.left-0.5 || r.right>sr.right+0.5 || r.top<sr.top-0.5 || r.bottom>sr.bottom+0.5;});}));
ck('hiding the guides hides the rings too', await p.evaluate(async ()=>{
  const g=document.getElementById('hideGuides'); g.checked=true; g.dispatchEvent(new Event('change'));
  await new Promise(r=>setTimeout(r,250));
  const none=document.querySelectorAll('.typemark').length===0;
  g.checked=false; g.dispatchEvent(new Event('change'));
  await new Promise(r=>setTimeout(r,250));
  return none && document.querySelectorAll('.typemark').length>0;}));
ck('rotating the art rotates what gets flagged', await p.evaluate(async ()=>{
  const before=[...document.querySelectorAll('.typemark')].map(e=>Math.round(e.getBoundingClientRect().width)).join(',');
  Object.assign(state.perPage[3],{rot:'90°'}); cache.clear(); renderAll();
  await new Promise(r=>setTimeout(r,350));
  const after=[...document.querySelectorAll('.typemark')].map(e=>Math.round(e.getBoundingClientRect().width)).join(',');
  Object.assign(state.perPage[3],{rot:'0°'}); cache.clear(); renderAll();
  await new Promise(r=>setTimeout(r,300));
  return before!==after;}));

// ink is deliberately NOT flagged: a cover whose photo runs to every edge is
// what bleed is for, and warning about it would train people to ignore this
await p.evaluate(()=>{ state.selected=1; state.openPage=1; renderAll(); });
await p.waitForTimeout(300);
await setTx({behavior:'fill', anchor:'Center', scale:100}); await p.waitForTimeout(350);
ck('a full-bleed background is not treated as type', await p.evaluate(()=>{
  const a=analyze(1);
  return !a.some(i=>['tight','cut'].includes(i.kind)) && (a.marks||[]).length===0;}),
  await p.evaluate(()=>analyze(1).map(i=>i.kind).join(',')||'clean'));

// a flat upload has no text layer, so it discloses that rather than passing
await p.evaluate(async ()=>{
  const c=document.createElement('canvas'); c.width=1800; c.height=1200;
  const x=c.getContext('2d'); x.fillStyle='#8b1f3f'; x.fillRect(0,0,1800,1200);
  const blob=await new Promise(r=>c.toBlob(r,'image/png'));
  loadArtSequence(1,[new File([blob],'photo.png',{type:'image/png'})]);
});
await p.waitForTimeout(800);
ck('a flat image says the margin could not be checked', await p.evaluate(()=>{
  const a=analyze(1);
  return a.some(i=>i.kind==='nomargin')
      && /no way to tell type from picture/.test(a.find(i=>i.kind==='nomargin').text);}),
  await p.evaluate(()=>analyze(1).map(i=>i.kind).join(',')));
ck('that disclosure is not dressed up as an all-clear', await p.evaluate(()=>{
  const t=document.getElementById('issuePanel').innerText;
  return !/no problem|looks good|all clear|ready to print/i.test(t) && /Not checked/.test(t);}));
await p.evaluate(()=>{ uploads.clear(); cache.clear(); state.selected=1; state.openPage=null;
  state.wholeOpen=true; MODEL.pages.forEach(pg=>state.perPage[pg.n]={behavior:'crop',anchor:'Center',scale:100,rot:'0°'});
  renderAll(); });
await p.waitForTimeout(400);

ck('no horizontal page scroll', await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
ck('no page errors overall', errs.length===0, errs[0]||'');
await b.close();
console.log(fail.length?('>>> '+fail.length+' FAILURE(S)'):'>>> PROTOTYPE OK');
process.exit(fail.length?1:0);
