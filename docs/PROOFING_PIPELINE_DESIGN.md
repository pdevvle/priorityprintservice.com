# Print-compatible proofing: design and two briefs

Status: design, 2026-11-21. Owner decisions recorded in §6. Written after
order 88056 (BOP2026 Schedule Booklet): the press received 300 DPI RGB
JPEG pages because the proof modal's print-ready file is built with
canvas + jsPDF; the source was a vector CMYK InDesign export.

Two systems share this design and are worked by two sessions:

- **Brief A — the imposition tool** (`imposition-tool.html`,
  `pps-imposition.php`, the production-only Missive imposition panel).
  Owned by the imposition session.
- **Brief B — the proofer** (the calculators' proof modal and approval
  package in `calc-*.html`, the approval-hash writer, a new server-side
  soft-proof renderer). Owned by the proofer session.

§3 is the contract between them. Neither brief may change a §3 item
without the other session agreeing in the same commit series.

---

## 1. The invariant

> There is one print file per order item. It is built once, from the
> customer's original, as vectors, with the customer's edits baked in.
> Nothing downstream re-renders it. Every picture anyone sees — customer or
> staff — is a rendering of that file through the press profile.

What this buys: the page content the designer exported (live type, CMYK
builds, spot colours, transparency) reaches the Fiery unchanged, and the
screen shows a calibrated conversion of exactly those bytes. The only thing
the design cannot make identical is a monitor and a sheet of paper.

What breaks it, and how each break is handled:

| Break | Where | Handling |
|---|---|---|
| Rasterising pages into RGB JPEG at proof time | calculators (today) | **removed** — Brief B |
| Press-safety edits after approval (overprint, hairlines, annots, alpha masks) | imposition tool (today) | **moved before the proof** — Brief B builds the normalised file; the tool's passes stay, idempotent |
| Retouch / Rasterise pages / Force greyscale | imposition tool, by owner decision | kept as **flagged prepress exceptions**, moved to **CMYK** rasters — Brief A |
| Bleed synthesis, marks, slug, gutters, creep | imposition tool | outside the cut; not a content change; unchanged |

Not a contract proof. A soft proof on an uncalibrated display is a press
simulation for content, layout and approximate colour. Label it so; offer a
hard proof for colour-critical work. Calibrate prepress's own monitor.

## 2. The pipeline

```
upload ──► raw file (hash R) ──► preflight (gs + pdf inspect) ──► modal edit (pdf.js = cursor only)
                                                                      │
                                                                      ▼
                        print file (pdf-lib, form XObjects, matrix+clip, normalised, OutputIntent) ── hash P
                                                                      │
                     ┌────────────────────────────────────────────────┼─────────────────────────┐
                     ▼                                                ▼                         ▼
        soft proof (gs, press ICC → sRGB,               approval record (P, R, transforms,   Drive: raw, print file,
        150/300 DPI, cached by P+page)                   profile id, preflight, time)         soft-proof PNGs, manifest
                     │
                     ▼
        imposition (verifies P, places form XObjects, K-only marks, RGB diff check) ──► Fiery (same profile)
                     │
                     └── prepress exceptions: Retouch / rasterise in CMYK via gs, flagged RETOUCHED, re-proof policy §6
```

## 3. Contracts between the two systems

### 3.1 Files in the order's Drive folder

| Name | Producer | Meaning |
|---|---|---|
| `<name>.<ext>` (customer's filename) | proofer | **raw upload, byte-identical** to what the customer sent; never re-encoded |
| `<name>_print-ready.pdf` | proofer | **the print file**: vector, normalised, OutputIntent embedded; the only imposition input |
| `<name>_preview_page_NNN.png` | proofer | soft-proof renders (sRGB, 150 DPI) — replaces today's canvas JPEGs; Missive/job ticket show these |
| `<name>_manipulation_manifest.txt` | proofer | manifest, format §3.3 |
| `IMPOSED_Order-<id>-i<item>_….pdf` | imposition | press sheets |
| `RETOUCHED_Order-<id>-i<item>_….pdf` | imposition | a print file with flagged prepress exceptions applied (CMYK rasters) |
| `CLEAN_….pdf` | imposition | sanitized 1:1 copy |

The queue's role tagging (`pps_impose_folder_files()`: print-ready · raw/pdf ·
raster · preview · clean · imposed · retouched) and its preference order are
unchanged: `_print-ready.pdf` is the default artwork; the picker can choose
any file; the match is a default, never a gate.

