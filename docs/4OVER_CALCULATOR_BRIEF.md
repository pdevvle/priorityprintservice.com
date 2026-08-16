# BRIEF — take the 4over calculator from draft to shippable

`calc-4over.html` exists at **`1649982`**, 1,061 lines, and works: it renders,
prices, switches between products, and its numbers were verified in headless
Chromium against the real Circle Business Cards capture before the sample was
swapped for a synthetic one.

**It is a catalogue, not a single product.** One page holds every 4over product
— Round / Standard / Square / Fold-over business cards in the sample — selected
from a dropdown, each with its own dimensions, options, run ladder, production
days and staleness.

It is a **draft**. About 60% of what it needs to be a real calculator is present
and about 90% of the *decisions* are made. This brief says which is which, so
you build the missing parts rather than relitigating the settled ones.

Read `docs/4OVER_REPLACEMENT.md` and `docs/4OVER_PIPELINE.md` first — they carry
the analysis this file assumes.

---

## 0 · Rules that are not negotiable

- **Never put real 4over costs in this repo.** The repo is public and CLAUDE.md
  forbids committing pricing figures. The embedded `SAMPLE_CATALOG` is synthetic
  and must stay synthetic — all four products in it. Real costs arrive at
  runtime as `PPS_CONFIG.catalog` and live only in `wp_options`.
- **Never render cost, markup or the shipping split anywhere a customer can
  reach** — including `pps_metadata`, which reaches the order screen, the
  customer email and the Missive spec. Staff see it in the debug panel only.
- **Never write a literal `</script>` inside the `text/babel` block.** Escape it
  `<\/script>`. This has broken the build before and the symptom is
  indistinguishable from a Jekyll failure.
- Pricing math lives in the calculator HTML, never in PHP (CLAUDE.md).
- 4over products are WooCommerce **virtual** products, like every PPS product.

## 1 · Run it

```
npm install react@18.3.1 react-dom@18.3.1 @babel/standalone@7.26.9
```

Then swap the three CDN `src` attributes for `./node_modules/...` paths, write
the result beside `node_modules`, and open it with Playwright or a browser.
`?debug=1` unlocks the staff cost breakdown. The scratch harness used to verify
the draft is in this session's transcript; rebuilding it is ten lines.

---

## 2 · What is already decided — do not redesign these

| Decision | Why |
|---|---|
| **The matrix defines the form** | Controls are generated from the selected product's `dimensions`, so one file serves every 4over product and adding one costs no code |
| **One page, many products** | `PPS_CONFIG.catalog` holds them all; the selector only renders when there is more than one |
| **Defaults preselect the product, never restrict it** | `/round-business-cards/` opens on Round and a customer can still switch — so you keep one WooCommerce product per shape for SEO and Shopping while one file serves them all |
| **Switching remaps, never resets** | See `remapSelection()` — this is the part most likely to be "simplified" into a bug |
| **Quantity is a grid of the matrix's tiers**, not free entry | You cannot order 1,750 from 4over; offering it means eating the 2,500 cost or intervening by hand every time |
| **`ship(C) = max(floor, min(mult×C, cap), pct×C)`** | Reduces the owner's four bands to one branchless expression, continuous at every handover |
| **Retail is delivered** — shipping inside the price | Owner's markup formula is `cost × 2.0010 + ship(cost)` |
| **Delivery is stated, not computed** | `production + 1 handling + 3 transit` business days. No zone map, no rush engine — 4over's schedule cannot be compressed from our side |
| **Stale matrix refuses to quote** | No API to reconcile against; quoting stale sells below cost silently |
| **Flat proofer, no fold renderer, no 3D** | A card has no panels |
| **Round trims get circular guides** | A square safety box on a die-cut round misleads in the direction that costs money |

If a faster turnaround exists at 4over it is **a captured axis with its own
prices**, never a multiplier — the same reason coating cannot be a percentage
(`UVFR/UV` ranges 1.0000–1.0711).

---

## 3 · What is stubbed, in the order I would build it

### 3.1 Artwork is a local preview only — the biggest gap

`readFile()` is a `FileReader` producing a data URL for the proof canvas. There
is **no Drive upload, no proof approval, no approval package, and no PDF
support**. Today a customer could reach Add to Order having uploaded nothing the
shop can print.

Port from `calc-brochure.html`:

- the pdf.js loader (`window.__ppsPdfJs`) and page-1/page-2 extraction
- `FitToggle` (Crop / Fill / Fit / Stretch / Scale / Rotate)
- the artwork upload path to Google Drive, with the idempotent retry
- `generateApprovalPackage()` and the approve flow

**Two things to carry over exactly**, both learned the hard way:

- The approval marker is the **manifest** (`*_manipulation_manifest.txt`), never
  the print-ready PDF. Well-prepared art needing no transforms produces no PDF
  by design (`skippedGeneration: noTransforms`), so keying on the PDF rejects
  correctly-approved orders. See `docs/ART_APPROVAL_GATE_REVIEW.md`.
- Every `Image()` needs `onerror` and every `FileReader` needs `onerror`. This
  is in the audited security list.

### 3.2 The PHP side does not exist at all

Nothing server-side has been written. Needed, roughly in this order:

**`pps-4over-matrix.php`** — storage and reader.

- **One stored matrix per product**, keyed `family/key`. The Drive folder holds
  one JSON file each; `docs/4OVER_MATRIX_SCHEMA.md` is the field-by-field
  contract and is the thing to implement against, not this summary.
- `rows` in the file → a flat `"40|UV|250"` cell map at ingest. The file stays
  capture-shaped; the runtime gets the lookup shape.
