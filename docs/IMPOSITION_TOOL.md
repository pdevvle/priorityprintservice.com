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
- **Optional content (PDF layers) is preserved.** `pdf-lib` copies a page's
  marked content and its OCG objects but NOT the catalog's `/OCProperties` —
  the dictionary recording which layers are switched OFF. Without it every
  layer defaults to visible, so a customer's HIDDEN layer prints. This bit a
  real job (Nursing Form, 2026-08-25): a hidden Illustrator "Layer 1" ghosted
  behind the live artwork on all four cells — 31% of the ink on the sheet.
  The tool now rebuilds `/OCProperties` on the output, carrying the source's
  ON/OFF state onto the copied OCGs, and additionally stamps hidden groups
  `/Usage /Print /PrintState /OFF` so a RIP reading usage rather than the
  default config also leaves them out. A warning names how many hidden layers
  were found. Applies to imposition (flats, saddle, gang) and the CLEAN 1:1
  copy alike. The visibility is *carried*, not flattened: marked-content
  nesting and `q`/`Q` nesting are independently legal in PDF (that very file
  opens a `q` inside the hidden block and closes it after the `EMC`), so
  cutting the block out by hand corrupts the graphics state. If a RIP ignores
  optional content entirely, flatten the layers in the source.
  - The mapping is a **parallel walk of source and copy by resource name**,
    matching by ref identity — never by layer NAME, which is per-namespace and
    routinely duplicated (the motivating file has four OCGs called "Layer 1",
    two on, two off). Covered shapes: BDC marked content via `/Properties`,
    `/OC` sitting directly on image/form XObjects, **OCMD membership dicts**
    (AnyOn default — hidden iff every member is off), nested XObjects with
    their own `/Properties` namespaces, and pages whose `/Resources` are
    INHERITED from the Pages tree. `/VE` visibility expressions are not
    evaluated (treated as visible — the safe direction). An entry with no
    source counterpart stays visible.
  - Implementation note: `asDict()` exists because a pdf-lib `PDFDict` *also*
    has a `.dict` property — the internal `Map`, not a sub-dictionary — so the
    `.lookup` test must come first or form XObjects silently read as empty.
    Collection happens after `await out.flush()`, because `embedPdf` is lazy
    and the copied XObjects do not exist in the context until then.
