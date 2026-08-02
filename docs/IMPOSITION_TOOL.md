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
   existing Drive connection. Matching is **per line item** (via the item's
   `_pps_artwork_files` deliverable names / `_pps_gdrive_file_id`), preferring
   its `*_print-ready.pdf` — a multi-item order can never impose the wrong
   item's art. Folder listings are cached ~3 min (Refresh busts the cache).
2. The engine imposes it and shows a rendered preview of every sheet side.
3. Review, then **Send to order's Drive folder** — the file lands next to the
   artwork as `IMPOSED_Order-<id>-i<item>_<job>_<trim>_<imp>up_<sheet>.pdf`,
   and an order note is added. The IMPOSED badge is per item (the `-i<item>`
   tag), and a failed Drive listing shows DRIVE ERROR instead of a
   misleading "no art".

**⚡ Impose all pending** runs the whole queue in one click: for every row
with a spec, artwork, and no IMPOSED file yet, it downloads, imposes, and
files the result back to Drive, then reports any failures.

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
  positions per the press flip edge (default **short edge** — Fiery
  short-edge feed on 13″ sheets; long-edge selectable) and the back's 180°
  tumble is **derived automatically** (needed exactly when the flip axis is
  perpendicular to the placed art's head axis — flats and booklets share the
  rule). Auto is the default; force-0/force-180 remain as overrides. All
  four flip×rotation combinations are asserted by the physical duplex
  simulator in `tests/imposition/duplex_sim.py`.
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
- Gutter policy (flats/stickers): 0.25″ (full double bleed) preferred,
  squeezed to 0.125″ then butt-cut 0″ only when the priced count can't fit
  otherwise — reported in the UI and slug. A **gutter override** (0–0.5″)
  replaces the auto policy; if the priced count can't fit at that gutter
  the tool refuses (same mismatch flow as always).
- **Saddle spreads BUTT by default**: signatures share ONE cut line and
  the interior bleed is cropped at it (each spread's clip stops at the
  shared trim), so bleed never manufactures a gutter the operator didn't
  ask for. The Gutter select (shown as "Butt (default)" for saddle) still
  spaces spreads out explicitly when wanted. Verified: every spread
  renders pixel-identical inside trim whether butted or guttered, on all
  faces, with no neighbour-bleed leak across the shared cut.
- **Layout position**: by default the trim block is **jogged 0.5″ against
  the sheet's lower-right corner** on the front — the back mirrors across
  the short-edge flip to the lower-LEFT, i.e. the same physical corner, so
  the cutter jogs both sides against one registration corner. Centered and
  custom (from-right / from-bottom) overrides available. The block is always
  clamped so the trim stays inside the printable area; if bleed would fall
  outside it, the tool warns.
- **Efficient mode** (flats imaging ≥2-up on 13×19, **and saddle booklets**):
  lays the same piece out on **13×27.5**, the multiple expanding to fill the
  27″ usable axis (e.g. 11×8.5 goes from 2-up to 3-up per pass). Pricing basis
  stays the 13×19 imp — badge and slug show both numbers. For **saddle
  stitch**, the "piece" is a printer's spread: efficient mode step-and-repeats
  more identical **signature copies per sheet** on the oversize sheet (e.g. a
  5.5×8.5 booklet goes 2→3 copies/side), cutting press passes for the same
  book count. It only fires when 13×27.5 actually holds more copies than the
  priced 13×19 layout (otherwise it says so and stays on 13×19); duplex
  registration on the wide sheet is verified by the physical simulator on
  both flip edges.
- **Piece patterns** (flats & stickers): Straight / **Head-to-head** (row
  pairs, heads meeting at the shared cut) / **Foot-to-foot** (phase-shifted
  complement) / **Reversal** (alternate columns 180°) / **Reversal
  alternate** (checkerboard) / **Manual** — a per-cell tap-to-flip grid for
  operator judgement calls (dutch-cut prep, grain, ink balance). Each
  piece's back inherits its front's 180° automatically, so patterned sheets
  still register through the duplex flip (simulator-verified per cell). A
  separate **grid orientation** override forces the pieces unrotated /
  rotated 90° on the sheet (refusing through the standard mismatch flow if
  the priced count no longer fits). Patterns don't apply to saddle
  signatures — the spine dictates orientation.
- **Size autodetect**: on file drop the trim is read from the PDF — an
  embedded TrimBox wins; otherwise the media size is judged bleed-inclusive
  when `media − 2×bleed` lands on a rounder size (halves beat quarters),
  honouring `/Rotate`. Standalone drops auto-fill the trim fields; when an
  order is loaded, the order spec stays authoritative and a measurement
  conflict is flagged loudly instead.