### 3.2 Order item meta

| Key | Writer | Value |
|---|---|---|
| `_pps_proof_hash` | proofer (approval) | SHA-256 hex of the print file bytes (**P**). Exists today. |
| `_pps_raw_hash` | proofer (approval) | SHA-256 hex of the raw upload (**R**). New. |
| `_pps_proof_profile` | proofer (approval) | press profile id the soft proof used, e.g. `GRACoL2013_CRPC6`. New. |
| `_pps_proof_transforms` | proofer (approval) | JSON, per page: `{fit, rotate, scale, blank}`. New. |
| `_pps_proof_preflight` | proofer (approval) | JSON summary of §B.1 results. New. |

The imposition queue passes `proof_hash` today and will also pass
`raw_hash` and `proof_profile` (Brief A). Hash policy: **advisory, never a
refusal** (owner, 2026-09-02) unless §6 changes it — a mismatch flags
`_UNAPPROVED` everywhere; a file matching **R** instead of **P** is flagged
`RAW` (new, same mechanism) rather than UNAPPROVED.

Where `_pps_proof_hash` is written today: `pps-proof-status.php`, which is
**on staging/production and not in the repo** (9,457 bytes on staging). The
proofer session brings it into the repo before changing it (CLAUDE.md
rule: a server-only file is one deploy from being undone).

### 3.3 Manifest

Plain text, as today, with these lines present and machine-readable
(`Key: value`, one per line, in the header block):

```
Print file: <name>_print-ready.pdf
Print file SHA-256: <P>
Raw file: <customer filename>
Raw file SHA-256: <R>
Build: pdf-lib vector (form XObjects)          ← never "JPEG quality …" again
Press profile: <profile id>
Normalised: overprint, hairlines, annots, alpha masks   ← the passes applied at build
Binding: Saddle Stitch | Perfect Bound | Coupon | Flat
```
plus the existing Booklet Specifications / Per-Page Detail / Preflight
blocks. `parseManifest()` in the tool reads `Binding:` already.

### 3.4 Server render endpoint (shared)

`wp_ajax_pps_proof_render` (nonce, logged-in). Owned by Brief B, used by
both.

