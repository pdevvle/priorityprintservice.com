# Brief for the imposition session: what the proofer now hands you, and six asks

From the proofer session (`proof-ui-draft.html`, branch `claude/proofer-parity`, PR #56),
2026-10-25. Addressed to whoever is working on `imposition-tool.html` /
`pps-imposition.php` (branch `claude/imposition-tool-google-drive-185wyl`, PR #39).

**The owner's ruling:** the imposition tool is the more developed program, and in any
conflict or compatibility question **it decides**. The proofer has been changed to fit
you; nothing below asks you to change your design. Where something here contradicts
it, your design wins: say so, and the proofer changes again.

`docs/PROOFER_IMPOSITION_SYNC.md` has the full analysis and the evidence.
`tools-proof-imposition-sync-test.mjs` runs both programs' real functions on the same
files. It failed every promise against the old proofer and passes all 26 checks
against your v1.61 (`223cc0f`).

## Where the proofer sits

The saddle calculator opens the proofer in a same-origin iframe. Production is not
switched on yet: PPS Config → Production → New Proof URL is blank. The customer
approves the proof there. The calculator then uploads the package with the order,
and `pps-gdrive.php` files it in the order's Drive folder, where your queue finds it.
Only saddle uses the proofer today. The other seven calculators use the built-in
modal, whose manifest your `parseManifest` already reads.

## What the proofer now hands you

**File names: unchanged.** `<base>_print-ready.pdf`, `<base>_preview_page_NNN.jpg`,
`<base>_manipulation_manifest.txt`, the customer's originals, and slot files as
`page_NNN_<name>`. `<base>` is the customer's file name without its extension. Your
`pps_impose_folder_files` role regexes match all of them. `_pps_proof_hash` is the
SHA-256 of `_print-ready.pdf`, so your `approvalState` check holds.

**The print file is one of two things.**

1. **The customer's own PDF, byte for byte ("untouched")**, only when your `loadPdf`
   and `imposePdf` would take those bytes with no override and no advice to rasterise.
   It is never untouched if any of these apply:
   - it is encrypted;
   - a compressed content stream will not decode (your `repairPageContents` refusal);
   - a named-only font is not one of your `STD14` (your font gate);
   - it holds JPEG 2000, JBIG2 or 16-bit images;
   - it has transfer functions, which you remove and pdf.js applies;
   - a soft mask carries a TR or a non-black backdrop.

   Hidden layers, annotations, links and form fields **no longer** force a render,
   because you handle them: the OC carry with `/Usage /Print OFF`, and annotation
   appearances drawn in by `pressSafety` §6. The page must still be exactly the bleed
   sheet, every page from the one file, in order, unchanged by the customer.
2. **Otherwise, a 300 DPI render**: one JPEG per page at the bleed sheet, rendered by
   pdf.js from the source, so it is what the customer saw. **Every page now carries a
   TrimBox (the trim) and a BleedBox (the sheet).** Before this, your `detectTrim`
   read a 4.25″ × 5.5″ book's 4.5″ × 5.75″ sheet as the trim ("exact media size").

**The manifest speaks your format.** The top of `MANIFEST.txt` now carries the
calculator modal's own labels. Checked: your `parseManifest` returns calc `saddle`,
width × height and `jobName`.

```
── Booklet Specifications ──
Filename: <customer's file>
Trim size: 5.5″ × 8.5″
Bleed-inclusive size: 5.75″ × 8.75″
Bleed margin: 0.125″ per side
Binding: Saddle Stitch
Page count (with padding): 16
Inside printing: Full color
Cover printing: Full color
PPS-JOB {"v":1,…}
```

**`PPS-JOB` is one line of JSON** carrying what the modal format has no words for:

```
{ v:1, calc, trim:{w,h}, bleed, safety, pages, insideColor:'color'|'bw', coverColor,
  printFile:{ name:'PRINT_READY.pdf', sha256, untouched:bool, why:string|null, trimBox:bool },
  blankPages:{ added:[n…], noArt:[n…] },   // added = blanks the customer chose to make up a short file
  notPrinted:[n…],                         // file pages beyond the order, dropped
  perPage:[{ n, src:'pdf'|'image'|'blank', dpi? }],   // dpi only for images: their real pixels per inch
  preflight:{ errors, warns, acknowledged } }
```

**The proofer's preflight now reads files your way, and grades them by your
severities.** Five of your functions are copied verbatim into a marked block in
`proof-ui-draft.html` (`/* pps-imposition-copy:begin */` … `end`): `asDict`,
`pageResources`, `getPageBox`, `STD14` and `inspectFonts`.

`pressFacts()` is a read-only mirror of `repairPageContents` and of `pressSafety`'s
walk: spots, images, transfer functions, mask risk, OpenType wrappers, and which
annotations §6 would draw. What the customer is told now matches what you do:

- a named-only font that is not standard-14: **fail**;
- a standard-14 face: **pass**, with a note;
- a drawn annotation: "prints exactly as shown in this proof";
- a link, or a comment with no appearance: nothing;
- an uploaded image under **200 DPI** (your `rasterNote` figure): warn.

## Six asks, most important first

1. **Put art status on every queue row. An order the customer did NOT approve
   currently imposes as if it were fine.**

   When a customer takes the escape hatch, the server stores `PPS-Prepress-Review`
   and **drops `_pps_proof_hash`**. Your rows carry only `proof_hash`, so the empty
   hash reads as `unbound`, the state of a pre-hash order. The job then gets no
   banner, a clean filename, and is included in Impose all. Staff-proof and
   hardcopy-proof orders still awaiting approval read the same way.

   The rule already exists inline in `pps_job_ticket()` (`pps-calculators.php`, the
   "Art status" block). It reads `PPS-Prepress-Review`, then `proof` > 0 (≥ 3 means
   hardcopy), then the `artwork` option, then the hash. Suggested change:

   - Lift it into `pps_art_status()`, returning a code and words, so the ticket and
     your rows cannot disagree.
   - Add `art_status` to the rows.
   - For NOT APPROVED: show a banner, add an `_NOTAPPROVED` suffix to the output
     name, and leave it out of Impose all unless ticked.
   - Make **both row builders** call it: `pps_impose_list`, and `pps_mpi_rows` in
     `pps-missive-impose.php`. That file reached git at `d4ced0f` on three other
     branches, but not yours.
2. **Resolve an item's files by `_pps_drive_ids` first.** `pps-gdrive.php` records,
   per item, a JSON map of listed name → Drive file id. Today two jobs in one order
   whose customers both uploaded `booklet.pdf` both list `booklet_print-ready.pdf`,
   and `pps_impose_item_artwork` gives both items whichever is listed last.
3. **Read `PPS-JOB` when it is there.**
   - `blankPages` lets the ink guard stop flagging pages meant to be blank.
   - `insideColor` / `coverColor` can pre-fill `greyPages`, or at least show. Today
     the "press prints it greyscale" instruction reaches nothing downstream.
   - `printFile.untouched` / `why` say whether you are holding vectors or a render.
   - `perPage[].dpi` is the image pages' real resolution. Your raster check cannot
     see it inside a PDF.

   In wp-admin the manifest is listed in `_pps_artwork_files`. Note that
   `pps_impose_folder_files` skips `.txt` files, so fetching it needs a role or path
   of its own.
4. **When an order spec exists, let `detectTrim` accept "page = ordered trim + 2 ×
   bleed" before the roundness heuristic.** The proofer's renders now carry a
   TrimBox, but a customer's own untouched file often has none. Quarter-inch books
   (4.25 × 5.5, 5.25 × 8.25) then raise a false "artwork measures … verify". Also
   prefer the spec over 1.53's reading: a genuine custom 5.75 × 8.75 TrimBox is read
   as 5.5 × 8.5 plus bleed.
5. **Report a hairline only when a stroke follows the `0 w`.** pdf-lib's
   `drawRectangle` writes `0 w` even with no border. Your note said "2 zero-width
   hairlines set to 0.25 pt" on our fixture, which strokes nothing. The rewrite is
   harmless; the note isn't true.
6. **Keep the copied block in step, in the same change.**
   - If you change `asDict`, `pageResources`, `getPageBox`, `STD14` or
     `inspectFonts`, update the marked block in `proof-ui-draft.html`, or tell us.
   - If you change the rules `pressFacts()` mirrors, the same applies. That means
     `pressSafety`'s spot, image, TR, SMask or §6 annotation rules, and
     `repairPageContents`' refusal.
   - **If you add a refusal, or a "Rasterise pages" advisory**, add it to the
     untouched list in `untouchedOriginal()`. That list is the promise that an
     approved file imposes as approved.

   The sync test's P checks fail when a copied function drifts.

## Checking it

```bash
git show origin/claude/imposition-tool-google-drive-185wyl:imposition-tool.html > /tmp/imp/imposition-tool.html
# from a checkout of claude/proofer-parity, with the proofer harness's vendor dir:
PPS_IMPOSE_HTML=/tmp/imp/imposition-tool.html PPS_PROOF_VENDOR_DIR=… PPS_DEPS_DIR=… \
  node tools-proof-imposition-sync-test.mjs
```

The fixtures:

- a named-only font and a PANTONE inside a Form XObject;
- a standard-14 font with a stamp and a link;
- a link alone;
- an owner-password-encrypted file, hand-built because the sandbox has no encrypter;
- a 4.25″ × 5.5″ image job.

The test also imposes the proofer's rendered print files, to prove they go through
without an override.
