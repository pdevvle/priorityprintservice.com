import http from 'http'; import fs from 'fs'; import path from 'path';
const ROOT = process.argv[2]; const PORT = 8137;
const types = {'.html':'text/html','.js':'text/javascript','.mjs':'text/javascript','.json':'application/json','.css':'text/css','.png':'image/png','.map':'application/json','.wasm':'application/wasm'};
http.createServer((req,res)=>{
  let p = decodeURIComponent((req.url||'/').split('?')[0]);
  if (p==='/') p='/ss-under-test.html';
  const fp = path.join(ROOT, p);
  if (!fp.startsWith(ROOT)) { res.writeHead(403); return res.end('no'); }
  fs.readFile(fp,(e,data)=>{
    if(e){res.writeHead(404);return res.end('404');}
    res.writeHead(200,{'Content-Type':types[path.extname(fp)]||'application/octet-stream'});
    res.end(data);
  });
}).listen(PORT,'127.0.0.1',()=>console.log('serving on '+PORT));
