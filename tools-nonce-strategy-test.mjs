// The nonce is tried before it is replaced.
//
// Until 2026-09-12 every submit threw the page-baked nonce away and took one
// from /wp-json/pps/v1/nonces. A REST request that carries a login cookie but
// no X-WP-Nonce header runs as user 0, so every LOGGED-IN customer — and every
// checkout creates an account and signs the customer in for 14 days — got guest
// nonces that admin-ajax refused: uploads died with "the page was open longer
// than the session allows", email-art orders with "Could not add to cart."
// Order 87186 ($660, rush) was placed with "Artwork already discussed" because
// of it; the customer's words were "this file will not upload on your website".
//
// This runs the SHIPPED submitToWooCommerce out of each compiled calculator
// (dist/) under a fake browser and pins the strategy:
//   - the baked nonce goes first, and nothing is fetched before it is tried;
//   - a bare -1 mints a fresh one from admin-ajax (honours the login cookie),
//     falling back to REST only when the server has no such action;
//   - one retry, then the customer is told to reload — never "try again";
//   - a 403 that is not -1 is named as a block, not a session problem;
//   - preset pages post their slug; reference files ride as supplementary
//     deliverables; a supplementary failure never aborts the order (this is
//     what took every perfect-bound order down with it).
//
// Run: node tools-nonce-strategy-test.mjs            (after tools-compile-calcs.mjs)

import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const DEPS = process.env.PPS_DEPS_DIR || path.join(HERE, 'node_modules');
const require = createRequire(import.meta.url);
const parser = require(path.join(DEPS, '@babel/parser'));

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

// ── pull the two functions out of a compiled page ───────────────────────────
function extract(html, name) {
  const m = html.match(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g) || [];
  // The app block is the largest inline script.
  const app = m.map(s => s.replace(/^<script[^>]*>/, '').replace(/<\/script>$/, ''))
               .sort((a, b) => b.length - a.length)[0];
  const ast = parser.parse(app, { sourceType: 'script', errorRecovery: true, allowReturnOutsideFunction: true });
  // The compiled app is one wrapping expression; the functions live inside it.
  const out = {};
  const walk = (node) => {
    if (!node || typeof node.type !== 'string') return;
    if (node.type === 'FunctionDeclaration' && node.id && ['ppsFreshNonces', 'submitToWooCommerce'].includes(node.id.name)) {
      out[node.id.name] = app.slice(node.start, node.end);
    }
    for (const k of Object.keys(node)) {
      if (k === 'loc' || k === 'start' || k === 'end') continue;
      const v = node[k];
      if (Array.isArray(v)) v.forEach(walk); else if (v && typeof v.type === 'string') walk(v);
    }
  };
  walk(ast.program);
  if (!out.ppsFreshNonces || !out.submitToWooCommerce) throw new Error(name + ': functions not found in the app block');
  return out.ppsFreshNonces + '\n' + out.submitToWooCommerce + '\nreturn { ppsFreshNonces, submitToWooCommerce };';
}

// ── a browser small enough to hold in one hand ──────────────────────────────
class FakeBlob {}
class FakeFile extends FakeBlob { constructor(parts, name, opts = {}) { super(); this.parts = parts; this.name = name; this.type = opts.type || ''; this.size = parts.reduce((n, p) => n + (p.size || (p.length || 0)), 0) || 1; } }
class FakeFormData { constructor() { this.m = new Map(); } append(k, v, n) { this.m.set(k, n !== undefined ? { v, n } : v); } get(k) { const x = this.m.get(k); return x && x.v !== undefined && x.n !== undefined ? x : x; } has(k) { return this.m.has(k); } }
const resp = (status, body) => ({ ok: status >= 200 && status < 300, status, text: async () => (typeof body === 'string' ? body : JSON.stringify(body)), json: async () => (typeof body === 'string' ? JSON.parse(body) : body) });

