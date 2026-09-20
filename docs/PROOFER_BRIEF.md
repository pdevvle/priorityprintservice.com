# The Proofer — handover brief

**Audience:** a Claude session arriving with no prior context on this work.
**Status at time of writing (2026-09-20):** the new proofer is built, tested, deployed
to both servers, and **switched off everywhere**. Seven of eight calculators still use
the old modal. Nothing in this document is speculative unless it says so.

Read this before touching `proof-ui-draft.html`, the proof modal inside any
`calc-*.html`, or anything named `tools-proof-*`.

---

## 0. Why the proofer exists at all

A print shop's single most expensive failure is printing the wrong thing at volume and
finding out from the customer. Proofing is the one gate between a customer's file and a
press. Everything below is in service of one property:

> **What the customer approved is what gets printed, and they had a fair chance to see
> what was wrong with it.**

Both halves matter. A proof that is faithful but unreadable fails. A proof that is
beautiful but not bound to the printed bytes fails. The system already has real scar
tissue from both failure modes; they are documented as case studies in §9.

---

## 1. Where everything lives

**It is all in this repo — `pdevvle/priorityprintservice.com`.** I considered splitting
the proofer into its own repository and decided against it. The reasons are load-bearing,
so do not undo this casually:

- The deploy pipeline (`pps-html-deploy.php`) carries `proof-ui-draft.html` in an
  explicit filename allowlist. A separate repo would need its own deploy path.
- `tools-proof-ui-style-test.mjs` **reads the colour palette out of
  `calc-preview-test.html`** rather than copying it, precisely so the two documents
  cannot drift apart. Across repos that invariant dies.
- The host seam (`ppsOpenProof`, `ppsProofFilesToOrder`) lives inside the calculator.
  The proofer is only half of a contract; splitting them hides the other half.
- `tools-proof-integration-test.mjs` drives the calculator and the proofer together.

If it is ever split, those four things are the migration, not the file move.

### File inventory

| File | Role |
|---|---|
| `proof-ui-draft.html` | **The new proofer.** ~170 KB, single standalone document, vanilla JS. Not a component — the calculator frames it. |
| `calc-preview-test.html` | Saddle stitch calculator. Holds the **old modal**, the `composePageCanvas()` engine, and the **only** host-seam wiring to the new proofer. |
| `calc-perfect-bound.html`, `calc-coupon-book.html`, `calc-brochure.html`, `calc-postcard.html`, `calc-greeting-card.html`, `calc-letterhead.html`, `calc-sticker.html` | The other seven. All use the old modal. **Zero** references to `proof_url`, `ppsOpenProof` or `composePageCanvas`. |
| `pps-html-deploy.php` | How the proofer reaches a server. Watches `_pending_html/`, copies into `uploads/pps-calculators/`, logs to `wp_options['pps_html_deploy_log_v2']`. |
| `pps-config-admin.php` | Defines the `proof_url` knob. **The repo copy only** — see §7.3. |
| `docs/PROOFER_BRIEF.md` | This file. |

### Test suites

Ten suites plus two harness tools. All live at repo root.

| Suite | Covers |
|---|---|
| `tools-proof-ui-draft-test.mjs` | Engine, core behaviour |
| `tools-proof-ui-preflight-test.mjs` | PDF preflight findings |
| `tools-proof-ui-mobile-test.mjs` | Touch layout, rotation |
| `tools-proof-ui-style-test.mjs` | **Design parity** — reads the palette from the saddle calculator; fails any rule whose `var()` does not resolve |
| `tools-proof-embed-test.mjs` | The host seam / postMessage contract |
| `tools-proof-integration-test.mjs` | Calculator ↔ proofer round trip |
| `tools-proof-slots-test.mjs` | Per-page slot uploads |
| `tools-proof-progress-test.mjs` | Approve progress readout (via MutationObserver) |
| `tools-proof-blank-pages-test.mjs` | Unsupplied pages on a hosted job |
| `tools-proof-size-check-test.mjs` | The ordered-size preflight |
| `tools-proof-serve.mjs` | Static server on `127.0.0.1:8137` + `/vendor/` |
| `tools-proof-vendor.mjs` | Installs the proofer's pinned pdf.js/pdf-lib into `proof-vendor/` |