- **Parent sheet override** (`spec.sheet`): `auto` (the product's own press
  sheet) / `13x19` / `13x27.5` / `12x18` / `custom` with `sheetLong`,
  — a custom sheet's key carries its dimensions (`custom17x11`) so the slug
  and `IMPOSED_` filename stay traceable, and an imp:1 booklet on a forced
  parent sheet passes preflight (the spread-count expectation is pinned, the
  sheet identity is the operator's call) —
  `sheetShort` and `sheetMargin` (imageable margin per edge, default 0.25″ —
  what all three stock sheets use). Long/short are normalised, so entering them
  either way round works. Capped at 60″; a margin that leaves no printable area
  is refused. It changes only the **physical** sheet — the priced imp stays what
  the calculator quoted, so a count that no longer fits raises the usual
  refusal (with `allowBestFit` as the escape hatch) rather than silently
  changing. Marks, slug and the full-sheet-fill bound all follow the new
  imageable area, and the output page is the custom size. Efficient mode and
  the manual grid use the forced sheet when one is set; a sticker job on a
  non-12×18 parent is laid out by the generic grid search and warned about,
  since the crack-n-peel pitch no longer applies.
- **Marks are three independent switches**: `spec.marks` (crop/trim marks),
  `spec.foldMarks` (saddle fold guides) and `spec.slug` (the slug line). Each
  can be hidden on its own — e.g. marks off but slug kept, for pre-cut stock or
  a customer-facing proof. `marks` stays the master for older specs: an explicit
  `marks:false` with nothing else still silences all three, exactly as before.
- **Pull art off trim** (`spec.artInset`, inches): for the recurring case of
  customer art whose text crowds the cut. Shrinks the placed art about the cell
  centre so every edge backs away from the trim, then the add-bleed synthesizer
  fills the ring it opens — from the art's new edge out past the cut to the
  bleed box. The two settings are one operation: the inset makes the room, the
  bleed fill makes up the difference (the fill band becomes `inset + bleed`).
  - Scale is **uniform** — customer art is never distorted — so one factor
    cannot give the same inset on both axes. It solves for the SHORT axis, so
    the value is a guaranteed **minimum** on every edge and the long axis pulls
    in proportionally further (a 0.125″ inset on 4×2 gives 0.125″ down,
    0.250″ across). The UI shows the resulting factor and both distances.
  - Works with art that already has bleed: pulling the art in drags its bleed
    in too, so the ring is filled from wherever the art's outer edge lands.
    Internally `art.covX/covY` is the signed coverage past the trim after every
    scale factor (fit-to-trim, shy-art pre-scale, inset); the fill reflects
    about that edge with band `bleed − cov`, which reduces **exactly** to the
    previous trim-edge mirror when there is no inset (byte-verified).
  - **Text-safe detection widens with it** — the reflected band is bigger, so
    `auto` searches for text within `bleed + inset` of the trim rather than
    just the bleed.
  - Refuses rather than print something unusable: combined with **Scale art up
    to bleed** (the two are opposite operations — scale re-expands to the bleed
    box and undoes the inset); with **Add bleed off** when the art cannot cover
    the gap (that band would print paper-white *inside* the trim); or when the
    inset would shrink the art by more than half.
  - Recorded on the slug as `PULLED OFF TRIM <n>in`.
- **Creep / shingling compensation** (saddle, `spec.creep`): folded signatures
  nest, so inner leaves push outward and trim narrower than the cover. The
  operator sets the TOTAL shift at the innermost signature; the cover gets none
  and the rest ramp linearly (`creep · k/(S−1)`) — the same way Fiery states it.
  Content slides spine-ward (left page +, right page −); the **trim and clip
  rects deliberately do not move**, because the guillotine still cuts in the
  same place. The sliver that opens at the outer edge is covered by the art's
  bleed, so a no-bleed file wants Add bleed on too. Capped at 0.5″ with a
  message that the value is a total, not per-signature. Verified: ramp linear
  to ±0.005″ across 8 signatures (ink-profile correlation), the two halves shift
  in OPPOSITE directions, trim/clip byte-identical with creep on, and duplex
  registers on both flip edges.
- **Free art scale** (`spec.artScale`, 25–400%): a plain scale on the placed
  art, up or down. Independent of the bleed-coupled pull-off-trim inset — the
  two compose — and it feeds the same `covX/covY` coverage maths, so scaling
  down inherits the refusal that stops a band printing paper white inside the
  trim. Scaling up simply clips to the cell. Verified exact at 90% and 110%
  against interior markers.
- **Manual signature copies** (saddle): the same control on the saddle side —
  the operator sets copies across × down outright instead of taking the spread
  count from the priced imp table, so a booklet can be ganged 3-up on the
  oversize sheet for fewer press passes. Every copy on a side is the SAME
  signature (verified byte-identical cell-to-cell), pagination and the duplex
  flip are unaffected, and the count is flagged against the priced imp exactly
  like a flat's manual grid — badge, warning, slug, excluded from the parity
  preflight. Refuses with a footprint report when the copies cannot fit.
- **Signatures onto the sheet** (saddle, `spec.sigOrder`) — a three-way radio
  in the options panel. Step and repeat here operates on a signature that
  already carries its own imposition (the 4-page saddle spread: `36|1` on the
  front, `2|35` on the back), so this stage is **imposing an imposition**.
  Pagination into signatures is identical in all three; only which signature
  lands in which cell changes:

  - **`repeat` — Step and repeat (default).** Every cell is the SAME signature,
    stepped across the sheet: one form per signature, and the run divides by the
    cell count (`ceil(qty/C)` sheets per form). Cut, then collate the lifts into
    books. This is the behaviour every build before 1.18 had, and 1.19 restored
    it — the content streams are byte-identical to the pre-1.18 output; only the
    PDF Title metadata differs. `spec.sigRepeat` additionally sets the copy
    count as a **number** ("3× per sheet") and lets the tool pick the
    arrangement, promoting to 13×27.5 or the forced parent when the standard
    sheet cannot hold a whole grid.
  - **`cutstack` — Cut and stack.** The signature list runs DOWN the columns:
    9 signatures 2-up gives `1|6, 2|7, 3|8, 4|9, 5|·`. Cut the pile once, drop
    each lift under the one to its left, and the stack is already in signature
    order.
  - **`gang` — Gang in order.** Consecutive signatures side by side —
    `1+2, 3+4, 5+6, 7+8, 9` — so one sheet through the press is one book's
    worth. A 16pp 3.5×5.5 book (4-up) lands on a single sheet front and back.

  **1.18 briefly made `gang` the default. That was wrong** — it was inferred
  from a report that the step-and-repeat toggle "did nothing", when the real
  cause was that the default already *was* step and repeat, so the toggle had
  nothing to change. The fix was to make the choice explicit, not to move the
  default. If a future session reads a complaint about this control, check
  which mode is selected before changing any default.

  Shared behaviour: uneven cases leave the last form's spare cells **blank**
  (filling them would print unequal quantities of a signature) and the warning
  quantifies the cost; the reverse case — fewer signatures than cells, cells
  dividing evenly among them — tiles instead (2 signatures in 4 cells →
  `1,2,1,2`). Labels and the slug read `FORM f/F SIGS 1+2 OUTSIDE`, and a
  preflight counts every page across the forms and refuses if any is placed
  more or fewer times than the mode calls for.

  Verified against a real 36pp customer job (`AH216627`, 5.5×8.5, 9 signatures)
  with every page stamped — repeat `1|36, 1|36`; cut-and-stack `1|36, 11|26`;
  gang `1|36, 3|34` — and **every half-page backs the correct leaf-mate under a
  single rigid sheet flip** in all three modes, on both flip edges, with and
  without creep. Flats and stickers are byte-identical across the change.

- **Manual grid** (flats & stickers, Fiery-style): the operator can set the
  **columns × rows outright** instead of taking the count derived from the
  priced imp — a 2×4 coupon can be run 9 across × 3 down, 8×2, 4×5, whatever
  the job calls for. A **press-sheet** selector (Auto / 13×19 / 13×27.5 /
  12×18) sits beside it; Auto tries the calc's normal sheet first and only
  promotes to the oversize sheet when the grid won't fit. Orientation follows
  the existing grid-orientation override, else whichever way fits (unrotated
  preferred), and the gutter ladder (2×bleed → bleed → butt) squeezes as
  needed to honour the requested count — reported when it does.
  **Pricing parity is deliberately not assumed here**: the layout is tagged
  `manualGrid`, the badge reads `MANUAL GRID a×b — PRICED n-UP` (amber when
  the counts differ), a warning spells out that sheet count and cost per piece
  will differ, and the slug carries `MANUAL GRID axb (priced n-up)`. It is
  excluded from the parity preflight for that reason — this is the one path
  where placing ≠ priced is intentional rather than a fault. A grid that
  cannot physically fit is refused with its footprint vs the sheet, and
  `MANUAL_GRID_MAX` (400 cells) backstops a fat-fingered entry.
- **Per-gap gutters everywhere**: the same per-gap editor now serves flats and
  stickers as well as saddle — one field per column gap and per row gap, sized
  to the live grid, honoured exactly (verified to 4 decimal places), with the
  full-sheet-fill toggle alongside. Gap arrays left over from a different grid
  are ignored in favour of the uniform gutter and the operator is told. A
  footprint larger than the physical sheet is refused; one larger than the
  imageable area warns unless "fill full sheet" is ticked.
- **Saddle signature gutters** (manual, Fiery-style): the gutter between
  signature copies is editable **per gap on both axes** from a "Signature
  gutters" panel — one field per column gap and per row gap, sized to the
  live `across×down` grid. A **"fill full sheet"** toggle lets the operator
  place gutters that push the layout past the 18.5×12.5 imageable area onto
  the full 13×19 sheet (for guillotine cutting); a footprint that exceeds the
  physical sheet is refused. Internally `buildCells`/`blockOrigin` use
  `colGaps[]`/`rowGaps[]` arrays — uniform gaps are byte-identical to the
  prior fixed-stride output (pixel-verified), and the physical duplex
  simulator confirms registration on both flip edges with asymmetric gutters.
  (An automatic small-book default ruleset is planned but not yet wired —
  gutters are operator-set for now.)
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
    bordered layouts) → the shy-art **pre-scale is suppressed**, but the
    fill still runs. It used to skip synthesis entirely, which read to the
    operator as *"the bleed control does nothing"* on exactly the files
    where they had reached for it. Mirroring a white edge yields white
    bleed — correct either way — so there was never cause to veto an
    explicit setting; the note now says the fill is white because of the
    file, not the setting.
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
