# The Proofer — handover brief

**Audience:** a Claude session arriving with no prior context on this work.
**Written:** 2026-09-20. Revised the same day after an adversarial review that checked
300 of its claims against the code; where a claim could not be verified from the
repository it is marked as such.
**Status:** the new proofer is built, tested and deployed to both servers, and — as far as
can be told without reading a credential-bearing option (see §7.3) — switched off on both.
Seven of eight calculators still use the old modal.

Read this before touching `proof-ui-draft.html`, the proof modal inside any
`calc-*.html`, or anything named `tools-proof-*` or `tools-parity-*`.

**All line numbers refer to JSX SOURCE on the integration branch** (worktree `src-wt`,
branch `pps-fixes` / `claude/optimistic-wozniak-11ql3y`). The publish mirror
(`/home/user/priorityprintservice.com`, branch `claude/woocommerce-domain-search-ly4vff`)
carries the same calculators **compiled**, where the whole app is one ~200 KB line. Grep
for the symbol there; the line numbers below will land you in unrelated code.

---

## 0. Why the proofer exists at all

A print shop's single most expensive failure is printing the wrong thing at volume and
finding out from the customer. Proofing is the one gate between a customer's file and a
press. Everything below serves one property:

> **What the customer approved is what gets printed, and they had a fair chance to see
> what was wrong with it.**

Both halves matter. A proof that is faithful but unreadable fails. A proof that is
beautiful but not bound to the printed bytes fails. Three real incidents illustrate the
three ways this goes wrong — §9.

---

## 1. Where everything lives

**All of it is in this repo — `pdevvle/priorityprintservice.com`.** Splitting the proofer
out was considered and rejected. The coupling is real and load-bearing:

- `pps-html-deploy.php` accepts `proof-ui-draft.html` by explicit allowlist (`:120-128`).
- `tools-proof-ui-style-test.mjs` **reads the colour palette out of
  `calc-preview-test.html`** rather than copying it, so the two documents cannot drift.
- The host seam (`ppsOpenProof`, `ppsProofFilesToOrder`) lives inside the calculator.
- `tools-proof-integration-test.mjs` drives calculator and proofer together;
  `tools-prepress-flag-test.mjs` reads the proofer, the PHP and all eight compiled
  calculators; `tools-proof-serve.mjs` serves both from one origin;
  `pps-proof-status.php` interprets the proofer's hash server-side.

If it is ever split, those are the migration, not the file move.

### 1.1 File inventory

| File | Role |
|---|---|
| `proof-ui-draft.html` | **The new proofer.** 170,517 bytes, vanilla JS, one `<script>` (lines 874–3172). Not a component: the calculator frames it. |
| `calc-preview-test.html` | Saddle stitch calculator. Holds the **old modal**, the `composePageCanvas()` engine, and the **only** host-seam wiring to the new proofer. |
| `calc-perfect-bound.html`, `calc-coupon-book.html` | The other two bound products. Old modal. Since 2026-08-26 both also carry a **wraparound-cover** surface — one wide sheet, back panel + spine + front, with its own upload slot, template and composition path; the raw-file fast path is disabled when one is present (`calc-perfect-bound.html:2846-2852, 3309`). Still marked DRAFT in code. |
| `calc-brochure.html`, `calc-postcard.html`, `calc-greeting-card.html`, `calc-letterhead.html`, `calc-sticker.html` | The five flats. Old modal. Brochure, postcard and greeting card share the same 10-entry fold-type list and 3D fold renderers; **letterhead is the only fold-free flat**. |
| `calc-4over.html` | A ninth `calc-*.html` in the tree and in `dist/`: an unregistered 2026-08-15 trade-products draft with **no proofing code at all**. Out of scope; mentioned so `ls calc-*.html` returning nine does not surprise you. |
| `pps-calculators.php` | Registers exactly eight calc types (`:4938-4945`, `:5745-5752`). Builds cart lines, stores `_pps_proof_hash`, derives the PPS-Spec proof token (`:3100-3101`), passes `pcf` to the page via `pps_get_public_config()` (`:82-99`). |
| `pps-config-admin.php` | Defines the `proof_url` knob under `pcf` (`:159`, saved with `esc_url_raw` at `:665`, rendered at `:1570/1593`). |
| `pps-proof-status.php` | Makes `SelfApproved` mean *someone signed off in the proofer* (`_pps_proof_hash` present) rather than *did not buy a staff proof*. **On staging's disk only, active on NEITHER site.** Until activated, every order on both sites reads `SelfApproved` whether or not any proof was seen. Gate: `php tools-proof-status-test.php`. |
| `pps-html-deploy.php` | How the proofer reaches a server — §1.4. |
| `imposition-tool.html` / `pps-imposition.php` | Consume `_pps_proof_hash`: refuse on mismatch (`imposition-tool.html:2498-2504`), bulk never overrides (`:2571-2579`), interactive override stamps `_UNAPPROVED`. The check only runs when the order carries a 64-hex hash; otherwise the state is `unbound`, and an `IMPOSED_` filename with no suffix looks identical in both cases. |
| `docs/HANDOFF_2026-08-23.md`, `docs/ART_APPROVAL_GATE_REVIEW.md`, `docs/HANDOFF_2026-08-12.md` | The prior proofing record: order 87032 and proof parity phase 1; the approval gate's origin and orders 87042/87045 that reached production without a package; the 2026-08-12 crash repro notes. |

### 1.2 Test suites

**Eleven** `tools-proof-*-test.mjs` files plus two harness tools. All at repo root. Every
suite is a **headless-Chromium Playwright test**; none runs over `file://` (pdf.js needs
a real origin).

| Suite | Covers | Env |
|---|---|---|
| `tools-proof-ui-draft-test.mjs` | Engine, core behaviour | hard-codes Playwright path and `:8137` |
| `tools-proof-ui-preflight-test.mjs` | PDF preflight findings | hard-codes both |
| `tools-proof-ui-mobile-test.mjs` | Touch layout, rotation | hard-codes both |
| `tools-proof-ui-style-test.mjs` | **Design parity** — reads the palette from the saddle calculator; fails any rule whose `var()` does not resolve | `PPS_PLAYWRIGHT`, `PPS_PROOF_BASE`, `PPS_CALC_FILE` |
| `tools-proof-embed-test.mjs` | The host seam / postMessage contract | `PPS_PLAYWRIGHT`, `PPS_PROOF_BASE` |
| `tools-proof-integration-test.mjs` | Calculator ↔ proofer round trip. **Needs a compiled `dist/calc-preview-test.html`** (exits 2 otherwise) and `PPS_DEPS_DIR` holding react/react-dom UMD + pdfjs-dist 4.10.38 legacy | + `PPS_DEPS_DIR`, `PPS_CALC_PATH` |
| `tools-proof-slots-test.mjs` | Per-page slot uploads | `PPS_PLAYWRIGHT`, `PPS_PROOF_BASE` |
| `tools-proof-progress-test.mjs` | Approve progress readout via MutationObserver | same |
| `tools-proof-blank-pages-test.mjs` | Unsupplied pages on a hosted job | same |
| `tools-proof-size-check-test.mjs` | The ordered-size preflight | same |
| `tools-proof-package-test.mjs` | **DEAD.** Pre-harness prototype: imports `./node_modules/playwright`, reads `ui/vendor/`, opens the file over `file://`, needs an uncommitted `test-art.pdf`. Superseded by the draft + progress suites. Delete or revive; do not count it as green. |
| `tools-proof-serve.mjs` | Static server on `127.0.0.1:8137` + `/vendor/`. Its second log line names `node_modules` regardless of where it found the libraries — trust `curl -sI http://127.0.0.1:8137/vendor/pdf.min.js`. Its header still says "three suites"; stale. |
| `tools-proof-vendor.mjs` | Installs the proofer's pinned pdf.js 3.11.174 / pdf-lib 1.17.1 into `proof-vendor/`. `--check` reports without installing. |