### Running the suites

They **cannot** run over `file://` — pdf.js needs a real origin.

```bash
node tools-proof-vendor.mjs                      # once, populates proof-vendor/
PPS_PROOF_VENDOR_DIR=<dir>/proof-vendor \
  node tools-proof-serve.mjs &                   # serves :8137 and /vendor/
PPS_PROOF_VENDOR_DIR=<dir>/proof-vendor \
PPS_DEPS_DIR=<dir>/node_modules \
  node tools-proof-ui-draft-test.mjs             # …and the other nine
```

**Port 8137 holds one server at a time.** The calculator suites want a plain
`python3 -m http.server 8137` in the harness dir; the proofer suites want
`tools-proof-serve.mjs`. Kill with `fuser -k 8137/tcp` when switching.

**The two pdf.js majors cannot share one `node_modules`.** The proofer pins 3.11.174;
the calculators run 4.10.38. Installing either evicts the other, which is why
`proof-vendor/` exists as a separate directory. If a calculator suite suddenly fails on
a missing pdf.js file, this is why.

---

## 2. The old modal proofer — what you are replacing

### How it was made

It grew inside `calc-preview-test.html` alongside the calculator, over months, as
features were needed. It was never designed as a proofing system; it accreted into one.
That origin explains most of its shape: there is no module boundary, no separate test
surface, and the same file is responsible for pricing, upload, composition, proofing,
PDF generation and cart submission.

### How the eight products use it — and they do not use it equally

This is the single most important fact about the current regime:

- **Saddle stitch (`calc-preview-test.html`) is the only mature one.** It has
  `composePageCanvas()` — a single composition engine that is the *only* place page
  composition maths lives. Proof modal, magnifier lens, grid view, thumbnails, 3D
  preview, print-ready PDF and preview JPEGs all take their pixels from it. Screen and
  print differ only in the `dpi` argument, so CSS cannot make the proof disagree with
  the print.
- **The other seven still composite the proof in CSS.** Their proof is a styled DOM
  approximation; their print file is generated separately. Nothing structurally
  guarantees the two agree. Porting them was logged as "the remaining proof-parity work"
  and never happened.

So "the proof system" is really two systems: one hardened one on saddle, and a
CSS approximation on everything else. **Any parity claim you inherit applies to saddle
only.**

### Approval binding (saddle only)

- Approval is bound to the **SHA-256 of the print-ready bytes**.
- The manifest records the hash; the order line item carries `_pps_proof_hash`.
- The imposition tool hashes what it downloads from Drive and **refuses to impose on
  mismatch**. Bulk never overrides; the interactive override tick flags the output
  filename `_UNAPPROVED`.
- Flagged preflight checks must be explicitly acknowledged before Approve unlocks, and
  the acknowledgment is recorded in the manifest.
- **The approval gate applies only to `proof === 0`.** Manual and hardcopy proofs are
  staff-approved; gating them once blocked paid-proofing uploads on all eight
  calculators.

### The proof options

Set by the `proof` field. Values are sentinels, not prices:

| Value | Meaning |
|---|---|
| `0` | Proof & Approve Online (included) — *or* "No Proof Needed" depending on the artwork option |
| `0.01` | Manual Digital Proof (`PCF.proof_digital_cost`) |
| `3.01` | Hardcopy Proof (`PCF.proof_hardcopy_cost`) — physically mailed |

`proof >= 3` opens a panel asking whether the hardcopy proof goes to the order address
or somewhere else. **See §7.5 — that answer is currently discarded.**

### What the modal lacks

1. **No honest resolution check.** See §9.1. This is the one that has already cost a job.
2. **Only two composition modes:** ship the raw file untouched, or rasterise everything
   to 300 DPI JPEG. There is no vector-preserving middle path, so a pure geometric crop
   of a vector PDF destroys its vectors.
3. **No module boundary.** A proofing failure takes the whole product page with it.
4. **No independent test surface.** Its gates are the saddle parity suites, which test
   composition and approval binding, not the proofing *experience*.
