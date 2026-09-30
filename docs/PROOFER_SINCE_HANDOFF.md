# The Proofer — what changed since the handoff

**Audience:** the session building the new proofer (`proof-ui-draft.html`).
**Written:** 2026-09-30.
**Baseline:** `docs/PROOFER_BRIEF.md` and `docs/NEW_PROOFER_BRIEF.md` as of 2026-09-26,
source commit `188fcf6`. Read those first; this is only the delta.

**`proof-ui-draft.html` had not changed since the handoff when this was written.**
Everything below changed in the calculators or on the server, and is something the new
proofer or its host must now match to keep functional parity with what ships. All of it
is deployed to staging and production.

## Status in the new proofer (branch `claude/proofer-parity`, PR #56, 2026-09-30)

The parity work was running in parallel. Where each item stands there:

| § | Item | Status |
|---|------|--------|
| 1.1 | Page counts always valid | **Done.** `validateJob` also refuses a count that is not a whole number or is past 1,000 pages, rather than laying it out. |
| 1.2 | Staple count | **Done.** The job carries `staples: twoStaplesApplied ? 2 : 1`; the proofer draws `[0.5]` or `[0.33, 0.67]` from it on the surface, in the magnifier and in every preview JPEG, and never works the count out itself. |
| 1.3 | Mixed colour | Saddle has none. The proofer refuses any colour mode but `color`/`bw`, so a mixed book is refused, never simulated. The per-set split is still owed when perfect bound or coupon is wired. |
| 2 | Hardcopy proofs; gate is `proof === 0` | **Done.** The job carries `proof`; above 0 the proofer is review-only (no Approve, no responsibility sentence, DONE closes). |
| 3.1–3.2 | Flats' `back` / `backBlank` | Not yet: flats are not wired to the proofer. Carry both when they are. |
| 3.3 | Undecodable files refused by name | **Done** for booklets. The loader refuses in the calculators' own words, naming the file and saying it was NOT added. It accepts an untyped `.jpg`/`.heic` by name, as the calculators do. |
| 3.4, 4 | Generated-file suffixes, Drive names | Unchanged and pinned. `ppsProofFilesToOrder()` still emits `_print-ready.pdf`, `_preview_page_NNN.jpg` and `_manipulation_manifest.txt` (`tools-proof-integration-test.mjs`). An untouched file ships as `PRINT_READY.pdf`, so it reaches the order as `<base>_print-ready.pdf` like any other. |
| 5.x | Server behaviour after `approved` | Nothing to do. The proofer does not assume Add to Order succeeds after it posts `approved`. |

Gate for the booklet items: `tools-proof-handoff-test.mjs` (14 checks; 5 fail against
the proofer as it stood before this was read).

Commits covered: `3053d28` (Drive names), `ddf4de6` (junctures), `071cf97` (re-quote v1,
military, one staple), `f06d8b9` (re-quote v2, ZIP+4), `03b29c8` (cart paths),
`799a318` (two-sided flats). `62f6fb7` / `da7a78a` (Finishing Report) have no proofing
impact.

Line numbers refer to JSX source on the integration branch (`src-wt`), as in the main
brief. In the compiled mirror, grep for the symbol.

---

## 1. Booklets — what the host now guarantees the proofer

### 1.1 Page counts are always valid (`ddf4de6`)

`ppsSnapPages()` runs at every entry point for a page count: share link, reorder, cart
edit, product default, Mixed mode. `ppsPagesError()` runs inside `calculate()`. Before
this, a 10-page or 2,000-page saddle and a 4-page perfect bound could reach the proof.

- `job.pages` is now always a value from the calculator's page list.
- The proofer may trust it, but should still refuse a count it cannot lay out rather than
  render it.

### 1.2 Saddle stitch: one staple under 3.5″ (`071cf97`)

Below `PCF.one_staple_max_edge` (Production tab, "1-Staple Only Below", default 3.5) on
the binding edge, `calculate()` sets `r.oneStapleOnly`. The saddle then applies:

```js
twoStaplesApplied = r.twoAuto || (twoStaple && !r.oneStapleOnly)
```

This is a `SetPreview` prop, so `openStandaloneProof` can see it. The built-in modal and
the downloadable template draw staple marks from it:

- two staples: `[0.33, 0.67]`
- one staple: `[0.5]`

The places are `calc-preview-test.html:3713`, `:5570` and `generateTemplate()`.

**Parity gap:** the proofer has no staple concept, and the job message does not say how
many staples there are. If the proofer draws staple or spine marks, the host must add
`staples: twoStaplesApplied ? 2 : 1` to `job` (built at `calc-preview-test.html:3299`).
The proofer must never work the count out itself.

### 1.3 Mixed colour survives an edit (`ddf4de6`, perfect bound + coupon)

An edited mixed-colour book used to come back full colour. The per-set
`colorMode` / `colorPages` / `bwPages` is now restored on edit, and the split always sums
to the set's pages.

- The saddle's job message carries only `insideColor` and `coverColor`. That cannot
  describe a mixed book, and `insideColor` reads `"color"` for one.
- When perfect bound or coupon is wired to the proofer, the job must carry the per-set
  split.
- The ticket now says "Mixed — N color + M black & white pages". The proof must not
  simulate a mixed book as all colour or all greyscale.

---

## 2. Hardcopy proofs — all eight calculators (`ddf4de6`, `f06d8b9`)

- A hardcopy proof going to a different address keeps `proofAddrSame` / `proofAddr` on a
  cart edit. It used to fall back to the ship-to, so the proof went to the wrong place.