`PPS_PROOF_VENDOR_DIR` is read **only** by the serve and vendor tools. Passing it to a suite
is harmless and does nothing.

### 1.3 Running the suites — concrete

Preconditions this sandbox meets and the brief's original recipe never stated: Node 22;
Playwright at `/opt/node22/lib/node_modules/playwright` (three suites hard-code it, the
rest default to it via `PPS_PLAYWRIGHT`); Chromium at
`/opt/pw-browsers/chromium-1194/chrome-linux/chrome` (hard-coded in eight suites). On any
other machine, fix those first.

```bash
S=/tmp/claude-0/-home-user-priorityprintservice-com/a7cc322e-a48b-50c5-b9b9-c14f307e7bcc/scratchpad
export PPS_PROOF_VENDOR_DIR=$S/proof-vendor
export PPS_DEPS_DIR=$S/ui/node_modules        # symlink to $S/node_modules

# 0. Check the vendored libraries. Without BOTH env vars the vendor tool npm-installs
#    into the repo root and writes proof-vendor/ beside whatever node_modules it used.
node tools-proof-vendor.mjs --check          # all "ok" here; only drop --check if MISSING

# 1. The integration suite serves dist/calc-preview-test.html. dist/ is gitignored.
BABEL_DIR=$S/node_modules node tools-compile-calcs.mjs

# 2. Port 8137 holds one server at a time.
fuser -k 8137/tcp; node tools-proof-serve.mjs &

# 3. The ten live suites.
for t in ui-draft ui-preflight ui-mobile ui-style embed integration slots progress blank-pages size-check; do
  node tools-proof-$t-test.mjs | tail -1
done
```

To look at the proofer standalone: `http://127.0.0.1:8137/proof-ui-draft.html` once the
server is up (demo booklet). It is **not** on GitHub Pages — `pages-public` does not
carry it. On a server it is `/wp-content/uploads/pps-calculators/proof-ui-draft.html`.

**The two pdf.js majors cannot share one `node_modules`.** The proofer pins 3.11.174;
the calculators run 4.10.38. Installing either evicts the other, which is why
`proof-vendor/` is a separate directory. A calculator suite suddenly failing on a
missing pdf.js file is this.

**The calculator suites** (`tools-parity-*`, `tools-slot-upload-*`, `tools-order-*`)
use a different harness: `python3 -m http.server 8137` in `$S/ui`, which holds
`parity-saddle.html`, `parity-pb.html`, `slot-coupon.html`, `theme-main.css` and a
`node_modules` with react/react-dom UMD, jspdf 2.5.1 and pdfjs-dist 4.10.38. Build the
pages with `tools-harness-prep.mjs dist/<calc>.html $S/ui/<name>.html`; the full recipe is
the header of `tools-parity-saddle.mjs`. Those suites write `art-*.png` / `s*.pdf`
fixtures into the cwd — disposable, never commit them.

**Fixtures are built in-page with pdf-lib** (copy `attach()` in
`tools-proof-ui-preflight-test.mjs`). No PDF binaries are committed. `layered.pdf`, which
`tools-parity-layers.mjs` needs, exists in no checkout and no scratchpad, and the
authoring notes its header points to are gone too — regenerate it in the test.

### 1.4 Deploying the proofer

Pull-based, pinned to a commit, same as a calculator:

1. Commit on the integration branch, mirror to the publish branch, push. The raw URL must
   come from a branch that carries the file (`pps-fixes`, `pps-pricing-config`, the
   mirror — **not** `pages-public`).
2. On the target site's MCP: `pps_plugin_download_url` with
   `url = https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/<sha>/proof-ui-draft.html`,
   `relative_path = pps-calculators/_pending_html/proof-ui-draft.html`.
3. Make any request to the site (`mcp_ping` is enough).
4. Verify in `wp_get_option('pps_html_deploy_log_v2')` — an `ok: true` entry with the
   byte count **followed by `page-cache-purged`** — and in
   `pps_uploads_list_files(subdirectory='pps-calculators')`. Not by fetching the page;
   the sandbox proxy blocks it.

Staging first, then the same SHA to production. The file is inert on a server until the
knob (§7.3) points at it.

---

## 2. The old modal proofer — what you are replacing

### How it was made

It grew inside `calc-preview-test.html` alongside the calculator, over months, as
features were needed. It was never designed as a proofing system; it accreted into one.
There is no module boundary, no separate test surface, and the same file handles
pricing, upload, composition, proofing, PDF generation and cart submission.

### How the eight products use it — and they do not use it equally

- **Saddle stitch is the only hardened one.** `composePageCanvas()` is the only place
  fit-mode maths lives (`:3373-3389`); the print-ready PDF, previews, proof modal,
  magnifier, grid and 3D preview all draw from it. Screen and print differ in the `dpi`
  argument — and in `opts.fast`, which screen call sites pass to skip the vector re-render
  and use the extracted JPEG page instead (`:3328`, `:4181`, `:4267`); geometry is
  identical, pixel source is not. The final proof-modal pass (`:4269`) is dpi-only and
  matches print exactly. Two display-only residues still use CSS `object-fit`: the slot
  grid thumbnails (`:4984`) and the 3D preview before `trimmedPages` exists (`:5592`).
  Neither feeds the print file.
- **Perfect bound and coupon book copied the saddle's generator but not its 2026-08-24
  fix.** Until 2026-09-21 they chose the PDF page to re-render as `i + 1` (display index),
  not `page.srcPdfPage`. With an 8-page upload into a 20-page book the back cover sits at
  index 19, `i + 1` points past the PDF, and the back cover was printed from its 108 DPI
  thumbnail; a page shifted by a blank, or replaced by a slot upload, would have printed
  the wrong page. Found by `tools-book-print-dpi-test.mjs` (§9.5), fixed the same day. All
  three booklets also placed the source on a fractional offset, resampling every page.
- **The other seven** show the proof as a CSS-positioned `<img>` (`calc-brochure.html:3544`)
  but build the print file on a **separate canvas path** — `generateApprovalPackage()`
  with `drawGuides()`, a 300 DPI canvas, jsPDF JPEG q0.95, 150 DPI previews and a manifest
  (`:2781-3010`). Same four deliverables as saddle. What they genuinely lack: nothing ties
  screen to print; **no SHA-256 is ever computed** (the `pps_proof_hash` post branch at
  `calc-brochure.html:4646-4648` exists in all seven but `artFiles.proofHash` is never set,
  so it is inert plumbing — do not read its presence as binding); no acknowledgment
  gate; **always rasterise** (`calc-brochure.html:2866-2869`, `isSourcePdf = false`).
