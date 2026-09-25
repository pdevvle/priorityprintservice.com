# The new proofer — build brief

**Written:** 2026-09-25. **For:** whoever builds the next proofer, most likely a Claude
session arriving with no context, working for a print shop owner who has no terminal.

**Relationship to the other brief.** `docs/PROOFER_BRIEF.md` is the *handover*: what exists
today, where it lives, how to run its suites and what is broken. This document is the *build
brief*: what the new proofer has to guarantee, how each guarantee will be proved, and the
order to build it in. Read this one first for direction, that one for the code.

---

## 1. The job, in the printer's words

A customer sends artwork. A press prints it. Between the two there is one chance to catch
a mistake before it is made a thousand times. The proofer is that chance, and it serves
three people:

- **The customer** needs to see honestly what will print, be told plainly what looks wrong,
  and be able to finish their order even when something does.
- **Production** needs every file the customer sent, the file that will actually print, and
  a clear record of what the customer agreed to, without opening a JSON blob.
- **The owner** needs to hear about the jobs that need a person *before* the customer calls,
  and to never again learn about a software fault from a reprint.

Everything below serves one sentence, carried over from the handover brief:

> **What the customer approved is what gets printed, and they had a fair chance to see
> what was wrong with it.**

September 2026 added a second one, which this brief treats as equally binding:

> **Everything the customer gave us reaches the order, whether or not anyone approved it.**

---

## 2. What the last two months taught — each incident is a requirement

Every row below is a real order. None was found by our own checks; every one was reported by
a customer or found while investigating one. The right-hand column is what the new proofer
owes because of it.

| Order | What happened | Requirement |
|---|---|---|
| **87032** | The screen proof showed the whole image; the print file, drawn by different code, cropped to the centre 32%. | **R3** One composition engine for screen and print. |
| **87042 / 87045** | Orders reached production with no approval package at all. | **R4** Approval is a recorded, hash-bound event, never an assumption. |
| **87154** | Approved in greyscale on screen, filed to Drive in colour — grey was a CSS filter the print path never applied. | **R3** again. Anything the customer sees must come from the function that makes the print file. |
| **87152** | A QR code reported unscannable. The saddle's print path resampled every page on a half-pixel offset, and a good file missed the lossless path by exactly 0.25″. | **R1** Print from source at print resolution, on whole pixels. **R1b** A lossless path whose tolerance does not punish correct files. |
| **87171** | A brochure's QR code would not scan. The five flat calculators printed the 144 DPI *screen preview*, blown up, while the manifest said "300 DPI" because a constant said so. | **R1** again, and **R10** A resolution claim is a measurement of the shipped file, never a constant. |
| **87198** | A hardcopy proof was bought with a different ship-to; the booklet calculators never sent the address. | **R5** Paid proofs are a different path, and their details reach the order. |
| **87202** | A self-cover booklet read "cardstock" on the order: the server guessed words from numbers. | **R9** The order says what the customer chose, in the calculator's own words. |
| **87272** | Our own new print-file check raised a false low-resolution alarm on a correct InDesign file. | **R6** Every check is proven silent on good files before it may speak. |
| **87273** | A coupon book, staff proof, twenty images chosen at once. The calculator showed all twenty pages; production received one file, `1.jpg`. The upload handler passed `fileList[0]` to the order; the full list was only built when a customer self-approved. In the code since March. | **R2** Every original reaches the order, byte-identical, regardless of proof type or approval state. |

**The pattern behind all nine:** two pieces of code that were supposed to agree, and nothing
that checked they did. Screen vs print. The flats vs the booklets they were copied from. What
the customer chose vs what the order carried. What the manifest claimed vs what the file was.
The new proofer has to be built so that each of those pairs is either **one piece of code**
or **measured by a test on the shipped output**.

---

## 3. Requirements

Each requirement names the test that proves it. A requirement without a passing test is not
met, however plausible the code looks. Where a gate already exists, it is named; where it does
not, writing it is part of the requirement.

### R1 — Print from the source, at print resolution

- Each page of the print file is rendered **from the customer's own bytes** (the PDF page, or
  the image at native size) at the print DPI, inside the package build. Never from a
  thumbnail, preview, or anything drawn for the screen.
- Pages are chosen by **`srcPdfPage`** carried from extraction, never by display index —
  reconciliation, slot replacement and back-cover moves all change display order.
