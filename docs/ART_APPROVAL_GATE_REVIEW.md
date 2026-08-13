# Review — `docs/ART_APPROVAL_GATE_BRIEF.md`

Reviewed against the code on `claude/optimistic-wozniak-11ql3y` @ `4feecb5`.

**Verdict: the bug is real, the diagnosis is correct, and the fix as written
will not compile.** Three corrections below, one of which is a
customer-blocking risk that has to be resolved before this ships.

## What the brief gets right

- The gap is genuine: the raw file registers with the parent on select,
  independent of approval, and `handleAddToOrder` never inspects approval.
- **All 8 calculators confirmed affected** — verified independently. Every
  file has `generateApprovalPackage()`; none gates checkout on approval.
- The `SelfApproved` red herring is called correctly. The proof *tier* and
  the in-tool Approve click are unrelated, and conflating them would send
  the next reader down the wrong path.
- Scoping the gate to fresh uploads only — not `"none"` (email-later /
  Canva) and not `"existing"` (reorder) — is right.
- Refusing to add a staff-review wait is right. `generateApprovalPackage()`
  is synchronous and client-side; this only needs to force one click.

---

## Correction 1 — the proposed code cannot work: `approved` is not in scope

The brief's fix reads `!approved` and calls `setProofOpen(true)` inside
`handleAddToOrder`. Neither identifier exists in that scope.

The approval flag lives in the **artwork/proof child component**, thousands
of lines above `App()`, with module-level function declarations in between:

| File | approval state | line | `handleAddToOrder` | `App()` starts |
|---|---|---|---|---|
| `calc-preview-test.html` | `artApproved` | 2677 | 6120 | 5346 |
| `calc-coupon-book.html` | `artApproved` | 2691 | 5686 | ~4900 |
| `calc-brochure.html` | `approved` | 1855 | 4978 | 4254 |

`handleAddToOrder` is inside `App()`. The approval state is not. Dropping
the snippet in produces a ReferenceError, not a gate.

Also note the identifier split the brief flagged as "needs confirmation" —
it is a clean 3/5, worth pinning before anyone starts:

| Family | Files | Approval setter | Modal opener |
|---|---|---|---|
| Booklet | preview-test, perfect-bound, coupon-book | `setArtApproved` | `setActiveModal("proof")` |
| Flat | brochure, greeting-card, letterhead, postcard, sticker | `setApproved` | `setProofOpen` |

### The fix that does work — infer approval from `artFiles`, don't lift state

Lifting a boolean through the component boundary in 8 hand-maintained files
is the expensive, error-prone option. The parent already has the signal:
**after approval, the child calls `onArtwork` again with the full package.**
Before approval it holds one raw file; after, it holds the package.

So gate on the payload, in the parent, with no state lifting:

```js
const artApprovedPkg = (artFiles?.list || []).some(
  f => /_manipulation_manifest\.txt$/i.test(f.name || '')
);
if (artFiles?.type === "files" && !artApprovedPkg) { openProof(); return; }
```

**Use the manifest as the marker — not the print-ready PDF.** See
Correction 2.

### Opening the modal from the parent — the idiom already exists

`openPreviewModalRef` is exactly this bridge: the child registers its opener
on a ref the parent owns.

```js
// child, calc-preview-test.html:3926
if (!openPreviewModalRef) return;
openPreviewModalRef.current = () => setActiveModal("preview");
return () => { if (openPreviewModalRef) openPreviewModalRef.current = null; };
```

All 8 files already have it (9 occurrences each) — for *preview*, not proof.
Add an `openProofModalRef` alongside it, per file, wired to that file's own
opener (`setActiveModal("proof")` or `setProofOpen(true)`). That is a
mechanical copy of a proven pattern rather than new architecture, and it
keeps each file's naming differences contained to one line.

---

## Correction 2 — the phase-2 server backstop would reject good orders

The brief proposes rejecting a cart item that "contains a raw file but no
`*_print-ready.pdf` entry." **That would reject correctly-approved orders —
probably most of them.**

`generateApprovalPackage()` returns `skippedGeneration: noTransforms`, and
the approve handler is explicit:

```js
if (!pkg.skippedGeneration && pkg.printReadyBlob) {
  files.push(new File([pkg.printReadyBlob], baseName + "_print-ready.pdf", …));
}
files.push(new File([pkg.manifestBlob], baseName + "_manipulation_manifest.txt", …));
```