- **The five flats printed the screen preview until 2026-09-21.** Brochure, postcard,
  letterhead, greeting card and sticker rendered an uploaded PDF exactly once, at upload,
  with pdf.js at `scale: 2` — 144 DPI, JPEG q0.85 — for the on-screen proof, and
  `generateApprovalPackage()` then decoded *that* JPEG and drew it up onto the 300 DPI
  canvas, on a fractional pixel offset that resampled the whole sheet a second time. The
  manifest said "300 DPI"; the pixels were 144 DPI blown up 2.08×. Perfect bound and
  coupon book, which the flats' generator was copied from, render the page at `DPI / 72`
  and never had it. Order 87171 (§9.5) is the customer who found out. Now each PDF side
  is rendered again from its own bytes at the print DPI inside the generator
  (`ppsPrintSourceCanvas()`), placed on whole pixels, and the manifest names the source
  per side (`print source: pdf page 1 rendered at 300 DPI`).
  `tools-flat-print-dpi-test.mjs` is the gate. This is the same failure shape as the new
  proofer's §7.0 — a print file built from the screen render — on the surface that is
  actually live.

So "the proof system" is really two systems, and every parity claim you inherit applies
to saddle only.

### Approval binding (saddle only)

- Approval is bound to the **SHA-256 of the print-ready bytes** (`:3717`). The manifest
  records it; the order item carries `_pps_proof_hash`; the imposition tool enforces it.
- Flagged preflight checks must be acknowledged before Approve unlocks; the
  acknowledgment is recorded in the manifest.
- **The approval gate applies only to `proof === 0`** (`:6895`). Gating paid proofs once
  blocked uploads on all eight calculators.
- **Every approval-voiding path revokes the parent's `artFiles.approved`** via
  `revokeApproval` / `emitArtwork` (`:3247`): transforms, spec changes, the Review
  button, slot changes, artwork-option switch, mid-generation edits.
- **`pps_proof_hash` posts only when `proofHashOf` names a deliverable that actually
  uploaded** (`:5987-5992`). Never hash a file Drive did not receive.
- **The raw-file fast path** ships the customer's own bytes as the print file when
  `rawIsExactSet` (`:3641-3650`): every page `crop/0°/100%` AND the upload is a PDF AND
  art within **0.25″** of the bleed box on each axis (`:3627-3631`, strict `<`) AND no
  reconciliation AND no slot files AND no wraparound cover. `skippedGeneration` reports the
  branch actually taken (`:3846`). Miss any conjunct and the whole document rasterises.
  This is the only lossless path in the codebase — see §7.0.

### The proof options

Sentinels, not prices (`PCF.proof_digital_cost`, `PCF.proof_hardcopy_cost`):

| Value | Meaning |
|---|---|
| `0` | Proof & Approve Online (included) — or "No Proof Needed" depending on artwork option |
| `0.01` | Manual Digital Proof — staff-approved |
| `3.01` | Hardcopy Proof — physically mailed, staff-approved |

`proof >= 3` opens a same/different address panel. See §7.5 for where that answer goes.

The PPS-Spec proof token is derived from the **purchase**, not from any sign-off
(`pps-calculators.php:3100-3101`): `PREPRESS-REVIEW` if the flag is set, else `Hardcopy`
/ `DigitalProof` / `SelfApproved`. `pps-proof-status.php` (§1.1) is what would make
`SelfApproved` honest; it is not active anywhere.

### What the modal lacks

1. **No honest resolution check for PDFs** — §7.1.
2. **Two composition modes only**: raw untouched, or everything to 300 DPI JPEG.
3. No module boundary; no independent test surface beyond the parity suites.
4. Seven products get no hash, no gate, no raw path.
5. **The bleed answer is overwritten**, not merely ignored: geometric detection calls
   `onBleedDetected` which sets the priced `bleed` option (`:7196`).
6. **Hidden-layer detection** is the *correct* kind — it reads pdf.js's optional-content
   config and names only groups switched OFF (`:63-70`, `:2258-2265`). Keep that
   semantics when porting (§7.6).

### Vulnerabilities

- **The 2026-08-12 crash.** The **coupon** calculator vanished on Approve with a real
  multi-page PDF (orientation mismatch plus an inserted blank). Never root-caused. Since
  `e5b4c5f` an ErrorBoundary on all eight turns it into a reload prompt instead of a blank
  page. The throw is in post-approval print-ready generation — which the proofer also
  performs. Repro notes: `docs/HANDOFF_2026-08-12.md:165-182`,
  `docs/ART_APPROVAL_GATE_REVIEW.md:132-138`.
- **pdf.js version collision.** Calculators load 4.10.38 ESM; the proofer pins 3.11.174
  UMD. Both claim `window.pdfjsLib`.
- **Lossy-by-default print path** whenever the raw path is not taken: extraction JPEG
  q0.8 (`:2243`), print JPEG q0.95 (`:3693`), previews q0.85.
- **The raw-path tolerance punishes good files.** A file with 0.25″ bleed for a 0.125″
  spec misses `< 0.25` by exactly zero and is rasterised. §9.1.

---

## 3. The new proofer — design decisions and why

### It is framed, not ported

`ppsOpenProof()` opens `proof-ui-draft.html` in a **same-origin iframe** (`:2843-2847`,
no `sandbox` attribute). Three reasons, in cost order:

1. **Exception and namespace isolation.** A throw in the proofer leaves the calculator
   standing, and the two pdf.js globals stay apart. **This is not a main-thread or memory
   boundary** — `buildPackage()` renders on the main thread with a yielded frame per step
   (`2454-2493`); a hang or OOM still takes the tab. **And it is not a privilege boundary**:
   same origin, unsandboxed, full reach to the parent DOM, cookies and page nonces. Review
   proofer code to the same standard as calculator code; load nothing into it you would not
   load into `calc-preview-test.html`.
2. The pdf.js collision above.
3. The suites keep testing the artifact that ships, not a port of it.

### The handshake — exact

**Same-origin only, and asymmetric.** The host accepts replies only from the frame it
created — `ev.origin` AND `ev.source === frame.contentWindow` (`:2866-2867`) — and posts
with an explicit target origin (`:2873`). The proofer accepts a `pps-proof:job` from **any
same-origin window** (`proof-ui-draft.html:2953`, origin check only) and a second job
replaces the first. Do not add other same-origin senders.

**Transport:** `post()` uses `window.PPS_PROOF_HOST(msg)` if the host defines it (direct
mount, used by five suites), else `postMessage` to `window.location.origin`
(`:2942-2947`).

**In** — `window.PPS_PROOF_JOB` before load, or posted after `ready`:

```js
{ type:'pps-proof:job',
  job:   { calc:'saddle', trim:{w,h}, bleed:0.125, safety:0.125, pages,
           insideColor:'color'|'bw', coverColor:'color'|'bw' },   // built at calc:3177-3191
  files: File[],                       // applied sequentially from page 1
  slots: [{ page:<1-based>, file }] }  // applied after files; out-of-range ignored
```