- Source is placed on **whole pixels**. A 1:1 placement is a copy, not a resample.
- **R1b — lossless path.** When the art needs no transform, ship the customer's own PDF bytes
  (or a `copyPages` crop) instead of rasterising. Fix the saddle's `< 0.25` tolerance at the
  same time: a file with 0.25″ bleed for a 0.125″ spec is a *correct* file.

**Proved by:** `tools-book-print-dpi-test.mjs` and `tools-flat-print-dpi-test.mjs`, pointed at
the proofer's own PRINT_READY.pdf — a QR at the customer's module size decodes on every page,
mid-grey share under 5%, sheet at the stated DPI. Run once against the current proofer first:
it renders from a 150 DPI source (handover §7.0) and must fail.

### R2 — Every original reaches the order

- Every file the customer supplied — a multi-file selection in full, per-page slot files
  (named `page_NNN_<name>`), a separate wraparound cover, reference files — is uploaded to
  the order **byte-identical**, as the first deliverables, before anything generated.
- This holds for **every** proof type and approval state: self-approved, staff digital proof,
  hardcopy proof, un-approved after a change, and the prepress escape hatch.
- One function builds that list (`allOriginals()` in the booklets since 2026-09-25). Every
  emit to the order calls it. No emit builds its own list.

**Proved by:** `tools-multi-file-upload-test.mjs` — twenty images on a staff-proof order, all
twenty uploaded in order; a slot replacement keeps the twenty and adds the page file. When a
product moves to the new proofer, the same test runs through the proofer path.

### R3 — One composition engine

- Screen proof, magnifier, thumbnails, 3D preview, preview JPEGs and the print file all take
  their pixels from **one** function. They may differ in DPI only.
- Greyscale, rotation, fit mode, guides: applied in that function, never by CSS on top.

**Proved by:** the parity suites (`tools-parity-saddle.mjs`, `-extended`, `-findings`, run
with the production theme loaded) and `tools-preview-greyscale-test.mjs`.

### R4 — Approval is bound, and every change undoes it

- Approval is the SHA-256 of the print-ready bytes. The manifest records it, the order line
  carries `_pps_proof_hash`, and the imposition tool refuses a mismatch.
- **Every change that alters what prints revokes approval on the order, not just on screen**:
  transforms, spec changes, slot changes, a new wraparound cover, the artwork option, the
  Review button, edits during generation. Perfect bound and coupon book cleared approval in
  their panel but not on the order until 2026-09-25, so a changed job could ship its old
  approved package.
- The manifest, not the presence of a print-ready PDF, is the approval marker.

**Proved by:** `tools-multi-file-upload-test.mjs` ("approve, then Review" is refused at Add to
Order), the proofer's draft suite, and `tools-prepress-flag-test.mjs` for the hash-drop rule.

### R5 — Paid proofs are review-only

- The job carries `proof`. When the customer bought a staff digital or hardcopy proof, the
  proofer shows the art and the checks but **no Approve button and no responsibility
  sentence**; a person on our side approves later.
- The hardcopy ship-to travels in the order and prints on the Job Ticket.

**Proved by:** a new check in the proofer's draft suite (`job.proof = 0.01` → no Approve, no
hash posted), plus `tools-job-ticket-test.mjs` for the ship-to.

### R6 — Checks that are silent on good files

- Every preflight check ships behind a fixture that proves it **stays silent on a correct
  file** before any fixture proves it fires. A check that warns on good files trains people
  to tick past the one gate before the press.
- Flagged checks require an explicit acknowledgment before Approve, read across **every page
  plus file-level checks**, recorded in the manifest.
- **Measured image resolution inside a PDF.** Walk the operator list with a graphics-state
  stack, take each image's device-space footprint, report pixels ÷ inches. Three fixtures:
  a true-vector QR and a 300 DPI placed QR stay silent; a 72 DPI placed QR at the same size
  fires. (Handover §7.1 has the algorithm and the trap.)
- **Hidden layers:** warn only on optional-content groups switched OFF, never on the presence
  of layers.

**Proved by:** `tools-proof-ui-preflight-test.mjs`, `tools-proof-size-check-test.mjs`, and new
fixtures for image DPI and layers-all-on.

### R7 — All eight products, as three families

