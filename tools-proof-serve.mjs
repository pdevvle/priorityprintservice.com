// Static server for the proof UI test suites.
//
// The proofer cannot be tested over file:// — it loads pdf.js and pdf-lib as
// scripts and reads PDFs through a worker, and both are blocked by the opaque
// origin a file:// page gets. So all three suites (draft, preflight, mobile)
// expect a real origin on 127.0.0.1:8137, and the preflight suite additionally
// expects the pinned libraries under /vendor/ so a test run never depends on a
// CDN the sandbox proxy blocks.
//
// Run it yourself:  node tools-proof-serve.mjs &
// or let a suite start it:  import { serve } from './tools-proof-serve.mjs'
//
// PPS_DEPS_DIR points at the node_modules holding pdfjs-dist@3.11.174 and
// pdf-lib@1.17.1 — the exact versions proof-ui-draft.html pins, so the test
// exercises the same code the CDN would hand a customer.

import http from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import path from 'node:path';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const DEPS = process.env.PPS_DEPS_DIR || path.join(HERE, 'node_modules');

/* The proofer pins pdf.js 3.11.174; the calculators run 4.10.38. Those cannot
   both live in one node_modules — installing either evicts the other, and the
   calculator smoke test starts failing on a missing file that has nothing to do
   with whatever you were working on. So the proofer's copies are kept in their
   own directory:

     mkdir -p proof-vendor && npm install --no-save pdfjs-dist@3.11.174 pdf-lib@1.17.1
     cp node_modules/pdfjs-dist/build/pdf.min.js node_modules/pdfjs-dist/build/pdf.worker.min.js \
        node_modules/pdf-lib/dist/pdf-lib.min.js proof-vendor/
     npm install --no-save pdfjs-dist@4.10.38      # put the calculators' copy back

   PPS_PROOF_VENDOR_DIR overrides the location. node_modules is still searched
   as a fallback, so a checkout that happens to have the right versions works
   without the extra directory. */
const VENDOR_DIR = process.env.PPS_PROOF_VENDOR_DIR || path.join(path.dirname(DEPS), 'proof-vendor');

const VENDOR = {
  'pdf.min.js':        'pdfjs-dist/build/pdf.min.js',
  'pdf.worker.min.js': 'pdfjs-dist/build/pdf.worker.min.js',
  'pdf-lib.min.js':    'pdf-lib/dist/pdf-lib.min.js',
};

// Prefer the dedicated directory, fall back to node_modules.
async function vendorPath(name){
  const flat = path.join(VENDOR_DIR, name);
  try { await stat(flat); return flat; } catch { /* fall through */ }
  return path.join(DEPS, VENDOR[name]);
}

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js':   'text/javascript; charset=utf-8',
  '.mjs':  'text/javascript; charset=utf-8',
  '.css':  'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.pdf':  'application/pdf',
  '.png':  'image/png',
  '.jpg':  'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.svg':  'image/svg+xml',
};

export async function serve(port = 8137, root = HERE) {
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://127.0.0.1');
      let file;

      if (url.pathname.startsWith('/vendor/')) {
        const key = url.pathname.slice('/vendor/'.length);
        if (!Object.prototype.hasOwnProperty.call(VENDOR, key)) {
          res.writeHead(404).end('unknown vendor file: ' + key);
          return;
        }
        file = await vendorPath(key);
      } else {
        // Resolve inside root, so a traversal cannot read outside the tree.
        const rel = decodeURIComponent(url.pathname).replace(/^\/+/, '') || 'index.html';
        file = path.resolve(root, rel);
        if (file !== root && !file.startsWith(root + path.sep)) {
          res.writeHead(403).end('outside root');
          return;
        }
      }

      const body = await readFile(file);
      res.writeHead(200, {
        'Content-Type': TYPES[path.extname(file).toLowerCase()] || 'application/octet-stream',
        'Cache-Control': 'no-store',
      });
      res.end(body);
    } catch (e) {
      res.writeHead(e && e.code === 'ENOENT' ? 404 : 500).end(String(e && e.message || e));
    }
  });

  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', resolve);
  });
  return server;
}

// Fail loudly at startup rather than letting every suite report a mystery
// "connection refused" when the libraries simply are not installed.
export async function checkVendor() {
  const missing = [];
  for (const name of Object.keys(VENDOR)) {
    const f = await vendorPath(name);
    try { await stat(f); } catch { missing.push(name); }
  }
  if (missing.length) {
    throw new Error(
      'missing proofer libraries: ' + missing.join(', ') +
      '\nlooked in ' + VENDOR_DIR + ' and ' + DEPS +
      '\nsee the note at the top of this file for how to populate proof-vendor/'
    );
  }
}

if (import.meta.url === 'file://' + process.argv[1]) {
  await checkVendor();
  const port = Number(process.argv[2]) || 8137;
  await serve(port);
  console.log('proof UI harness on http://127.0.0.1:' + port + '/proof-ui-draft.html');
  console.log('vendored libs from ' + DEPS + ' at /vendor/');
}
