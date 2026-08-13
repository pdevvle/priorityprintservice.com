# Art-Approval Checkout Gate — Bug Brief & Fix Spec

> **Status:** root-caused, not yet patched. Confirmed present in all 8
> calculators. Triggered live on order #87003 (production) — customer
> uploaded artwork, checked out, and only the raw file reached Google
> Drive; no print-ready PDF was ever generated, so the Imposition Tool
> queue showed "NO ART" even though a file existed.

---

## 1. The bug

Every calculator lets a customer upload artwork and, separately, open an
**Artwork Proof modal** to review bleed/trim/safety guides and click
**Approve** — which is what actually generates the print-ready PDF,
preview JPEGs, and manipulation manifest (`generateApprovalPackage()`).

**Nothing requires that second step to happen before checkout.** A
customer can upload a file and click "Add to Order" immediately, without
ever opening the proof modal. The order completes successfully with only
the raw uploaded file — no PDF, no manifest — because the raw file
registers with the parent component the instant it's selected, entirely
independent of approval:

```js
// fires on file select/drop, BEFORE the proof modal ever opens
setFrontArt({dataUrl,file,dims});
setApproved(false);
if (onArtwork) onArtwork({type:"files", list:[rawFile]});
```

and the order-placement handler never checks `approved` or looks at what
`artFiles` actually contains:

```js
const handleAddToOrder = async () => {
  if (!result || result.error || submitting) return;
  if (missingDest) { openShipping(); return; }
  // ... no artwork/approval check at all ...
  await submitToWooCommerce(result.total, summary, metadata, rushCost, artFiles, ...);
};
```

`submitToWooCommerce` ships whatever `artFiles` currently holds — which,
if the proof modal was never opened, is just the raw upload.

### A related, separate red herring

`PPS-Spec`'s proof-tier label — `SelfApproved` vs `DigitalProof` vs
`Hardcopy` — comes from the customer's chosen **proof-tier** setting
(`proof === 0` in the config), a purely business decision about whether
staff sends a formal review copy. It has **no relationship** to whether
the in-tool Approve button (PDF generation) ever ran. An order can be
`SelfApproved` and have zero interaction with the proof modal. Don't
conflate the two when reading a spec string — `SelfApproved` does not
mean "the PDF was generated and reviewed," it means "no formal proof
requested."

## 2. Evidence (order #87003, production, 2026-08-12)

- Order placed 2026-08-12 20:30 UTC. `PPS-Spec` shows `Upload Art with
  Order | SelfApproved`.
- Drive folder `Order #87003 — Erica Yi` contains exactly one file:
  `Untitled_Artwork-1-1.JPG`, created **2026-08-13 03:31 UTC — ~7 hours
  after the order**. No `_print-ready.pdf`, no preview JPEGs, no
  manifest.
- The Imposition Tool queue (`imposition-tool.html`) only matches
  `.pdf` files when picking artwork for a job (`pps_impose_pick_artwork()`
  / `pps_impose_item_artwork()` in `pps-imposition.php`, both gated on
  `preg_match('/\.pdf$/i', $name)`), so it correctly found the folder but
  matched nothing, and rendered "none found" / a "NO ART" badge.
- The 7-hour gap and file-type both point at the same explanation: the
  customer uploaded, never opened/completed the proof modal, and checked
  out — the PDF pipeline never ran. (The JPG's timestamp likely reflects
  a later manual recovery step by staff, not the calculator's own
  synchronous upload.)

## 3. Scope — confirmed present in all 8 calculators

Every calculator has its own inline copy of this flow (no shared JS
module — see architecture note in `CLAUDE.md`), so the vulnerable pattern
had to be checked file-by-file. All 8 have `generateApprovalPackage()`
and **none** gate `handleAddToOrder` on `approved`:

| File | `generateApprovalPackage` | `handleAddToOrder` checks `approved`? |
|---|---|---|
| `calc-preview-test.html` (saddle stitch) | yes | **no** |
| `calc-perfect-bound.html` | yes | **no** |
| `calc-brochure.html` | yes | **no** |
| `calc-coupon-book.html` | yes | **no** |
| `calc-postcard.html` | yes | **no** |
| `calc-sticker.html` | yes | **no** |
| `calc-letterhead.html` | yes | **no** |
| `calc-greeting-card.html` | yes | **no** |

The fix must be applied to all 8 — this is a copy-pasted pattern, not a
shared function, so there's no single-file fix.

## 4. Proposed fix

### Client-side gate (primary fix, all 8 files)

Add an artwork-approval check to `handleAddToOrder`, using the same
early-return + redirect-to-fix-it idiom already used for `missingDest`:

```js
const handleAddToOrder = async () => {
  if (!result || result.error || submitting) return;
  if (missingDest) { openShipping(); return; }
  if (artFiles.type === "files" && !approved) {
    setProofOpen(true);   // or whatever opens the Artwork Proof modal in this file
    return;
  }
  // ... existing body unchanged ...
};
```

**Gate only on `artFiles.type === "files"`** — i.e. only when there's a
fresh raw upload this session that hasn't been through the approval
package yet. Do **not** gate on:
- `artFiles.type === "none"` — customer chose to email art later / already
  discussed / Canva-link-only. No file to approve.
- `artFiles.type === "existing"` — reorder flow reusing a previously
  approved file. Already has a real PDF from the original order.

**Do not require staff/human review.** `generateApprovalPackage()` is a
synchronous, client-side, instant action — the fix only needs to force
that one click to happen, not add a wait-for-staff step. `SelfApproved`
(skip formal proof) must keep working exactly as before; this only
closes the gap where "self-approved" was being read as "never approved
at all."

Pair the gate with a visible inline message near the upload/proof area
(existing pattern already shows a similar "Open the proof view to review
and approve your artwork" message when `proofOpen && !currentArt` — reuse
that string/placement) so the customer understands why nothing happened
when they click "Add to Order," matching how `missingDest` already
scrolls/focuses the shipping section rather than failing silently.

### Server-side backstop (optional hardening, phase 2)

Not required for the immediate fix, but worth doing given this is a
production-cost problem (wasted jobs, customer back-and-forth), not just
a UX nicety: in `pps-calculators.php`'s cart-add handler, reject (or flag
for review) an item whose `pps_artwork_files` payload contains a raw file
but no `*_print-ready.pdf` entry, as defense-in-depth against a bypassed
or stale client. Scope this separately — it touches the add-to-cart AJAX
contract and needs its own review of what should happen to the request
(hard reject vs. silent order-note flag for staff).

## 5. Edge cases to verify before shipping

- **Front+back proof:** confirm one Approve click in the modal covers
  both sides (it does, per current code — `generateApprovalPackage()`
  processes all of `pageSources`, front and back together, in one call;
  no separate per-side approval needed).
- **Canva-link-only submissions:** confirm `artFiles.type` is `"none"`
  (not `"files"`) when the customer only provides a Canva link with no
  file upload, so the gate doesn't wrongly block them.
- **Reorder flow:** confirm `artFiles.type === "existing"` is set
  correctly when reusing prior artwork, so returning customers aren't
  forced through the proof modal again for art that's already approved
  and in production-ready form.
- **Failed `generateApprovalPackage()`:** the existing `try/catch` around
  the Approve button already surfaces an alert on failure and leaves
  `approved` false — confirm the new gate then correctly re-blocks
  checkout and reopens the modal, rather than leaving the customer stuck
  with no path forward.
- **Mobile:** the proof modal is `React.createElement(Float,...)` a
  full-screen overlay — confirm `setProofOpen(true)`-on-gate doesn't fight
  with any mobile-specific scroll/focus behavior the way `openShipping()`
  already has to handle.

## 6. Files to touch

`calc-preview-test.html`, `calc-perfect-bound.html`, `calc-brochure.html`,
`calc-coupon-book.html`, `calc-postcard.html`, `calc-sticker.html`,
`calc-letterhead.html`, `calc-greeting-card.html` — each file's own
`handleAddToOrder` function. Exact variable names (`setProofOpen` vs.
whatever each file's modal-open setter is called) need to be confirmed
per file rather than assumed identical.

## 7. Test plan

Per calculator, before/after:
1. Upload art, do **not** open the proof modal, click "Add to Order" —
   must now be blocked and redirected into the proof modal instead of
   completing.
2. Upload art, open the proof modal, click **Approve**, then "Add to
   Order" — must complete normally, with raw file + `_print-ready.pdf` +
   previews + manifest all present in the resulting Drive folder.
3. Choose "email art later" / no upload, click "Add to Order" — must
   complete normally, ungated (regression check on the `type==="files"`
   scoping).
4. Reorder flow with existing approved art — must complete normally,
   ungated.
5. Confirm the Imposition Tool queue shows a real `art_file_name` (not
   "none found") for a fresh test order created via step 2.

## 8. Deploy

Calculator HTML changes go out via the normal `pps-pricing-config`
branch → GitHub Pages preview → staging/production HTML deploy process
(see root `CLAUDE.md`, "Branch & Deploy"). No PHP changes required for
the client-side fix (Section 4, primary). If the Section 4 (phase 2)
server-side backstop is added later, that's a `pps-calculators.php`
change and follows the "repo first, then deploy" rule in `CLAUDE.md`
("Server-side patches must come home the same session").