| Family | Products | What the proof surface must show |
|---|---|---|
| **Bound** | saddle, perfect bound, coupon book | Ordered pages, spine guide, reconciled page order (back cover stays the back cover); PB and coupon add an optional wraparound cover surface (2 × trim + spine, spine-boundary guides). |
| **Flat, two-sided** | brochure, postcard, greeting card, letterhead | Front and back; fold lines on all but letterhead; 3D fold preview. |
| **Flat, repeated** | sticker | One face; step-and-repeat is downstream. |

The job describes **surfaces**, not a booklet:
`job.surfaces = [{ id, label, trimW, trimH, bleed, spineW?, guides:[{type, at, orientation}] }]`,
plus the reconciled page order and `proof`. Guides are data, drawn by one function into the
screen and the previews alike.

**Proved by:** per product, the integration round trip (`tools-proof-integration-test.mjs`
extended per family) plus that product's own gates (section 5).

### R8 — Failure is safe, and never strands a customer

- The proofer runs in a same-origin frame, so a throw inside it leaves the calculator
  standing. (It is not a memory or main-thread boundary; a hang still takes the tab.)
- If a proof cannot be prepared, the customer can still order: the **prepress escape** sends
  every original, marks the order `PREPRESS-REVIEW`, drops any hash, notes the order, and it
  shows in the daily exceptions email.
- A proofer URL that does not load is **not** a fallback today — it blocks the order. The
  cutover procedure (handover §6.3) is to fetch the URL as a logged-out visitor and confirm
  `ready` before enabling.

**Proved by:** `tools-prepress-flag-test.mjs`, `tools-proof-embed-test.mjs`.

### R9 — The order reads like a job ticket

- The proofer's result feeds the **Job Ticket** on the order: art status in words
  (self-approved and hash-bound / awaiting staff proof / NOT APPROVED — prepress review), the
  file names, what the print file was rendered from.
- Words come from the calculator's own option tables, never inferred on the server.
- Add-ons appear as their own line in words.

**Proved by:** `tools-job-ticket-test.mjs`, `tools-addons-meta-test.mjs`.

### R10 — The manifest tells the truth about the file

- The manifest names, per page, **what the print pixels came from**
  (`print source: pdf page 3 rendered at 300 DPI`, `raw file shipped untouched`,
  `image 4.jpg at native 938 DPI`), the transforms, the acknowledgments and the hash.
- No number in the manifest comes from a constant alone.

**Proved by:** the print-DPI suites read the manifest line and compare it to the decoded file.

### R11 — Honest about time

- Anything over a second shows a determinate bar, the stage in words, and **yields a frame
  before each long block so the bar actually paints**. A failure names the step it stopped at.
- The customer can choose **"Render at full resolution (slow to load)"** to see the 300 DPI
  render on screen and under the loupe, with a status bar while it works.

**Proved by:** `tools-proof-progress-test.mjs` (records every value the readout holds, so a bar
that updates but never paints fails), and the full-resolution check in
`tools-flat-print-dpi-test.mjs`.

### R12 — Phone-sized

The customer approves on a phone as often as on a desk. Touch targets, the responsibility
sentence beside the button on small screens, no horizontal scroll.

**Proved by:** `tools-proof-ui-mobile-test.mjs`.

---

## 4. Decisions already made — do not reopen without cause

- **Framed, not ported.** One same-origin iframe. The calculators load pdf.js 4.x as ESM and
  the proofer pins 3.11 UMD; both claim `window.pdfjsLib`. The frame also keeps the proofer's
  suites testing the file that ships.
- **The proofer never uploads.** It hands back files; the calculator owns upload, order meta
  and cart. `ppsProofFilesToOrder()` is the only place proofer names become order names.
- **Same-origin handshake only**, checking origin and source. In: `pps-proof:job`. Out:
  `ready`, `error`, `approved`, `approve-failed`, `escape`, `close`.
- **An admin setting turns it on, per product.** Today `PCF.proof_url` is one site-wide value;
  per product it should be injected by PHP from the calc type (handover §7.3).
- **Everything stays in this repo.** The proofer, calculators, PHP and tests are coupled by
  tests that read each other; splitting them is a migration, not a file move.

---

## 5. Build order, with the gate that ends each phase

Nothing moves to the next phase until its gate is green on the **compiled** builds.

**Phase 0 — make the current proofer print-honest (saddle only).**
Close handover §7.0 (print from the PDF at print DPI, lossless path, tolerance fix), §7.4
(`proof` in the job, review-only), §7.6 (layers: OFF groups only), §7.7 (reconciled page
order in the job). Add the originals list (R2) to the saddle's proofer path.
*Gate:* the eleven proofer suites; `tools-book-print-dpi-test.mjs` against the proofer's
PRINT_READY.pdf; `tools-multi-file-upload-test.mjs` through the proofer; parity ×3.

