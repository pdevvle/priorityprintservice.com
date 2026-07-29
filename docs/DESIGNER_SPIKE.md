# PPS Designer — export spike

**Date:** 2026-07-29 · **Status:** spike complete, verdict below · **Code:** `designer/`

This is the pick-up-cold document. It is deliberately blunt about what does not
work. If you are returning to this after months away, read this file and
`designer/README.md`, then run `cd designer && npm test`.

---

## The question

Not "can we build an editor" — of course we can. The question that decides
whether the project is worth doing at all:

> Can a browser emit a PDF that a commercial press will accept?

If the answer were no, every hour spent on canvas UI would be wasted. So it was
built first, before any of the fun parts.

## Verdict

**Yes, for a digital-press workflow. Not yet for a strict PDF/X-1a workflow.**

The browser produces a file with correct box geometry, real CMYK vector and
text, and properly subset embedded fonts. That is genuinely most of the way
there. The remaining gaps are known, bounded, and listed below — none of them
look like research problems.

The recommendation is to continue, on the web stack, and defer Tauri.

---

## What is proven

Run `npm test`. It builds a two-page tri-fold with a full-bleed background,
CMYK vector shapes, an embedded PNG, wrapped body copy, and rotated + tracked
text; exports; reloads the bytes; and asserts. All six checks pass.

| Proven | Evidence |
|---|---|
| Page geometry with real bleed | MediaBox 11.25×8.75″, TrimBox 11×8.5″ inset 0.125″ on all four sides, verified by reloading the file |
| CMYK survives to the file | `k`/`K` operators found in the decompressed content stream. Independently confirmed visually: pdf.js renders K=100 as dark slate, not pure black — i.e. it is doing a genuine DeviceCMYK conversion |
| Fonts embedded **and subset** | Three faces of ~400 KB each embed as 4–8 KB. Whole doc is 105 KB |
| Images embed at full res | PNG XObject, orientation verified against a corner marker |
| Rotation is correct | 15° clockwise in the model renders 15° clockwise on the page |
| Output is verifiable | `preflight.ts` reloads and validates before anything can be shipped — same discipline as the imposition tool |
| Same engine runs headless and in-browser | Node test and browser export produce equivalent, correct files |

Rendered proof of both paths was checked visually, not just asserted.

---

## What is NOT solved

Ranked by how much it should worry you.

### 1. Text is the real project — everything here is a placeholder

`wrapLines()` is greedy first-fit. There is **no shaping, no kerning pairs, no
ligatures, no hyphenation, no justification, no bidi, no non-Latin support**.
It is adequate for headlines and short copy. It is **not** adequate for a
body-copy-heavy booklet, and it never will be without a real engine.

This is unchanged from the original assessment: text is the long pole and no
amount of canvas polish shortens it. Options when it becomes the blocker:
HarfBuzz (`harfbuzzjs` in browser, `rustybuzz` native), or adopt a layout core
like Typst (Apache-2.0, Rust→WASM) rather than reimplementing typesetting.

**Corollary — preview and output do not agree.** The canvas wraps using
`ctx.measureText`, the exporter wraps using fontkit metrics. They are close but
not identical, so a line can break differently on screen than in the PDF. This
is the classic WYSIWYG trap and it gets worse, not better, as text features are
added. Fixing it properly means one shared measurement path — which in practice
means doing the real text engine.

### 2. CMYK is stored and exported, but not colour-managed

`cmykToRgb()` is naive algebra for screen preview only. No ICC, no rendering
intent, no soft proofing. On-screen colour will not match press output.
Needs lcms2 (wasm or native) + the press profile.

Related: **images are RGB.** `embedPng`/`embedJpg` pass pixels through
untouched, so a CMYK workflow gets RGB images. Fine for digital presses that
convert at RIP; not fine for PDF/X-1a.

### 3. The OutputIntent is identification only

It stamps a condition name with no embedded ICC profile, so **the file is not
PDF/X conformant** despite carrying the marker. The exporter emits a warning
saying so, deliberately. Real conformance also needs transparency rules, colour
rules, and the `GTS_PDFXVersion` Info key.

### 4. pdf-lib does not tag subset fonts correctly