5. **Seven of eight products get a CSS approximation**, as above.
6. **The bleed answer is ignored.** The customer is asked whether their art has bleeds;
   both surfaces detect bleed geometrically from the art and ignore the selection.

### Vulnerabilities

- **Blast radius.** On 2026-08-12 a real multi-page PDF with an orientation mismatch
  took the entire calculator down — a customer on a blank product page, mid-order. That
  failure was never root-caused. It is the primary reason the new proofer is framed
  rather than ported.
- **pdf.js version collision.** The calculators load 4.10.38 as ESM; the proofer pins
  3.11.174 UMD. Both claim `window.pdfjsLib`. In one document, one of them loses.
- **Lossy-by-default print path.** `pdf.addImage(c.toDataURL("image/jpeg", 0.95), "JPEG", …)`
  builds the print-ready PDF from JPEG page images whenever the raw-file path is not
  taken. Source extraction is JPEG q0.8; previews q0.85.
- **The raw-file fast path is a knife edge.** It requires *no transforms* AND *no
  reconciliation* AND *no slot files* AND art within a hard tolerance of the bleed size.
  Miss any one and the whole document rasterises. See §9.1 for what that cost.

---

## 3. The new proofer — design decisions and why

### It is framed, not ported

`ppsOpenProof()` opens `proof-ui-draft.html` in a **same-origin iframe**. Three reasons,
in the order they cost you:

1. **Blast radius.** The 2026-08-12 crash was never root-caused. A frame boundary makes
   the worst case a broken proof, not a customer stranded on a blank page mid-order.
2. **The pdf.js collision** above. Two majors, one global, one loser.
3. **The suites keep testing the artifact that ships**, not a port of it.

Do not "simplify" this by inlining the proofer. All three reasons still hold.

### The handshake

**Same-origin only** — these messages carry the customer's artwork and the hash their
approval is bound to.

**In:** `window.PPS_PROOF_JOB` set before load, or a posted `pps-proof:job` after it.
The job carries `files` and, separately, `slots: [{ page, file }]`.

**Out:** `ready`, `error`, `approved`, `approve-failed`, `escape`, `close`.

**The host owns everything after approval** — upload, order metadata, cart. *The proofer
never uploads anything itself.* Keep it that way: it is what lets the proofer be tested
standalone and what stops a proofing bug becoming an ordering bug.

Seam functions, all in `calc-preview-test.html`:

| Symbol | Line (approx) | Role |
|---|---|---|
| `ppsOpenProof({url, job, files, slots, onProgress})` | 2834 | Opens the frame, does the handshake |
| `ppsProofFilesToOrder(pkgFiles, baseName)` | 2913 | **The only place** proofer file names become pipeline names (`PRINT_READY.pdf` → `<base>_print-ready.pdf`) |
| `window.__ppsProof` | 2941 | Test hook |
| call site | 3211 | Where the calculator actually invokes it |

**The manifest is the approval marker, not the print-ready PDF.** Art needing no
transforms legitimately produces no PDF. Any logic that treats "has a print-ready PDF"
as "was approved" is wrong.

### Rendering model

- `PDF_RENDER_DPI = 150` — the proofer rasterises every PDF page at this fixed scale for
  display and the magnifier.
- `DPI_WARN = 120`, `DPI_BAD = 90` — placement thresholds.
- `effDpi = sw / (drawW / pxPerIn)` — measures how thinly the held raster is stretched by
  the current placement. **Read §7.1 before trusting this number for anything else.**
- `readPdfStructure()` parses the file's own dictionaries with pdf-lib: MediaBox/TrimBox/
  BleedBox per page, and embedded-font detection (`FontFile`/`FontFile2`/`FontFile3`,
  Type0 descendants followed, Type3 correctly exempt, subset prefixes stripped).

---

## 4. What is already closed in the new proofer

Each of these was a real defect found on staging or in production. Do not regress them;
each has a named gate.

