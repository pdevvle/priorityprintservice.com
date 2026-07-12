# PPS Auto-Imposition Tool — how it works & how to run it

Browser-based, vector-preserving sheetwise imposition. Takes the artwork a
customer's order put on Google Drive and lays it out step-and-repeat on the
correct press sheet — the **same count, sheet, and orientation the calculator
priced** — then files the imposed, press-ready PDF back **into the same order
folder** on Drive.

Design spec / failure-mode analysis: `docs/IMPOSITION_TOOL_BRIEF.md`.

## Files

| File | Role |
|---|---|
| `imposition-tool.html` | The whole tool: layout engine + PDF engine (pdf-lib) + UI. Runs in the browser — nothing to install, no server-side PDF processing. |
| `pps-imposition.php` | wp-admin host: **PPS Calculators → Imposition** page (iframe) + AJAX bridge to WooCommerce and the existing Drive OAuth (`pps-gdrive.php`). Loaded by `pps-calculators.php`. |

## Two ways to use it

### 1. In wp-admin (the normal way)

**PPS Calculators → Imposition.** The page lists recent orders that have a
Drive artwork folder, with the spec already parsed from each line item's
`_pps_metadata` (calc type, trim size, sides, qty). Click **Impose** on a row:

1. The artwork is pulled from the order's Drive folder through the site's
   existing Drive connection (prefers `*_print-ready.pdf`, falls back to the
   raw upload; previews/manifests are ignored).
2. The engine imposes it and shows a rendered preview of every sheet side.
3. Review, then **Send to order's Drive folder** — the file lands next to the
   artwork as `IMPOSED_Order-<id>_<job>_<trim>_<imp>up_<sheet>.pdf`, and an
   order note is added. Rows already containing an `IMPOSED_*` file show a
   green IMPOSED badge.

No new Google login — it reuses the refresh token stored by `pps-gdrive.php`.

### 2. Standalone (GitHub Pages / any browser)

Open `imposition-tool.html` directly (after merge:
`https://pdevvle.github.io/priorityprintservice.com/imposition-tool.html`).
No WordPress context → no order queue; drag any PDF in (drop the
`*_manipulation_manifest.txt` too and the spec autofills), set the spec, and
download the imposed PDF. Useful for testing and one-off jobs.

## What the engine guarantees (the four historical failure modes)

- **(a) Bad PDF output** — source pages are embedded as **vector XObjects**
  (pdf-lib `embedPdf`), never rasterized; fonts/CMYK pass through untouched.
  Every output sheet gets explicit MediaBox/CropBox/BleedBox/TrimBox, and a
  preflight reloads and validates the file before it can be downloaded or
  uploaded.
- **(b) Wrong orientation** — the source page's `/Rotate` is read and
  compensated; a cell is rotated 90° **only** when the art's own displayed
  orientation differs from the cell's. Duplex backs use mirrored cell
  positions per the press flip edge (long/short, configurable) with an
  optional 180° tumble.
- **(c) Wrong cropping** — art is located by **TrimBox** (fallback:
  BleedBox − 0.125″, then MediaBox size heuristics: bleed-inclusive /
  exact-trim / fit-to-trim). Scaling is always **fit-to-trim (contain)** —
  content is letterboxed, never crop-filled. Every cell is hard-clipped to
  its allowed bleed box so neighbours can't overlap.
- **(d) Arbitrary fill** — deterministic step-and-repeat: same source page,
  identical transform, in every computed cell. No cell is ever undefined.
  (Gang runs of multiple jobs are out of scope for v1.)

## Layout rules (mirror of calculator pricing)

- `flatGrid`/`flatImp`/`stickerImp` in `imposition-tool.html` are verbatim
  ports of the calculators' `calcBrochureImp`/`calcPostcardImp`/
  `calcLetterheadImp`/`calcGreetingImp`/`calcStickerImp`. A 31k-case sweep
  against the live calculator files verified 0 mismatches at build time.
  **If a calculator's imp function changes, change the tool's copy too.**
- Flats: 13×19 (usable 18.5×12.5); imp<1 → 13×27.5 (usable 27×12.5), same
  two-sheet rule as the calculators. Stickers: 12×18 crack-n-peel, fixed
  0.25″ pitch. Bleed 0.125″ everywhere.