`bleed`/`safety` are **hard-coded** by the host (`:3183`); `bindDir` is already folded
into `trim` by `trimDims` (`:1929`). **The job carries no `proof` field** (§7.4), no
`blankPlacement` and no page order (§7.7).

`validateJob` (`proof-ui-draft.html:923-947`) **refuses** — posting `pps-proof:error` and
replacing the stage with a refusal box (`:3136-3155`) — when trim is non-positive, pages
< 4, pages odd, saddle and pages % 4, bleed/safety negative, or a colour is not exactly
`'color'`/`'bw'`. **A two-face flat is rejected before anything renders.** `applyJob`
(`:951-966`) sets `MODEL.hosted = true`, which is what switches blank pages from demo art
to white.

**Out** (`proof-ui-draft.html`):

| type | payload | host resolves |
|---|---|---|
| `ready` | `{}` | — |
| `error` | `{ errors:[] }` | rejects |
| `approved` | `{ hash, pageCount, calc, trim, files:[{name, blob, note}] }` (`:3054-3059`) | `{ action:'approved', hash, pageCount, files }` |
| `approve-failed` | `{ attempts, step, message }` (`:3077-3080`) | forwarded to `onProgress` minus `step` (`:2881`); live call site passes no `onProgress` |
| `escape` | `{ reason, attempts }` (`:3013-3015`) | `{ action:'escape', reason }` → `emitArtwork({approved:false, prepressReview})` (`:3226-3231`) |
| `close` | `{}` | `{ action:'closed' }` |

**The escape hatch is a crash fallback, not a "send to prepress" option.** It is offered
only after **two consecutive `buildPackage` failures** (`:3081`,
`approveFailures >= 2`), with reason `'approval failed N times'`. A customer whose proof
builds fine cannot reach it. The paid proof options are the human route — and the proofer
is not told when one is selected (§7.4).

`ppsOpenProof` fails after a **20 s** wait for `ready` (`:2900`); the catch alerts and
**deliberately does not open the modal** (`:3234-3239`). See §6.3.

**The host owns everything after approval** — upload, order metadata, cart. The proofer
contains no `fetch`/XHR/`FormData`; it never uploads. Keep it that way.

`ppsProofFilesToOrder()` (`:2913-2932`) is the only place proofer names become pipeline
names, and it **silently drops any name it does not recognise**: `PRINT_READY.pdf` →
`<base>_print-ready.pdf`, `PREVIEW_pNN.jpg` (two-digit) → `<base>_preview_page_NNN.jpg`,
`MANIFEST.txt` → `<base>_manipulation_manifest.txt`. The integration test pins that nothing
else gets through (`tools-proof-integration-test.mjs:104`). **The original upload is not
in the proofer's package** — the host adds its own `File` objects, slot files named
`page_NNN_<name>` (`:3205-3224`). A host that forwards only `r.files` loses the original.

### Rendering model — read §7.0 before trusting any of this for print

- `PDF_RENDER_DPI = 150` (`:2061`). `ingestPdf` rasterises each PDF page **once** at this
  scale and stores only the canvas plus text boxes (`:2165-2196`). **No pdf.js page handle
  is retained.**
- `renderPrintPage(n)` is `composePage(n, 300 DPI)` drawing **that same 150 DPI canvas**
  (`:2449-2451`, `:1225`). PRINT_READY.pdf is therefore a 2× upsample, JPEG q0.94
  (`:2477`), embedded with pdf-lib, **emitted unconditionally** (`:2492`).
- `effDpi = sw / (drawW / pxPerIn)` (`:1229`) measures how thinly the held raster is
  stretched by the placement. Since the held raster is the proofer's own 150 DPI render,
  this reports over-scaling honestly and cannot see a low-resolution image inside the
  file (§7.1). `DPI_WARN = 120`, `DPI_BAD = 90` (`:996`).
- `readPdfStructure()` (`:2098-2148`, pdf-lib) returns per-page MediaBox/TrimBox/
  BleedBox, `notEmbedded[]` fonts (Type0 descendants followed, Type3 exempt, subset
  prefixes stripped), `spots[]` (Separation + DeviceN colorants), `hasOptionalContent`,
  `hasAcroForm`, `hasAnnots`. pdf-lib's `getTrimBox()` falls back to **CropBox, then
  MediaBox**, so `pg.trim` is never null and a declared trim is one that differs from the
  page (`:2318-2323`).
- Greyscale: a page whose colour is `bw` is shown grey on the sheet, thumbnails, 3D and
  baked grey into the previews (`bakeGreyscale`, `:2505`); **PRINT_READY.pdf deliberately
  keeps colour** and the manifest lists the grey pages (`:2529`). §9.4.
- `ISSUE_TEXT` strings are built once at load from the demo `MODEL.bleed` (`:1239-1247`).
  Harmless while the only host sends 0.125; make them functions of `MODEL` before any
  product with a different bleed goes through.

### 3.1 What is measured — what feeds the acknowledgment gate

Page-level `analyze()` (`:1304-1339`):

| kind | level | trigger |
|---|---|---|
| `noart` | error | hosted job, no artwork on the page |
| `bleed` | error | art does not fill the bleed area |
| `lowres` | error | `effDpi < 90` |
| `softres` | warn | `effDpi < 120` |
| `cut` | error | type crosses the trim |
| `tight` | warn | type inside the safety margin |
| `nomargin` | info | flat image — type cannot be told from picture; **not gated** |

File-level `FILE_CHECKS` (`:2291-2364`): `pages`, `size`, `fonts`, `spots`, `layers`,
`interactive` — each pass/warn/fail/unknown. `size` emits warn only, never fail. `pages`
says "Saddle stitch needs a multiple of four" regardless of `MODEL.calc`.

`jobFlags()` (`:2739-2764`) counts page error+warn and file fail+warn across **every
page plus the file-level checks**; `info` and `unknown` do not gate.

Manifest sections: DOCUMENT, PRINT FILE (SHA-256), BLANK PAGES (conditional), ORIGINAL
FILES AS RECEIVED, PER PAGE (behavior/anchor/rot/effDpi/src/type margins), APPROVAL (flag
counts, acknowledged yes/no, each flagged item, terms). A PREFLIGHT section exists in code
but **can never emit** — it is guarded on `lastStructure`, which is referenced nowhere
else (`:2515`).

---

## 4. What is already closed in the new proofer

Each was a real defect. Do not regress them; each has a named gate.