| Closed | What it was | Gate |
|---|---|---|
| **Approval checkpoint** (2026-09-08) | Approve required no acknowledgment. Now a second explicit acknowledgment whenever anything is flagged, recorded in the manifest. The gate reads `jobFlags()`, which scans **every page plus the file-level preflight** — a clean cover says nothing about page 5. The responsibility sentence sits with the button and appears nowhere else; do not remove it on small screens. | draft suite |
| **Prepress escape hatch** (2026-09-13) | Taking the escape hatch told nobody. Now the calculator posts `pps_prepress_review`; PHP stores staff-visible `PPS-Prepress-Review` item meta, turns the spec token into `PREPRESS-REVIEW`, raises an order note, and **drops any `pps_proof_hash`** so an unapproved job cannot look approved to the imposition tool. | `tools-prepress-flag-test.mjs` |
| **Per-slot uploads** (2026-09-13) | Never reached the proofer. Job now carries `slots` alongside `files`; applied after the whole-file sequence so a per-page choice wins where both cover a page; out-of-range pages ignored. `loadArt`/`loadArtSequence` made genuinely sequential — they used to fire and forget, which is why a second batch could not layer on the first. | `tools-proof-slots-test.mjs` |
| **Demo art on empty pages** (2026-09-13) | `SRCget()` drew the prototype's fake booklet on any page with no upload — and `buildPackage()` renders every page into PRINT_READY.pdf, so the approval hash bound to it and **the press would have printed it**. Hosted jobs now get a white page, one error-level `noart` finding per empty page, and a BLANK PAGES manifest section. Standalone keeps the placeholder. | `tools-proof-blank-pages-test.mjs` |
| **Silent Approve** (2026-09-13) | Tens of seconds of frozen UI reading as a crash. Now a determinate bar, the stage in words, the percentage on the button — and, the half that is easy to forget, **a yielded frame before each long block so the readout actually paints**. Failures name the step they stopped at. | `tools-proof-progress-test.mjs` |
| **Ordered-size check** (2026-09-13) | Warned on *every correctly bled file*. A print file with bleed is trim + 2 × bleed and usually carries no TrimBox. Its own no-TrimBox branch could never fire, because **pdf-lib answers `getTrimBox()` with the MediaBox when there is none** — so a declared trim must be read as one that *differs* from the page. | `tools-proof-size-check-test.mjs` |

**The lesson threaded through the last two is the most transferable thing in this
document:** a check that warns on good files is worse than no check, because it feeds
the acknowledgment gate and teaches people to tick past the one barrier between artwork
and a press.

---

## 5. Feature parity checklist — modal → proofer

Before the proofer can replace the modal anywhere, it must match these. Status is my
assessment; verify rather than trust it.

| Capability | Modal (saddle) | New proofer | Notes |
|---|---|---|---|
| Bleed / trim / safety guides | ✅ | ✅ | |
| Spine guide | ✅ | ✅ | Saddle-specific |
| Fold-line overlays | ✅ (brochure modal) | ❌ | Needed for brochure — see §8 |
| Magnifier with guides | ✅ | ✅ | |
| Hi-res 300 DPI render | ✅ | ✅ | |
| Per-page transforms (Crop/Fill/Fit/Stretch/Scale/Rotate) | ✅ `FitToggle` | ⚠️ partial | Confirm full parity before switching any product |
| Per-page slot uploads | ✅ | ✅ | |
| Grid / all-pages view | ✅ | ✅ | |
| 3D closed-book + spread preview | ✅ | ❌ | Saddle/PB only; arguably belongs in the calculator, not the proofer |
| Approval package: raw file | ✅ | ✅ | |
| Approval package: print-ready PDF | ✅ | ✅ | |
| Approval package: preview JPEGs with guides | ✅ | ✅ | |
| Approval package: manipulation manifest | ✅ | ✅ | |
| SHA-256 approval binding | ✅ | ✅ | Must survive any refactor |
| Acknowledgment gate on flagged checks | ✅ | ✅ | |
| Prepress escape hatch | ✅ | ✅ | |
| Hidden-layer (OCG) detection | ✅ `ppsAnalyzePdfRisks()` all 8 | ❓ verify | pdf.js honours the off state so proof and print agree, but an engine that drops `/OCProperties` prints them — this bit the imposition tool once |
| Embedded-font check | ❌ | ✅ | **Proofer is ahead here** |
| Honest placement DPI | ❌ (rubber stamp) | ⚠️ partial | See §7.1 — better, still blind to embedded images |
| Reader's-spread splitting | ⚠️ mothballed | ❌ | Machinery intact in the modal, control removed 2026-07-30 |

