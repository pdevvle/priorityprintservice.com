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
// ── revision 7: the gate moves out of the note, above APPROVE + close ──
ck('agree is NOT inside the disclaimer note any more', await p.evaluate(()=>!document.querySelector('.note .agree')));
ck('full disclaimer text is still present, unabridged', await p.evaluate(()=>{
  const t=document.querySelector('.note').innerText.replace(/\s+/g,' ');
  return /Online proof approval is best for expediency/.test(t)
      && /request and purchase a Hardcopy Proof/.test(t)
      && /unsure about the template markings/.test(t)
      && /request a Professional Digital Proof/.test(t);}));
ck('agree sits directly above APPROVE and close', await p.evaluate(()=>{
  const a=document.querySelector('.approvewrap .agree'), w=document.querySelector('.approvewrap');
  if(!a||!w.contains(document.getElementById('approveBtn'))||!w.contains(document.getElementById('closeBtn'))) return false;
  const ar=a.getBoundingClientRect(), br=document.getElementById('approveBtn').getBoundingClientRect();
  const cr=document.getElementById('closeBtn').getBoundingClientRect();
  return ar.bottom<=br.top+1 && ar.bottom<=cr.top+1;}));
ck('header got shorter, not taller', await p.evaluate(()=>document.querySelector('.header').getBoundingClientRect().height<200),
  await p.evaluate(()=>Math.round(document.querySelector('.header').getBoundingClientRect().height)+'px'));

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
  return tb.lastElementChild===document.querySelector('.approvewrap')
      && document.querySelector('.approverow').lastElementChild===c
      && c.getBoundingClientRect().left > document.getElementById('approveBtn').getBoundingClientRect().left;}));
ck('undo reads "Undo" with an icon', await p.evaluate(()=>{
  const u=document.querySelector('.undo');
  return /^\s*\S\s*Undo\s*$/.test(u.textContent) && !!u.querySelector('.ic');}));

// ── revision 6: Scale is a 50–200% slider with 0.5% knurled controls ──
await p.evaluate(()=>{const h=[...document.querySelectorAll('#rail .acc .acchead')].find(b=>/Page: 1/.test(b.textContent)); if(!document.querySelector('#rail .acc.open .behavior')) h.click();});
await p.waitForTimeout(150);
// ── revision 8: nine anchor points, corners included, new heading ──
const anchors = await p.evaluate(()=>[...document.querySelectorAll('.anchorgrid .apt')].map(b=>b.title));
ck('crop offers all 9 reference points', JSON.stringify(anchors)===JSON.stringify(
  ['Top Left','Top','Top Right','Left','Center','Right','Bottom Left','Bottom','Bottom Right']), JSON.stringify(anchors));
ck('anchors laid out 3 across, 3 down', await p.evaluate(()=>{
  const r=[...document.querySelectorAll('.anchorgrid .apt')].map(e=>e.getBoundingClientRect());
  const rows=[...new Set(r.map(x=>Math.round(x.top)))], cols=[...new Set(r.map(x=>Math.round(x.left)))];
  return rows.length===3 && cols.length===3;}));
ck('anchor position in the grid matches its name', await p.evaluate(()=>{
  const b=[...document.querySelectorAll('.anchorgrid .apt')];
  const g=n=>b.find(e=>e.title===n).getBoundingClientRect();
  return g('Top Left').top<g('Center').top && g('Bottom Right').top>g('Center').top
      && g('Top Left').left<g('Center').left && g('Bottom Right').left>g('Center').left
      && Math.abs(g('Top').top-g('Top Left').top)<2 && Math.abs(g('Left').left-g('Top Left').left)<2;}));
ck('heading reads "Crop Anchor: which reference point for art"', await p.evaluate(()=>
  document.querySelector('.subbox .subtitle').textContent.trim()==='Crop Anchor: which reference point for art'),
  await p.evaluate(()=>document.querySelector('.subbox .subtitle').textContent.trim()));