| Closed | What it was | Gate |
|---|---|---|
| **Approval checkpoint** (2026-09-08, code review) | Approve required no acknowledgment. Now a second explicit acknowledgment whenever anything is flagged, recorded in the manifest, reading `jobFlags()` across every page. The responsibility sentence sits with the button and appears nowhere else; do not drop it on small screens. | draft suite |
| **Prepress escape hatch** (2026-09-13, review) | Taking it told nobody. Calculator posts `pps_prepress_review`; PHP stores staff-visible `PPS-Prepress-Review` item meta, spec token → `PREPRESS-REVIEW`, order note, and **drops any `pps_proof_hash`**. See §3 for when it is actually offered. | `tools-prepress-flag-test.mjs` |
| **Per-slot uploads** (2026-09-13, review) | Never reached the proofer. Job now carries `slots`; applied after `files`; `loadArt`/`loadArtSequence` made genuinely sequential. **Calculator half, same commit:** `handleSlotFile()` sent any PDF to `processFiles()`, which replaces the whole book, so the second single-page PDF wiped the first and `slotFilesRef` stayed empty. Saddle + coupon. | `tools-proof-slots-test.mjs`; `tools-slot-upload-test.mjs` (both compiled builds) |
| **Demo art on empty pages** (2026-09-13, staging) | `SRCget()` drew the prototype's fake booklet on any page with no upload, `buildPackage()` renders every page, so the approval hash bound to it and **the press would have printed it**. Hosted jobs now get white + `noart` per empty page + BLANK PAGES in the manifest. | `tools-proof-blank-pages-test.mjs` |
| **Silent Approve** (2026-09-13, staging) | Tens of seconds of frozen UI. Now a determinate bar, stage in words, percentage on the button, and **a yielded frame before each long block so the readout paints**. Failures name the step. | `tools-proof-progress-test.mjs` |
| **Ordered-size check** (2026-09-13, staging) | Warned on every correctly bled file: bleed = trim + 2×bleed and most files carry no TrimBox, and the no-TrimBox branch could never fire because of the pdf-lib fallback above. | `tools-proof-size-check-test.mjs` |
| **Greyscale** (2026-09-11, production — order 87154) | Approved grey on screen, filed to Drive in colour: the modal's grey was a CSS filter the package generator never applied. Fixed in both surfaces (`c22baeb`, `ea7274b`). | `tools-preview-greyscale-test.mjs` |

**The lesson threaded through the last three, and the most transferable thing here:** a
check that warns on good files is worse than no check, because it feeds the
acknowledgment gate and teaches people to tick past the one barrier between artwork and
a press. Ship any new check behind a test that proves it stays **silent on a correct
file** first.

---

## 5. Feature parity checklist — modal → proofer

Every cell below was checked against the code on 2026-09-20.

| Capability | Modal (saddle) | New proofer | Notes |
|---|---|---|---|
| Bleed / trim / safety guides | ✅ | ✅ | |
| Spine / bound-edge guide | ✅ (saddle, PB, coupon) | ⚠️ | On the proof sheet and legend only (`:1417-1427`); **absent from the lens and the preview JPEGs** — `drawGuides()` strokes bleed/trim/safety only (`:2428-2442`) |
| Fold-line overlays | ✅ (brochure, postcard, greeting card) | ❌ | See §6.2 step 2 |
| Magnifier with guides | ✅ | ✅ | |
| Hi-res 300 DPI print render | ✅ (vector re-render at 300) | ❌ | **150 DPI source upsampled** — §7.0 |
| Raw file shipped untouched (vector preserved) | ✅ saddle only, knife-edge tolerance | ❌ | **Always flattens** — §7.0 |
| Per-page transforms | ✅ Crop/Fill/Fit/Stretch/Scale/Rotate, free `posX/posY` | ⚠️ | All six modes (`:1014-1022`) with a **9-point anchor grid** instead of free position; rotate presented as a sixth behavior; no rotate-all uniform/spine/edge modes. Not a superset. Decide whether the grid is acceptable before cutover |
| Per-page slot uploads | ✅ | ✅ | |
| All-pages view | ✅ grid mode | ⚠️ filmstrip | Always-visible thumbnail strip (`:1727-1770`), no switchable grid |
| Page-count reconciliation | ✅ `blankPlacement` covers/end; over-length keeps front + N−2 + **back cover** (`:4082-4145`) | ❌ | Fills from page 1, **drops trailing pages including the back cover** (`:2161-2162`); blanks always at the end. Not in the job — §7.7 |
| 3D closed-book + open-spread preview | ✅ saddle/PB/coupon | ✅ | Ported 2026-08-30 (`:760, 1530-1662`); approval disabled while in 3D mode |
| Approval package: raw file | ✅ | ✅ pipeline / ❌ proofer | Added by the **host**, not the proofer — §3 |
| Approval package: print-ready PDF | ✅ (skipped on raw path) | ✅ always | |
| Approval package: preview JPEGs with guides | ✅ | ✅ | no spine guide, above |
| Approval package: manifest | ✅ | ✅ | |
| SHA-256 approval binding | ✅ | ✅ | bound to the flattened bytes |
| Acknowledgment gate on flagged checks | ✅ | ✅ | |
| Approve hidden for paid proofs | ✅ (`:5135-5155`) | ❌ | Job has no `proof` field — §7.4 |
| Prepress escape hatch | ❌ | ⚠️ | Proofer only, after two failed approvals |
| Hidden-layer (OCG) detection | ✅ **OFF groups only** | ❌ regression | Warns on **presence** of `/OCProperties` (`:2141, 2352-2357`) — every correct layered export trips the gate. §7.6 |
| Embedded-font check | ⚠️ advisory banner, all 8 (`ppsAnalyzePdfRisks` `:89-96`), not gated | ✅ fail-level, gated, names faces | Proofer ahead |
| Spot colours, interactive content | ❌ | ✅ | Proofer ahead |
| Honest placement DPI | ❌ rubber stamp | ⚠️ | Placement only — §7.1 |
| Greyscale pages shown grey, previews baked grey, print kept colour | ✅ (2026-09-11) | ✅ (2026-09-11) | |
| Wraparound cover surface (PB/coupon) | ✅ draft | ❌ | §6.1 |
| Reader's-spread splitting | ⚠️ machinery intact, control removed 2026-07-30 | ❌ | |

---

## 6. How to adapt the proofer from saddle to every product

### 6.1 The shape of the problem

Three families, not eight special cases:

| Family | Products | Proof shape |
|---|---|---|
| **Bound** | saddle, perfect bound, coupon book | Ordered page list, spine guide; PB and coupon additionally an optional **wraparound cover** — one surface of width 2×trim + spine (+ bleed) with spine-boundary guides |
| **Flat, two-sided** | brochure, postcard, greeting card, letterhead | Exactly two faces; fold geometry on all but letterhead |
| **Flat, repeated** | sticker | One face, step-and-repeat downstream |

The proofer today refuses anything under 4 pages (§3). Page-count padding happens in the
calculator before the job is posted; the proofer validates and refuses, it does not pad.

### 6.2 The route

1. **Make the job describe surfaces, not a booklet.**
   `job.surfaces = [{ id, label, trimW, trimH, bleed, spineW?, guides:[…] }]`, with
   `trimW` allowed to be 2×page + spine so a wraparound cover is a surface, not a page.
   Carry the **already-reconciled page order** (or `blankPlacement`) and **`proof`** so the
   proofer can render review-only for paid proofs. Relax `validateJob` per family.
2. **Guides become data** — `{type:'fold'|'spine'|'safety'|'trim'|'bleed', at, orientation}`
   — and `drawGuides()` draws all of them into the previews too. The fold renderers exist in
   `brochure-fold-previewer.html` and the three folding flats.
3. **Give the proofer the saddle modal's lossless path first** (§7.0). Then decide whether
   the proofer owns composition outright. Two engines is the precondition for the 87032
   class of bug — but deleting `composePageCanvas()` before the proofer has a raw/vector
   branch deletes the only lossless path in the system.