function makeWorld(opts) {
  const log = [];        // every network touch, in order
  const alerts = [];
  const state = {
    validUpload: opts.validUpload, validCart: opts.validCart,
    adminNonces: opts.adminNonces,   // response for admin-ajax?action=pps_nonces (or null = action missing)
    restNonces: opts.restNonces,     // response for the REST route
    cartPosts: 0,
    cartScript: opts.cartScript || null, // (fd, n) => response override
    uploadScript: opts.uploadScript || null,
  };
  const window = { PPS_CONFIG: Object.assign({ ajaxUrl: 'https://shop.test/wp-admin/admin-ajax.php', cartUrl: 'https://shop.test/cart/', cartNonce: 'cartBaked', uploadNonce: 'upBaked', productId: 33670, maxUpload: 200 * 1048576 }, opts.config || {}), location: { href: '' } };
  const fetch = async (url, init = {}) => {
    if (/action=pps_nonces/.test(url)) {
      log.push('GET admin-ajax pps_nonces');
      if (state.adminNonces === null) return resp(400, '0');   // WP answers an unknown action with "0"
      return resp(200, state.adminNonces);
    }
    if (/wp-json\/pps\/v1\/nonces/.test(url)) {
      log.push('GET rest nonces');
      return resp(200, state.restNonces);
    }
    if (init.method === 'POST') {
      const fd = init.body; state.cartPosts++;
      log.push('POST add_to_cart nonce=' + fd.m.get('nonce'));
      if (state.cartScript) { const r = state.cartScript(fd, state.cartPosts); if (r) return r; }
      if (fd.m.get('nonce') !== state.validCart) return resp(403, '-1');
      return resp(200, { success: true, data: { cart_item_key: 'k' } });
    }
    throw new Error('unexpected fetch ' + url);
  };
  const uploadWithProgress = async (url, fd) => {
    const f = fd.m.get('artwork');
    log.push('UPLOAD ' + f.n + ' nonce=' + fd.m.get('nonce'));
    if (state.uploadScript) { const r = state.uploadScript(fd); if (r) return r; }
    if (fd.m.get('nonce') !== state.validUpload) return { success: false, data: 'PPS_STALE_NONCE', ppsCode: 'nonce' };
    if (/\.html$/.test(f.n)) return { success: false, data: 'File type not allowed: .html' };
    return { success: true, data: { path: 'pps-artwork/2026/09/' + f.n } };
  };
  return { window, fetch, uploadWithProgress, alerts, log, state,
           alert: m => alerts.push(m), FormData: FakeFormData, File: FakeFile };
}

function load(code, w) {
  const fn = new Function('window', 'fetch', 'FormData', 'File', 'Blob', 'alert', 'uploadWithProgress', 'console', 'Date', code);
  return fn(w.window, w.fetch, w.FormData, w.File, FakeBlob, w.alert, w.uploadWithProgress, { warn() {}, error() {}, log() {} }, Date);
}

const art = (names) => ({ type: 'files', list: names.map(n => new FakeFile(['x'], n, { type: 'application/pdf' })), approved: true });
const meta = JSON.stringify({ requestedBizDays: 5 });

