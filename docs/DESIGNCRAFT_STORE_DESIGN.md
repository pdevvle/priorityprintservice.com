# PPS Designer on DesignCraft — design

**Date:** 2026-10-11 · **Status:** proposed, owner decisions open (§9) · **Supersedes:** the
editor half of `docs/DESIGNER_SPIKE.md` (its verdict and "what not to do" still stand)

The goal: a customer designs a print product in the browser and orders it without leaving the
editor, the way Canva's "Print" button turns a design into a cart line. The editor is built on
[DesignCraft](https://github.com/storytold/designcraft) (MIT OR Apache-2.0), a Rust/WebAssembly
page-layout engine; the store is ours, and stays ours.

---

## 1. The one decision everything else follows from

**The calculator is the store. The designer only makes artwork.**

Canva's print flow is: design → Print → a side panel with product options and a live price →
cart. We already have that side panel. Every PPS calculator collects size, stock, quantity,
finishing, shipping and rush, prices it with `calculate()`, proofs the artwork, and adds it to the
WooCommerce cart through the nonce-hardened submit path. So the designer does **not** get a
product catalogue, a price engine, a cart, or an upload pipeline of its own. It gets:

- a **job spec** from the calculator (trim, bleed, safety, page count and multiple, fold lines,
  binding edge, spine width), which becomes the document, and
- a **PDF** going back to the calculator, which enters the existing artwork intake exactly as an
  uploaded file does — preflight, proof, approval hash, Drive upload, order meta, imposition.

What this buys:

- **Pricing parity holds by construction.** Pricing math lives only in the calculator HTML
  (CLAUDE.md, "Pricing changes"). A Rust port of `calculate()` would be a second copy to drift,
  across eight calculators. There is none.
- **No new path to the press.** The imposition tool, the proof hash, PPS-Spec, Drive, the paper
  report: all of it sees an ordinary uploaded PDF. Nothing downstream learns the designer exists.
- **Every gate we already have tests the designer for free.** If the designer's PDF fails the
  ordered-size check or the proofer's preflight, that is a designer bug, caught by suites that
  already run.

This is the proofer's pattern run in the other direction (`ppsOpenProof()`, CLAUDE.md
"Proofing"): a same-origin frame the calculator opens, a small postMessage handshake, and the host
owning everything after the handoff.

## 2. What we take from DesignCraft, and what we don't

DesignCraft is an engine-first Cargo workspace with enforced layering. That layering is what makes
it usable here.