4. **Wire letterhead first**, not postcard: it is the only flat with no fold engine, so it
   surfaces the booklet assumptions without fold complexity. Checklist: relax `validateJob`
   (`:930-933`); extend `ppsProofFilesToOrder` with a per-family name table so flats produce
   `<base>_preview_front.jpg`/`_back.jpg` (the flats' own package names sides
   `["front","back"]`, `calc-postcard.html:3280`) and update
   `tools-proof-integration-test.mjs:95-105`; one PRINT_READY.pdf, page 1 front, page 2
   back.
5. Then postcard and greeting card (folds), brochure, perfect bound / coupon (wraparound),
   sticker.
6. **Retire the modal per product.** `PCF.proof_url` is one site-wide string; see §7.3 for
   the per-calc-type mechanism.

### 6.3 Rollout mechanics

- **Blank is the only safe wrong value.** A cross-origin value falls back to the modal
  with a `console.warn` (`:2821-2825`). A same-origin value that does not load — 404, a
  cached old build, a WAF block, a file that throws before `ready` — is **not** a
  fallback: 20 s timeout, alert, no modal, and the `proof === 0` submit gate then refuses
  the order. **Before setting the knob, fetch the URL as a logged-out visitor and confirm
  the proofer posts `ready`.**
- Deploy the file before enabling anywhere (done on both servers, 170,517 bytes,
  2026-09-15).
- Each product cutover needs the ten proofer suites plus that product's own gates.
- **Never enable on production before staging has run a real order through it** end to
  end, including the Drive upload and the imposition hash check.
- To confirm "off" without reading `pps_calc_config`: on a live saddle page,
  `window.__ppsProof.urlFor()` in the console returns `null` when the knob is blank
  (`:2941`), or the owner opens PPS Config → Production → New Proof URL.

---

## 7. What is still missing — prioritised

### 7.0 The proofer flattens everything, from a 150 DPI source — **blocks any cutover**

Covered in §3. Two independent problems:

- **No raw or vector path.** Port the saddle modal's `rawIsExactSet` branch (ship the
  original bytes as PRINT_READY.pdf, or emit no PRINT_READY and let the host hash the raw
  file as the modal does at `:3730-3732`), or a pdf-lib `copyPages` branch for pure
  geometric crops. Fix the `< 0.25` tolerance at the same time (`:3629`) — `<=`, or "within
  0.25″ of trim OR bleed". It is the only confirmed cause in §9.1 and it is one token. Note
  the raw path accepts art at **bleed** size only; a correctly sized trim-only file is
  always rasterised.
- **Print resolution.** Retain the pdf.js page handle in `ingestPdf` and re-render at
  `PRINT_DPI` in `renderPrintPage`, as `composePageCanvas`'s vector branch does
  (`calc-preview-test.html:3329`). Until then PRINT_READY.pdf is effectively 150 DPI.

Then rewrite §8 invariant 3 to match. Today the proofer never legitimately lacks a PDF.

### 7.1 Neither surface can see a low-resolution image inside a PDF

- **The modal rubber-stamps.** `calc-preview-test.html:3999-4001`:
  `if (source === "pdf") → pass "PDF source — vector/high resolution"`. Nothing is
  measured. Images get a real DPI with warn/fail; PDFs get a pass on the file extension.
- **The proofer measures placement only** (§3).

**Where the fix goes:** `ppsAnalyzePdfRisks()` (`calc-preview-test.html:52-99`, present
in all eight calculators) **already walks `page.getOperatorList()`** for transparency,
gradient and font risks. Add the image walk there so all eight products get it now, and
have the proofer consume or port the same function — never two implementations.

**The algorithm:** walk ops maintaining a graphics-state stack (`save`/`restore`/
`transform`); on `paintImageXObject` / `paintInlineImageXObject`, multiply the unit square
by the CTM for the device-space footprint; `effectiveDpi = pixelWidth / (footprintPt/72)`;
report the per-page minimum beside the physical size.

**The trap:** get the CTM subtly wrong and you have a check that warns on good files
(§4). Build three fixtures in-page — a true-vector QR, a 300 DPI placed QR, a 72 DPI
placed QR at the same physical size — and prove the first two stay **silent** before the
third is allowed to fire.

### 7.2 Only the saddle calculator is wired

Seven of eight have zero references to `proof_url`, `ppsOpenProof` or
`composePageCanvas`. §6.

### 7.3 The knob — where it exists, how it resolves, how to make it per product

**Staging already has it.** Staging runs `pps-config-admin.php` at 118,443 bytes = the
commit that added `proof_url`, deployed 2026-09-13; the field is live under PPS Config →
Production → New Proof URL. **Production does not**: 117,942 bytes = the commit before.
Enabling on production means deploying `pps-config-admin.php` (pinned SHA) first.

Resolution order (`calc-preview-test.html:2806-2827`): `window.PPS_CONFIG.proofUrl`
first — **never set by any PHP today** — then `PCF.proof_url`, which arrives via
`pps_get_public_config()` passing the whole `pcf` array minus two secret keys. The value
is the same-origin path of the deployed file, `/wp-content/uploads/pps-calculators/proof-ui-draft.html`.
Verify in the browser console, not by looking at the admin field.

**Do not read or write `pps_calc_config` directly** — it carries live credentials.

**Per calc type:** do not add `proof_url_postcard`-style keys — unknown `pcf` keys are
`floatval()`'d on save (`pps-config-admin.php:670`) and would store as `0`. Either store a
map (`pcf.proof_urls = { saddle:'', … }`) sanitised per value at `:665`, or use the
unused first branch: `pps-calculators.php` knows the calculator file (hence calc type via
`pps_get_calc_type_for_filename`, `:5743-5755`) where it builds `PPS_CONFIG` (`:1193-1214`),
so it can inject `proofUrl` per page. The comment at `:2807-2811` explaining why the knob
is not a `PPS_CONFIG` key is stale.

### 7.4 Paid proofs go through the self-approval surface

The Proof button opens the proofer for any `proof` value (`:4916`); the job has no
`proof`; the proofer always renders Approve and posts a hash, so a hardcopy-proof customer
is walked through the responsibility sentence and PHP stores a hash alongside a
`Hardcopy` token. Pass `proof` in the job and render review-only when `job.proof !== 0`;
until then gate the call site: `if (proof === 0 && ppsProofUrl() && …)`.

### 7.5 The hardcopy proof address — a booklet-only gap — **closed 2026-09-22**

Closed by the Job Ticket work: the three booklets now put `proofAddrSame`/`proofAddr` in
the config and the metadata, and `pps_job_ticket()` prints "Proof ship-to" on the order
for every hardcopy proof, on all eight products (`tools-job-ticket-test.mjs`). What is
still open from the paragraph below: `proofAddrSame` is not seeded from `D`, so a reorder
does not restore it. The original finding, for the record:

All eight calculators show the same/different address form. **The five flats have
serialised `proofAddrSame`/`proofAddr` into `_pps_metadata` since 2026-06-19**
(`calc-brochure.html:4377-4378`, `:5468`). **Saddle, perfect bound and coupon book never
wired those two lines into `buildMetadata`** — in `calc-preview-test.html` the identifiers
appear on 11 lines (`:6214-6215`, `:7264-7277`), all state and inputs, none in
`buildMetadata`/`buildSummary`/`submitToWooCommerce`. A different address on a booklet
order produces a payload byte-identical to the same address. `proofAddrSame` is also not
seeded from `D`, so reorders do not restore it. Fix: copy the flats' lines. Then decide
whether PHP should lift the address onto the order — today nothing reads it there either.

### 7.6 The proofer's hidden-layer check is presence-based — the §4 anti-pattern

`hasOptionalContent = !!cat.get('OCProperties')` (`:2141`) → `layers` warn (`:2352-2357`)
→ `jobFlags`. Every InDesign/Illustrator export with layers, all visible, trips the gate.
Port the modal's semantics (`getOptionalContentConfig()`, warn only when a group is OFF)
and gate it with a fixture whose layers are all on.

### 7.7 Page-count reconciliation is absent from the proofer

§5. The job must carry the reconciled page order or `blankPlacement`; `blankPlacement` is
already part of `specSig` (`:3258`), so a change there already revokes approval on the
host side.

### 7.8 Edit mode and reorders lose the approval package

Both restore artwork as `{type:"existing", path}` (`:6345-6349`); the gate exempts
`existing` (`:6878-6895`); only `pps_artwork_path` is posted (`:5983`).
`pps_ajax_add_to_cart` builds the new line from POST alone (`pps-calculators.php:2230-2298`)
and removes the old one (`:2316-2318`), so `pps_proof_hash`, `pps_artwork_files` and
`pps_prepress_review` do not carry over. An edited or reordered line reaches the
imposition queue **unbound**, with no print-ready file. Fix: copy those three keys from
the old cart item when spec-affecting fields are unchanged; otherwise require a fresh
proof.

### 7.9 The "I don't have bleeds" answer reaches neither surface

The calculator holds it in `bleed` state and posts it in the order, but the job builder
hard-codes `bleed: 0.125` (`:3183`) and the proofer already honours `job.bleed` (`:959`).
One line at the job builder — then decide what guides and default fit do at bleed 0
before shipping it. Do not add another geometric check; the geometric one cannot fire
because crop scales art to fill the bleed.

### 7.10 `pps-proof-status.php` is active nowhere

§1.1. Every order on both sites reads `SelfApproved` on purchase alone.

### 7.11 Dead tests

`tools-parity-layers.mjs` (fixture never committed, notes gone) and
`tools-proof-package-test.mjs` (§1.2). Revive or delete; neither is currently a gate.

---

## 8. Invariants — break these and you will not find out for weeks

1. **Same-origin only** for the handshake; host checks origin **and** source.
2. **The proofer never uploads.** The host owns everything after approval.
3. **Nothing may infer approval from the presence of a print-ready PDF.** The saddle
   modal's raw path legitimately emits none; the proofer today always emits one (§7.0);
   the manifest is the marker either way.
4. **`ppsProofFilesToOrder()` is the only place** proofer names become pipeline names,
   and it drops unknown names silently — extend it, never bypass it.
5. **Approval is SHA-256-bound** to the print-ready bytes; the imposition tool enforces
   it when a hash is present.
6. **`pps_proof_hash` posts only when `proofHashOf` names a deliverable that uploaded.**
7. **Every approval-voiding path revokes the parent's `artFiles.approved`** —
   transforms, spec, slots, artwork-option switch, Review, mid-generation edits.
8. **`skippedGeneration` reports the branch actually taken**; never infer it from
   `noTransforms`.
9. **The acknowledgment gate reads `jobFlags()`** — every page plus file-level checks.
10. **The responsibility sentence lives with the Approve button** and nowhere else.
11. **Pages carry `srcPdfPage`** and the modal's vector branch renders by it, never by
    display index. It rides through reconciliation by object spread; slot replacement
    creates a page without it (raster path). `splitReaderSpreads()` deliberately does
    not set it. No test names this invariant — worth one.
12. **The approval gate applies only to `proof === 0`.**
13. **Never write a literal `</script>`** inside a `text/babel` block; escape as
    `<\/script>`.
14. **The style suite must keep reading the palette from `calc-preview-test.html`.**
15. **Any new preflight check ships behind a test that proves it is silent on a correct
    file.**
16. **Every original the customer supplied reaches the order, whatever the proof type or
    approval state.** One function lists them (`allOriginals()` in the three booklets);
    every emit that is not the approval package sends it. §9.6.

---

## 9. Case studies

### 9.0 Order 87032 — the proof that lied about the crop

The theme's `img` clamp made the on-screen proof show the whole image while the
print-ready PDF, drawn on a canvas where no CSS applies, cropped to the centre 32%. The
customer approved a proof that was not what printed. This is the archetype of §0, the
reason `composePageCanvas()` exists, and the reason `tools-harness-prep.mjs` injects the
production theme CSS — "a parity claim made without the theme loaded is worthless."
`docs/HANDOFF_2026-08-23.md:28-44`.

### 9.1 Order 87152 — a QR code reported unscannable

**What the records show.** A 12-page vector PDF at 4.5″ × 4.5″ for a 4″ trim with
0.125″ bleed (bleed box 4.25″). No transforms on any page. Placed 2026-09-07, completed
and shipped 2026-09-10; a second, Next Day Air Saver label on 2026-09-17. The Drive
manifest records the print file as 12 pages at 300 DPI JPEG q0.95 and the raw file as
11 MB against a 44 MB print-ready. **The unscannable-QR report itself is recorded
nowhere in the repo, the order notes or Drive** — it was relayed by the owner in
conversation. Treat it as the owner's report, not a system record.

**What is confirmed.** The raw-file path was missed by exactly zero:
`|4.5 − 4.25| = 0.25`, and the test is strict `< 0.25` (`:3629`). Being *more* generous
than the spec on bleed is what rasterised the file. Every downstream guard then worked:
the hash bound to the rasterised bytes, an `IMPOSED_` file with no `_UNAPPROVED` suffix
exists — though from here that is indistinguishable from the check never running, since
`_pps_proof_hash` on an HPOS order is not readable through the MCP tools.

**What is hypothesis.** This is a saddle order, so the flats' 144 DPI print path (§9.5)
does not apply — the saddle renders at `DPI / 72`. But the saddle's print path did
degrade it: until 2026-09-21 the rendered page was placed on a fractional offset and
resampled, measured at 20% mid-grey over a QR that should read 0% (§9.5). That is a
confirmed contributor, not the whole story. Rasterisation alone does not explain it: order 87202, a week later, took the same code branch and its QR was reported fine — but 87202's art was
downscaled ~1.9× under `fit=cover`, which raises the effective DPI of any placed bitmap,
so it is a weaker control than "identical." The leading explanation is that the QR was a
low-resolution placed bitmap inside the PDF (§7.1) — **unconfirmed**; nobody has opened
the file. Confirm with `pdfimages -list` or the operator-list walk before committing press
time, because a reprint from the same artwork would then fail identically. Whatever the
answer, the preflight told the customer "vector/high resolution" without measuring.

### 9.2 Demo art on unsupplied pages