async function scenarios(name, code) {
  console.log('\n── ' + name + ' ──');

  // S1 — guest on a fresh page: the baked nonces are right and nothing is fetched first.
  {
    const w = makeWorld({ validUpload: 'upBaked', validCart: 'cartBaked', adminNonces: { cart: 'c2', upload: 'u2' }, restNonces: { cart: 'g', upload: 'g' } });
    const { submitToWooCommerce } = load(code, w);
    await submitToWooCommerce(100, 's', meta, 0, art(['art.pdf']), null, '');
    ok('S1 baked nonces are used first, nothing is fetched before them',
       w.log[0] === 'UPLOAD art.pdf nonce=upBaked' && !w.log.some(l => /nonces/.test(l)), w.log.join(' | '));
    ok('S1 and the order reaches the cart', w.window.location.href === 'https://shop.test/cart/', String(w.window.location.href));
  }

  // S2 — stale page (cached guest page older than the tick): -1, refresh from admin-ajax, retry, succeed.
  {
    const w = makeWorld({ validUpload: 'u2', validCart: 'c2', adminNonces: { cart: 'c2', upload: 'u2' }, restNonces: { cart: 'wrong', upload: 'wrong' } });
    const { submitToWooCommerce } = load(code, w);
    await submitToWooCommerce(100, 's', meta, 0, art(['art.pdf']), null, '');
    ok('S2 a rejected nonce is refreshed from admin-ajax, not REST',
       w.log.includes('GET admin-ajax pps_nonces') && !w.log.includes('GET rest nonces'), w.log.join(' | '));
    ok('S2 the upload is retried once with the fresh nonce', w.log.filter(l => /^UPLOAD art\.pdf/.test(l)).length === 2 && w.log.includes('UPLOAD art.pdf nonce=u2'));
    ok('S2 the cart nonce learned in the same refresh is used, no second refresh',
       w.log.filter(l => /pps_nonces/.test(l)).length === 1 && w.log.includes('POST add_to_cart nonce=c2') && w.window.location.href.endsWith('/cart/'), w.log.join(' | '));
  }

  // S3 — logged-in customer on an old server: admin-ajax action missing → REST fallback still works for the guest case.
  {
    const w = makeWorld({ validUpload: 'r2', validCart: 'r2', adminNonces: null, restNonces: { cart: 'r2', upload: 'r2' } });
    const { submitToWooCommerce } = load(code, w);
    await submitToWooCommerce(100, 's', meta, 0, art(['art.pdf']), null, '');
    ok('S3 without the admin-ajax action the REST route is the fallback', w.log.includes('GET admin-ajax pps_nonces') && w.log.includes('GET rest nonces'), w.log.join(' | '));
    ok('S3 and the order still completes', w.window.location.href.endsWith('/cart/'));
  }

  // S4 — the refresh does not help either: say reload, say it once, do not loop.
  {
    const w = makeWorld({ validUpload: 'never', validCart: 'never', adminNonces: { cart: 'x', upload: 'x' }, restNonces: { cart: 'x', upload: 'x' } });
    const { submitToWooCommerce } = load(code, w);
    await submitToWooCommerce(100, 's', meta, 0, art(['art.pdf']), null, '');
    ok('S4 after one refresh the customer is told to reload, once',
       w.alerts.length === 1 && /reload/i.test(w.alerts[0]) && !/try again on the same/i.test(w.alerts[0]), w.alerts.join(' || '));
    ok('S4 exactly two upload attempts, no more', w.log.filter(l => /^UPLOAD/.test(l)).length === 2, w.log.join(' | '));
    ok('S4 nothing was added to the cart', w.state.cartPosts === 0);
  }

  // S5 — add-to-cart alone rejects the nonce (email-art order, no upload): refresh + retry.
  {
    const w = makeWorld({ validUpload: 'upBaked', validCart: 'c2', adminNonces: { cart: 'c2', upload: 'u2' }, restNonces: {} });
    const { submitToWooCommerce } = load(code, w);
    await submitToWooCommerce(100, 's', meta, 0, { type: 'none' }, null, '');
    ok('S5 a -1 on add-to-cart is refreshed and retried', w.log.join(' | ') === 'POST add_to_cart nonce=cartBaked | GET admin-ajax pps_nonces | POST add_to_cart nonce=c2', w.log.join(' | '));
    ok('S5 and lands in the cart', w.window.location.href.endsWith('/cart/'));
  }

  // S6 — add-to-cart -1 that persists: reload message, not "Could not add to cart."
  {
    const w = makeWorld({ validUpload: 'upBaked', validCart: 'never', adminNonces: { cart: 'x', upload: 'x' }, restNonces: {} });
    const { submitToWooCommerce } = load(code, w);
    await submitToWooCommerce(100, 's', meta, 0, { type: 'none' }, null, '');
    ok('S6 a persistent -1 on add-to-cart says reload, not a generic failure', w.alerts.length === 1 && /reload/i.test(w.alerts[0]) && !/Could not add to cart/.test(w.alerts[0]), w.alerts.join(' || '));
  }

  // S7 — a 403 that is not -1 (WAF) is named as a block, and not retried as a nonce.
  {
    const w = makeWorld({ validUpload: 'upBaked', validCart: 'cartBaked', adminNonces: { cart: 'c2', upload: 'u2' }, restNonces: {},
                          cartScript: () => resp(403, '<html>blocked</html>') });
    const { submitToWooCommerce } = load(code, w);
    await submitToWooCommerce(100, 's', meta, 0, { type: 'none' }, null, '');
    ok('S7 a WAF 403 on add-to-cart is reported as a block', w.alerts.length === 1 && /blocked before it reached us/.test(w.alerts[0]) && /mobile data/.test(w.alerts[0]), w.alerts.join(' || '));
    ok('S7 and no nonce refresh is attempted for it', !w.log.some(l => /nonces/.test(l)), w.log.join(' | '));
  }

  // S8 — the server's reason comes through verbatim.
  {
    const w = makeWorld({ validUpload: 'upBaked', validCart: 'cartBaked', adminNonces: {}, restNonces: {},
                          cartScript: () => resp(200, { success: false, data: 'Could not add to cart: Calculator price below product minimum. Refresh the calculator and try again.' }) });
    const { submitToWooCommerce } = load(code, w);
    await submitToWooCommerce(100, 's', meta, 0, { type: 'none' }, null, '');
    ok('S8 a refusal with a reason shows the reason', /price below product minimum/i.test(w.alerts[0] || ''), w.alerts.join(' || '));
  }

  // S9 — preset pages post their slug so the server can resolve the product.
  {
    let seen = null;
    const w = makeWorld({ validUpload: 'upBaked', validCart: 'cartBaked', adminNonces: {}, restNonces: {}, config: { presetSlug: 'bulk-booklet-printing', productId: undefined },
                          cartScript: (fd) => { seen = fd; return null; } });
    const { submitToWooCommerce } = load(code, w);
    await submitToWooCommerce(100, 's', meta, 0, { type: 'none' }, null, '');
    ok('S9 a preset page posts pps_preset_slug', seen && seen.m.get('pps_preset_slug') === 'bulk-booklet-printing');
  }

  // S10 — deliverables and reference files: supplementary failures never abort; references upload prefixed.
  {
    let seen = null;
    const w = makeWorld({ validUpload: 'upBaked', validCart: 'cartBaked', adminNonces: {}, restNonces: {}, cartScript: (fd) => { seen = fd; return null; } });
    const { submitToWooCommerce } = load(code, w);
    const refs = [new FakeFile(['r'], 'brief.pdf', { type: 'application/pdf' })];
    await submitToWooCommerce(100, 's', meta, 0, art(['art.pdf', 'art_preview.html', 'art_print-ready.pdf']), null, '', refs);
    const files = seen ? JSON.parse(seen.m.get('pps_artwork_files')) : [];
    ok('S10 a rejected supplementary deliverable does not abort the order', w.window.location.href.endsWith('/cart/') && w.alerts.length === 0, w.alerts.join(' || '));
    ok('S10 the primary file is the order artwork', seen && seen.m.get('pps_artwork_path') === 'pps-artwork/2026/09/art.pdf');
    ok('S10 the rest of the package is listed, minus the refused file', files.map(f => f.name).join(',') === 'art.pdf,art_print-ready.pdf,reference_brief.pdf', JSON.stringify(files));
    ok('S10 reference files upload after the artwork, prefixed', w.log.some(l => l === 'UPLOAD reference_brief.pdf nonce=upBaked'), w.log.join(' | '));
  }

  // S12 — reference files arrive as { name, size, file } records (that is what the
  // UI stores); the bytes uploaded must be the file's, not the record's.
  {
    let seen = null; const uploadedBlobs = [];
    const w = makeWorld({ validUpload: 'upBaked', validCart: 'cartBaked', adminNonces: {}, restNonces: {}, cartScript: (fd) => { seen = fd; return null; },
                          uploadScript: (fd) => { uploadedBlobs.push(fd.m.get('artwork').v); return null; } });
    const { submitToWooCommerce } = load(code, w);
    const inner = new FakeFile(['brief-bytes'], 'brief.pdf', { type: 'application/pdf' });
    const refs = [{ name: 'brief.pdf', size: 11, file: inner }, { name: 'ghost.pdf', size: 1 }];
    await submitToWooCommerce(100, 's', meta, 0, art(['art.pdf']), null, '', refs);
    const ref = uploadedBlobs.find(b => b.name === 'reference_brief.pdf');
    ok('S12 a reference record uploads the file inside it, not the record', !!ref && ref.parts[0] === inner, ref ? String(ref.parts[0] && ref.parts[0].name) : 'no reference upload');
    ok('S12 and a record with no file is skipped, not uploaded as text', !uploadedBlobs.some(b => b.name === 'reference_ghost.pdf'));
  }

  // S13 — a reorder that reuses its artwork and adds a reference must still list
  // the artwork, because the Drive filer prefers pps_artwork_files over the raw path.
  {
    let seen = null;
    const w = makeWorld({ validUpload: 'upBaked', validCart: 'cartBaked', adminNonces: {}, restNonces: {}, cartScript: (fd) => { seen = fd; return null; } });
    const { submitToWooCommerce } = load(code, w);
    const refs = [{ name: 'notes.pdf', size: 3, file: new FakeFile(['n'], 'notes.pdf', { type: 'application/pdf' }) }];
    await submitToWooCommerce(100, 's', meta, 0, { type: 'existing', path: 'pps-artwork/2026/08/old.pdf' }, null, '', refs);
    const files = seen ? JSON.parse(seen.m.get('pps_artwork_files')) : [];
    ok('S13 existing artwork + reference: the artwork path is posted', seen && seen.m.get('pps_artwork_path') === 'pps-artwork/2026/08/old.pdf');
    ok('S13 and the package list leads with the artwork, then the reference',
       files.length === 2 && files[0].path === 'pps-artwork/2026/08/old.pdf' && files[1].name === 'reference_notes.pdf', JSON.stringify(files));
    ok('S13 and the order still lands in the cart', w.window.location.href.endsWith('/cart/'));
  }

  // S11 — a primary failure still stops everything, with the server's words.
  {
    const w = makeWorld({ validUpload: 'upBaked', validCart: 'cartBaked', adminNonces: {}, restNonces: {},
                          uploadScript: (fd) => (fd.m.get('artwork').n === 'art.webp' ? { success: false, data: 'File type not allowed: .webp' } : null) });
    const { submitToWooCommerce } = load(code, w);
    await submitToWooCommerce(100, 's', meta, 0, art(['art.webp']), null, '');
    ok('S11 a refused primary file stops the order and names the reason', w.alerts.length === 1 && /File type not allowed: \.webp/.test(w.alerts[0]) && w.state.cartPosts === 0, w.alerts.join(' || '));
  }
}

