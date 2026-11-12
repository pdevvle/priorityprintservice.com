#!/usr/bin/env python3
"""The calculators report upload failures the server cannot see (2026-11-12).

Owner: "log failed uploads". pps-upload-log.php records every refusal the upload endpoint
makes; this adds what never reaches it. ppsReportUploadFail() posts to the public
admin-ajax action pps_upload_failure (sendBeacon, else a keepalive fetch; never awaited,
never throws) at:
  - the host's bot check answering an upload (once per file; the retry then follows),
  - a file over the site's limit, stopped in the browser before sending,
  - every upload failure the customer is shown, or a generated file that is skipped
    (final = the order stopped),
  - a connection that dropped or an unreadable answer,
  - Add to Order failing after the uploads.
Applies on top of tools-bot-check-retry-patch.py. Five anchored edits, each asserted unique.
usage: tools-upload-report-patch.py in.html out.html
"""
import sys

REPORT = ('function ppsReportUploadFail(o){try{const P=window.PPS_CONFIG||{};if(!P.ajaxUrl)return;const f=o.file;'
          'const d={code:o.code||"refused",reason:String(o.reason||"").slice(0,300),file:f&&f.name||"",size:f&&f.size||0,'
          'status:o.status||0,page:String(location.pathname||"").slice(0,160),calc:"product "+(P.productId||""),final:!!o.final};'
          'const fd=new FormData();fd.append("action","pps_upload_failure");fd.append("data",JSON.stringify(d));'
          'if(navigator.sendBeacon&&navigator.sendBeacon(P.ajaxUrl,fd))return;'
          'fetch(P.ajaxUrl,{method:"POST",body:fd,credentials:"same-origin",keepalive:true}).catch(()=>{});}catch(e){}}')

EDITS = [
    # 1. The reporter, and the bot check recorded once per file as it is waited out.
    ('async function uploadWithProgress(url,formData,onProgress){const waits=[1500,3000,6000,10000];let r;'
     'for(let a=0;;a++){r=await uploadWithProgressOnce(url,formData,onProgress);'
     'if(!(r&&r.ppsCode==="challenge")||a>=waits.length)return r;await new Promise(z=>setTimeout(z,waits[a]));}}',
     REPORT +
     'async function uploadWithProgress(url,formData,onProgress){const waits=[1500,3000,6000,10000];let r;'
     'for(let a=0;;a++){r=await uploadWithProgressOnce(url,formData,onProgress);'
     'if(a===0&&r&&r.ppsCode==="challenge"){let f=null;try{f=formData.get("artwork");}catch(e){}'
     'ppsReportUploadFail({code:"challenge",reason:"bot check answered the upload; retrying",file:f,status:200});}'
     'if(!(r&&r.ppsCode==="challenge")||a>=waits.length)return r;await new Promise(z=>setTimeout(z,waits[a]));}}'),
    # 2. Over the limit, stopped before sending.
    ('if(PPS.maxUpload&&file.size>PPS.maxUpload){',
     'if(PPS.maxUpload&&file.size>PPS.maxUpload){ppsReportUploadFail({code:"toolarge",reason:"over the "+_mb(PPS.maxUpload)+" limit",file,final:isPrimary||mustArrive});'),
    # 3. The upload answered with a failure: what the customer is told, or a skipped deliverable.
    ('if(isPrimary){alert("Artwork upload failed: "+reason+',
     'ppsReportUploadFail({code:data&&data.ppsCode||"refused",reason,file,final:isPrimary||mustArrive});'
     'if(isPrimary){alert("Artwork upload failed: "+reason+'),
    # 4. The connection dropped or the answer could not be read.
    ('}catch(err){if(isPrimary){alert("Artwork upload error: "+err.message',
     '}catch(err){ppsReportUploadFail({code:/network/i.test(err&&err.message||"")?"network":"invalid",reason:err&&err.message,file,final:isPrimary||mustArrive});'
     'if(isPrimary){alert("Artwork upload error: "+err.message'),
    # 5. Add to Order did not go through.
    ('if(r.code==="ok"&&r.body&&r.body.success){window.location.href=PPS.cartUrl;}',
     'if(!(r.code==="ok"&&r.body&&r.body.success))ppsReportUploadFail({code:"cart",reason:r.code+(r.body&&r.body.data?": "+r.body.data:""),status:r.status,final:true});'
     'if(r.code==="ok"&&r.body&&r.body.success){window.location.href=PPS.cartUrl;}'),
]

src, dst = sys.argv[1], sys.argv[2]
s = open(src, encoding='utf-8').read()
for old, new in EDITS:
    n = s.count(old)
    assert n == 1, f'{src}: anchor found {n} times: {old[:70]}'
    s = s.replace(old, new)
open(dst, 'w', encoding='utf-8').write(s)
print(dst, 'patched')