| Param | Meaning |
|---|---|
| `file_id` or upload | the PDF to render (Drive id for staff paths; the in-flight print file for the modal) |
| `page` | 1-based |
| `dpi` | 150 (page) or 300 (zoom); server clamps |
| `mode` | `srgb` (soft proof PNG) · `cmyk` (CMYK TIFF/raw for Brief A's raster passes) · `tac` (max C+M+Y+K per page, JSON) |

Implementation: Ghostscript, `-dSAFER -dBATCH -dNOPAUSE -dUseBleedBox
-dTextAlphaBits=4 -dGraphicsAlphaBits=4 -dOverprint=/simulate`,
`-sDefaultCMYKProfile=<press.icc>`; for `srgb`: `-sDEVICE=png16m
-sOutputICCProfile=sRGB -dRenderIntent=1 -dBlackPtComp=1`; for `cmyk`:
`-sDEVICE=tiff32nc -sOutputICCProfile=<press.icc>`. Cache under uploads by
`sha256(file)+page+dpi+mode`, swept by the existing retention policy.
Config: `wp_options['pps_press_profile']` (path + id) and
`wp_options['pps_tac_limit']` (default 300).

### 3.5 Print-file build rules (so the tool can rely on them)

- Pages are **form XObjects of the original pages** (pdf-lib `embedPage`
  with an explicit box, as the tool's `embedPageBox()` does), drawn with the
  customer's transform as the matrix and a rectangular clip at the bleed
  box. No rendering, no re-encoding.
- Rasters (JPEG/PNG uploads) are embedded **as the uploaded bytes**; a CMYK
  JPEG stays CMYK (the tool's `uprightJpeg` rule: re-encode only to fix EXIF
  orientation, and then only RGB).
- Page size = trim + 2×bleed; TrimBox and BleedBox set; `/Rotate` 0.
- Blank pages are empty pages of the same size; signature padding goes
  **before the last page** for bound products (back cover stays last).
- `/OutputIntent` (GTS_PDFX subtype, the press profile) embedded.
- Press-safety normalisation applied at build (the tool's `pressSafety()`,
  `convertAlphaMasks()`, `flattenVectorMasks()`, `repairPageContents()`,
  `ensurePageContents()` — port or share them; see Brief B §B.3).
- Deterministic: fixed `/CreationDate`/`/ModDate`, no random `/ID` — the
  same inputs give the same **P**.

---

## 4. Brief A — the imposition tool (this session)

Goal: consume the print file without altering page content; make every
remaining content change CMYK, flagged and auditable; catch the old
flattened files until they drain out.

### A.1 Guardrails (warn, never refuse)
1. **Flattened-proof detection** at load: Producer/Creator `jsPDF`, or
   every page a single full-page image XObject (`/Subtype /Image` filling
   the MediaBox, DeviceRGB/ICCBased-RGB). Warning names it ("this is a
   rasterised proof, not the customer's file"), the queue offers the raw
   upload in the picker with one click, and the output filename carries
   `_FLATPROOF`. Not a refusal: old orders in flight, raster uploads,
   Retouch output and the owner's rasterise paths are all legitimately
   single-image pages.
2. **RGB diff post-check**: count DeviceRGB / ICC-RGB image XObjects and
   `rg`/`RG`/`sc`/`scn`-with-RGB operators across source page content
   streams (recursing into form XObjects) and across the output; the
   output may add **none** (marks and slug are K-only since 1.34). A new
   RGB appearance is a red warning in the report and `_RGB` on the
   filename.
3. **Two-hash verification**: `approvalState()` compares against **P** and
   **R**; `verified` (P) · `raw` (R: flagged `RAW`, not UNAPPROVED) ·
   `mismatch` · `unbound` · `unverifiable`. Queue rows carry
   `raw_hash`, `proof_profile`.
4. **OutputIntent carried** onto the imposed output when the source has
   one; the tool's `loadPdf()` must not drop it (check: pdf-lib copies the
   catalog entry? — verify, as with `/OCProperties`).

### A.2 CMYK prepress exceptions
Retouch, Rasterise pages, Force greyscale switch from the browser canvas
(RGB only) to the shared render endpoint in `cmyk` mode: Ghostscript
renders the page through the press profile to a 4-channel raster, the
healing (`inpaintFMM` + `healTexture`, channel-count agnostic) runs on
C,M,Y,K, and the page is re-embedded as a **DeviceCMYK** image (Flate or
DCT). Greyscale becomes K-only from the K plate (or a proper CMYK→K
conversion through the profile, not luminance). The Retouch modal shows an
sRGB conversion of that CMYK raster for the operator; strokes stay in
points of the raw page frame, so nothing about the modal's geometry
changes. Standalone (no server) falls back to today's RGB path with a
louder warning. Flags unchanged: `_RETOUCHED`, slug, badges, order note.

### A.3 Re-proof hook
After a retouched file is filed to Drive, call the render endpoint for the
affected pages so the folder's `_preview_page_NNN.png` for those pages show
the retouched result, and write an order note. Whether the customer must
re-approve is §6.

### A.4 Hygiene
- Bring **`pps-missive-impose.php`** (production only, 17,122 bytes) into
  the repo first; then give it the same picker / raw-file offer and the
  flattened-proof warning, since it is the path that imposed 88056.
- The tool's press-safety passes remain and become no-ops on files the
  proofer already normalised (they are idempotent; verify byte-identity on
  a normalised fixture).

### A.5 Acceptance (order 88056's files)
- Imposing the raw `BOP2026-Schedule-Booklet_3.5x5.5in_PRESS.pdf`: zero
  DeviceRGB images in the output; CMYK operator counts equal the source
  plus marks/slug; DIN 2014 text extractable from the sheets; no hairline
  at the centre-spread spine at 600 DPI.
- Imposing today's `_print-ready.pdf`: `_FLATPROOF` flag, warning names
  jsPDF, picker offers the raw file.
- A one-byte change to the print file after approval → `mismatch`,
  `_UNAPPROVED`; the raw file → `RAW`.
- Retouch on a CMYK fixture: output page is a DeviceCMYK image; the navy's
  C,M,Y,K at a healed spot equal the surrounding build within JPEG
  tolerance; no RGB anywhere in the output.

### A.6 Order of work
1. A.1 (1–3) + A.4 Missive panel import — one release, no server work.
2. A.2 + A.3 once the render endpoint (B.4) exists on staging.
3. A.1 (4) with the proofer's OutputIntent landing.

---

## 5. Brief B — the proofer (other session)

Goal: the proof modal builds a vector, normalised print file; the customer
approves a soft proof rendered from that file; the approval record binds to
it.

### B.1 Intake and preflight
On upload, hash the raw file (**R**) and run preflight server-side; show the
results in the modal in plain language before any editing:
- page count vs product; page size vs trim/bleed (TrimBox/BleedBox/MediaBox,
  the three-try cascade in `docs/PPS_METADATA_REFERENCE.md`);
- fonts not embedded (refuse to proceed, as the tool's font gate does);
- RGB or ICC-RGB content (warn: "will shift when printed — the preview
  shows the press result"); spot colours named;
- raster effective resolution below 200 DPI at placed size;
- total area coverage from the `tac` render mode vs `pps_tac_limit`.

### B.2 Editing
Keep the modal's interactive view on pdf.js; it is only a cursor. Store
transforms per page as data: `fit ∈ crop|fill|fit`, `rotate ∈ 0|90|180|270`,
`scale` percent, `blank`. These are the **same semantics** the imposition
tool uses for `artFit`/`artRotate`/`artScale`; do not invent new ones.

### B.3 Build the print file (replaces canvas + jsPDF)
Where: the approval-package builder in `calc-preview-test.html` (the
`generatingPdf` / `new jsPDF` block near line 3150 and its skip rule at
3112–3130), then the other calculators. Rules in §3.5. Vendor pdf-lib
1.17.1 into the calculators as the tool does (`imposition-vendor/`).
Port the normalisation passes from the tool (`pressSafety`,
`convertAlphaMasks`, `flattenVectorMasks`, `repairPageContents`,
`ensurePageContents`) — ideally by moving them into a shared file both
load, otherwise copy with a "keep in sync" header in both. The current
skip rule ("raw is already print-ready when no transforms") becomes
redundant: the general path is lossless; drop it rather than keep two
paths.

### B.4 Soft proof
Implement §3.4. In the modal: show the soft proof as the proof, with trim /
bleed / safe guides as SVG overlays; label "Press simulation —
<profile>"; a "screen colours" toggle shows the pdf.js view labelled "not
what prints". **Approve is enabled only when every page's render exists
for the current P.** While rendering, the pdf.js view may show greyed as
"draft". If the server cannot render, approval blocks with a message (or
§6 decides an explicit "approve on screen colours" path).

### B.5 Approval record and deliverables
On approval write the five meta keys in §3.2 (`pps-proof-status.php`, after
bringing it into the repo), upload the §3.1 files with the §3.3 manifest.
Previews are the soft-proof PNGs. The raw upload is kept forever beside
the print file.

### B.6 Acceptance (order 88056's raw file)
- Built print file: 8 pages, 3.75×5.75, zero images added, DIN 2014
  embedded and extractable, CMYK operator counts equal the source, no
  jsPDF producer, OutputIntent present; **P** stable across two builds.
- The imposition tool imposes it with no `_FLATPROOF` / `_RGB` flag and
  `verified` approval state.
- Soft proof of page 1: the navy renders as the press profile's
  conversion of 99/89/39/42, visibly different from pdf.js's render; the
  orange (0/83/100/0) likewise; an RGB test image shows gamut clipping.
- Approve is disabled until renders exist; a transform change invalidates
  renders and re-disables it.

### B.7 Infra to confirm first
- Ghostscript on the Cloudways host (`exec('gs --version')` from PHP) and
  that `exec` is permitted; if absent, the fallback is Ghostscript WASM in
  the browser (large download) or a render microservice — decide with the
  owner before building B.4.
- The press profile file, provided by the owner, identical to the one the
  Fiery is configured with (§6).

---

## 6. Owner decisions (open unless marked)

| # | Decision | Default if not decided |
|---|---|---|
| 1 | Press profile (must equal the Fiery's simulation/output profile) | blocks B.4 and A.2 |
| 2 | Hash policy: advisory (current, 2026-09-02) or refuse on mismatch | advisory |
| 3 | Retouched page: internal correction, or re-proof + customer re-approval | internal correction, flagged |
| 4 | Offer a hard proof for colour-critical jobs; label the soft proof a simulation | yes |
| 5 | If Ghostscript is unavailable on the host: WASM in browser vs microservice | decide when known |
| 6 | TAC limit | 300 % |

## 7. What is explicitly out
- jsPDF and canvas JPEGs anywhere in the print path.
- pdf.js as a source of print data.
- Python / Pillow on the server (Ghostscript alone does the colour work).
- Treating the soft proof as a contract proof.
