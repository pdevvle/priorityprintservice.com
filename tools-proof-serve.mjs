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

// Served under /vendor/ — the names the page asks for, mapped to where npm puts them.
const VENDOR = {
  'pdf.min.js':        'pdfjs-dist/build/pdf.min.js',
  'pdf.worker.min.js': 'pdfjs-dist/build/pdf.worker.min.js',
  'pdf-lib.min.js':    'pdf-lib/dist/pdf-lib.min.js',
};

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
        file = path.join(DEPS, VENDOR[key]);
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
  for (const [name, rel] of Object.entries(VENDOR)) {
    try { await stat(path.join(DEPS, rel)); } catch { missing.push(name + ' (' + rel + ')'); }
  }
  if (missing.length) {
    throw new Error(
      'missing vendored libraries under ' + DEPS + ':\n  ' + missing.join('\n  ') +
      '\ninstall with: npm install --no-save pdfjs-dist@3.11.174 pdf-lib@1.17.1'
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
