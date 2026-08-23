# Calculator verification battery

The tests that gate every calculator change, promoted out of session scratchpads so
they stop being rebuilt from memory each time. They drive the real files in a real
browser — no mocks of the pricing engine or the proof pipeline.

| script | proves |
|---|---|
| `validate_babel.mjs <file>` | the inline babel block compiles. Run on **all nine** after any edit. |
| `states.mjs <file>` | all 50 states offered; Georgia survives a saved config that omits it. |
| `datepick.mjs <file>` | clicking a rush-zone day populates the field (handles both the empty and pre-filled fork behaviours). |
| `pagecount.mjs <file>` | page-count staleness notice, even-only round-up message, no self-referential cross-sell links. |
| `prooffix.mjs [file]` | the full upload→proof flow: pdf.js 4 loads, a transparency-heavy PDF renders its blue block (the 3.11 regression), the preflight banner names the risks, a clean control shows no banner. Works on both section styles (unmount vs CSS-hidden). |
| `server.mjs <root>` | static server on :8137. **The root argument is required** — without it every test reports ERR_CONNECTION_REFUSED. |

## Setup (once per environment)

- Vendor the CDN deps the REPL maps point at: `npm i` react/react-dom/@babel/standalone/jspdf per the REPL arrays, plus `pdfjs-dist@4.10.38` into `newpdf/` for the legacy build.
- Playwright is imported by absolute path (`/opt/node22/lib/node_modules/playwright` in the cloud sandbox); adjust `PW` if yours lives elsewhere.
- `prooffix.mjs` needs two fixtures **not committed here**: `pitch.pdf` (a customer file — never commit it, this repo is public) and `art-4pg.pdf` (any construct-free control). Ask the operator or generate a control with jsPDF.

## Rules learned the hard way

- Drive React inputs with focus + bubbling events, then Enter/blur. `new Event('change')`
  does not bubble; `blur()` without focus fires nothing. Both have produced false results.
- A test that can pass vacuously (querying fresh instead of holding the handle it measured)
  will eventually lie to you. Hold handles.
- Section forks differ: classic files unmount closed sections; the modern layout keeps them
  mounted and hides via CSS var, so "element exists" ≠ "visible". Assert on `:visible`.