| Layer | Crates | Use? |
|---|---|---|
| L0–L1 | `geom`, `color`, `doc`, `fonts` | **Yes** — document model, CMYK/spot, styles, shaping (harfrust) |
| L2 | `compose` | **Yes** — Knuth–Plass composer, hyphenation, justification; same breaks on screen and in PDF. This is spike gap #1, solved |
| L3 | `render` (vello_cpu) | **Yes** — page pixels for the canvas and thumbnails |
| L4 | `tools` | **Yes** — pointer events → commands + overlay data (select, move, resize, text caret) |
| L5 | `engine` | **Yes** — `Session::execute(command, json)`; every action is a JSON command with undo |
| — | `pdf` (krilla) | **Yes** — subset-tagged fonts, CMYK/spot, TrimBox/BleedBox, PDF/X-4 (spike gaps #3–#5) |
| L6 | `ui-egui` | **No** — InDesign's UI: menus, docked panels, Control panel. The opposite of Canva |
| L7 | `apps/designcraft-web` | **No** — replaced by our shell (§4) |
| — | `mcp` | **Later** — §8, AI first drafts |

Concretely, the seams already exist upstream:

- `file.new` takes `{width, height, pages, facingPages, bleed, margins, columns}` in points —
  a job spec maps onto it directly.
- `file.exportPdf` returns the PDF as base64.
- `layout.documentSetup`, `file.place` (images and PDFs), styles, swatches and data merge are all
  commands, so the shell never touches engine internals.

## 3. Repository layout

- **New repo, `pdevvle/pps-designer`** (name open): DesignCraft's history with `upstream` as a
  remote, plus our additions. Not a GitHub "fork" button — a fork of a public repo cannot be made
  private, and CLAUDE.md's go-private plan would strand it.
- **Our additions are new crates, not edits to theirs.** One crate, `pps-bridge`
  (wasm-bindgen), exposes the engine to JavaScript:
  `execute(cmd, json) → json`, `pointer(event) → overlays`, `renderPage(i, scale) → RGBA`,
  `exportPdf(opts) → bytes`. Everything else upstream stays byte-identical, so merging upstream
  is a fast-forward of their crates. That matters: upstream is ten days old, pre-alpha, and moving
  at agent speed (0.1 → 0.6 in a week). We want their fixes, so we must not fork their code.
- **ArtCraft trademarks come out** (`docs/brand/`, About window) — their brand licence requires it
  for modified versions. Keep `NOTICE` and the licence files.
- **The host side lives in this repo**: `ppsOpenDesigner()` in each calculator, the shell page,
  and their test suites, next to the proofer's.
- **Builds run in GitHub Actions**, because the owner has no local toolchain: `cargo build
  --target wasm32-unknown-unknown`, `wasm-bindgen`, `wasm-opt`, artefacts attached to a release
  pinned by commit. Deploy pulls those by SHA, the same rule as everything else
  (CLAUDE.md, "Server-side patches must come home").

## 4. The shell: HTML, not egui

The editor the customer sees is a plain HTML/JS page (`designer.html`), compiled the same way as
the calculators, around the wasm engine:

```
┌ designer.html (same-origin iframe, full-screen over the calculator) ───────────┐
│  top bar:  ← Back to options   Postcard 6×9 · 2 sides     $ live price   Print ▸ │
│ ┌ left drawer ─────┐ ┌ <canvas> ── engine render + our overlay layer ────────┐ │
│ │ Templates        │ │                                                        │ │
│ │ Text             │ │     trim / bleed / safety / fold lines always on       │ │
│ │ Photos (upload)  │ │                                                        │ │
│ │ Shapes · Colours │ └────────────────────────────────────────────────────────┘ │
│ └──────────────────┘   page strip: Front · Back  (booklets: spreads, +4 pages)  │
└──────────────────────────────────────────────────────────────────────────────────┘
```

Why not reuse upstream's egui UI:

- **It is InDesign.** Canva's whole proposition is that a non-designer finishes in five minutes.
  Hiding most of an InDesign UI is more work than drawing a small one.
- **egui draws everything into one canvas.** That costs us native text input on phones, IME
  (upstream gap #8), screen-reader access, and the browser's own form controls — and a Canva-style
  customer is as likely to be on a phone as at a desk.
- **Our shell must look like the store.** It shares the calculators' palette and the Panel/
  RichTip vocabulary. The proofer's style suite already enforces that; the designer joins it.

The engine stays authoritative: the shell sends commands and draws what `renderPage` and
`pointer` return. Pointer handling, hit-testing, snapping and the text caret come from upstream's
`tools` crate, not from us.

## 5. The two ways in

Both end in the same place — a calculator with artwork loaded and a price on screen.

**A. Calculator first** (ship first). Beside "Upload artwork" every calculator gets **Design it
online**. It opens the designer with the job spec it already holds. **Print ▸** closes the
designer and hands the PDF to the calculator's existing intake, as if the customer had dropped
the file. The proof opens as it does today.

**B. Designer first** (the Canva landing). `/design/` lists products from the registry and
presets — both already exist in PHP and are already listed in `llms.txt`. Picking one routes to
that product's calculator URL with `?design=new&template=…`, which opens the designer
immediately, full-screen. From the customer's side it is a design app; underneath it is flow A.

### The handshake (`pps-design:*`, same-origin only)

| Direction | Message | Carries |
|---|---|---|
| host → designer | `job` | spec (trim, bleed, safety, pages, page multiple, folds, binding, spine), template id, saved design (if any) |
| designer → host | `ready`, `error` | — |
| host → designer | `price` | the calculator's current total, quantity, turnaround — **display only** |
| designer → host | `spec` | a change the customer made in the editor that the calculator owns (page count, two sides → one side) |
| designer → host | `print` | PDF bytes, the native design document, page thumbnails |
| designer → host | `close` | the design document (so Back keeps the work) |

Rules, carried over from the proofer:

- **The designer never prices, uploads or adds to cart.** It cannot hold a nonce.
- **The calculator stays the source of the spec.** If the customer adds four pages in the editor,
  the editor *asks* (`spec`); the calculator re-prices and answers with a new `job` and `price`.
  A spec the calculator refuses never becomes a document.
- **The PDF goes through the same approval as an upload** — no "designed here, so it's fine"
  bypass. That keeps the proof hash, the acknowledgment gate and `PPS-Proof` meaning what they
  mean today.

## 6. The output contract

What the designer hands back must pass, unmodified, the checks the store already runs:

- **Geometry:** MediaBox = trim + 2 × bleed, TrimBox = trim, BleedBox declared. Upstream already
  writes all three. The ordered-size check (`tools-proof-size-check-test.mjs`) is the gate.
- **Booklets:** page count is a multiple of the binding's (saddle = 4); spine width is the
  calculator's number, never the designer's. Cover and interior follow the perfect-bound
  calculator's split.
- **Colour:** replace upstream's invented "Generic CMYK (SWOP-like)" output intent with our press
  profile. Its ICC licence must allow embedding before it ships. RGB placed images are acceptable
  for the digital press (as the spike concluded); say so in the manifest.
- **Fonts:** OFL only, embedded and subset. No system fonts exist on the web build, so the font
  library is whatever we bundle — every face we add is a licence row and download weight.
- **The native design document rides with the order** as a supplementary deliverable
  (`<base>_design.pps.json`), next to the PDF. This is the prepress win the spike's ROI case is
  about: staff fix a typo in the design instead of asking for a new file. It also makes
  "Reorder with changes" possible.

## 7. Saving designs

1. **Draft in the browser** (IndexedDB, per product URL), so a refresh or Back does not lose
   work. Wrapped in try/catch; the editor works without it.
2. **"My designs" for signed-in customers** — a REST route storing the design document against
   the account. Checkout already creates accounts, so most repeat customers are signed in.
   The nonce rules in CLAUDE.md apply: admin-ajax first, never REST-minted nonces for a
   logged-in visitor.
3. **Attached to the order** (§6) — the copy staff and reorders use.

## 8. Later: AI first drafts

Upstream ships an MCP server over the same command API. That makes "describe your flyer and get a
first draft" a matter of driving `execute()` from a model with the job spec and a template —
Canva's Magic Design, built on commands we already test. **Staff-side first** (prepress builds a
draft from a customer's brief), customer-facing only once the output contract (§6) has held up.

## 9. Open decisions — the owner's

1. **Create `pdevvle/pps-designer`** as a new repository seeded from upstream. This session's
   GitHub access covers only `priorityprintservice.com`, so creating it is either your click or a
   permission change.
2. **Customer-facing first, or staff-facing first.** This design assumes customers, as Canva
   does. Staff-first would ship upstream's full UI sooner, for in-house layout work, with the
   store integration following.
3. **Which product leads.** Recommended: **flats — postcards, business cards, flyers**. One or
   two pages, no spreads or spine, the highest order volume, and Canva's core use. Brochures
   (fold lines) next, booklets last.
4. **The press profile** to embed, and confirmation its licence allows it.

## 10. Phases

Each phase ends when the named gates pass. None starts while the previous one is red.

| Phase | Delivers | Gate |
|---|---|---|
| **P0 — measure** | Repo created; upstream web build compiled in CI; **wasm size, cold start on a mid-range phone, frame time while dragging**; a postcard exported and run through our proofer preflight **and a real RIP** | Numbers recorded here. A payload or phone result that is unusable stops the project before P1 — that is the point of P0 |
| **P1 — bridge + flow A on one flat** | `pps-bridge`; minimal shell (text, image, shapes, front/back); "Design it online" on the brochure/flats calculator; PDF into the existing intake | Proofer's six suites + size check + `tools-order-e2e-test.mjs` pass with a designer-made PDF |
| **P2 — store loop** | `price` and `spec` messages, live price chip, Print ▸; `/design/` landing (flow B) | New `tools-designer-handshake-test.mjs`: a page-count change in the editor re-prices in the calculator and never reaches the document if refused |
| **P3 — templates + saved designs** | Template library per calc type and size; drafts; "My designs"; design document attached to the order | Order e2e proves the `.pps.json` reaches the cart's package, and that a reopened design exports byte-identical PDF |
| **P4 — folds and booklets** | Brochure fold panels from the calculator's fold tables; saddle and perfect-bound spreads, page multiples, spine from the calculator | Imposition tool imposes a designer-made booklet with no MISMATCH |
| **P5 — AI drafts (staff)** | MCP-driven first drafts from a brief | Drafts pass §6 unmodified |

## 11. Risks, in order of how much they should worry you

1. **Upstream maturity.** Pre-alpha by its own measure (~47% "ready for real work"), with crash
   and copy/paste bugs on Windows and Linux. Our exposure is lower than its headline — we use
   the engine and skip its UI, where most of the open issues sit — but the engine is ten days
   old. Mitigation: never edit upstream crates; pin to commits; merge upstream deliberately,
   behind the gates above.
2. **Payload and phones.** An engine with fonts, a CPU renderer and a PDF writer is a large wasm
   download, and the renderer is single-threaded unless the page is cross-origin isolated
   (COOP/COEP), which a WordPress page with third-party scripts is not. **Not yet measured** —
   building upstream from this sandbox was refused (it runs third-party build scripts), so P0
   measures it in CI. Fallbacks if it is heavy: load only on "Design it online", cache
   aggressively, render at lower preview DPI.
3. **Text editing on touch screens.** The hardest piece of any editor. Upstream's caret logic
   helps, but mobile selection, the on-screen keyboard and IME belong to our shell. Budget for it.
4. **Deploying wasm.** `pps-html-deploy.php` accepts an explicit list of HTML files, by design.
   The designer adds a `.wasm`, JS glue and fonts; it needs its own allow-listed, SHA-pinned
   deploy path, not a widened list.
5. **The repo is public.** Templates are fine; nothing in the designer may carry pricing — and by
   §1 it doesn't.

## 12. What happens to the TypeScript spike

`designer/` stays where it is until P1 ships, then is retired. What carries forward:

- its **verdict** (a browser can emit a press-acceptable PDF) and its **"what not to do"**
  (no UI that can't survive to the PDF; no Illustrator-parity race; no WooCommerce designer-plugin
  code),
- its **export assertions** — box geometry, CMYK operators, subset fonts, image orientation,
  rotation handedness — rewritten against the DesignCraft exporter as P0's acceptance test,
- its **first next step, still undone**: put a real file through the real RIP.

`products.ts` (hand-typed trade sizes) is not carried forward. The calculator sends the spec, which
closes that file's own TODO.
