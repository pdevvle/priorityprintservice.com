# Proofer ↔ imposition tool: keeping file handling and preflight in sync

Analysis of 2026-10-25. Proofer: `proof-ui-draft.html` at `945cc78` (branch
`claude/proofer-parity`, PR #56). Imposition tool: `imposition-tool.html` +
`pps-imposition.php` v1.61 at `223cc0f` (branch
`claude/imposition-tool-google-drive-185wyl`, PR #39, draft, built by another
Claude session). Every claim marked **measured** comes from
`tools-proof-imposition-sync-test.mjs`, which drives both tools' real functions
on the same files. Claims from reading the code say so.

## Where the imposition work lives

- **PR #39**, draft, open since 2026-07-15, base `pps-pricing-config`. Last commit
  2026-10-20 (v1.61: orientation beside the size; a priced count that does not
  fit is placed, never refused). The session is linked from the PR body.
- **Production runs v1.58** (2026-10-18, `38d9be9`). v1.59–1.61 are on Pages only.
- **A second host exists**: `pps-missive-impose.php` serves the same tool inside
  Missive and builds its own queue rows (`pps_mpi_rows`). It reached git at
  `d4ced0f` on three other branches, not on PR #39's branch.

## How a file gets from the proofer to the press today

```
proofer (iframe)                 calculator host                  Drive order folder            imposition queue
PRINT_READY.pdf  ──────────────▶ <base>_print-ready.pdf ───────▶ role "print-ready" (preferred)
PREVIEW_pNN.jpg  ──────────────▶ <base>_preview_page_NNN.jpg ──▶ role "preview" (never defaulted)
MANIFEST.txt     ──────────────▶ <base>_manipulation_manifest.txt   (not read in wp-admin)
printHash        ──────────────▶ _pps_proof_hash (item meta) ──▶ hashed against the download
```

`<base>` is the customer's file name without its extension
(`calc-preview-test.html`, `ppsProofFilesToOrder`). The queue matches each line
item's files by **name** against `_pps_artwork_files`
(`pps_impose_item_artwork`) and prefers `*_print-ready.pdf`.

## What already agrees — keep it that way

| | Proofer | Imposition tool |
|---|---|---|
| Libraries | pdf.js 3.11.174, pdf-lib 1.17.1 | the same two, vendored with pinned SHA-256s |
| File names → roles | `ppsProofFilesToOrder` | `pps_impose_folder_files` regexes match every name the proofer emits |
| Approval binding | SHA-256 of `PRINT_READY.pdf`, untouched or rendered | SHA-256 of the Drive download vs `_pps_proof_hash`; a mismatch is advisory and stamps `_UNAPPROVED` |
| An image with no stated size | taken at 300 DPI (`loadArt`) | wrapped at 300 DPI (`PPS_RASTER_DPI`) |
| Hidden layers | warned; the file is never shipped untouched | the layer settings are carried, so OFF stays off |
| EXIF orientation | the browser uprights it | uprighted explicitly (`uprightJpeg`) |

The shared libraries matter most: one PDF reader can serve both tools without a
version fight. That is not true of the calculators, which load pdf.js 4.x.

## Where they disagree

### Measured (`tools-proof-imposition-sync-test.mjs`: 8 checks, 8 fail today)

| # | The same file | Proofer | Imposition tool | What it costs |
|---|---|---|---|---|
| 1 | Bleed-exact 4-page PDF, a named-only font inside a Form XObject | **ships it untouched**; the approval is bound to these bytes | **refuses** these exact bytes ("FONTS NOT EMBEDDED: ArialMT") | The approved file cannot be imposed without an override, and the output then carries `_FONTS`. The customer approved pdf.js's stand-in font; the press would use Fiery's. |
| 2 | That font | does not see it: it reads fonts from page resources only, never inside Form XObjects, which is where InDesign and Illustrator put placed artwork | refuses it (it walks forms) | The customer is never told about the font that stops the job. |
| 2b | Helvetica, named only | **fail** | a note: every RIP has the standard 14 | A failure on a harmless file feeds the acknowledgment gate. People learn to tick past it, the problem the size check had in September. |
| 3 | PANTONE 185 C inside a Form XObject | "No spot colors" | names it | Same blind spot as 2. |
| 4 | A stamp annotation | "comments, form boxes and links **do not print**" | draws it into the page (since 1.54) | Untrue twice: the proofer's **own** 300 DPI print file shows the stamp (100 % dark), because pdf.js draws annotation appearances by default. |
| 5 | A plain file with one hyperlink | refuses to ship it untouched ("annotations would not survive downstream") and renders every page to 300 DPI JPEG | ignores links | A Canva or InDesign export with a URL loses its vector type and fonts for nothing. That reason was written before 1.54. |
| 6 | The proofer's `MANIFEST.txt` | writes `trim 5.5" x 8.5"`, `bind saddle` | `parseManifest` expects the modal's `Trim size:` / `Binding:` → `{}` | Dropping the manifest beside the file autofills nothing. |
| 7 | A rendered print file for a 4.25″ × 5.5″ book | the page is the bleed sheet; no TrimBox | `detectTrim` → **4.5″ × 5.75″** ("exact media size"); right for 5.5 × 8.5, wrong for quarter-inch trims | A false "artwork measures 4.5×5.75 but the order is 4.25×5.5 — verify" on every such order. In standalone use, the wrong trim is filled in. |

### From reading the code (not in the test)

- **An order the customer did NOT approve imposes as if it were fine.** Taking
  the proofer's escape hatch posts `pps_prepress_review`. The server stores
  `PPS-Prepress-Review`, writes PREPRESS-REVIEW into PPS-Spec, and **drops the
  proof hash** so the job cannot look approved. But the queue rows
  (`pps_impose_list` and `pps_mpi_rows`) carry only `proof_hash`. An empty hash
  reads as `unbound`, the state of a pre-2026 order: no banner, a clean output
  name, and **Impose all** includes it. The same goes for staff-proof and
  hardcopy-proof orders still waiting for a proof. **This is the most serious
  gap.**
- **Two jobs in one order with the same file name** both upload
  `<base>_print-ready.pdf`. `pps_impose_item_artwork` matches by name, so both
  items resolve to whichever is listed last. `_pps_drive_ids` (name → Drive file
  id, per item) was added on 2026-09-27 for exactly this, and the queue does not
  read it yet.
- **Encrypted files (owner password).** pdf.js opens and renders them, so the
  proofer shows and approves them and can ship them untouched. The imposition tool
  refuses them at load, because pdf-lib copies their content still encrypted. The
  proofer has no `isEncrypted` check. (Not measured: no PDF encrypter in the sandbox.)
- **Three resolution thresholds.** The proofer warns below 120 DPI (bad below 90),
  the imposition tool below 200 (raw JPEG/PNG only), Job Health below 280. Job
  Health measures the print file's container, which is always 300 for a rendered
  proof, so it never sees the art. For a proofer job, the only true per-page
  effective DPI is in the manifest, which nothing downstream reads.
- **Greyscale.** The manifest says "PRINT_READY.pdf keeps its colour and the
  press prints it greyscale". The order's `insideColor` never reaches the queue,
  and the tool's own greyscale control (`greyPages`) is manual. Nothing between the
  proof and the press carries that instruction.
- **Hairline note noise.** pdf-lib's `drawRectangle` writes `0 w` even with no
  border, so the imposition tool reports "2 zero-width hairlines set to 0.25 pt"
  on a file with no stroke at all (measured on the fixture). The rewrite is
  harmless; the note is not true.

## Recommendation

**The principle: the proof is the reference.** pdf.js's rendering is what the
customer approved. The proofer must tell the truth about what will print. The
imposition tool's repairs (overprint, masks, annotations, hairlines) should move
the press toward what pdf.js showed, never away from it. One rule follows from
both: **the proofer may ship a file untouched only if the imposition tool will
impose those exact bytes without an override.**

### 1. One reader of PDF facts, in both tools

The imposition tool's walkers are the thorough ones. `pressSafety`'s visitor
reaches every indirect object, `inspectFonts` follows Form XObjects, and
`inspectPdfThreats` and `getPageBox` handle inherited boxes. Move them, as they
are, into one vanilla-JS file, `pps-pdf-facts.js`, which reads facts and
changes nothing:

```
ppsPdfFacts(doc) → {
  encrypted, pages:[{ media, crop, trim (declared only), bleed, rotate, userUnit }],
  fonts:{ missing:[…], std14:[…], openType }, spots:[…],
  layers:{ any, off:[names] | null }, annots:{ drawn, links, widgets },
  active:[…], overprint, transfer, halftone, maskRisk, images:{ jpx, jbig2, deep }, hairlines
}
```

Each tool keeps its own words: the customer and the operator need different
sentences. Both read the same facts and reach the same state (pass, warn, fail,
unverified, refuse).

**Inline it, don't load it.** The proofer deploys through `pps-html-deploy.php`
(an explicit HTML list). The imposition tool deploys from the plugin directory
with a vendored runtime. A shared script would need a third deploy path and a
version handshake. Instead, a small stamp script copies the file between
`/* pps-pdf-facts:begin */` and `/* :end */` markers in both HTML files, and a
test fails when the two copies' hashes differ. That is how this repo already keeps
copies honest (the function-hash sweep in CLAUDE.md, "the calculators are copies").

### 2. A written hand-off contract, tested on both sides

1. **Names.** Already right. Pin them: `__ppsProof.mapFiles` on one side,
   `pps_impose_folder_files` on the other.
2. **Item resolution.** `pps_impose_item_artwork` reads `_pps_drive_ids` first,
   then names.
3. **Manifest.** `MANIFEST.txt` gains one line, `PPS-JOB {json}`, carrying calc,
   width × height (spine edge), bleed, pages, colour per section, intended blank
   pages, print hash, untouched yes/no, and per-page effective DPI.
   `parseManifest` reads that line first and the modal's text second. The wp-admin
   loader fetches the item's manifest (it is in `_pps_artwork_files`), so the ink
   guard can stop flagging pages that were meant to be blank, greyscale can be
   pre-filled, and per-page DPI can be shown.
4. **Art status on every queue row.** Build it on the server, with the rule the
   Job Ticket already uses: self-approved (hash), NOT APPROVED (prepress review),
   awaiting staff proof, awaiting hardcopy proof, no art yet, or legacy. Both row
   builders call the same function. NOT APPROVED gets a banner and an
   `_NOTAPPROVED` file suffix, and **Impose all** skips it unless ticked.
5. **The print file states its trim.** The proofer writes a TrimBox and BleedBox
   on every rendered page (two lines), so `detectTrim` never has to guess.

### 3. One gate

`tools-proof-imposition-sync-test.mjs` grows into a fixture corpus. Add these
fixtures: owner-password encrypted, a hidden layer, a widget (form field) with an
appearance, JPEG 2000, a TrimBox at the bleed size (1.53's case), reader spreads,
a CropBox smaller than the MediaBox, and an inherited CropBox. For every fixture
the test asserts:

- untouched implies imposable without an override;
- every refusal the imposition tool makes, the proofer has named;
- neither tool says something prints that the other drops, or the reverse.

## Build order

**Phase 1: the proofer (this branch, no waiting on anyone).**

- Adopt `pps-pdf-facts.js`, extracted verbatim from v1.61. This fixes 2, 2b and 3,
  and the inherited-box cases.
- Change `untouchedOriginal`: no for missing non-standard fonts or encryption; yes
  despite annotations and links. This fixes 1 and 5.
- Annotation wording: they print as shown; links are not mentioned. This fixes 4.
- Write a TrimBox and BleedBox on rendered pages (fixes 7), and add the `PPS-JOB`
  manifest line (half of 6).

Expected result: checks 1–5 and 7 turn green.

**Phase 2: the imposition tool (the other session).**

- Art status on rows, built by one function shared with `pps-missive-impose.php`
  (bring that file onto PR #39's branch).
- `_pps_drive_ids` first.
- Read `PPS-JOB` (the other half of 6) and fetch the manifest in wp-admin.
- Report a hairline only when a stroke follows.
- Replace its inline walkers with the stamped `pps-pdf-facts.js` block.

**Phase 3: one number.** Resolution thresholds go into the shared file. The
numbers themselves are the owner's call (today they are 90/120, 200 and 280).
Until then, the imposition tool shows the proofer's per-page figure from the
manifest.

Scope note: only the saddle calculator opens the proofer today. The other seven
use the modal, whose manifest `parseManifest` already reads. The `PPS-JOB` line
belongs in the modal's manifest too once it exists, so the queue has one format to
read.