// ── static invariants across the sources, too ───────────────────────────────
console.log('── the sources ──');
const srcs = readdirSync(HERE).filter(f => /^calc-(?!4over).*\.html$/.test(f)).sort();
for (const f of srcs) {
  const s = readFileSync(path.join(HERE, f), 'utf8');
  ok(f + ': no pre-emptive nonce refresh before submit', !/Refresh just before submit/.test(s));
  ok(f + ': the .html preview is never queued for upload', !/_preview\.html", \{ type: "text\/html" \}\)\);/.test(s) || /calc-preview-test|calc-coupon-book/.test(f));
  ok(f + ': isPrimary is declared where it is used', /primary: isPrimary \} = queue\[fi\]/.test(s));
  ok(f + ': "Upload Art with Order" without a file is stopped', /Please add your artwork file before ordering/.test(s));
  ok(f + ': a file that cannot be read is reported to the customer', /We couldn't read that file/.test(s) || /Couldn't read \$\{f0\.name\}/.test(s));
  ok(f + ': the mobile bar shows the first pricing error', !/return compact\s*\?\s*null\s*:\s*<div style=\{ST\.pnl\}><div style=\{\{\s*padding:\s*20\s*\}\}>\{result\.error/.test(s));
  ok(f + ': the picker offers only what the server and the browser accept', !/accept="\.pdf,image\/\*"/.test(s) && !/accept="\.pdf,\.jpg,\.jpeg,\.png,\.tiff,\.tif"/.test(s));
}

// ── and the shipped code ────────────────────────────────────────────────────
const dist = path.join(HERE, 'dist');
if (!existsSync(dist)) { console.log('dist/ missing — run tools-compile-calcs.mjs first'); process.exit(1); }
for (const f of srcs) {
  const html = readFileSync(path.join(dist, f), 'utf8');
  await scenarios('dist/' + f, extract(html, f));
}

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> NONCE STRATEGY FAILED' : '>>> NONCE STRATEGY OK');
process.exit(failed ? 1 : 0);
