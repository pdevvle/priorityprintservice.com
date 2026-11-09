// Quantity table vs. the chosen delivery date (2026-11-09). Drives a COMPILED calculator in
// Chromium with the clock fixed to Thu 2026-11-05, opens Shipping & Delivery, picks day N of the
// month and prints every quantity-table row. Run it against the live build and the patched one and
// compare: only rows that cannot make the date may differ, and they must read 'Not available …'.
//   NODE_PATH=$(npm root -g) node tools-rush-table-page.mjs <calc.html> 'qty=25&pages=48&state=AZ' 12 [port]
import { createRequire } from 'module'; const { chromium } = createRequire(process.env.HOME+'/')('playwright');
import http from 'http'; import fs from 'fs';
const [file, query, day, port] = process.argv.slice(2);
const srv=http.createServer((q,r)=>{r.setHeader('content-type','text/html');r.end(fs.readFileSync(file))}).listen(+port||8197);
const b=await chromium.launch(); const p=await b.newPage({viewport:{width:1400,height:1100}});
await p.clock.setFixedTime(new Date('2026-11-05T17:00:00Z'));
const errs=[]; p.on('pageerror',e=>errs.push(e.message));
await p.goto(`http://127.0.0.1:${+port||8197}/?`+query); await p.waitForTimeout(2500);
await p.locator('text=Shipping & Delivery').first().click(); await p.waitForTimeout(600);
await p.locator('#pps-ship-zip').fill('85086'); try{await p.locator('#pps-ship-state').selectOption('AZ')}catch(e){}
await p.waitForTimeout(600);
let field=p.locator('text=/^Select a date/').first(); if(!(await field.count())) field=p.locator('text=Select a specific date').locator('xpath=following::*[contains(normalize-space(.),"Nov")][1]');
await field.click(); await p.waitForTimeout(600);
const cands=p.locator(`xpath=//*[normalize-space(text())="${day}"]`);
const n=await cands.count(); let clicked=false;
for(let i=0;i<n;i++){ const el=cands.nth(i); if(await el.isVisible()){ try{await el.click({timeout:800}); clicked=true; break;}catch(e){} } }
await p.waitForTimeout(1200);
const more=p.locator('button',{hasText:/Show (all|more)|more quantities|Show \d/i}).first(); if(await more.count()){await more.click(); await p.waitForTimeout(400);}
const rows=await p.$$eval('table tr',trs=>trs.map(t=>t.innerText.replace(/\s+/g,' ').trim()).filter(Boolean));
const note=await p.locator('text=/rush|No rush|deliver by/i').allInnerTexts();
console.log(JSON.stringify({errs,clicked,cands:n,rows,note:note.slice(0,4)},null,1));
await p.screenshot({path:file+'.png'});
await b.close(); srv.close();