- `pps_4over_catalog( $family )` from `wp_options`, returning `{family, products:[…]}`
- injection into `PPS_CONFIG.catalog` on a 4over product page, parallel to how
  `pps-calculators.php:1113` injects `PPS_CONFIG.defaults`. The product a page
  opens on comes from `_pps_defaults.product`
- **which catalogue a product page gets is a per-product setting.** A business
  card page should not offer postcards. Expect a `_pps_4over_family` meta beside
  `_pps_defaults`
- age helpers so both the calculator and the admin see the same number
- an admin screen listing every matrix with `captured_at`, `verified_at`, age,
  and a staleness flag

**`pps-4over-ingest.php`** — the Drive pull.

- WP-Cron, pulling from the Drive folder via the existing `pps-gdrive.php` OAuth
- **a failed fetch changes nothing** — inherit this verbatim from
  `pps-gbp-sync.php`, which is built and tested. A blanked matrix is a product
  that cannot be quoted, or worse quotes zero.
- the five gates from `docs/4OVER_PIPELINE.md` §6: schema, completeness,
  monotonicity, magnitude, freshness. A failed gate keeps the previous matrix.
- writes `manifest.json` back to Drive so the Chrome task reads its work list
  from the plugin rather than a hand-maintained copy

**Registration** — add `calc-4over.html` to `pps_get_calc_type_for_filename()`
and give it a calc type (`fourover`), so FAQ schema, the registry and the
spawner all recognise it.

### 3.3 Shippo for outlying states

`calculate()` sets `needsShippo` and the Panel shows a caveat, but no rate is
fetched. Wire the existing Shippo integration for `AK, HI, PR, GU, VI, AS, MP`.
Origin is not a concern — 4over ships those from California and the owner has
confirmed that does not meaningfully move the rate versus PPS's own origin.

### 3.4 The catalogue's own gaps

- **Grouping is one level.** `product.group` renders as `<optgroup>`. Once the
  catalogue spans families — business cards *and* postcards *and* flyers — a flat
  list of forty gets unusable and it wants a family → product two-step.
- **No per-product imagery.** The header shows the product name and its fixed
  spec chips; a shape thumbnail beside each option would make the selector read
  much faster.
- **`remapSelection()` is silent.** Switching from Round at 25,000 to Square
  lands on 10,000 because Square's ladder stops there — correct, but the
  customer is not told their quantity moved. A one-line notice under the
  quantity grid would fix it, and the same pattern already exists in `TxtNum`
  ("Adjusted to N — allowed range is…").

### 3.5 Smaller gaps

| Gap | Note |
|---|---|
| Ship-state list is 13 hardcoded options | Needs all 50 + territories; the other calculators have a list to copy |
| No `RichTip` tooltips | Port the component and add `tipKey`s |
| No dark-mode toggle | The tokens and `data-pps-theme` rules are present; only the toggle and `pps_applyTheme` are missing |
| No reorder / edit mode | `pps-reorder.php` expects specific `pps_metadata` keys |
| Add-ons are priced but not itemised in the spec string | PPS-Spec should name them |
| `_pps_defaults_price` | Should be settable from a share link, as the spawner does for the other eight |

---

## 4 · Verify like this

The draft was checked by driving the rendered UI, not by reading the code. Keep
that: it is how the pricing-matrix and min-price tools work, and it measures
what a customer is actually shown.

What was asserted, and what any change must keep passing:

- all nine tiers match the computed retail table **to the cent**
- coating changes the price at 25,000 and **only** at 2,500 and above
- colorspec changes **nothing**
- delivery lands exactly `production + 1 + 3` business days out
- the debug panel's decomposition adds up to the displayed total
- each of the four products renders **its own** colorspecs, coatings and ladder
  (9 / 8 / 6 / 5 tiers) and its own production days (4 / 3 / 4 / 5)
- **the remap test:** Round at UVFR / 25,000 → switch to Square → lands on
  UV / 10,000 with no error and a correct price. Square has no UVFR and its
  ladder stops at 10,000. If a change breaks one assertion, make it this one.

Worth adding: a matrix with `captured_at` well in the past must refuse to quote,
and a run size absent from the matrix must be unreachable in the UI.

---

## 5 · Open questions the draft cannot answer

1. **Does the 7-day turnaround exist as a selectable option?** The capture found
   it in the Specs tab with no control. If it is reachable, the matrix is short
   an axis and every captured number is the 4-day price. Resolve before scaling
   past one product.
2. **Does the captured price include freight?** Almost certainly not. Settle it
   with four Shippo quotes at the derived weights (`docs/4OVER_REPLACEMENT.md`)
   — 1.2 lb, 13.2 lb, 25.8 lb and 64 lb across 3 parcels.
3. **Which WooCommerce variable products are being replaced?** Each must leave
   the variable system entirely or the two will fight over the cart — the same
   class of problem as the WCPA coexistence rule in CLAUDE.md.
4. **Two suspicious cells in the original capture** — UV-front-only at 5,000 and
   Aqueous at 15,000 are non-monotonic in a way that is also the exact signature
   of reading an AJAX price before it settles. Unresolved until a capture with a
   settle condition reproduces them.

---

## 6 · The two numbers that make this worth doing carefully

At 250 units the gross margin is **$7.57** and the shipping estimate is
**$10.00** — shipping is 132% of the margin, so a modest under-estimate takes
the order negative. At 25,000 the margin is $231.60 and shipping is $34.71
against a parcel that weighs ~64 lb in three boxes.

The bottom of the range is **fragile**; the top is **exposed**. Both ends want
the Shippo check before this goes live, and neither is visible from inside the
calculator — which is exactly why the debug panel shows the decomposition.