---

## 6. How to adapt the proofer from saddle to every product

The proofer is currently wired to exactly one calculator. Making it general is mostly a
data-modelling problem, not a rendering one.

### 6.1 The shape of the problem

Today the proofer assumes a **booklet**: an ordered page list, a spine, a page count with
padding, saddle-stitch imposition downstream. The eight products are really three
families:

| Family | Products | Proof shape |
|---|---|---|
| **Bound** | saddle, perfect bound, coupon book | Ordered page list, spine, page-count padding |
| **Flat, two-sided** | brochure, postcard, greeting card, letterhead | Exactly two faces (front/back), plus **fold geometry** on brochure |
| **Flat, repeated** | sticker | One face, step-and-repeat downstream |

### 6.2 The recommended route

1. **Make the job message describe the product, not a booklet.** Replace the implicit
   booklet assumption with an explicit surface descriptor:
   ```
   job.surfaces = [ { id, label, trimW, trimH, bleed, guides:[…] }, … ]
   ```
   A saddle job emits N page surfaces; a brochure emits two with fold guides; a sticker
   emits one. The proofer renders surfaces, not pages. This is the whole unlock — every
   other item is small once this exists.
2. **Generalise the guide layer.** Guides become data (`{type:'fold'|'spine'|'safety'|
   'trim'|'bleed', at, orientation}`) rather than booklet-specific drawing code. The
   proven fold renderers already exist in `brochure-fold-previewer.html` and
   `calc-brochure.html`.
3. **Port `composePageCanvas()` semantics into the proofer**, or have the proofer own
   composition outright and delete the modal's copy. Two composition engines is the
   condition that produced the class of bug the single-engine rule was written to stop.
   **Do not run both long-term.**
4. **Wire one flat product first — postcard.** It is the simplest: two faces, no folds,
   no spine, no page count. It will surface every booklet assumption baked into the
   proofer without fold complexity confusing the signal.
5. **Then brochure** (adds folds), **then perfect bound / coupon** (bound, but different
   imposition), **then greeting card / letterhead / sticker**.
6. **Retire the modal per product, not globally.** `PCF.proof_url` is already a per-site
   knob; make it per-calc-type so products can cut over one at a time and roll back
   individually.

### 6.3 Rollout mechanics

- The knob is **blank = off**. That is the rollback, and it is instant.
- Deploy the proofer to a server *before* enabling it anywhere — it is inert while the
  knob is blank. This has already been done on both servers.
- Each product cutover needs its own pass of the six proofer suites plus that product's
  own gates.
- **Never enable on production before staging has run a real order through it** end to
  end, including the Drive upload and the imposition hash check.

---

## 7. What is still missing — prioritised

### 7.1 Neither surface can see a low-resolution image inside a PDF — **highest priority**

This is the live one. It has already cost a printed job (§9.1).

- **The modal rubber-stamps.** In `calc-preview-test.html`, the resolution preflight is:
  ```js
  if (source === "pdf") {
    checks.push({ level:"pass", id:"res", label:"Resolution",
                  detail:"PDF source — vector/high resolution" });
  }
  ```
  Nothing is measured. Images get a real computed DPI with warn/fail tiers; PDFs get a
  pass on the strength of the file extension. **A PDF is a container** — it can hold a
  72-DPI screenshot of a QR code.
- **The proofer measures the wrong thing.** Its `effDpi` divides the held raster's pixel
  width by the placed width. But `src.el` is the proofer's *own* render at a fixed
  `PDF_RENDER_DPI = 150`, so it is always as crisp as the proofer chose. It honestly
  reports over-scaling; it cannot report an image that was low-resolution inside the file.

**The fix, precisely.** Extend `readPdfStructure()` to report, per page, the effective
DPI of each placed raster:

