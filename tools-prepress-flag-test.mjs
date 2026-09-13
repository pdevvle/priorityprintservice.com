// The proofer's escape hatch has to reach a human.
//
// After two failed approvals the proofer offers "continue and have prepress
// check my file". The customer then orders with the file exactly as supplied
// and NO approval. Until 2026-09-13 that fact stopped at the browser: the
// artwork payload carried prepressReview, the calculator never posted it, and
// the resulting order was indistinguishable from one somebody had approved —
// so production would print it on a say-so nobody gave.
//
// Two halves, both pinned here because either alone is useless:
//   - the CLIENT posts pps_prepress_review, and only when the flag is set;
//   - the SERVER turns it into staff-visible item meta, a PREPRESS-REVIEW token
//     in the spec string, an order note, and drops any proof hash that would
//     otherwise make the job look approved to the imposition tool.
//
// Run: node tools-prepress-flag-test.mjs          (after tools-compile-calcs.mjs)

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

// ── the server ──────────────────────────────────────────────────────────────
console.log('── what the order carries (pps-calculators.php) ──');
{
  const php = readFileSync(path.join(HERE, 'pps-calculators.php'), 'utf8');

  ok('the POST field is read and bounded',
     /\$prepress\s*=\s*sanitize_text_field\(\s*wp_unslash\(\s*\$_POST\['pps_prepress_review'\]/.test(php)
     && /mb_substr\(\s*\$prepress,\s*0,\s*300\s*\)/.test(php));
  ok('a prepress flag drops any proof hash — an unapproved job must not look approved',
     /\$cart_item_data\['pps_prepress_review'\][\s\S]{0,200}unset\(\s*\$cart_item_data\['pps_proof_hash'\]\s*\)/.test(php));
  ok('it survives the cart session',
     /\$keys\s*=\s*array\([^)]*'pps_prepress_review'/.test(php));
  ok('it lands on the order line as staff-visible meta',
     /add_meta_data\(\s*'PPS-Prepress-Review'/.test(php));
  ok('and is kept off the customer copy',
     /function pps_internal_item_meta_keys\(\)[\s\S]{0,200}'PPS-Prepress-Review'/.test(php));
  ok('the spec string says PREPRESS-REVIEW instead of SelfApproved',
     /\$proof\s*=\s*\$prepress\s*!==\s*''\s*\?\s*'PREPRESS-REVIEW'/.test(php));
  ok('an order note is raised, once, on both checkout paths',
     /function pps_note_prepress_review/.test(php)
     && /_pps_prepress_noted/.test(php)
     && /add_action\(\s*'woocommerce_checkout_order_processed',\s*'pps_note_prepress_review'/.test(php)
     && /add_action\(\s*'woocommerce_store_api_checkout_order_processed',\s*'pps_note_prepress_review'/.test(php));
  ok('the note says the artwork is NOT approved',
     /PREPRESS REVIEW REQUESTED[\s\S]{0,400}NOT approved/.test(php));
}

// ── the proofer offers it at all ────────────────────────────────────────────
console.log('\n── the proofer still offers the hatch (proof-ui-draft.html) ──');
{
  const pr = readFileSync(path.join(HERE, 'proof-ui-draft.html'), 'utf8');
  ok('two failures offer a way through', /approveFailures/.test(pr) && /offerEscapeHatch/.test(pr));
  ok('and it posts pps-proof:escape to the host', /pps-proof:escape/.test(pr));
}

// ── the client posts it ─────────────────────────────────────────────────────
function extract(html, name) {
  const blocks = html.match(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g) || [];
  const app = blocks.map(s => s.replace(/^<script[^>]*>/, '').replace(/<\/script>$/, ''))
                    .sort((a, b) => b.length - a.length)[0];
  const ast = parser.parse(app, { sourceType:'script', errorRecovery:true, allowReturnOutsideFunction:true });
  const out = {};
  const walk = node => {
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
  if (!out.submitToWooCommerce) throw new Error(name + ': submitToWooCommerce not found');
  return out.ppsFreshNonces + '\n' + out.submitToWooCommerce + '\nreturn { submitToWooCommerce };';
}

class FakeBlob {}
class FakeFile extends FakeBlob {
  constructor(parts, name, opts = {}) { super(); this.parts = parts; this.name = name; this.type = opts.type || ''; this.size = 1; }
}
class FakeFormData {
  constructor() { this.m = new Map(); }
  append(k, v, n) { this.m.set(k, n !== undefined ? { v, n } : v); }
  get(k) { return this.m.get(k); }
  has(k) { return this.m.has(k); }
}
const resp = (status, body) => ({ ok: status >= 200 && status < 300, status,
  text: async () => (typeof body === 'string' ? body : JSON.stringify(body)),
  json: async () => (typeof body === 'string' ? JSON.parse(body) : body) });

async function post(code, artFiles) {
  let seen = null;
  const window = { PPS_CONFIG: { ajaxUrl:'https://s.test/wp-admin/admin-ajax.php', cartUrl:'https://s.test/cart/',
                                 cartNonce:'c', uploadNonce:'u', productId: 33670, maxUpload: 209715200 },
                   location:{ href:'' } };
  const fetch = async (url, init = {}) => {
    if (init.method === 'POST') { seen = init.body; return resp(200, { success:true, data:{} }); }
    return resp(200, { cart:'c', upload:'u' });
  };
  const uploadWithProgress = async (u, fd) => ({ success:true, data:{ path:'pps-artwork/2026/09/' + fd.m.get('artwork').n } });
  const fn = new Function('window', 'fetch', 'FormData', 'File', 'Blob', 'alert', 'uploadWithProgress', 'console', 'Date', code);
  const { submitToWooCommerce } = fn(window, fetch, FakeFormData, FakeFile, FakeBlob, () => {}, uploadWithProgress,
                                     { warn(){}, error(){}, log(){} }, Date);
  await submitToWooCommerce(100, 's', JSON.stringify({ requestedBizDays:5 }), 0, artFiles, null, '');
  return seen;
}

const art = (extra) => Object.assign(
  { type:'files', list:[new FakeFile(['x'], 'art.pdf', { type:'application/pdf' })] }, extra);

console.log('\n── what the calculators post ──');
const dist = path.join(HERE, 'dist');
if (!existsSync(dist)) { console.log('dist/ missing — run tools-compile-calcs.mjs first'); process.exit(1); }
const files = readdirSync(HERE).filter(f => /^calc-(?!4over).*\.html$/.test(f)).sort();
for (const f of files) {
  const code = extract(readFileSync(path.join(dist, f), 'utf8'), f);

  const escaped = await post(code, art({ approved:false, prepressReview:'approval failed twice' }));
  ok(f + ': an escaped order posts the flag',
     escaped && escaped.m.get('pps_prepress_review') === 'approval failed twice');

  const approved = await post(code, art({ approved:true, proofHash:'a'.repeat(64), proofHashOf:'art.pdf' }));
  ok(f + ': an approved order posts no flag', approved && !approved.has('pps_prepress_review'));
  ok(f + ': and still posts its proof hash', approved && approved.m.get('pps_proof_hash') === 'a'.repeat(64));

  const long = await post(code, art({ prepressReview:'x'.repeat(900) }));
  ok(f + ': an absurd reason is trimmed before it is sent',
     long && long.m.get('pps_prepress_review').length === 300);
}

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> PREPRESS FLAG FAILED' : '>>> PREPRESS FLAG OK');
process.exit(failed ? 1 : 0);