- **Bleed override** (default 0.125″/side): flows through everything bleed
  touches — cell clip allowances, art-location heuristics, crop-mark
  offsets, the bleed-clip warnings, and the auto gutter ladder (2×bleed →
  bleed → butt). Non-default bleed is stamped on the slug. Sticker pitch
  stays fixed at 0.25″ per the crack-n-peel spec.
- **Add bleed** (for files that have none): synthesizes bleed for source
  pages that arrive exactly at trim. Four methods — **auto (text-safe)**,
  the recommended default: a true mirror on clean edges, switching to
  streak on any edge where TEXT sits within bleed+0.04″ of the trim
  (pdf.js `getTextContent` gives exact glyph geometry; a mirror there
  would reflect a READABLE copy of the text into the bleed — streak
  smears it into nothing). **Mirror edges** (classic prepress mirror
  bleed: the art's edge content is reflected outward past the trim line,
  all vector, content at the cut stays put). **Scale up** (art enlarged
  just enough to cover the bleed box; trim-line content shifts outward
  and the safety margin shrinks by the bleed amount — the better pick for
  photo collages and faces, where a mirror would visibly reflect people
  at the edges). **Streak** (the outermost sliver of art stretched
  outward to fill the bleed — sliver width adjustable via the "Streak
  sampler" input, default 0.025″, clamped 0.005–0.25″: smaller = purer
  edge color, larger = softer smear — continuous at the cut, nothing readable
  survives; what auto uses on text edges, selectable outright). Per-axis
  the transform is one family: a mirrored map about the trim edge with
  stretch factor k (k=1 = mirror, k=bleed/0.025 = streak); corners
  compose the two adjacent edges' factors, so mixed mirror/streak
  corners stay continuous with both bands. If text detection fails, auto
  streaks every edge rather than risk duplicating text. Applies per page
  and ONLY to pages that genuinely lack bleed — pages with a real
  TrimBox/bleed are never touched. Works across flats, collated modes,
  patterns (mirror strips inherit per-cell 180°), duplex backs, and
  saddle signatures (the spine's no-bleed clip also clips the synthesized
  bleed, so nothing crosses the fold). Interior butt cuts stay clean —
  the cell clip that blocks real bleed blocks the synthetic kind too. The
  UI highlights the control whenever a no-bleed warning fires. Verified
  by pixel tests: true mirror symmetry at every trim edge on both sides,
  zero pixel change inside trim, and zero change to files that already
  have bleed.
- **Smart edge detect** (on by default with add-bleed): each no-bleed
  page is rasterized (pdf.js, 144 dpi) and the actual ink is measured
  against the trim on every edge. Three outcomes per page:
  - ink reaches trim → synthesize normally;
  - ink stops **just short** of trim (≤3/16″ — a full-bleed design that
    fell shy) → the art is pre-scaled about its center so ink reaches the
    cut first (mirror: ×h/(h−gap); scale folds the gap into its factor:
    ×(h+b)/(h−gap)) — without this, mirroring/scaling from the page edge
    would DOUBLE the white sliver at the cut;
  - edges are **white by design** (all margins >3/16″: text pages,
    bordered layouts) → synthesis is skipped entirely, page passes
    through untouched (white bleed happens naturally).
  Detection failures fall back to synthesizing from the page edge with a
  warning. Verified: shy-art fixtures show ink through the cut with smart
  on and a white sliver with it off; design-margin pages render
  pixel-identical to add-bleed-off.
- **Per-page add-bleed override**: for multipage files a per-page picker
  (blank = job setting / off / mirror / scale) lets e.g. a booklet's
  photo-collage pages use scale while others mirror or stay off. Engine:
  `spec.addBleedPages = {pageNumber: mode}` (1-based, main file; a
  separate back file uses the job-level mode). Overrides reset when a new
  file is loaded. Saddle warnings summarize the per-page outcome
  ("synthesized on 5 of 8 pages…"). Verified by reference-run comparison:
  the per-page run's cells are pixel-identical to all-mirror/all-scale
  runs cell-by-cell per the chosen mode.
- **Gang combo (multiple files on one sheet)**: add 2+ same-trim PDFs in
  the "Gang combo" list and each design takes a share of the sheet's
  cells — blank slot counts split the cells evenly, or set an explicit
  count per file (total may not exceed the cell count; unassigned cells
  stay blank). Pieces fill contiguous blocks in reading order so each
  design cuts as a cluster. 2-sided reads each file's page 1 (front) +
  page 2 (back), and every piece's back registers on its front's
  mirrored cell — color-fixture verified per cell. Qty = press sheets
  (each sheet yields the listed count per design). Job-level add-bleed
  applies per file (per-page overrides are hidden while ganging).
  Flats/stickers only — a saddle booklet is one document. The engine
  accepts the list as `imposePdf(art, back, spec, gangFiles)` with
  `[{bytes, name, slots}]`.