1. `page.getOperatorList()` from pdf.js.
2. Walk the ops maintaining a graphics-state stack — `OPS.save`, `OPS.restore`,
   `OPS.transform` — so you have the current transformation matrix at every point.
3. On `OPS.paintImageXObject` / `OPS.paintInlineImageXObject`, look up the image's
   intrinsic pixel dimensions and multiply the unit square by the CTM to get its
   device-space footprint in points.
4. `effectiveDpi = imagePixelWidth / (footprintWidthInPoints / 72)`.
5. Take the **minimum** across images on the page, and report it next to the page's
   physical size.

**The trap, and it is the whole reason this is not already done:** if you get the CTM
tracking subtly wrong you will produce a check that warns on good files. This codebase
has been burned by exactly that once already (the ordered-size check, §4), and the
consequence is worse than having no check — it feeds the acknowledgment gate and trains
people to click past it. **Ship this behind a test that proves it stays silent on a
correct file before you let it raise anything.** Build fixtures: a true-vector QR, a
300 DPI placed QR, and a 72 DPI placed QR at the same physical size. The first two must
stay silent; the third must fire.

### 7.2 Only the saddle calculator is wired

Seven of eight have zero references to `proof_url`, `ppsOpenProof` or
`composePageCanvas`. §6 is the plan.

### 7.3 The knob does not exist on either server

`proof_url` lives in the **repo's** `pps-config-admin.php` only. Production runs a copy
without it (117,942 bytes, dated 2026-09-06) and staging an older one still. Turning the
proofer on anywhere means deploying that file first, then setting **PPS Config →
Production → New Proof URL** by hand.

**Do not write it into `pps_calc_config` directly** — that option carries live
credentials and must never be bulk-copied or blind-edited.

### 7.4 The "I don't have bleeds" answer reaches neither surface

The customer is asked. Both surfaces then detect bleed geometrically from the art and
ignore the selection. Inside the proofer, the default crop behaviour scales art to fill
the bleed, so the geometric check cannot fire at all — it only catches art that is
letterboxed. What the customer sees instead is the *consequence*: type warnings near the
trim. Worth closing by honouring the stated answer rather than by adding another
geometric check.

### 7.5 The hardcopy proof address is collected and thrown away

Not strictly the proofer, but it is in the proof flow and it is costing real information
today. When `proof >= 3` the calculator shows a same/different toggle and a full address
form:

```js
const [proofAddrSame, setProofAddrSame] = useState(true);
const [proofAddr, setProofAddr] = useState({name:"",street:"",city:"",state:"",zip:""});
```

`proofAddr` appears **11 times in the entire file** — twice declaring state, nine times
wiring inputs. **Zero times** in `buildMetadata`, `buildSummary` or
`submitToWooCommerce`. So a customer who types a different proof address produces a
payload byte-identical to one who did not. It has never reached an order.
`proofAddrSame` is also not seeded from `D`, so reorders do not restore it.

### 7.6 Two composition engines

Until the proofer owns composition outright, saddle has one engine and the proofer has
another. That is the precondition for the exact class of bug the single-engine rule
exists to prevent. Treat as a standing risk until §6.2 step 3 lands.

### 7.7 `tools-parity-layers.mjs` cannot run

Its hand-authored `layered.pdf` fixture was never committed, so the hidden-layer
regression test is dead. Either commit a fixture or generate one in the test.

---

## 8. Invariants — break these and you will not find out for weeks

1. **Same-origin only** for the handshake. It carries artwork and the approval hash.
2. **The proofer never uploads.** The host owns everything after approval.
3. **The manifest is the approval marker**, not the print-ready PDF.
4. **`ppsProofFilesToOrder()` is the only place** proofer names become pipeline names.
5. **Approval is SHA-256-bound** to the print-ready bytes; the imposition tool enforces it.
6. **The acknowledgment gate reads `jobFlags()`** — every page plus file-level preflight,
   never just the visible page.
7. **The responsibility sentence lives with the Approve button** and nowhere else. Do not
   drop it on small screens.