- Gutter policy: 0.25″ (full double bleed) preferred, squeezed to 0.125″
  then butt-cut 0″ only when the priced count can't fit otherwise — reported
  in the UI and slug.
- Sheets = ceil(qty ÷ imp), shown in UI and slug.
- Marks: cut guides for every trim line, drawn **only in the sheet margins**;
  slug line (order, job, trim, imp, sheet count, date, FRONT/BACK) at the
  bottom-left.

## Known pricing/production mismatch (custom long-narrow flats)

The calculators' `across` threshold (`r1 < 3 → 2-up`) prices 2-across for
pieces up to 12.5″ long, but two of anything over 9.25″ can't physically sit
across an 18.5″ usable axis. For **custom** sizes with long edge ≈ 9.5–12.5″
and small short edge (e.g. 11×6 prices 4-up; only 3 fit), the priced imp is
unachievable. Standard presets are unaffected.

The tool **refuses** these by default with a clear error. The operator can
tick **"allow best physical fit"** to place the maximum that fits — the sheet
then carries a red MISMATCH badge in the UI and `MISMATCH n-up placed vs
m-up priced` in the slug. Long-term fix belongs on the pricing side — see
`docs/MASTER_PRICING_LOGIC.md` before touching those thresholds.

## Saddle stitch booklets (printer-spread signatures)

Select **Saddle Stitch Booklet** (or load a saddle order from the queue) and
drop a multi-page PDF in reading order (cover = page 1). The engine:

- pads the page count to a multiple of 4 (blanks at the back, warned);
- paginates classic saddle signatures — sheet *k* carries pages
  `[P−2k | 2k+1]` outside and `[2k+2 | P−2k−1]` inside; folded and nested
  they read in order, outermost sheet = cover (slug-labelled `COVER` so it
  can run on cover stock);
- lays out **impHalf spreads per sheet side** where imp comes from the
  saddle calculator's preset table (authoritative — e.g. 6×4 is 8/side even
  though `calcCustomImp` says 6) with the verbatim `calcCustomImp` port for
  custom sizes. The `imp:1` presets (12×12, 11×8.5 L, 12×9 L) move to the
  13×27.5 sheet at 1 spread/side, as `resolveSize`'s signature limits imply;
- gives the spine **no bleed** (pages meet exactly at the fold; dashed fold
  guides drawn in the margins) while outer edges keep full bleed + clip;
- backs up the inside with mirrored slots and automatic tumble: 180° when
  the press flip axis is perpendicular to the placed pages' head axis
  (long-edge flip of a horizontal spread → tumbled; short-edge → upright) —
  verified for both flip modes with numbered-page fixtures;
- slug shows `SIG k/S OUTSIDE|INSIDE · run N sheets`; total sheets =
  signatures × ceil(books ÷ spreads-per-side).

**No creep compensation in v1** — the tool warns at ≥8 nested sheets; thick
books may need shingling allowance later.

## Scope (v1) and later

- **In:** brochure/flat, postcard, letterhead, greeting card, sticker
  (single-design step-and-repeat, 1- or 2-sided), and **saddle stitch
  booklets** (printer-spread signatures, above).
- **Out (v2 candidates):** perfect-bound & coupon book imposition; creep
  compensation; ganging multiple orders on one sheet; fully hands-off
  automation (a scheduled Claude session or Make.com flow could drive the
  same Drive-in/Drive-out loop server-side; the engine is deliberately
  callable headlessly via `window.__ppsImpose`).

## Deployment

1. Merge to `pps-pricing-config` (Pages serves the standalone tool from the
   branch root — `.nojekyll` already handles the Babel/Liquid issue).
2. Copy `imposition-tool.html`, `pps-imposition.php`, and the updated
   `pps-calculators.php` into the live plugin directory (the usual
   plugin-file deploy flow / `pps_plugin_write_file`).
3. Requirements on the site: Drive connected in **PPS Calculators → Google
   Drive**, WooCommerce active. The Imposition submenu appears under PPS
   Calculators for admins (`manage_options`).
