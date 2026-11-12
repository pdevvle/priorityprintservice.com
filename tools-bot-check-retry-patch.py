#!/usr/bin/env python3
"""Uploads and Add to Order ride through the host's bot check (2026-11-12).

Owner: "several customers reporting that they're having 'difficulties' uploading their art".
The server's own bot protection (Cloudways, in front of WordPress — no plugin makes it)
answers some requests with an HTML page titled "One moment, please..." (HTTP 200, ~0.2 s)
instead of passing them on. Measured on production: of 10 quick uploads in a row, one was
answered that way and the next went straight through. A calculator order sends several
uploads back to back (the customer's file, the print-ready PDF, previews, the manifest; or
every page of an image booklet), so any order could meet it. The calculators read the page
as "Invalid server response (HTTP 200)": the first file stopped the order ("Artwork upload
error"), a customer file after it stopped the order, a generated deliverable was dropped in
silence, and Add to Order said "The server sent back something we couldn't read".

Now a response that IS that page (recognised by its title, nothing else) is waited out and
sent again — 1.5 s, 3 s, 6 s, 10 s — and only then reported, in words. The page never
reaches WordPress, so sending again cannot add a line twice.

Seven anchored edits (six plus the final message), each asserted unique.  usage: tools-bot-check-retry-patch.py in.html out.html
"""
import sys

CHALLENGE = '/<title>\\s*One moment, please/i'
EDITS = [
    # 1. The XHR upload keeps its own name for one attempt...
    ('function uploadWithProgress(url,formData,onProgress){return new Promise(',
     'function uploadWithProgressOnce(url,formData,onProgress){return new Promise('),
    # 2. ...and says when the answer was the bot check, before anything else reads it.
    ('try{body=JSON.parse(xhr.responseText);}catch(e){parsed=false;}',
     'try{body=JSON.parse(xhr.responseText);}catch(e){parsed=false;}'
     'if(!parsed&&' + CHALLENGE + '.test(xhr.responseText||"")){resolve({success:false,data:"PPS_CHALLENGE",ppsCode:"challenge"});return;}'),
    # 3. uploadWithProgress = that, sent again while the bot check answers.
    ('async function ppsFreshNonces(',
     'async function uploadWithProgress(url,formData,onProgress){const waits=[1500,3000,6000,10000];let r;'
     'for(let a=0;;a++){r=await uploadWithProgressOnce(url,formData,onProgress);'
     'if(!(r&&r.ppsCode==="challenge")||a>=waits.length)return r;'
     'await new Promise(z=>setTimeout(z,waits[a]));}}'
     'async function ppsFreshNonces('),
    # 4. If it never lets go, the customer is told what happened and what to do.
    ('if(reason==="PPS_STALE_NONCE")',
     'if(reason==="PPS_CHALLENGE")reason="our server\'s security check kept interrupting the upload. Please wait a minute and try again. If it keeps happening, choose \\"Email Art After Order\\" and send the file to us, and we\'ll attach it to your order.";else if(reason==="PPS_STALE_NONCE")'),
    # 5. Add to Order keeps the text so it can recognise the same page...
    ('try{body=JSON.parse(await resp.text());}catch(e){parsed=false;}if(body===-1||body===0)return{code:"nonce",status:resp.status};',
     'const _txt=await resp.text();try{body=JSON.parse(_txt);}catch(e){parsed=false;}if(body===-1||body===0)return{code:"nonce",status:resp.status};'
     'if(!parsed&&' + CHALLENGE + '.test(_txt||""))return{code:"challenge",status:resp.status};'),
    # 6. ...waits it out and sends again (it never reached WordPress, so no double add)...
    ('let r=await post();if(r.code==="nonce")',
     'let r=await post();for(const w of[1500,3000,6000,10000]){if(r.code!=="challenge")break;await new Promise(z=>setTimeout(z,w));r=await post();}if(r.code==="nonce")'),
]
FINAL_OLD = 'else if(r.code==="unparsed"){'
FINAL_NEW = ('else if(r.code==="challenge"){alert("Our server\'s security check kept interrupting your order, so it was not added to the cart and nothing was charged.\\nPlease wait a minute and press the button again, or call us and we\'ll take the order by phone.");}'
             'else if(r.code==="unparsed"){')

src, dst = sys.argv[1], sys.argv[2]
s = open(src, encoding='utf-8').read()
for old, new in EDITS + [(FINAL_OLD, FINAL_NEW)]:
    n = s.count(old)
    assert n == 1, f'{src}: anchor found {n} times: {old[:70]}'
    s = s.replace(old, new)
open(dst, 'w', encoding='utf-8').write(s)
print(dst, 'patched')