8. **Pages carry `srcPdfPage`** and the composition vector branch renders by it, never by
   display index. Slot replacement and reconciliation reorder display positions.
   `splitReaderSpreads()` deliberately does *not* set it, so split spreads compose from
   raster — that is intentional and documented, not a bug.
9. **The approval gate applies only to `proof === 0`.** Gating paid proofs blocked uploads
   on all eight calculators once.
10. **Never write a literal `</script>`** inside a `text/babel` block; escape as `<\/script>`.
11. **The style suite must keep reading the palette from `calc-preview-test.html`.**
    Copying it defeats the purpose.

---

## 9. Case studies

### 9.1 Order 87152 — the QR code that printed unscannable

**What happened.** A customer uploaded a 12-page **vector** PDF at 4.5″ × 4.5″ for a 4″
trim with 0.125″ bleed (bleed box 4.25″). She applied **no transforms** — all twelve
pages `fit=crop rotate=0° scale=100%`. The job printed and shipped. A QR code on page 2
came out unscannable.

**The proximate trigger.** The raw-file fast path requires art within tolerance of the
bleed size:

```js
Math.abs(ed0.wIn - bleedW) < 0.25     // |4.5 − 4.25| = 0.25
```

`0.25 < 0.25` is false. **She missed the untouched path by exactly zero.** So all twelve
vector pages were re-rendered and written into the print-ready PDF as JPEG q0.95 — an
11 MB vector file became a 44 MB raster one.

**Why that is not the root cause.** Another order the same week (87202, a 2000-copy zine)
went through the *identical* raster path and its QR came out fine. Same treatment,
different outcome — so the rasterisation cannot be the cause. The difference is in the
files, and the likeliest explanation is that her QR was a low-resolution placed bitmap
inside the PDF, which §7.1 explains neither surface can see. **Her preflight said
"Resolution: PDF source — vector/high resolution."**

**Three lessons.**
- Being *more* generous than the spec (0.25″ bleed instead of 0.125″) is what pushed her
  over a tolerance and off the lossless path. Tolerances that punish good files are bugs.
- **Every downstream guard worked perfectly, and that is the worst part.** The hash bound
  to the rasterised bytes, the imposition tool verified it, matched, and imposed. The
  chain faithfully printed exactly what it had shown her. Integrity guarantees protect
  correctness of *transport*, not correctness of *content*.
- A reprint from the same artwork will fail identically. Diagnose the file before
  committing press time.

### 9.2 Demo art on unsupplied pages

`SRCget()` drew the prototype's placeholder booklet on any page with no upload, and
`buildPackage()` renders **every** page into PRINT_READY.pdf. The approval hash bound to
it. Had this shipped, a press would have printed a fake Capoeira programme inside a
customer's booklet, and every integrity check would have passed. Found on staging by
looking, not by a test. Now gated.

### 9.3 The ordered-size check that warned on everything

Covered in §4. The reason it matters here: it was *feeding the acknowledgment gate*. A
noisy check does not merely annoy — it actively destroys the one deliberate pause between
a customer and a press.

---

## 10. Open questions for the owner

1. **Should the proofer own composition outright**, deleting the modal's engine? My
   recommendation is yes, but it is a real decision with a real risk window.
2. **Should `proof_url` become per-calc-type** so products cut over one at a time?
   Recommended, and it is the cheapest way to de-risk the rollout.
3. **Does the 3D book preview belong in the proofer or stay in the calculator?** It is a
   sales aid, not a proofing surface. I would leave it in the calculator.
4. **What is the reprint policy** when the customer's own file is at fault but our
   preflight told them it was fine? §9.1 is the live instance.

---

## 11. First moves for a new session

1. Read this file, then `CLAUDE.md` §"Proofing — two surfaces, one of them dark".
2. Populate `proof-vendor/`, start `tools-proof-serve.mjs`, run all ten suites. They
   should be green. If they are not, fix that before anything else.
3. Do **not** enable `proof_url` anywhere until §7.1 and §7.4 are closed.
4. If you are adding a preflight check, write the test that proves it stays **silent on a
   correct file** first. That is the discipline this system was missing.