`SRCget()` drew the placeholder booklet on any page with no upload; `buildPackage()`
renders every page; the hash bound to it. Had this shipped, a press would have printed a
fake programme inside a customer's booklet and every integrity check would have passed.
Found on staging by looking. Integrity guarantees protect correctness of *transport*, not
of *content*.

### 9.3 The ordered-size check that warned on everything

§4. It was feeding the acknowledgment gate — actively destroying the one deliberate pause
between a customer and a press.

### 9.4 Order 87154 — approved grey, filed in colour

The modal's greyscale was a CSS filter; the package generator never applied it; page 28
went to Drive in colour. Same failure shape as 87032 — screen and print composed by
different code — on a different axis. Fixed in both surfaces 2026-09-11.

### 9.5 Order 87171 — the QR code that was printed from the screen preview

**What the records show.** A 3-panel accordion brochure, 21″ × 7″ trim, two-sided, from
a two-page 11 MB PDF at 21.5″ × 7.5″ (0.25″ bleed against the 0.125″ ordered — so, as on
87152, `|art − bleed| = 0.25` and no raw path; on a flat there is no raw path anyway).
Crop, 0°, 100%. Placed 2026-09-09, shipped 2026-09-15. The customer reported the QR code
on the printed piece would not scan.

**What is confirmed, from the print-ready file on Drive.** The QR sits on the outside at
0.78″ across with modules of about 4 px at 300 DPI (0.013″), a logo knocked out of its
centre. OpenCV finds the finder patterns and cannot decode it. Over the QR's area, 26.5 %
of pixels are mid-grey (60–195) and the Laplacian variance is 1,873 — the signature of a
soft upscale, not of a crisp render. The same measurement on the pre-fix brochure build
driven with a clean vector QR at the same module size gives 35.7 % and 2,551; on the
fixed build, 0.0 % and 18,654. The cause is in §2: the print file was the 144 DPI
preview JPEG drawn up 2.08× and resampled once more on a half-pixel offset. A module of
0.013″ is 1.9 px at 144 DPI. Everything after that was faithful to a file that was
already ruined, and the manifest called it 300 DPI.

**What is not confirmed.** The raw file exceeds the Drive tool's 10 MB download limit, so
whether the customer's QR was itself vector has not been checked from here. A reprint
from the fixed build will settle it: if the QR is still soft, the source is a bitmap and
§7.1 applies.

**Then the booklets were measured instead of trusted.** The line above about the booklets
rendering at `DPI / 72` was itself read off the code. `tools-book-print-dpi-test.mjs`
drives saddle, perfect bound and coupon with an 8-page 6″ × 9″ QR PDF (0.25″ off the
bleed sheet, so rasterised, as 87152 was). First run: every page on all three measured
20% mid-grey — the source was placed at `(canvasW - drawW) / 2`, a fractional offset, and
Canvas resampled the whole sheet; and page 8 on perfect bound and coupon measured 50%,
because it was the back cover moved to book page 20 and rendered from its 108 DPI
thumbnail (§2). After whole-pixel placement and `srcPdfPage`: 0% on every page of all
three. So on order 87152 the saddle's own print path *did* soften the QR, measurably,
before any question of what was inside the PDF.

**Two lessons.** First, a number in a manifest is a claim, not a measurement — the
manifest said 300 DPI because a constant said 300. Second, the flats are the live
surface for five products, and their generator was a "parameterized copy" of the
booklets' that quietly dropped the one line that mattered (`scale: DPI / 72`).
`tools-flat-print-dpi-test.mjs` now measures the print file itself, and it was run
against the old build first to prove it fails there. It also turned up that the sticker
uploader threw `setBackArt is not defined` on any multi-page PDF — a state the flats'
copy assumed and the sticker never declared — which is fixed in the same commit.

### 9.6 Order 87273 — twenty images chosen, one received

**What happened.** A coupon book with a staff digital proof, 2026-09-25. The customer chose
twenty JPGs at once; the calculator showed twenty pages. The Drive folder received one
file, `1.jpg`, three seconds after it was created, and nothing else — no error anywhere.
The customer wrote in: "I attached 20 files."

**Why.** The multi-file handler in all three booklets did
`onArtwork({ type: "files", list: [fileList[0]] })`. The complete list — every original,
slot file and wraparound cover — was only assembled inside the *self-approval* path. Any
order without a self-approved package (a staff or hardcopy proof) shipped file 1 of N. The
line had been there since the first commit in March. Two twins in the same place: a page
dropped on a slot sent the slot files *alone*, dropping the whole-file set (the pre-fix
coupon build uploaded only `page_002_…` for a twenty-image order with one slot changed);
and on perfect bound and coupon, clearing an approval — Review, a transform, a new
wraparound cover — cleared it in the panel but not on the order, so Add to Order shipped
the stale approved package (invariant 7, which the saddle had since 2026-08-24 and the
copies never received).

**Fix, 2026-09-25.** `allOriginals()` in each booklet; every non-package emit calls it;
perfect bound and coupon revoke on the order when approval is cleared.
`tools-multi-file-upload-test.mjs` drives all three compiled builds: twenty images on a
staff-proof order, all twenty uploaded in order; a slot change keeps them and adds
`page_003_…`; approve-then-Review is refused at Add to Order. Run against the pre-fix
coupon build first: 5 of 13 checks failed, the first with `["1.jpg"]` — the order exactly.

**The sweep, 2026-09-26.** The same question — does everything the customer gave us reach
the order? — asked of every path found five more: the flats' per-side slots and approval
revocation (all five), customer files skipped at upload when refused or too large (all
eight), a cart edit shedding every file but one, the Drive uploader assuming a missing
file was already uploaded, and the print-file check reading a random path and calling a
layout PDF "62 DPI" (87272). `CLAUDE.md` §"Every original reaches the order" lists each with
its gate; every gate was run against the live build first and failed there.

**Lesson.** A preview proves the calculator *read* the files, not that the order *carries*
them. Test what reaches the upload, on the path customers who pay for a proof take — the
self-approval path was the only one anyone had exercised.

---

## 10. Open questions for the owner

1. **Should the proofer own composition outright**, deleting the modal's engine — *after*
   §7.0 gives it a lossless path? Recommended, with that ordering non-negotiable.
2. **Per-calc-type `proofUrl` via PHP injection, or a `pcf.proof_urls` map?** §7.3. The
   injection route is the smaller change.
3. **Keep two 3D previews** (calculator and proofer both have one), or drop the proofer's
   to keep that surface strictly a proof?
4. **Reprint policy** when the customer's file is at fault but our preflight said it was
   fine — 87152 is the live instance.
5. **Activate `pps-proof-status.php`?** Until then the spec token means nothing.

---

## 11. First moves for a new session

1. Read this file, then `CLAUDE.md` §"Proofing — two surfaces, one of them dark".
2. Run §1.3 exactly. Ten suites green; the eleventh is dead by design.
3. Do **not** enable `proof_url` anywhere until §7.0, §7.4 and §7.6 are closed. On staging
   the knob already exists; that makes it easier to enable by accident.
4. If you add a preflight check, write the silent-on-a-correct-file test first.
5. If you touch composition, run `tools-parity-saddle.mjs`, `-extended`, `-findings`,
   themed, before and after.