Subsetting genuinely works (400 KB → 5 KB), but pdf-lib names the result
`Name-1234` instead of the spec-required six-uppercase-letter tag
(`ABCDEF+Name`, PDF 32000 §9.6.4). Strict PDF/X validators flag this. Preflight
reports it as a warning. Fix is either a post-process pass over the font
dictionaries or a patch upstream — small, but it must be done before claiming
PDF/X.

### 5. No clipping path on images

A `fill`-fitted image is clipped in the canvas preview but **not** in the PDF —
the overflow prints. The exporter warns when it detects this. Needs a real clip
path in the content stream.

### 6. Everything else v1 doesn't have

No spot colours, no overprint, no transparency/blend modes, no vector pen tool,
no boolean path ops, no master pages, no paragraph/character styles, no text
threading, no linked (vs embedded) images, no spreads view, no PDF import, no
save/load of the document itself, no crop-mark slug area, no imposition
hand-off, no calculator/pricing integration.

**Note the last two.** The pricing loop and the imposition hand-off are the
actual strategic differentiators, and neither exists yet. The spike deliberately
bought certainty about the export path first.

---

## Decisions worth remembering

**Points are the base unit.** The model stores PostScript points (1/72″), so
the exporter does zero unit conversion. Inches appear only at the UI edge.

**Item origin is the top-left of the bleed box, y-down.** The exporter is the
only code that flips into PDF's y-up space. Consequence: a model rotation of
+15° (clockwise on screen) becomes `degrees(-15)` in pdf-lib. The y-flip
reverses handedness — this is derived in a comment in `pdf.ts` and it is very
easy to get backwards.

**`model/` and `export/` import no DOM.** That is what lets the node test drive
the real engine, and what will let a Rust backend swap in later behind the same
interface. Do not break this.

**Fonts are injected, not imported.** `exportPdf` takes a `loadFont` callback —
`fetch` in the browser, `fs` in node, and eventually the OS font enumerator in
Tauri. Same engine, three hosts.

**Undo is whole-document snapshots.** Fine at this scale, trivially correct.
Swap to a patch log only if it measurably stutters.

---

## Strategic context

Recorded because it will not be obvious in a year: **Affinity Studio became
free in October 2025** under Canva's ownership — Designer, Photo and Publisher
in one app, with CMYK, master pages and PDF/X export. Scribus was already free.

So "a free design app for people without Adobe" is **not** a viable position,
and this project should not be pitched or built as one. The defensible wedge is
the part those tools structurally cannot do:

- the document is a **product**, not a blank artboard
- output is correct **by construction**, not by export dialog
- **live pricing** as the document changes
- one click from design → order → imposition → press

The ROI case is prepress labour saved on jobs already being taken, not new
customer acquisition. Build for existing customers first; a public free
download is upside, not the thesis.

---

## What to do next, in order

1. **Send `out/spike.pdf` to the press.** Everything above is a lab result. One
   real RIP pass is worth more than the entire test suite. Do this before
   writing more code.
2. **Document save/load** (`.ppsd` = the JSON doc). Currently a refresh loses
   everything. Cheap, and it makes the tool usable enough to actually test.
3. **Wire product specs to the live calculators.** `products.ts` is hand-typed
   trade sizes; it should be generated from the calculator size/fold tables so
   the two can never disagree — the same parity invariant the imposition tool
   holds.
4. **Live pricing panel.** Reuse `calculate()`. This is the first thing that
   makes the tool obviously not-Canva, and it's mostly integration work.
5. **Fix the bounded export gaps:** image clip path, subset font tags, embedded
   ICC + real PDF/X conformance.
6. **Then, and only then, the text engine.** It is the biggest single piece of
   work in the project. Don't start it until 1–5 prove the thing is worth having.
7. **Tauri packaging last.** It costs the fast iteration loop (push → 60s Pages
   refresh becomes push → ~10 min CI → download → install). Stay on the web
   stack while the design is still moving. The one thing worth starting early
   is the Apple Developer and Azure Trusted Signing applications (~$220/yr
   combined), because identity verification is calendar-blocked and nothing
   else waits on it.

## What not to do

- Don't add UI features that can't survive to the PDF.
- Don't chase Illustrator/InDesign parity. That race is lost and it was never
  the point.
- Don't mine WooCommerce product-designer plugins for code — they're Fabric.js
  wrappers that solve the layer already solved here, they have none of the hard
  parts, and the GPL contamination risk is real if this is ever published.
- Don't skip `npm test` after touching `export/pdf.ts`.
