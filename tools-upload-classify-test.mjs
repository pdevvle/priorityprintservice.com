// What the customer is told when an upload fails.
//
// On 2026-09-11 a customer spent an afternoon on "This page was open longer
// than the session allows... Please reload the page and try again", through
// three browsers and a private window, for a 9.6MB PNG. None of that could
// ever have worked: the request was being refused with a 403 before it reached
// WordPress, and the calculator mapped every 403 to a stale nonce. The advice
// was not merely unhelpful, it was confidently wrong about the cause.
//
// admin-ajax rejects a nonce with a bare "-1" and nothing else does, so the two
// cases ARE distinguishable. This asserts that they stay distinguished, in all
// eight calculators, by running the shipped xhr.onload out of each file rather
// than a copy of it kept here — a copy would pass forever after someone edits
// the real one.
//
// Run: node tools-upload-classify-test.mjs

import { readFileSync } from 'node:fs';
import { globSync } from 'node:fs';
import path from 'node:path';

const HERE = path.dirname(new URL(import.meta.url).pathname);

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

/** Pull the body of `xhr.onload = () => { ... };` out of a calculator. */
function extractOnload(src) {
  const start = src.indexOf('xhr.onload = () => {');
  if (start < 0) return null;
  const open = src.indexOf('{', start);
  let depth = 0, i = open;
  for (; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') { depth--; if (depth === 0) break; }
  }
  return src.slice(open + 1, i);
}

const files = globSync(path.join(HERE, 'calc-*.html'))
  .filter(f => readFileSync(f, 'utf8').includes('PPS_STALE_NONCE'))
  .sort();

ok('every calculator with an uploader is covered', files.length === 8,
   files.length + ' files: ' + files.map(f => path.basename(f)).join(', '));

// (status, responseText) -> whatever the shipped code decides.
function classify(body, status, responseText) {
  let out = null, rejected = null;
  const xhr = { status, responseText };
  const fn = new Function('xhr', 'resolve', 'reject', body);
  fn(xhr, v => { out = v; }, e => { rejected = e; });
  return { out, rejected };
}

for (const file of files) {
  const name = path.basename(file, '.html');
  const body = extractOnload(readFileSync(file, 'utf8'));
  if (!body) { ok(name + ': has an upload handler to test', false); continue; }

  // The case that caused the report: a security layer in front of WordPress.
  // Its body is an HTML error page, never "-1".
  const waf = classify(body, 403, '<html><head><title>403 Forbidden</title></head><body>Access denied</body></html>');
  ok(name + ': a 403 that is not admin-ajax is reported as blocked, not as a nonce',
     waf.out && waf.out.ppsCode === 'blocked',
     JSON.stringify(waf.out) + (waf.rejected ? ' rejected: ' + waf.rejected.message : ''));

  // Cloudflare's is JSON-shaped often enough to be worth pinning separately.
  const cfJson = classify(body, 403, '{"success":false,"error":"blocked"}');
  ok(name + ': and so is a 403 whose body happens to parse as JSON',
     cfJson.out && cfJson.out.ppsCode === 'blocked', JSON.stringify(cfJson.out));

  // The real thing: admin-ajax answers a rejected nonce with a bare -1.
  const nonce403 = classify(body, 403, '-1');
  ok(name + ': a bare -1 is still a nonce, so the retry still fires',
     nonce403.out && nonce403.out.ppsCode === 'nonce', JSON.stringify(nonce403.out));

  // admin-ajax does not always use 403 for it.
  const nonce200 = classify(body, 200, '-1');
  ok(name + ': -1 on a 200 is a nonce too',
     nonce200.out && nonce200.out.ppsCode === 'nonce', JSON.stringify(nonce200.out));
  const zero200 = classify(body, 200, '0');
  ok(name + ': and so is a bare 0',
     zero200.out && zero200.out.ppsCode === 'nonce', JSON.stringify(zero200.out));

  // Unchanged behaviour, asserted so this edit cannot have moved it.
  const big = classify(body, 413, '');
  ok(name + ': 413 is still the size case',
     big.out && big.ppsCode !== 'blocked' && big.out.ppsCode === 'size', JSON.stringify(big.out));

  const good = classify(body, 200, '{"success":true,"data":{"path":"pps-artwork/2026/09/x.pdf"}}');
  ok(name + ': a real success passes straight through',
     good.out && good.out.success === true && good.out.data.path.endsWith('x.pdf'),
     JSON.stringify(good.out));

  const boom = classify(body, 500, 'Fatal error');
  ok(name + ': a 500 still rejects rather than resolving to a lie',
     !!boom.rejected && !boom.out, boom.rejected ? boom.rejected.message : 'resolved: ' + JSON.stringify(boom.out));
}

// The wording is the whole point of the change, so it is asserted, not assumed.
console.log('\n── what the customer actually reads ──');
for (const file of files) {
  const name = path.basename(file, '.html');
  const src = readFileSync(file, 'utf8');
  const m = src.match(/reason === "PPS_BLOCKED"\) reason = "([^"]+)"/);
  ok(name + ': the blocked case has its own sentence', !!m);
  if (!m) continue;
  const msg = m[1];
  // Saying "reloading will not help" is the point; *instructing* a reload is
  // the bug this replaced.
  ok(name + ': it does not instruct a reload, which cannot help',
     !/reload the page|please reload/i.test(msg), msg.slice(0, 70) + '…');
  ok(name + ': and it says so outright, so they stop trying',
     /will not help/i.test(msg));
  ok(name + ': it names a test they can actually run, and a way out',
     /network|mobile data/i.test(msg) && /email the file/i.test(msg));
}

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> UPLOAD CLASSIFY FAILED' : '>>> UPLOAD CLASSIFY OK');
process.exit(failed ? 1 : 0);