ck('heading is not shouted in all caps', await p.evaluate(()=>
  getComputedStyle(document.querySelector('.subbox .subtitle')).textTransform==='none'));
ck('Center is selected by default', await p.evaluate(()=>document.querySelector('.anchorgrid .apt.on').title==='Center'));
ck('picking a corner selects exactly that one', await p.evaluate(()=>{
  [...document.querySelectorAll('.anchorgrid .apt')].find(e=>e.title==='Bottom Right').click();
  const on=[...document.querySelectorAll('.anchorgrid .apt.on')];
  return on.length===1 && on[0].title==='Bottom Right';}));
await p.selectOption('.behavior select','fill'); await p.waitForTimeout(150);
ck('fill offers the same 9 points, with its own heading', await p.evaluate(()=>
  document.querySelectorAll('.anchorgrid .apt').length===9
  && document.querySelector('.subbox .subtitle').textContent.trim()==='Fill Anchor: which reference point for art'));
await p.selectOption('.behavior select','fit'); await p.waitForTimeout(150);
ck('fit shows a note, no controls', await p.evaluate(()=>!!document.querySelector('.subbox .subnote')&&document.querySelectorAll('.subbox .pill, .subbox .apt').length===0));
await p.selectOption('.behavior select','stretch'); await p.waitForTimeout(150);
ck('stretch shows a note, no controls', await p.evaluate(()=>!!document.querySelector('.subbox .subnote')&&document.querySelectorAll('.subbox .pill, .subbox .apt').length===0));
await p.selectOption('.behavior select','scale'); await p.waitForTimeout(200);
ck('scale renders a slider, not pills', await p.evaluate(()=>{
  const r=document.querySelector('.subbox input[type=range]');
  return !!r && document.querySelectorAll('.subbox .pill, .subbox .apt').length===0;}));
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
ck('3D swaps the view controls for the book controls', await p.evaluate(()=>
  document.getElementById('viewGroup').hidden===true
  && document.getElementById('bookGroup').hidden===false
  && getComputedStyle(document.getElementById('viewGroup')).display==='none'));
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

// ── revision 9: the real 3D rendering, ported from the shipped preview ──
await go('book'); await p.waitForTimeout(400);
await p.evaluate(()=>[...document.querySelectorAll('#bookModes button')].find(b=>b.dataset.book==='closed').click());
await p.waitForTimeout(300);
ck('closed book builds all six faces', await p.evaluate(()=>{
  const f=[...document.querySelectorAll('#bpv .bpface')];
  const has=c=>f.some(e=>e.classList.contains(c));
  return f.length===6 && ['front','back','topedge','bottomedge','foreedge','spineedge'].every(has);}),
  await p.evaluate(()=>document.querySelectorAll('#bpv .bpface').length+' faces'));
ck('front face is page 1, back face is the last page', await p.evaluate(()=>{
  const g=c=>document.querySelector('#bpv .bpface.'+c).style.backgroundImage;
  const thumbs=[...document.querySelectorAll('#stripRail .pg .thumb')].map(e=>e.style.backgroundImage);
  return thumbs.length===8 && g('front')===thumbs[0] && g('back')===thumbs[7]
      && g('front')!==g('back');}));
ck('saddle depth comes from the page count, no spine face', await p.evaluate(()=>{
  const c=document.querySelector('#bpv .bpclosed');
  const half=parseFloat(c.style.getPropertyValue('--half-depth'));
  return Math.abs(half-(Math.max(2,8*0.3)/2))<0.001 && !document.querySelector('#bpv .bpface.spine');}),
  await p.evaluate(()=>document.querySelector('#bpv .bpclosed').style.getPropertyValue('--half-depth')));
