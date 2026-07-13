# PPS Auto-Imposition Tool — Integration Brief & Spec

> **Status:** the prior iteration of this tool could not be located in any repo
> (`priorityprintservice.com`, `PPS-tools`), Google Drive, or as a Claude
> Artifact. This brief is written forward — what the tool must do, how it plugs
> into the live order → artwork → production flow, and root-cause fixes for the
> four failure modes seen last time (bad PDF output, wrong orientation, wrong
> cropping, arbitrary fill). **To analyze/diff the last iteration, the actual
> file or chat is needed** — paste the code or drop the file in and this brief
> becomes a concrete fix list.

---

## 1. Purpose

Take the artwork a customer submits with an online order and **automatically
lay it out (impose) on the correct press sheet in the most material-efficient
way**, producing a print-ready, vector-preserved imposed PDF plus a marks layer
— with zero manual prepress for standard jobs.

## 2. Where it sits in the current workflow

```
Customer configures job in a PPS calculator (size, stock, sides, qty, bleed)
        │  → cart line item captures the full spec
        ▼
WooCommerce order created
        │  PPS-Spec (pipe-delimited spec string) written to order meta
        │  Artwork uploaded to Google Drive by pps-gdrive.php
        │    (idempotent; artwork path stored on the order for reorder)
        ▼
►►►  AUTO-IMPOSITION TOOL  (new)  ◄◄◄
        │  1. read the order spec (size, sides, qty, stock, bleed)
        │  2. pull the artwork PDF from the order's Drive folder
        │  3. pick press sheet + compute n-up (SAME logic the calc prices on)
        │  4. impose (step-and-repeat), preserving vectors + bleed
        │  5. write imposed PDF + marks back to a "ready-to-print" Drive folder
        ▼
Press operator prints the imposed sheet(s); count = ceil(qty ÷ imp)
```

**Critical integration rule — pricing and production must agree.** The
calculators already compute imposition per press sheet (`calcBrochureImp`,
`calcLetterheadImp`, `calcStickerImp`, and the booklet `imp`/`impHalf`). The
tool must reuse the **same** imposition counts, press-sheet selection, and
orientation the customer was *priced on* — otherwise you either lose margin
(fewer-up than priced) or over-run. Extract that logic into a shared spec
(a small JSON/table of `size → (imp, press_sheet, orientation)`) that both the
JS calculators and the Python tool read, so they can never drift.

## 3. Inputs the tool consumes (all already exist)

| Input | Source |
|---|---|
| Trim size (long × short) | order spec / PPS-Spec |
| Sides (1 or 2) | order spec |
| Quantity | order line item |
| Stock | order spec (selects press sheet & sheet inventory) |
| Bleed present? | order spec (`I don't have bleeds` adds 0.5 handling) |
| Artwork PDF(s) | Google Drive path stored on the order (`pps-gdrive.php`) |

## 4. Press sheets & imposition (mirror the calculators)

- Standard parent sheet **13×19**; oversized jobs (imp < 1 on 13×19) run on
  **13×27.5**; stickers run on **12×18** crack-n-peel. Bleed **0.125"**.
- `imp` = copies per parent sheet. **Sheets = ceil(qty ÷ imp).**
- "Most efficient" = maximize copies/sheet: test both orientations (0° and 90°),
  pick the one that yields more cells while respecting bleed + gripper/trim
  margins, then step-and-repeat.

## 5. The four failure modes — root cause → fix

### (a) "PDFs not output properly"
**Cause:** almost always rasterizing pages, or writing a PDF with malformed
page boxes / no font embedding.
**Fix:** place the source PDF as a **vector XObject** — do not rasterize. Use
`pikepdf` (qpdf) or `PyMuPDF` (`page.show_pdf_page()`) so vectors/fonts/CMYK are
preserved. Set every imposed sheet's **MediaBox** (full parent sheet),
**TrimBox** (finished sheet), and **BleedBox** explicitly. Validate output with
a preflight (page count, box geometry, embedded fonts) before it's written.

### (b) "Orientating them wrong"
**Cause:** blind 90° rotation, ignoring the source PDF's `/Rotate`, or rotating
to fit without preserving reading direction; on duplex, not mirroring the back.
**Fix:** normalize each source page by its `/Rotate` first. Rotate a *cell*
90° **only** when it strictly increases copies-per-sheet **and** the artwork's
own aspect ratio matches (never rotate a portrait design into a landscape cell
just to fill space). For **duplex**, flip the back sheet on the correct axis for
the **bind/flip edge** (long-edge vs short-edge) so front/back register — this
is the #1 duplex imposition bug.

### (c) "Cropping them wrong"
**Cause:** placing by **MediaBox** instead of **TrimBox/BleedBox**, or scaling
"fill" so content gets clipped.
**Fix:** locate art on its **TrimBox** (fall back to BleedBox, then MediaBox
only if absent). Scale **fit-to-trim** (never fill-crop). Require/extend the
0.125" bleed; if the file has no bleed, mirror-extend edge pixels or pull the
declared bleed rather than scaling content into the trim. Never let content
cross into the safety margin.

### (d) "Arbitrarily filling them"
**Cause:** cells populated non-deterministically — wrong art in cells, or
placeholder/garbage in leftover cells.
**Fix:** two explicit modes, no guessing:
- **Single-design run (default):** deterministic **step-and-repeat** — the same
  artwork in every cell, identical transform. No cell is ever undefined.
- **Gang run (multiple jobs on one sheet):** an explicit **cell → job map**
  from a bin-packing pass; log the assignment; leftover cells stay **empty**
  (blank), never filled with a placeholder. Emit the map alongside the PDF.

## 6. Output

- **Imposed press-ready PDF** (vector), one page per sheet side, boxes set.
- **Marks layer:** crop/trim marks, bleed box, fold/score lines where relevant,
  a slug line (order #, job name, size, stock, sheet N of M).
- Written back to a per-order **"ready-to-print"** Drive folder (reuse the
  `pps-gdrive.php` credentials/flow) and/or attached to the WooCommerce order.

## 7. Recommended build

- **Python**, in the **`PPS-tools`** repo (it's already the Python tools home) as
  a new `imposition/` module + a `pps-tools impose <order-id>` CLI command.
- **Libraries:** `pikepdf`/`PyMuPDF` for vector placement (NOT reportlab-raster),
  optional `reportlab` only for the marks overlay. Add a preflight step.
- **Shared imposition spec:** export the calculators' `size → (imp, press_sheet,
  orientation)` table to JSON so JS pricing and Python production share one
  source of truth.

## 8. Open items before build

1. **The old tool** — share the file/chat so its real bugs can be diffed against
   this spec (don't want to re-solve what already worked).
2. Confirm the **Drive folder convention** for finished/imposed files.
3. Confirm whether **ganging different orders** on one sheet is in scope now, or
   single-design step-and-repeat only for v1.