**Phase 1 — one real saddle order on staging, end to end.**
Enable the setting on staging only. A real order: upload, proof, approve, pay, Drive folder
holds every original + print-ready + previews + manifest, imposition tool accepts the hash,
Job Ticket reads correctly, the daily email shows nothing it should not.
*Gate:* that order, inspected file by file, written up in the handover's case studies.
Only then enable on production for saddle.

**Phase 2 — surfaces job model, letterhead first.**
Letterhead is the only flat without folds, so it exposes the booklet assumptions without the
fold engine. Relax `validateJob`; per-family names in `ppsProofFilesToOrder`
(`_preview_front.jpg` / `_back.jpg`).
*Gate:* flat print-DPI suite through the proofer; integration round trip for letterhead.

**Phase 3 — folding flats:** postcard, greeting card, brochure. Fold guides as data; the
proven 3D fold renderers from `brochure-fold-previewer.html`.
*Gate:* as phase 2, plus fold-line positions checked in the preview JPEGs.

**Phase 4 — perfect bound and coupon book,** including the wraparound cover surface and
reconciled page order.
*Gate:* book print-DPI suite, multi-file suite, slot suite, all through the proofer.

**Phase 5 — sticker.**

**Phase 6 — retire the old modal, one product at a time,** only after that product has run
real orders on production through the new proofer for two weeks with nothing in the
exceptions email that traces to it.

---

## 6. Definition of done, per product

A product is on the new proofer when all of these are true — and each is checked, not assumed:

- [ ] A real order on staging, every Drive file opened and compared to what was uploaded.
- [ ] The print file decodes: QR at the customer's module size, every page, under 5% mid-grey.
- [ ] Every original is in the Drive folder, byte-identical, including multi-file and slot uploads.
- [ ] A staff-proof order shows no Approve button and carries every original.
- [ ] Changing anything after approval blocks Add to Order until re-approved.
- [ ] A correct file raises no warnings; a known-bad file raises the right one.
- [ ] The Job Ticket names the art status, files and print source in words.
- [ ] The prepress escape works and the order shows NOT APPROVED.
- [ ] The phone layout passes the mobile suite.
- [ ] `CLAUDE.md` and the handover brief updated in the same commit.

---

## 7. Things not to do

- **Do not trust a constant.** "Renders at 300 DPI" was a line of code for five months while
  the file was 144 DPI. Decode the output.
- **Do not fix one copy.** The calculators are copies; a fix in one does not reach the others,
  and nothing complains. After any change to shared machinery, grep all eight, and run the
  function-hash sweep described in `CLAUDE.md` ("Shop closures").
- **Do not ship a check that has only been shown to fire.** Prove it silent on a good file first.
- **Do not build an emit list by hand.** Call the one function that lists the originals.
- **Do not enable the setting on production before a staging order has gone all the way
  through**, Drive and imposition included.
- **Do not read or write `pps_calc_config`** from a tool; it carries live credentials.
- **Do not patch a server by hand.** Commit, then deploy pinned to the commit.

---

## 8. Decisions the owner still has to make

1. **Should the new proofer own composition outright** once it has a lossless path, deleting
   the old modal's engine? Recommended — two engines are how 87032 and 87154 happened — but
   only after R1b exists, because the old engine holds the only lossless path today.
2. **The 9-point anchor grid vs free positioning.** The new proofer positions art on a
   nine-point grid; the old modal allows free positioning. Is the grid enough for your
   customers?
3. **One 3D preview or two?** Both the calculator and the proofer have one.
4. **Reprint policy** when the customer's file is at fault but our checks passed it.

---

## 9. Where to start

1. Read `CLAUDE.md`, then `docs/PROOFER_BRIEF.md` §1 (where things are) and §7 (what is broken).
2. Run the suites exactly as handover §1.3 describes, plus `tools-book-print-dpi-test.mjs`,
   `tools-flat-print-dpi-test.mjs` and `tools-multi-file-upload-test.mjs`. All green before you
   change anything.
3. Start Phase 0 with R1: point the book print-DPI suite at the proofer's PRINT_READY.pdf and
   watch it fail. That failure is the first thing to fix.