ck('viewport has perspective and the scene is 3D', await p.evaluate(()=>{
  const v=getComputedStyle(document.getElementById('bpv'));
  const sc=getComputedStyle(document.getElementById('bpscene'));
  return v.perspective!=='none' && sc.transformStyle==='preserve-3d' && sc.transform!=='none';}));
ck('drag rotates the book', await p.evaluate(()=>{
  const before=document.getElementById('bpscene').style.transform;
  const vp=document.getElementById('bpv');
  vp.dispatchEvent(new MouseEvent('mousedown',{clientX:400,clientY:300,bubbles:true,cancelable:true}));
  window.dispatchEvent(new MouseEvent('mousemove',{clientX:500,clientY:340,bubbles:true}));
  const during=document.getElementById('bpscene').style.transform;
  window.dispatchEvent(new MouseEvent('mouseup',{bubbles:true}));
  return before!==during && /rotateY\(15deg\)/.test(during) && /rotateX\(-27deg\)/.test(during);}),
  await p.evaluate(()=>document.getElementById('bpscene').style.transform));
ck('pitch is clamped, yaw is free', await p.evaluate(()=>{
  const vp=document.getElementById('bpv');
  vp.dispatchEvent(new MouseEvent('mousedown',{clientX:0,clientY:0,bubbles:true,cancelable:true}));
  window.dispatchEvent(new MouseEvent('mousemove',{clientX:2000,clientY:-4000,bubbles:true}));
  const t=document.getElementById('bpscene').style.transform;
  window.dispatchEvent(new MouseEvent('mouseup',{bubbles:true}));
  return /rotateX\(60deg\)/.test(t) && !/rotateY\(60deg\)/.test(t);}),
  await p.evaluate(()=>document.getElementById('bpscene').style.transform));
ck('"Reset view" returns the starting angle', await p.evaluate(()=>{
  document.getElementById('resetView').click();
  return document.getElementById('bpscene').style.transform==='rotateX(-15deg) rotateY(-25deg)';}));
// Pages view
await p.evaluate(()=>[...document.querySelectorAll('#bookModes button')].find(b=>b.dataset.book==='open').click());
await p.waitForTimeout(300);
ck('Pages view shows two leaves with a gutter', await p.evaluate(()=>
  document.querySelectorAll('#bpv .bppage').length===2
  && !!document.querySelector('#bpv .bppage.left') && !!document.querySelector('#bpv .bppage.right')
  && !!document.querySelector('#bpv .bpspineshadow')));
ck('Pages opens to the spread you were already looking at', await p.evaluate(()=>{
  // the rail selection at this point is P4, so the book should open there
  return [...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',')==='4,5';}),
  await p.evaluate(()=>[...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',')));
// drive to the first spread deliberately for the remaining checks
await p.evaluate(()=>[...document.querySelectorAll('#stripRail .pg')].find(e=>/P2/.test(e.textContent)).click());
await p.waitForTimeout(200);
ck('spreads pair the interior pages, covers excluded', await p.evaluate(()=>{
  const n=[...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent);
  return JSON.stringify(n)===JSON.stringify(['2','3']);}),
  await p.evaluate(()=>[...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',')));
ck('covers never appear as a spread leaf', await p.evaluate(()=>{
  const seen=new Set();
  for(let i=0;i<6;i++){
    [...document.querySelectorAll('#bpv .bppagenum')].forEach(e=>seen.add(e.textContent));
    const n=document.getElementById('nextSpread'); if(n.disabled) break; n.click();
  }
  return !seen.has('1') && !seen.has('8') && seen.size===6;}),
  await p.evaluate(()=>'pages seen across all spreads'));
await p.evaluate(()=>[...document.querySelectorAll('#stripRail .pg')].find(e=>/P2/.test(e.textContent)).click());
await p.waitForTimeout(200);
ck('nav reads the spread and back is disabled at the start', await p.evaluate(()=>
  document.querySelector('.navlbl').textContent.replace(/\u2013/g,'-')==='Pages 2-3'
  && document.getElementById('prevSpread').disabled===true
  && document.getElementById('nextSpread').disabled===false));
