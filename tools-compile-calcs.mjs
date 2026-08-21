#!/usr/bin/env node
/**
 * Precompile the calculators' inline JSX so shipped pages never run Babel in
 * the browser.
 *
 * Why: QA (2026-08-09, caching pass) measured ~5.4s of main-thread blocking on
 * every product page load — Babel Standalone transpiling the ~567KB inline
 * <script type="text/babel"> block. Server caching can't help; the cost is
 * client-side by construction.
 *
 * What it does, per calculator:
 *   1. extracts the single <script type="text/babel"> block (the first literal
 *      </script> after it is the real terminator — inner ones are escaped as
 *      <\/script> per the CLAUDE.md rule),
 *   2. transforms it with @babel/preset-react only (JSX -> createElement;
 *      modern JS passes through untouched),
 *   3. re-injects it as a plain <script> and removes the @babel/standalone
 *      CDN include,
 *   4. refuses to emit if the compiled code somehow contains a literal
 *      </script> (would truncate the block at parse time).
 *
 * Workflow (documented in CLAUDE.md "Branch & Deploy"):
 *   - the integration branch keeps JSX SOURCE in calc-*.html;
 *   - `node tools-compile-calcs.mjs` writes compiled copies to dist/
 *     (gitignored here);
 *   - publish branches (pps-pricing-config, pages-public) and staging
 *     _pending_html deploys carry the dist/ output under the same filenames.
 *   Editing a compiled file directly is always a mistake — edit source, rebuild.
 *
 * Deps: @babel/core + @babel/preset-react resolved via BABEL_DIR (defaults to
 * the session scratchpad's node_modules; any dir with them installed works).
 */
import { readFileSync, writeFileSync, mkdirSync } from 'fs';
import { createRequire } from 'module';
import path from 'path';

const ROOT = path.dirname(new URL(import.meta.url).pathname);
const BABEL_DIR = process.env.BABEL_DIR
  || '/tmp/claude-0/-home-user-priorityprintservice-com/95b8a064-e217-5e73-b902-7dbdf4acb4c3/scratchpad';
const req = createRequire(path.join(BABEL_DIR, 'package.json'));
const babel = req('@babel/core');
const presetReact = req('@babel/preset-react');

const FILES = [
  'calc-brochure.html', 'calc-coupon-book.html', 'calc-greeting-card.html',
  'calc-letterhead.html', 'calc-perfect-bound.html', 'calc-postcard.html',
  'calc-preview-test.html', 'calc-sticker.html',
  'calc-4over.html',
];

const OPEN = '<script type="text/babel">';
const CLOSE = '</script>';

mkdirSync(path.join(ROOT, 'dist'), { recursive: true });
let failed = 0;

for (const f of FILES) {
  const src = readFileSync(path.join(ROOT, f), 'utf8');

  const first = src.indexOf(OPEN);
  if (first === -1 || src.indexOf(OPEN, first + 1) !== -1) {
    console.error(`FAIL ${f}: expected exactly one text/babel block`);
    failed++; continue;
  }
  const codeStart = first + OPEN.length;
  const codeEnd = src.indexOf(CLOSE, codeStart);
  if (codeEnd === -1) { console.error(`FAIL ${f}: unterminated babel block`); failed++; continue; }

  const jsx = src.slice(codeStart, codeEnd);
  let compiled;
  try {
    compiled = babel.transformSync(jsx, {
      presets: [[presetReact, { runtime: 'classic' }]],
      sourceType: 'script',
      compact: true,
      comments: false,
      babelrc: false,
      configFile: false,
      // Escape non-ASCII in string literals. Babel 7 did this by default;
      // Babel 8 flipped the default, so an unpinned build silently emits
      // literal — where every published calculator has \u2014 — identical at
      // runtime, but it buries a real one-line diff under thousands of
      // cosmetic ones and makes "is this byte-for-byte what shipped?"
      // unanswerable. Pin it so the two Babel majors agree.
      generatorOpts: { jsescOption: { minimal: false } },
    }).code;
  } catch (e) {
    console.error(`FAIL ${f}: babel error — ${String(e.message).split('\n')[0]}`);
    failed++; continue;
  }

  if (/<\/script/i.test(compiled)) {
    console.error(`FAIL ${f}: compiled output contains a literal </script> — refusing to emit`);
    failed++; continue;
  }

  let out = src.slice(0, first)
    + '<script>/* compiled by tools-compile-calcs.mjs — DO NOT EDIT; edit the source calc-*.html and rebuild */\n'
    + compiled
    + '\n' + src.slice(codeEnd);

  // Drop the now-useless Babel Standalone include (keep the line count sane by
  // removing the whole line).
  const before = out.length;
  out = out.replace(/^.*@babel\/standalone.*\r?\n/m, '');
  if (out.length === before) {
    console.error(`FAIL ${f}: babel.min.js include not found/removed`);
    failed++; continue;
  }

  writeFileSync(path.join(ROOT, 'dist', f), out, 'utf8');
  const savedKB = Math.round((src.length - out.length) / 1024);
  console.log(`ok ${f}: jsx ${(jsx.length / 1024).toFixed(0)}KB -> compiled ${(compiled.length / 1024).toFixed(0)}KB (page ${savedKB >= 0 ? '-' : '+'}${Math.abs(savedKB)}KB)`);
}

process.exit(failed ? 1 : 0);