- **Multipage collated flats**: when a flat/sticker file has more pages than
  a single design uses, a "Multi-page file" select offers three handlings —
  **repeat single design** (page 1 [+2], default), **gang in order** (pages
  fill the cells sheet by sheet in reading order), or **cut & stack** (pages
  dealt pile-by-pile so the cut piles, stacked in reading order, yield the
  collated sequence), or **repeat each page** (every piece gets its own full
  step-and-repeat sheet — for per-design run quantities; sheets = pieces ×
  ceil(qty ÷ imp)). 2-sided collated files read front/back interleaved
  (p1 = front of piece 1, p2 = its back, …) or take a separate back file
  (page i backs piece i). Leftover cells stay deterministically blank;
  qty means copies per collated set; sheets = qty × sheets-per-set.
- Sheets = ceil(qty ÷ imp), shown in UI and slug.
- **Marks live INSIDE the printable image area** (12.5×18.5 / 12.5×27 /
  11.5×17.5) — the press can't image the sheet margins, so a margin mark
  would never print. Each crop-mark ray extends from just beyond the bleed
  and stops at the printable boundary or the nearest piece's bleed box,
  drawn only "as size allows" (at standard 0.25″ gutter, bleed meets bleed
  between pieces, so interior marks are omitted). The slug line is placed in
  the largest clear band inside the printable area (with the default jog,
  the strip above the block) and omitted with a warning when there's no
  room. Fold guides follow the same rule.

## Security — malicious-PDF defense

Customer-uploaded PDFs are an untrusted input. The tool treats them as such:

- **Threat inspection on load** — every dropped or Drive-pulled PDF is
  walked for the payload classes that live in the document catalog and
  annotations: document/annotation JavaScript, `/OpenAction` and `/AA`
  auto-run actions, `/Launch` actions, `/EmbeddedFiles` attachments,
  AcroForm/XFA form fields, and RichMedia/Screen/Movie/3D annotations. A
  red **ACTIVE CONTENT** badge lists exactly what was found and warns the
  operator not to open the original in a PDF viewer.
- **Imposition is inherently sanitizing** — source pages are re-embedded as
  vector XObjects into a brand-new document; the catalog/annotation
  payloads never carry over. `stripActiveContent()` also deletes those keys
  from the source *before* embedding, so no orphaned payload objects linger
  in the output bytes either. The output preflight re-inspects the finished
  PDF and refuses if any active content somehow survived. (Verified: a
  fixture carrying JS + OpenAction + AA + Launch + embedded EXE + AcroForm
  is fully detected, and both the imposed sheet and the CLEAN copy come back
  with zero threats and zero payload strings in the raw bytes, content
  pixel-identical.)
- **CLEAN 1:1 copy** — a button on any loaded PDF produces
  `CLEAN_<name>.pdf`: the same pages at the same size, vectors intact, with
  all active content stripped — even for files imposition can't or won't
  lay out (any product, any size). In wp-admin it can be filed straight to
  the order's Drive folder (`CLEAN_Order-<id>-i<item>_…`). **Operational
  rule: staff open the CLEAN copy, not the customer original.** Caveat:
  annotations/form fields lose their visible appearance in a CLEAN copy
  (they're part of the stripped layer).
- **Upload hardening** (`pps-calculators.php`) — the customer artwork
  endpoint validates magic bytes against the claimed extension (blocks
  polyglots/renamed executables), drops an `.htaccess` + `index.html` guard
  in the artwork tree (no execution, no directory listing), and — when
  `wp_options['pps_vt_api_key']` is set — does a **privacy-safe VirusTotal
  HASH lookup** (only the SHA-256 is sent, never the file); ≥2 engine
  detections reject the upload, everything else fails open (second opinion,
  not primary defense). The imposition upload endpoint accepts only
  `IMPOSED_*`/`CLEAN_*` `.pdf` names with a `%PDF` magic check.

Not covered: the press RIP rasterizes and never executes PDF actions, so it
isn't a target here. The larger surface is the WordPress admin itself
(2FA/login throttling) — out of scope for this tool.

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
  saddle preset table — **injected live from `pps_get_config()['size_presets']`
  in wp-admin** (an admin preset edit can never drift production from
  pricing; the embedded copy is only the standalone fallback) — with the
  verbatim `calcCustomImp` port for custom sizes. The `imp:1` presets
  (12×12, 11×8.5 L, 12×9 L) move to the 13×27.5 sheet at 1 spread/side, as
  `resolveSize`'s signature limits imply;
- gives the spine **no bleed** (pages meet exactly at the fold; dashed fold
  guides drawn in the margins) while outer edges keep full bleed + clip;
- backs up the inside with mirrored slots and automatic tumble: 180° only
  when the press flip axis is perpendicular to the placed pages' head axis.
  **Default flip is short-edge** (Fiery presses feed 13″ sheets short-edge
  first), so horizontal spreads back up upright with no rotation; both flip
  modes are verified with numbered-page fixtures;
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