- A blank proof address now stops Add to Order with "Your order has not been placed."
- The proof ZIP goes through `ppsFmtZip`, which writes nine digits as `85001-1234`. Every
  ZIP check accepts `#####`, `#####-####` and `##### ####`.
- **The approval gate is still `proof === 0` only.** None of this changes it. A hardcopy
  or staff proof must never be blocked on self-approval.

---

## 3. Flats — the `onArtwork` contract changed (`799a318`)

This applies to brochure, postcard, greeting card and letterhead, and matters as soon as
the proofer is adapted to flats.

### 3.1 Both emits carry `back`

```js
onArtwork({ type: "files", list, approved: false, back: !!backArt })
onArtwork({ type: "files", list, approved: true,  back: !!backArt })
```

A proofer-driven approval on a flat must emit the same shape.

### 3.2 One-sided art on a two-sided job

Add to Order asks: "This job prints on BOTH sides, but only one side has artwork…"

- **OK** prints the other side blank. It sets `backBlank: true` in config and metadata,
  plus a ticket line: "Second side: No artwork supplied — print it blank (customer
  confirmed)".
- **Cancel** returns the customer to the calculator.

The proofer should not ask the same question again. It must never produce a two-sided
package that quietly fills the missing side, and it should show the empty side as empty.

### 3.3 Undecodable images are refused

`ppsUnreadableImageMsg(file)` names the file and refuses it at all three entry points on
all five flats: `ppsReadSideArt`, the image path in `processFile`, and the slot handler.
The proofer's loader should also refuse, and name, a file it cannot decode rather than
show a blank.

### 3.4 The server tells customer files from generated ones by name

`pps_one_side_missing()` in `pps-calculators.php` flags a two-sided "upload art" flat
with exactly one customer image and no `backBlank`. The flag reaches the Job Ticket, an
order note and the daily exceptions email.

It skips files whose names end in:

- `_print-ready.pdf`
- `_preview*`
- `_manipulation_manifest.txt`

**`ppsProofFilesToOrder()` must keep emitting exactly those suffixes.** A renamed
deliverable would be counted as customer art and would hide, or cause, a missing-side
flag.

Sticker keeps `backBlank = false` and has no confirm.

---

## 4. Drive file names are a contract (`3053d28`)

`pps_impose_item_artwork()` matches the Drive folder listing against
`_pps_artwork_files` names **exactly**.

- An "Item N - " prefix shipped on 2026-09-26 made multi-job orders impose the raw file
  instead of the approved print-ready one. It was reverted.
- Files now go up under the listed name, unchanged, and each item records
  `_pps_drive_ids` (name → Drive file id).
- The names `ppsProofFilesToOrder()` produces go straight to Drive and imposition.
  Do not change them without changing imposition in the same commit.

---

## 5. Server behaviour the proofer's output feeds (`ddf4de6`, `071cf97`, `f06d8b9`)

### 5.1 Art status follows the artwork option

It no longer means "no staff proof bought". Email-after-order, Canva and design services
no longer read "Self-approved online".

- Self-approved is still `proof === 0` plus a hash-bound package.
- A prepress-review order still reads NOT APPROVED and drops `pps_proof_hash`.

### 5.2 "Print File Check" is its own staff-only line

It is no longer the last line of the Job Ticket, so it stays off customer receipts. It
finds the print-ready file by its `_print-ready.pdf` name, which is one more reason to
keep the names in §3.4 and §4.

### 5.3 A later-day re-quote never touches the artwork

The metadata now carries `quotedOn`. A cart paid on a later day is re-quoted: dates move,
and a rush may be re-priced. A re-quote never revokes an approval or changes files.

### 5.4 Add to Order can still stop after `approved`

These gates run after a proofer approval:

- a picked delivery date that can no longer be met
- a military (APO/FPO/DPO) address
- a blank hardcopy-proof address
- the saddle's quote re-running when the shop's day changes (a 60-second check)

That is correct behaviour. The proofer should not treat Add to Order as guaranteed once
it has sent `approved`.

---

## 6. Smaller changes affecting what production reads

- `BLEED_OPTS` goes through `_cfgList()`, so an option list injected as `[]` falls back to
  the default. The "I don't have bleeds" answer still reaches neither proof surface
  (unchanged; see the main brief).
- New ticket lines:
  - "Pages needing edits"
  - a binding line that reads "Single Staple" when one staple applies
  - the edge that binds, on custom books
- A reference file that is refused or over the size limit stops the order by name
  (`mustArrive`). This was already covered at handoff and is unchanged.

---

## 7. Gates to re-run after wiring any of the above

| Test | Covers |
|------|--------|
| `tools-multi-file-upload-test.mjs` | flat `back` flag, front-only confirm, unreadable back |
| `tools-order-junctures-test.mjs` | proof address, ZIP+4, date gate, day-change re-quote |
| `tools-order-inputs-test.mjs` | snapped page counts, mixed-colour restore |
| `tools-job-ticket-test.mjs` | ticket lines, "Second side", re-quote line |
| `tools-server-junctures-test.php` | art status by option, PPS-Spec, add-to-cart guards |
| `tools-job-health-test.php` | one-side-missing digest section (order 87285) |
| `tools-gdrive-missing-test.php` | Drive uploads under the listed names |

Also run the live proofer suites listed in `docs/PROOFER_BRIEF.md` §1.2 — seventeen
since 2026-09-30, including `tools-proof-handoff-test.mjs`.