ck('stepping forward advances the spread', await p.evaluate(()=>{
  document.getElementById('nextSpread').click();
  return [...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',')==='4,5';}));
ck('arrow keys step spreads too', await p.evaluate(()=>{
  window.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true}));
  const fwd=[...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',');
  window.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowLeft',bubbles:true}));
  const back=[...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',');
  return fwd==='6,7' && back==='4,5';}));
ck('last spread disables forward', await p.evaluate(()=>{
  document.getElementById('nextSpread').click();
  return document.getElementById('nextSpread').disabled===true
      && document.getElementById('prevSpread').disabled===false;}));
ck('stepping keeps the rail thumbnails in step', await p.evaluate(()=>{
  const sel=[...document.querySelectorAll('#stripRail .pg.sel')].map(e=>e.textContent.match(/P(\d)/)[1]);
  return JSON.stringify(sel)===JSON.stringify(['6','7']);}),
  await p.evaluate(()=>[...document.querySelectorAll('#stripRail .pg.sel')].map(e=>e.textContent.match(/P(\d)/)[1]).join(',')));
ck('clicking an interior thumbnail opens that spread', await p.evaluate(()=>{
  [...document.querySelectorAll('#stripRail .pg')].find(e=>/P4/.test(e.textContent)).click();
  return [...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',')==='4,5';}));
ck('clicking a cover thumbnail returns to the closed book', await p.evaluate(()=>{
  [...document.querySelectorAll('#stripRail .pg')].find(e=>/P8/.test(e.textContent)).click();
  return !!document.querySelector('#bpv .bpclosed') && !document.querySelector('#bpv .bpopen')
      && [...document.querySelectorAll('#bookModes button')].find(b=>b.classList.contains('on')).dataset.book==='closed';}));
ck('opening Pages from a cover moves the rail selection with it', await p.evaluate(()=>{
  const go=b=>[...document.querySelectorAll('#bookModes button')].find(x=>x.dataset.book===b).click();
  go('closed');
  [...document.querySelectorAll('#stripRail .pg')].find(e=>/P1/.test(e.textContent)).click();
  go('open');
  const leaves=[...document.querySelectorAll('#bpv .bppagenum')].map(e=>e.textContent).join(',');
  const sel=[...document.querySelectorAll('#stripRail .pg.sel')].map(e=>e.textContent.match(/P(\d)/)[1]).join(',');
  return leaves==='2,3' && sel==='2,3';}),
  await p.evaluate(()=>[...document.querySelectorAll('#stripRail .pg.sel')].map(e=>e.textContent.match(/P(\d)/)[1]).join(',')));
ck('drag handlers do not stack across re-renders', await p.evaluate(()=>{
  const go=b=>[...document.querySelectorAll('#bookModes button')].find(x=>x.dataset.book===b).click();
  for(let i=0;i<6;i++){ go('open'); go('closed'); }
  document.getElementById('resetView').click();
  const vp=document.getElementById('bpv');
  vp.dispatchEvent(new MouseEvent('mousedown',{clientX:0,clientY:0,bubbles:true,cancelable:true}));
  window.dispatchEvent(new MouseEvent('mousemove',{clientX:100,clientY:0,bubbles:true}));
  const t=document.getElementById('bpscene').style.transform;
  window.dispatchEvent(new MouseEvent('mouseup',{bubbles:true}));
  return /rotateY\(15deg\)/.test(t);}),
  await p.evaluate(()=>document.getElementById('bpscene').style.transform));

ck('no horizontal page scroll', await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
ck('no page errors overall', errs.length===0, errs[0]||'');
await b.close();
console.log(fail.length?('>>> '+fail.length+' FAILURE(S)'):'>>> PROTOTYPE OK');
process.exit(fail.length?1:0);
