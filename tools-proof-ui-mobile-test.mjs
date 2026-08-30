/* Narrow-viewport checks for proof-ui-draft.html.
   The desktop suite (drafttest.mjs) covers behaviour; this one covers whether
   that behaviour is still reachable when the screen is small. */
const PW='/opt/node22/lib/node_modules/playwright';
const {chromium}=await import(PW+'/index.mjs');
let fail=[];
const ck=(n,ok,d)=>{console.log((ok?'PASS ':'FAIL ')+n+(d?'  '+d:''));if(!ok)fail.push(n);};
const browser=await chromium.launch();

const DEVICES=[
  { name:'phone',          w:390,  h:844, touch:true  },
  { name:'small phone',    w:320,  h:640, touch:true  },
  { name:'phone landscape',w:844,  h:390, touch:true  },
  { name:'tablet',         w:834,  h:1112,touch:true  },
];

for (const d of DEVICES){
  console.log('\n── '+d.name+' ('+d.w+'x'+d.h+') ──');
  const ctx=await browser.newContext({viewport:{width:d.w,height:d.h},hasTouch:d.touch,isMobile:d.touch,deviceScaleFactor:2});
  const p=await ctx.newPage();
  const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,200)));
  p.on('dialog',async x=>{await x.dismiss();});
  await p.goto('http://127.0.0.1:8137/proof-ui-draft.html',{waitUntil:'domcontentloaded'});
  await p.waitForTimeout(1100);

  ck(d.name+': renders without errors', errs.length===0, errs[0]||'');
  ck(d.name+': layout viewport matches the device (not shrunk to fit)', await p.evaluate(w=>
    Math.abs(window.innerWidth-w)<=2, d.w),
    await p.evaluate(()=>'innerWidth '+window.innerWidth));

  // 1. nothing runs off the side
  ck(d.name+': no horizontal page scroll', await p.evaluate(()=>
    document.documentElement.scrollWidth<=window.innerWidth+1),
    await p.evaluate(()=>document.documentElement.scrollWidth+' vs '+window.innerWidth));
  ck(d.name+': every control sits inside the viewport', await p.evaluate(()=>{
    const sel='button, select, input, .apt, .pill, .pg, .knurl';
    const bad=[...document.querySelectorAll(sel)].filter(e=>{
      const r=e.getBoundingClientRect();
      if(!r.width && !r.height) return false;                     // hidden
      return r.left < -1 || r.right > window.innerWidth+1;
    }).map(e=>(e.id||e.className||e.tagName));
    return bad.length ? bad.slice(0,4).join(',') : true;}),
    '');

  // 2. the artwork fits the width it has
  ck(d.name+': the proof sheet fits the screen and is big enough to judge', await p.evaluate(()=>{
    const r=document.getElementById('sheet').getBoundingClientRect();
    return r.width<=window.innerWidth && r.width>=150;}),
    await p.evaluate(()=>Math.round(document.getElementById('sheet').getBoundingClientRect().width)+'px wide'));
  ck(d.name+': the art still leaves room for something else', await p.evaluate(()=>
    document.getElementById('sheet').getBoundingClientRect().height < window.innerHeight*0.80),
    await p.evaluate(()=>Math.round(document.getElementById('sheet').getBoundingClientRect().height)+'px of '+window.innerHeight));

  // 3. the three things that must survive the squeeze
  ck(d.name+': the issues panel is NOT hidden', await p.evaluate(()=>{
    const el=document.getElementById('issuePanel');
    return getComputedStyle(el).display!=='none' && el.getBoundingClientRect().width>50;}));
  ck(d.name+': the found problem is readable', await p.evaluate(()=>
    /doesn.t fill bleed/.test(document.getElementById('issuePanel').innerText)));
  ck(d.name+': the approve gate is on screen without scrolling', await p.evaluate(()=>{
    const r=document.getElementById('approveBtn').getBoundingClientRect();
    return r.top>=0 && r.bottom<=window.innerHeight+1 && r.width>60;}),
    await p.evaluate(()=>{const r=document.getElementById('approveBtn').getBoundingClientRect();
      return 'bottom '+Math.round(r.bottom)+' of '+window.innerHeight;}));
  ck(d.name+': close is reachable in the top corner', await p.evaluate(()=>{
    const r=document.getElementById('closeBtn').getBoundingClientRect();
    return r.top>=0 && r.top<window.innerHeight*0.4 && r.right<=window.innerWidth+1;}));

  // 4. tap targets
  ck(d.name+': approve and close are thumb-sized', await p.evaluate(()=>{
    const a=document.getElementById('approveBtn').getBoundingClientRect();
    const c=document.getElementById('closeBtn').getBoundingClientRect();
    return a.height>=36 && c.height>=32 && c.width>=32;}));

  // 5. the rail is reachable, and the fixed bar does not bury its last control
  ck(d.name+': the artwork-edit rail is present and full width', await p.evaluate(()=>{
    const r=document.getElementById('railPanel').getBoundingClientRect();
    return r.width > window.innerWidth*0.8 || window.innerWidth>900;}),
    await p.evaluate(()=>Math.round(document.getElementById('railPanel').getBoundingClientRect().width)+'px'));
  ck(d.name+': scrolling to the end still clears the fixed approve bar', await p.evaluate(async ()=>{
    window.scrollTo(0, document.body.scrollHeight);
    await new Promise(r=>setTimeout(r,250));
    const accs=[...document.querySelectorAll('#rail .acc')];
    if(!accs.length) return true;
    const last=accs[accs.length-1].getBoundingClientRect();
    const bar=document.querySelector('.approvewrap').getBoundingClientRect();
    const fixed=getComputedStyle(document.querySelector('.approvewrap')).position==='fixed';
    const ok = !fixed || last.bottom <= bar.top + 1;
    window.scrollTo(0,0); await new Promise(r=>setTimeout(r,150));
    return ok;}));

  // 6. the filmstrip becomes one scrolling row rather than a wall
  if (d.w<=900){
    ck(d.name+': filmstrip is a single scrolling row', await p.evaluate(()=>{
      const s=document.getElementById('stripBottom');
      const cs=getComputedStyle(s);
      const tops=[...s.querySelectorAll('.pg')].map(e=>Math.round(e.getBoundingClientRect().top));
      return cs.flexWrap==='nowrap' && cs.overflowX==='auto' && new Set(tops).size===1;}));
    ck(d.name+': all 8 pages are reachable by scrolling that row', await p.evaluate(async ()=>{
      const s=document.getElementById('stripBottom');
      s.scrollLeft=s.scrollWidth; await new Promise(r=>setTimeout(r,150));
      const last=[...s.querySelectorAll('.pg')].pop().getBoundingClientRect();
      const sr=s.getBoundingClientRect();
      const ok=last.right<=sr.right+2;
      s.scrollLeft=0;
      return document.querySelectorAll('#stripBottom .pg').length===8 && ok;}));
    ck(d.name+': the art stays on screen while the controls scroll', await p.evaluate(async ()=>{
      const sw=document.querySelector('.stagewrap');
      if(window.innerHeight<=560){
        // short screen: side-by-side instead of pinned, so just check both are up top
        const art=document.getElementById('sheet').getBoundingClientRect();
        const iss=document.getElementById('issuePanel').getBoundingClientRect();
        return getComputedStyle(sw).flexDirection==='row' && Math.abs(art.top-iss.top)<80;
      }
      if(getComputedStyle(sw).position!=='sticky') return 'not sticky';
      const h=document.getElementById('sheet').getBoundingClientRect().height;
      // deep into the rail, not just past the header — a sliver is not "on screen"
      window.scrollTo(0, document.body.scrollHeight*0.6); await new Promise(r=>setTimeout(r,300));
      const r=document.getElementById('sheet').getBoundingClientRect();
      const shown=Math.min(r.bottom,window.innerHeight)-Math.max(r.top,0);
      window.scrollTo(0,0); await new Promise(r=>setTimeout(r,150));
      return shown >= h*0.9 ? true : Math.round(shown)+'px of '+Math.round(h)+' visible';}));
  }

  // 7. the magnifier works without a mouse
  if (d.touch){
    ck(d.name+': the lens follows a finger', await p.evaluate(()=>{
      document.getElementById('mag').checked=true;
      const s=document.getElementById('sheet'), b=s.getBoundingClientRect();
      const t=(x,y)=>new Touch({identifier:1,target:s,clientX:x,clientY:y});
      const ev=(type,x,y)=>new TouchEvent(type,{touches:[t(x,y)],bubbles:true,cancelable:true});
      s.dispatchEvent(ev('touchstart', b.left+b.width*0.4, b.top+b.height*0.4));
      const l1=document.getElementById('lens'); if(!l1) return false;
      const x1=l1.style.left;
      s.dispatchEvent(ev('touchmove', b.left+b.width*0.7, b.top+b.height*0.6));
      const l2=document.getElementById('lens');
      const moved = !!l2 && l2.style.left!==x1;
      s.dispatchEvent(new TouchEvent('touchend',{touches:[],bubbles:true}));
      const gone = !document.getElementById('lens');
      document.getElementById('mag').checked=false;
      return moved && gone;}));
    ck(d.name+': the lens is sized for the screen', await p.evaluate(()=>{
      document.getElementById('mag').checked=true;
      const s=document.getElementById('sheet'), b=s.getBoundingClientRect();
      const t=new Touch({identifier:1,target:s,clientX:b.left+b.width*0.5,clientY:b.top+b.height*0.5});
      s.dispatchEvent(new TouchEvent('touchstart',{touches:[t],bubbles:true,cancelable:true}));
      const l=document.getElementById('lens');
      const w=l?l.getBoundingClientRect().width:0;
      s.dispatchEvent(new TouchEvent('touchend',{touches:[],bubbles:true}));
      document.getElementById('mag').checked=false;
      return w>0 && w<=window.innerWidth*0.7;}));
  }

  // 8. 3D fits too
  await p.evaluate(()=>[...document.querySelectorAll('#modes button')].find(b=>b.dataset.mode==='book').click());
  await p.waitForTimeout(450);
  ck(d.name+': 3D cover fills the width it has and fits the height', await p.evaluate(()=>{
    const v=document.getElementById('bpv').getBoundingClientRect();
    return v.width<=window.innerWidth+1 && v.width>=window.innerWidth*0.75
        && v.height>80 && v.height<window.innerHeight*0.80;}),
    await p.evaluate(()=>{const v=document.getElementById('bpv').getBoundingClientRect();
      return Math.round(v.width)+'x'+Math.round(v.height);}));
  await p.evaluate(()=>[...document.querySelectorAll('#bookModes button')].find(b=>b.dataset.book==='open').click());
  await p.waitForTimeout(450);
  ck(d.name+': an open spread fits without clipping and is worth reading', await p.evaluate(()=>{
    const v=document.getElementById('bpv').getBoundingClientRect();
    const leaves=[...document.querySelectorAll('#bpv .bppage')].map(e=>e.getBoundingClientRect());
    return leaves.length===2 && leaves.every(r=>r.left>=v.left-1 && r.right<=v.right+1 && r.width>=70);}),
    await p.evaluate(()=>{const l=[...document.querySelectorAll('#bpv .bppage')].map(e=>Math.round(e.getBoundingClientRect().width));
      return 'leaves '+l.join('+')+'px in '+Math.round(document.getElementById('bpv').getBoundingClientRect().width)+'px';}));
  ck(d.name+': spread nav is reachable', await p.evaluate(()=>{
    const n=document.getElementById('nextSpread'); if(!n) return false;
    const r=n.getBoundingClientRect();
    return r.width>=30 && r.right<=window.innerWidth+1;}));
  ck(d.name+': 3D still blocks approval', await p.evaluate(()=>
    document.getElementById('agree').disabled===true && document.getElementById('approveBtn').disabled===true));
  ck(d.name+': 3D does not shrink the layout viewport either', await p.evaluate(w=>
    Math.abs(window.innerWidth-w)<=2 && document.documentElement.scrollWidth<=window.innerWidth+1, d.w),
    await p.evaluate(()=>'innerWidth '+window.innerWidth));

  // 9. rotate back and make sure it recomposes rather than stretching
  await p.evaluate(()=>[...document.querySelectorAll('#modes button')].find(b=>b.dataset.mode==='proof').click());
  await p.waitForTimeout(300);
  const before=await p.evaluate(()=>Math.round(document.getElementById('sheet').getBoundingClientRect().width));
  await p.setViewportSize({width:d.h,height:d.w});
  await p.waitForTimeout(500);
  ck(d.name+': rotating recomposes to the new width', await p.evaluate(()=>{
    const r=document.getElementById('sheet').getBoundingClientRect();
    return r.width<=window.innerWidth && document.documentElement.scrollWidth<=window.innerWidth+1;}),
    'was '+before+'px');
  ck(d.name+': no errors after rotation', errs.length===0, errs[0]||'');

  await ctx.close();
}

await browser.close();
console.log(fail.length?('\n>>> '+fail.length+' FAILURE(S)'):'\n>>> MOBILE OK');
process.exit(fail.length?1:0);