When the customer's art needs **no transforms** — correct size, no crop, no
rotation, i.e. the *well-prepared* files — **no print-ready PDF is produced
at all**, by design: the raw file already is the print file. Only the
manifest is pushed unconditionally.

So: **the manifest is the approval marker. The print-ready PDF is optional.**
Both the client gate and any server backstop must key on the manifest.

This does not weaken the brief's #87003 conclusion — that folder had *no
manifest and no previews either*, which is decisive. But the reasoning
should be recorded as "no manifest ⇒ approval never ran", not "no PDF ⇒
approval never ran", or the backstop inherits the wrong test.

### Knock-on: the Imposition Tool's "NO ART" is broader than this bug

`pps_impose_pick_artwork()` matches `/\.pdf$/i` only. A **correctly approved**
no-transform **raster** upload (JPG/PNG at exact size) legitimately produces
no PDF — so the queue will show "NO ART" for it too. Fixing the approval gate
will reduce these but not eliminate them. Worth a separate look at whether
the tool should accept the raw raster when a manifest is present.

---

## Correction 3 — do not gate on a step that can currently crash

This is the one that could cost orders.

**Reported 2026-08-12, unresolved:** on the coupon calculator, clicking
Approve made *the entire calculator disappear*. Root cause never found —
reproduced only with a real multi-page PDF carrying an orientation mismatch
and an inserted blank; a headless run with synthetic PNG art completed
cleanly. Mitigated in `e5b4c5f` by porting the ErrorBoundary + DOM guard to
all 8 calculators, so it now degrades to a reload prompt plus a console
trace instead of a blank page. **The underlying throw is still there.**

Gating checkout on approval turns that from *a quality problem* into *a
customer who cannot place an order at all*. Today they check out with a raw
file and staff sorts it out; after the gate, they are simply stuck.

The brief's edge case 4 sees the shape but resolves it the wrong way —
"confirm the new gate then correctly re-blocks checkout and reopens the
modal." If approval genuinely cannot succeed for that file, re-blocking is
precisely "no path forward."

**Required before shipping the gate, in order:**

1. **Find the approval crash.** It needs the console trace and ideally the
   customer's PDF. Until then, any gate is built on a step with a known
   failure mode.
2. **Ship an escape hatch regardless.** After N failed approval attempts
   (2 is plenty), surface "We couldn't prepare your proof — continue and
   our prepress team will review this file for you," let the order through
   with the raw file, and stamp an order note / `PPS-Spec` flag so staff
   knows to inspect it. That is strictly better than today (staff is
   *told*) and it cannot strand a paying customer.

Without the escape hatch, this fix trades a silent quality problem for a
silent revenue problem — and the second is worse, because you never see the
orders that did not get placed.

---

## Smaller notes

- **Test plan should assert the manifest**, not "print-ready PDF present."
  Add a case: well-prepared art needing no transforms → approve → confirm
  manifest present, PDF legitimately absent, order completes.
- **`artFiles` shape** — confirm per file that `artFiles.list` holds `File`
  objects with `.name` before relying on the regex; a couple of calculators
  may wrap differently.
- **Deploy note is right but incomplete for the current queue.** Calculator
  changes go via `pps-pricing-config` → Pages → `_pending_html`. There is
  already an unshipped set pinned at `35d97c6` plus a repo-only PHP patch
  (`ff542a1`) — see `docs/HANDOFF_2026-08-12.md`. This work should fold into
  that queue, not open a third parallel deploy.
- **Order #87003 is recent and real** (2026-08-12, after 86984). Worth
  checking whether other orders since go-live show the same signature —
  Drive folder with a raw file and no manifest — since each is a job
  production had to chase.

## Recommended sequence

1. Fix (or bound) the approval crash — Correction 3.
2. Add the escape hatch. It is independently valuable and it de-risks the gate.
3. Add `openProofModalRef` per file, copying the `openPreviewModalRef` idiom.
4. Add the payload-based gate keyed on the **manifest**.
5. Test per calculator, including the no-transform case.
6. Fold into the existing deploy queue.
7. Phase-2 server backstop, keyed on the manifest, as a flag-for-review
   rather than a hard reject.
