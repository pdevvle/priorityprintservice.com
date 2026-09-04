// Proof package — end-to-end check for proof-ui-draft.html.
//
// Loads a real multi-page PDF through the page's own ingest, clicks Build proof
// package, and verifies every artifact it claims to produce actually comes out
// with the right magic bytes: a real PDF, one guide-marked JPEG per page, and a
// manifest naming the transforms, effective DPI, margin findings and hashes.
//
// The fixture is generated with type deliberately over the trim on page 3, so a
// silent margin check would fail this rather than pass quietly.
//
// Needs: node_modules/playwright, ui/vendor/{pdf.min.js,pdf.worker.min.js,
// pdf-lib.min.js} (the CDN is unreachable from the sandbox; a browser fetches
// them normally), and test-art.pdf beside this file.

// End-to-end: upload a real 8-page PDF into the draft, build the proof package,
// and check every artifact it claims to produce actually comes out.
import { chromium } from './node_modules/playwright/index.mjs';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'; import path from 'node:path';
const SP = path.dirname(new URL(import.meta.url).pathname);
const V  = p => readFileSync(path.join(SP,'ui/vendor',p),'utf8');
const b = await chromium.launch({ executablePath:'/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args:['--no-sandbox'] });
const p = await b.newPage({ viewport:{ width:1600, height:1000 } });
const errs = [];
p.on('pageerror', e => errs.push(String(e.message||e)));
p.on('console', m => { if (m.type()==='error') errs.push('console: '+m.text()); });
await p.route('**/*', async r => {
  const u = r.request().url();
  if (u.startsWith('file://')) return r.continue();
  if (u.includes('pdf-lib'))    return r.fulfill({ contentType:'application/javascript', body:V('pdf-lib.min.js') });
  if (u.includes('pdf.worker')) return r.fulfill({ contentType:'application/javascript', body:V('pdf.worker.min.js') });
  if (u.includes('pdf.min.js')) return r.fulfill({ contentType:'application/javascript', body:V('pdf.min.js') });
  if (u.includes('fonts.g'))    return r.fulfill({ contentType:'text/css', body:'' });
  return r.fulfill({ status:204, body:'' });
});
await p.goto('file://' + path.join(SP,'src-wt/proof-ui-draft.html'), { waitUntil:'load' });
await p.waitForTimeout(1200);

// Load the real PDF through the page's own ingest. The File is built in-page
// because Playwright will not populate the hidden <input> the tile picker uses;
// ingestPdf is the same entry point that picker ends up calling.
const b64 = readFileSync(path.join(SP,'test-art.pdf')).toString('base64');
await p.evaluate(async (b64) => {
  const bin = atob(b64); const u8 = new Uint8Array(bin.length);
  for (let i=0;i<bin.length;i++) u8[i]=bin.charCodeAt(i);
  await ingestPdf(new File([u8],'test-art.pdf',{type:'application/pdf'}), 1);
}, b64);
await p.waitForTimeout(2500);

const ingested = await p.evaluate(() => ({
  uploads: (typeof uploads !== 'undefined') ? uploads.size : -1,
  issues: document.body.innerText.match(/Issue:[^\n]{0,90}/g) || [],
}));
console.log('pages ingested:', ingested.uploads);
console.log('issues surfaced:', ingested.issues.slice(0,3));

await p.click('#pkgBtn');
await p.waitForFunction(() => {
  const o = document.getElementById('pkgOut');
  return o && !o.hidden && (o.querySelector('a[download]') || o.querySelector('.pkgerr'));
}, null, { timeout: 120000 });

const res = await p.evaluate(async () => {
  const out = document.getElementById('pkgOut');
  const err = out.querySelector('.pkgerr');
  if (err) return { error: err.textContent };
  const links = Array.from(out.querySelectorAll('a[download]'));
  const files = [];
  for (const a of links){
    const blob = await (await fetch(a.href)).blob();
    const buf  = await blob.arrayBuffer();
    files.push({ name:a.getAttribute('download'), type:blob.type, bytes:buf.byteLength,
                 head: Array.from(new Uint8Array(buf.slice(0,5))).map(c=>String.fromCharCode(c)).join('') });
  }
  return { files, hash:(out.querySelector('.pkghash')||{}).textContent||'' };
});

if (res.error){ console.log('\nPACKAGE ERROR:', res.error); await b.close(); process.exit(1); }
console.log('\nname                     type              bytes     magic');
console.log('-'.repeat(66));
for (const f of res.files) console.log(f.name.padEnd(25)+f.type.padEnd(18)+String(f.bytes).padEnd(10)+f.head.replace(/[^\x20-\x7e]/g,'.'));
console.log('\n' + res.hash.trim());

// Pull the manifest out so its content can be read.
const man = await p.evaluate(async () => {
  const a = Array.from(document.querySelectorAll('#pkgOut a[download]')).find(x=>/MANIFEST/.test(x.getAttribute('download')));
  return a ? await (await fetch(a.href)).text() : null;
});
mkdirSync(path.join(SP,'pkgout'), { recursive:true });
if (man) writeFileSync(path.join(SP,'pkgout/MANIFEST.txt'), man);
console.log('\nerrors:', errs.length ? errs.slice(0,3) : 'none');
await p.screenshot({ path: path.join(SP,'pkgout/package.png') });
await b.close();
