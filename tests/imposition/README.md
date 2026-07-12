# Imposition tool test harness

Not wired to CI (this repo has none) — run in any sandbox/session when the
engine or a calculator imp function changes.

| File | What it proves | Deps |
|---|---|---|
| `parity.mjs` | The tool's `flatImp`/`flatGrid`/`stickerImp` are byte-equivalent to the LIVE calculator JS (extracted from the calc-*.html files at run time; 31k-case sweep) + a physical-fit audit of priced imps. **Run after any change to a calculator imp function or the tool's layout spec.** | node only |
| `make_flat_fixtures.py`, `make_booklet_fixtures.py` | Generate orientation-marked test PDFs (asymmetric corner markers / edge bands, TrimBox set, `/Rotate` variants). | `pip install pymupdf` |
| `duplex_sim.py` | Physical duplex simulation: models the press flip (short/long edge), cuts each piece/slot, turns it like a person would, and asserts the back reads UPRIGHT and unmirrored. This is the test that caught the flat rotated-cell tumble bug. | `pip install pymupdf numpy` |

Browser engine runs (impose fixtures through the real tool) need Chromium +
Playwright and locally vendored copies of the CDN scripts (npm i playwright
react@18.3.1 react-dom@18.3.1 @babel/standalone@7.26.9 pdfjs-dist@3.11.174
pdf-lib@1.17.1, then rewrite the CDN URLs to the local copies when serving
imposition-tool.html). See the session transcripts on PR #38 for the exact
runner scripts; `window.__ppsImpose` exposes the engine headlessly.
