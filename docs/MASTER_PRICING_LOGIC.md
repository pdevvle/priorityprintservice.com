# PPS Pricing — Master Logic Reference

Single source of truth for the pricing engine across all three calculators. Replaces the previous trio of `PRICING-APPROACH.md`, `PRICING-ROLLBACK.md`, and `PRICING_PHILOSOPHY.md` (consolidated 2026-05-10).

**Read this file before suggesting any formula change, PCF default change, or new pricing knob.**

- Live branch (GitHub Pages preview): `pps-pricing-config`
- Last major tune: 2026-04-14 (perfect bound + saddle-stitch cover-print fix)
- Strategy: position **+5% to +15%** above Vistaprint at every size/quantity tier

---

## TL;DR — Architectural rules

1. **If it's a number, put it in PCF on the Production tab.** Don't bury it in a row of a sub-tab spreadsheet.
2. **Option arrays are for IDENTITY (label+val), not for PRICING.** Prices should be referenced via PCF lookups keyed on `val` ranges.
3. **Hardcoded HTML defaults are only fallbacks.** In prod, `wp_options` always overrides. But keep them in sync so standalone preview and fresh installs behave correctly.
4. **Backward compat is free.** `calculate()` reading PCF scalars can safely ignore stale `price` fields on saved option rows.
5. **Each calculator is self-contained.** A change to shared pricing philosophy needs to be applied in all 3 HTML files. They will NEVER be a shared module — that's the deliberate architecture.

---

## Value flow: `wp_options` → `calculate()`

```
pps-config-admin.php :: pps_default_config()
        ↓  (seeds the defaults)
wp_options row 'pps_calc_config'
        ↓  (loaded via pps_get_config())
pps-calculators.php :: inline script tag on product page
        ↓  window.PPS_CONFIG = { calc: {...} }
calc-*.html :: const _CFG = (window.PPS_CONFIG||{}).calc || {}
        ↓
const PCF = Object.assign({ ...hardcoded defaults... }, _CFG.pcf || {});
const ART_OPTS = _CFG.art_opts || [ ...hardcoded defaults... ];
        ↓
calculate(c) → reads PCF.* and the option arrays
```

**Key insight:** the hardcoded defaults in each HTML file are **only fallbacks** for standalone preview/testing. In production (inside WordPress), the `pps_calc_config` `wp_options` row always overrides them. Admins edit values via **WP Admin → PPS Config** (tabs: Production, Papers, Finishing, Artwork, Sizes, Shipping, SEO).

The PHP plugin contains **no pricing math**, with exactly one exception (below). It loads calculator HTMLs from `wp_upload_dir()/pps-calculators/` and injects `PPS_CONFIG`. Pricing logic lives only in the HTML files — by deliberate architecture, not by accident.

> **The one exception:** `pps_materials_price_floor()` in `pps-calculators.php` computes a
> materials lower bound to reject tampered cart prices. It is a security check, never a
> quote, and it must not be used to price anything. See *Materials price floor* at the end
> of this document.

---

## PCF philosophy: scalars vs option arrays

### What goes in PCF (scalar constants, Production tab)
- Labor rates (`labor_press_hr`, `labor_bindery_hr`, `labor_cutting_hr`, `labor_gw_hour`, `labor_gw_setup`)
- Machine speeds (`press_printsperhour`, `bindery_morgana_impressionperhour`, `cutter_cyclesperhour`, `speed_gwperhr`)
- Markup factors (`backend_maximummarkup`, `backend_minimummarkup`, `booklet_*`, `perfectbound_*`)
- Easy discount (`easydiscount_factor`, `easydiscount_max`)
- Fees (`proof_hardcopy_cost`, `proof_digital_cost`, `bleed_minimum`, `non_inventory_fee`)
- Turnaround knobs (`minimum_turnaround_days`, `sheetsforlowcosthardcopyproof`)
- Artwork fees: `art_pagesperhour`, `art_newdesignmodifier`, `art_canva_fee`, `art_edit_rate`, `art_design_rate`

### What goes in option arrays (Papers/Finishing/Artwork tabs)
- `papers_nc` / `papers_cs` — paper stocks with per-row `val`, `label`, `price`
- `coatings`, `bundling`, `corners`, `perf_opts` — finishing options with `val`, `label`, `price`, `count`
- `art_opts`, `bleed_opts` — artwork options
- `size_presets` — sheet size groups
- `closures` — shipping holiday closures

### Red flag to watch for
If you find yourself wanting to change "the price of X" and it's buried as a `price` field on a single row of an option array, consider whether it should be promoted to a PCF scalar. Recent example: the Canva fee used to live as the `price` field of the "I have a design in Canva" row in `art_opts`. It was promoted to `PCF.art_canva_fee` so admins can edit it on the Production tab next to other labor/fee constants.

---

## Strategy — positioning above Vistaprint

Target range: **+5% to +15%** above Vistaprint. Premium positioning signals quality while staying within the band where competitive shoppers will still convert.

### Honest disclaimer

**All "Vistaprint" comparison numbers used during tuning were Claude's industry-knowledge estimates, NOT scraped real-world prices.** Why:

- This environment can't reach competitor sites (network blocked)
- Vistaprint blocks automated requests even from unrestricted environments
- Their pricing is session-token-based, not URL-deterministic

What this means for a new session:
- The estimates are in the right ballpark (printing industry pricing is well-understood)
- Real prices probably within ±15% of estimates
- If real-world positioning feels wrong post-launch, adjust PCF values via WordPress admin (no code change needed)

If you get real quotes, share them as `size × pages × qty → VP price` and the curve can be re-tuned around real data points.

---

## Why brochure ≠ booklet (the tS range insight)

**Critical insight:** naively copying the brochure markup values broke the booklet calculator and produced +40-200% overpricing.

### Pricing formula pattern (shared across calculators)

```javascript
const tS = <total parent sheets>;            // depends on product
const dL = coef * Math.log(tS) - constant;   // decay curve
const mk = Math.max(mkx - dL, mkn);          // markup, floored at mkn
// Costs multiplied by mk to produce final price
```

- `tS` grows with quantity and (for booklets) page count
- `mk` starts at `mkx` for tiny jobs, decays logarithmically with volume, floors at `mkn`
- Different products use different `tS` formulas, different curve params, different minimums

### Brochure tS range

```
tS = qty / imp
```

At qty 25, 8.5×11 trifold: tS ≈ 12. At qty 2500, 8.5×11 trifold: tS ≈ 1250. **Range: ~12 to ~1250.**

### Booklet tS range

```
tS = (qty * pages / 4) / (imp / 2)
```

At qty 25, 8pp, 5.5×8.5: tS = 25. At qty 1000, 32pp, 8.5×11: tS = 8000. **Range: ~25 to ~8000** — much larger because page count is in the formula.

### Why this matters

A markup curve tuned for tS=12..1250 (brochure) decays to the floor long before it reaches tS=8000 (booklet). Applying the brochure's steep coefficient (1.85) to booklets makes the markup drop off way too fast AND the floor (3.5) stays too high at those huge tS values — result: way too expensive at every quantity.

**Solution:** each product gets its own PCF keys (`backend_*` for brochure, `booklet_*` for saddle, `perfectbound_*` for perfect bound) so they can be tuned independently.

### Booklet imposition penalty

Booklets come in two common sizes with very different imposition efficiency:

| Size | imp | Meaning |
|---|---|---|
| 5.5×8.5 | 4 | 4-up on press sheet (efficient) |
| 8.5×11 | 2 | 2-up on press sheet (2x more paper) |

Same qty and page count: 8.5×11 uses **exactly 2x** the paper of 5.5×8.5.

A single markup curve can't compress both into a tight band above Vistaprint. Best achievable ranges (with uniform curve, no discounts):

| Config | Range vs Vistaprint |
|---|---|
| 5.5×8.5 32pp | +2% to +21% ✓ |
| 5.5×8.5 16pp | +13% to +25% ✓ |
| 5.5×8.5 8pp | +19% to +52% |
| 8.5×11 32pp | +15% to +66% |
| 8.5×11 16pp | +35% to +68% |
| 8.5×11 8pp | +39% to +73% |

**Decision:** apply a size-based discount specifically for `imp < 4` (8.5×11 and larger) to bring it closer to competitors while keeping a single curve.

---

## Currently applied values (per calculator)

### Brochure (`calc-brochure.html`)

```
backend_maximummarkup: 15.2
backend_minimummarkup: 3.5
easydiscount_max:      0
markup curve:          dL = 1.85 * ln(tS) - 0.5
print markup:          uniform (applied to all print costs)
baseCost:              removed ($35 was always added)
```

Expected positioning: +1% to +12% above Vistaprint.

### Saddle stitch booklet (`calc-preview-test.html`)

```
booklet_maximummarkup: 8
booklet_minimummarkup: 1.5
booklet_size_discount: 0.15  (15% off for imp<4 sizes)
easydiscount_max:      0
common_discount_max:   0
markup curve:          dL = 0.80 * ln(tS)
print markup:          uniform
```

Expected positioning:
- 5.5×8.5: +3-53% (tighter for thicker books, hot for 8pp at high qty)
- 8.5×11: +1-49% with size discount

### Perfect bound (`calc-perfect-bound.html`) — tuned 2026-04-14

```
perfectbound_maximummarkup: 8
perfectbound_minimummarkup: 1.5
perfectbound_size_discount: 0.15  (15% off for imp<4 sizes)
easydiscount_max:      0
common_discount_max:   0
markup curve:          dL = 0.80 * ln(tS)     // was two-branch: quadratic<1000, linear≥1000
print markup:          uniform                // was asymmetric (mk only on BW inside)
```

Mirrors the saddle-stitch architecture: dedicated `perfectbound_*` PCF keys so it can be tuned independently of brochure (previously shared `backend_*`). Old two-branch curve and asymmetric print markup are preserved inline as commented-out `// ROLLBACK:` blocks for quick revert without a git operation.

### Pricing surcharge (booklet family) — raised 0.30 → 0.40, 2026-08-01

```
booklet_surcharge:      0.40   (calc-preview-test.html, calc-modern-draft.html)
perfectbound_surcharge: 0.40   (calc-perfect-bound.html)
couponbook_surcharge:   0.40   (calc-coupon-book.html)
```

Applied as `P.surcharge = subBeforeSurcharge * rate`, where `subBeforeSurcharge` is the
running sum of **every** line item at that point — materials, print, all labor, add-ons,
fees, and every discount already taken (`discBW`, `discEasy`, `discCommon`, `discSize`).
It lands after all discounts and before the site-wide sale discount, and shows in the
debug breakdown as the `fee`-group line item "Pricing Surcharge".

Two consequences worth remembering before tuning it:

- **It scales the discounts too.** Because it multiplies the post-discount subtotal,
  raising it makes every discount you grant proportionally smaller in absolute terms.
  The same applies to fees — the `$35` `non_inventory_fee` is inflated by this rate.
- **Measured effect of 0.30 → 0.40 is a uniform +7.69%** (= 1.40/1.30 − 1) across the
  entire quantity curve on all three calculators, verified end-to-end through the
  rendered quantity-pricing table:

  | qty | saddle 0.30 → 0.40 | perfect bound | coupon book |
  |---|---|---|---|
  | 100 | $164.03 → $176.65 | $219.86 → $236.77 | $254.09 → $273.64 |
  | 1000 | $575.32 → $619.58 | $763.71 → $822.46 | $988.77 → $1064.83 |
  | 5000 | $2539.76 → $2735.12 | $2820.10 → $3037.04 | $4006.77 → $4314.99 |

**ROLLBACK 2026-08-01:** previous value was `0.30` on all three keys. Revert in the four
HTML files and the three `pps-config-admin.php` defaults, *and* in the saved option — see
the deployment note below.

**DEPLOYMENT — the code change alone does not move live pricing.** `pps_get_config()`
resolves as `array_merge($defaults['pcf'], $saved['pcf'])`, so a value stored in
`wp_options['pps_calc_config']` wins over the default in `pps-config-admin.php`. Staging
had all three stored at `0.3` when this change was made. To take effect on a live site,
the three fields must also be changed in **PPS Calculators → Config → Production**.
Changing them there is preferred over rewriting the option programmatically: the stored
array has already lost type fidelity once (`sale_label` and `question_recipient_email` are
persisted as `0` rather than strings — harmless today only because the calculators read
them as `PCF.sale_label || "Sale"`), which is what a full round-trip through the API does
to it.

### The five flat calculators have no surcharge

Brochure, greeting card, letterhead, postcard and sticker carry no surcharge term at all —
their entire margin comes from the `backend_*` markup curve. This is a real asymmetry in
the catalogue, not an oversight to fix blindly: adding a 40% term to a flat product is a
40% price rise, so model it before changing anything.

### WordPress admin (`pps-config-admin.php`)

Defaults updated to match all calculators:
- `backend_*` keys: brochure values (15.2 / 3.5)
- `booklet_*` keys: booklet values (8 / 1.5 / 0.15)
- `perfectbound_*` keys: perfect bound values (8 / 1.5 / 0.15)
- Admin UI split into four sections: **Brochure Markup**, **Booklet Markup**, **Perfect Bound Markup**, **Discounts**

### Structural fixes applied to all calculators

1. **Uniform print markup.** Originally inside-print BW got `×mk` but the fullcolor addition had none, and cover print had no markup at all. Fixed by multiplying `mk` uniformly across all print costs.
2. **Disabled `easydiscount` and `common_discount`.** These ad-hoc discount caps were redundant with the new curve and created non-monotonic pricing. Both max caps set to 0.
3. **Size-based discount** (booklets only). New `P.discSize` line item: 15% off the subtotal when `imp < 4`. Shows as "Size Adj." in the price breakdown. Hides automatically for 5.5×8.5 (imp=4).

### Rollout: two-sheet model to all calculators (2026-06-17)

The 13×19 + 13×27.5 two-sheet rule (below) was rolled out from saddle stitch to **perfect bound**, **coupon book**, and **brochure**. Same rule everywhere: **yield/`imp` is computed on 13×19; if `imp < 1` the piece images on the 13×27.5 sheet**, and the non-inventory fee applies unless the paper(s) are stocked at 13×27.5 (`LARGE_SHEET_VALS`, default `[0.003, 0.03]` = 100lb Gloss Text / 100lb Gloss Cardstock — identical paper vals across all four calcs).

- **Perfect bound & coupon book** — structural twins of saddle: `calcCustomImp` gained the `sheetLong` arg; `resolveSize` switches to 27″ when `imp<1` (`sheet`/`needsLargeSheet`); `calculate()` fee requires both inside+cover to be 13×27.5 papers on oversized jobs. Also picked up the same `COVER_INV` text-weight fix (`…INV_NC,…INV_CS`). The throwaway `calcSaddle` comparison helper was left as-is.
- **Brochure** — flat/single-paper, its own `calcBrochureImp` (the long axis divisor is now parameterized 18.5→27). After imp is resolved (preset or custom), `imp<1` re-images on 27″ and sets `needsLargeSheet`; the single-paper `nonInv` then checks `LARGE_SHEET_VALS` instead of `INV_VALS`. Note the `11×25.5` preset (`imp:0.5`) routes to the 13×27.5 sheet. **(Superseded 2026-07-11 for flats — see "Oversized flats keep their sub-1 yield" below: brochure/postcard/greeting-card/letterhead now KEEP `imp 0.5` instead of recomputing to 1, so oversized size-costs ≈2×.)**

Presets with `imp ≥ 1` and normal custom sizes are unchanged in all four calcs (default `sheetLong` keeps the 13×19 math byte-identical).

### Oversized flats keep their sub-1 yield — charge the oversized premium (2026-07-11)

**Reverses the flat-calc half of the two-sheet rollout above** (per operator). For the flat calculators — **brochure, postcard, greeting-card, letterhead** — an oversized piece (`imp < 1` on 13×19) no longer has its `imp` recomputed up to the 13×27.5 yield. It **keeps the sub-1 13×19 yield** (e.g. the `11×25.5` preset stays `imp 0.5`), so `pressSheets = qty / imp` roughly **doubles** and every size-scaled line — paper, front/back print, press labor, coating — scales with it (≈2× at `imp 0.5`; the `ln(pressSheets)` volume-discount curve gives a small offset, hence "essentially" double). The 13×27.5 sheet flag + `non_inventory_fee` still apply.

- **Change:** in each flat `calculate()`, the `imp < 1` block sets `needsLargeSheet` / `pressSheet="13x27.5"` but only re-images (`imp = calc*Imp(longE, shortE, 27)`) when **`imp === 0`** — i.e. only when a piece won't fit even 0.5-up on 13×19 (keeps `pressSheets` finite for very large customs). Applies to the `11×25.5` preset **and** any custom size in the half-yield range.
- **Verified:** brochure debug at 11×25.5 now shows `imp 0.5000` (was 1); `pressSheets` doubles (e.g. qty 500 → 1000).
- **Not applied to the booklets** (saddle / perfect bound / coupon) — they keep the efficient-sheet routing from the 2026-06-17 rollout below. Extend on request.
- **Rationale:** an oversized flat consumes ~2× the press resources of a standard piece; the shop charges that premium rather than discounting it onto the big sheet.

### Saddle stitch: two-sheet inventory model (13×19 + 13×27.5) (2026-06-17)

Stock reality: most papers are inventoried only at **13×19**; **100lb Gloss Text** (`val 0.003`) and **100lb Gloss Cardstock** (`val 0.03`) are *also* stocked at **13×27.5**. The calculator's `imp` (books per press sheet) is defined on the **13×19** sheet — so all preset prices (every preset is `imp ≥ 1`) are unchanged.

Rule (per operator): **`imp < 1` on 13×19 → run the job on the 13×27.5 sheet.** This only happens on large *custom* sizes (presets never go below 1).

Implementation:
- `calcCustomImp(longest, shortest, bindDir, sheetLong)` gained an optional `sheetLong` (usable long-axis inches; default `18.5` = 13×19, `27` = 13×27.5). Short axis stays `12.5`. Default arg keeps all existing 13×19 calls byte-identical.
- `resolveSize()` computes `imp` on 13×19; if `imp < 1` it re-images on 13×27.5 (`sheetLong=27`) and returns `sheet:"13x27.5"`, `needsLargeSheet:true`. Otherwise `sheet:"13x19"`, `needsLargeSheet:false`. (Presets return `13x19`/`false`.)
- `LARGE_SHEET_VALS` (`_CFG.large_sheet_vals || [0.003, 0.03]`) lists the papers stocked at 13×27.5.
- Non-inventory fee: when `needsLargeSheet`, "in stock" means **both inside and cover are 13×27.5 papers** (cover "same as inside" inherits the inside check); otherwise the `non_inventory_fee` applies (special-order at the big sheet). Normal (`imp ≥ 1`) jobs keep the existing 13×19 inventory test (`INV_NC`/`INV_CS`/`COVER_INV`).

Net effect: oversized custom jobs are now priced on their real (larger, more-efficient) press sheet instead of being floored at `imp 0.5` on 13×19, and a special-order fee is added unless the chosen papers are the two 13×27.5 stocks. Debug panel exposes **Press sheet** and a **Needs 13×27.5 sheet** flag. PHP default + `$json_keys` carry `large_sheet_vals`.

### Saddle stitch: in-stock text-weight covers exempt from non-inventory fee (2026-06-17)

The `$35` `non_inventory_fee` is meant for genuinely non-stock papers. But the cover-inventory set was `COVER_INV = [COVER_SAME.val, ...INV_CS]` — it only recognized "Same as Inside" plus in-stock **cardstocks** as stocked covers. A text-weight sheet that is in-stock for *interiors* (e.g. 100lb Gloss Text, `val 0.003`, which is in `INV_NC`) was therefore tagged non-inventory the moment it was chosen as a *separate cover*, adding `$35` (further inflated by the 30% `booklet_surcharge`). Net effect: a pricier 80lb cardstock cover could come out cheaper than a 100lb Gloss Text cover.

Fix (calc-preview-test.html, `COVER_INV` definition): include the in-stock text weights too.

```javascript
// before
const COVER_INV = [COVER_SAME.val, ...INV_CS];
// after
const COVER_INV = [COVER_SAME.val, ...INV_NC, ...INV_CS];
```

Now `P.nonInv` is `0` for any in-stock cover (text-weight *or* cardstock); the fee still applies to non-stock/factory cover papers. `COVER_INV` is derived in the HTML only (no config key), so this is an HTML-only change — `inv_nc`/`inv_cs` PHP config is unchanged.

### Saddle stitch: 8-up markup bonus (2026-05-12)

The smallest saddle sizes (`imp >= 8` — 3.5×5.5, 4×6, 4.25×5.5, Square 4×4, 6×4 Landscape, etc.) print 8 books per press sheet, so material costs scale down accordingly. But the customer perception of value doesn't scale 1:1 with sheet-count, and labor (press time, cutting, stitching) is roughly the same per-book regardless of imp. New PCF knob `booklet_8up_markup_bonus` (default `0.15`) multiplies `mk` by `(1 + bonus)` when `imp >= 8`:

```javascript
const mkBase = Math.max(maxMk - dL, minMk);
const mk = imp >= 8 ? mkBase * (1 + PCF.booklet_8up_markup_bonus) : mkBase;
```

This affects materials and print costs only (those are the line items that scale with `mk`). Labor stays at its base hourly rate. Tunes the 8-up-vs-4-up price ratio without bloating labor cost in the breakdown.

Set to 0 to disable. Currently saddle-only — PB and coupon book have different imp tables and would need their own knobs.

---

## Sale discount

Manual on/off site-wide sale via two PCF scalars. Shipped 2026-05-10.

### Knobs

| PCF key | Default | Meaning |
|---|---:|---|
| `sale_discount_pct` | `0` | Decimal fraction (e.g. `0.15` = 15% off). `0` = sale off. Hard-capped at `0.5` in admin/preset save. |
| `sale_label` | `"Sale"` | Human label shown on the calculator panel badge and in the price breakdown. |

### Apply order

```
…all existing line items + existing discounts (discBW, discEasy, discCommon, discSize) + surcharge…
const subBeforeSale = sum(P)
P.discSale = (PCF.sale_discount_pct > 0) ? -round(subBeforeSale * PCF.sale_discount_pct) : 0
total = sum(P)              // includes P.discSale
grandTotal = total + rushCost
final = grandTotal + shipping
```

The sale discounts the **post-existing-discount, post-surcharge** subtotal. **Excluded:** rush surcharge, shipping, and turnaround add-ons (these are added after `total`). This matches retail convention — "15% off" applies to product cost, not to shipping & handling.

### Resolution: site-wide vs per-preset

- Sitewide via WP Admin → **PPS Config → Production → Site-Wide Sale**.
- Per-preset overrides via **Presets admin** (`Sale %` and `Sale Label` fields on each preset row). Non-zero per-preset values override the site-wide PCF values via `PPS_CONFIG.calc.pcf.sale_*` injection in `pps_render_preset_calculator()`.
- Calculators always read `PCF.sale_*` — they have no preset-awareness. Override happens at the PHP injection layer.

### Display

- When `P.discSale < -0.005`: panel renders the original price struck-through alongside the sale price plus a magenta `<label> · Save $<amount>` badge. Breakdown line item appears with the configured `sale_label`.
- When `P.discSale === 0`: zero sale UI rendered. Identical to pre-sale baseline.

### Files touched

- `pps-config-admin.php` — defaults + Production tab "Site-Wide Sale" group + `sale_label` added to text-input list.
- `pps-calculators.php` — `pps_save_preset()` accepts and clamps the two fields; `pps_render_preset_calculator()` injects per-preset overrides into `PPS_CONFIG.calc.pcf`.
- `pps-presets-admin.php` — two form fields under the basic-info grid; passes through to save handler.
- `calc-preview-test.html`, `calc-perfect-bound.html`, `calc-brochure.html`, `calc-coupon-book.html` — PCF defaults, `P.discSale` line in `calculate()`, breakdown row, return-object additions, Panel strikethrough/badge.

### Tuning

- "Sale needs to be more aggressive": raise `sale_discount_pct`. Cap is 0.5 (50%).
- "Sale should not appear on a particular preset": leave preset's `Sale %` blank or `0`; that preset will fall through to the site-wide value, which can also be 0 to disable globally.
- "Different label per campaign": just edit `sale_label` in admin — no code change.

---

## Add-on availability (per-calc on/off)

Per-calc-type on/off switches for finishing add-ons. Shipped 2026-05-10.

### Storage

`wp_options['pps_addons_visibility']` — associative array keyed by add-on slug, then by calc type. Only calc types that support each add-on appear under its key. Defaults: every cell `true` (current behavior). Setting any cell `false` makes that add-on unavailable on that calculator.

### Supported add-ons

| Slug | Label | Saddle | PB | Brochure | Coupon |
|---|---|:-:|:-:|:-:|:-:|
| `vivid` | Vivid Print | ✓ | ✓ | ✓ | ✓ |
| `coating` | UV Coating | ✓ | ✓ | ✓ | ✓ |
| `bundling` | Bundling | ✓ | ✓ | ✓ | ✓ |
| `rc` | Round Cornering | ✓ | ✓ | ✓ | ✓ |
| `two_staple` | Two-Staple | ✓ | — | — | — |
| `perforation` | Perforation | — | ✓ | ✓ | ✓ |
| `outfold` | Outfold | — | ✓ | — | ✓ |

### "Off" semantics

- Calculator form row is **hidden** when the add-on is off for that calc.
- If a pre-filled state (reorder URL, preset defaults, edit-mode) carries a non-default value for the disabled add-on, the row is **force-shown** so the user can see and clear it, AND `calculate()` returns `{error: ["<Label> is currently unavailable. Please change this option to continue."]}` so no price displays until cleared.
- This is stricter than the sale-discount knob: off = truly unavailable, not just suppressed.

### Admin UI

- **Finishing tab**: above each of the Coatings / Bundling / Round Cornering spreadsheets, a row of 4 calc-type checkboxes ("Available on: Saddle / Perfect Bound / Brochure / Coupon").
- **Production tab**: bottom-of-tab "Add-on Availability" section for the four add-ons that don't have their own finishing spreadsheet (Vivid Print, Two-Staple, Perforation, Outfold).

### Injection

`pps-calculators.php` resolves the visibility matrix to just this calculator's flags via `pps_get_addons_visibility_for_calc($calc_type)` and writes the result as `PPS_CONFIG.addons` on both the WC product render path and the preset render path. Calculator JS reads `window.PPS_CONFIG.addons.<slug>` directly.

### Files touched

- `pps-config-admin.php` — constant, defaults matrix, getter helpers, save handler, two new render helpers, UI sections.
- `pps-calculators.php` — `$config['addons']` injection on both render paths.
- All 4 calc HTMLs — `const _AD = ...` near `_CFG`; add-on availability guard at start of `calculate()`; each add-on UI row gated with `(_AD.<slug>!==false || <stateValue> non-default) && <UI>`.

---

## Worked examples — the philosophy in action

### 1. Perforation reprice (commits `9625325`, `6004198`, `c87cd8a`, `27095e7`)

**Old formula:** charged perforation setup once per order, regardless of line count.

**New formula** (brochure ~line 325):
```js
const perLineCost = Math.ceil(((qty * perf.price) / PCF.speed_gwperhr)
  * (PCF.labor_gw_hour * 2) * mk) + PCF.labor_gw_setup;
const perfMult = perf.count === 2 ? 1.6 : 1.0;  // 60% for each additional line
P.perforation = Math.ceil(perLineCost * perfMult);
```

Philosophy demonstrated:
- **Per-line setup fee** (`labor_gw_setup`, currently $50). Each perf line carries its own setup.
- **60% rule for additional lines.** After the first line is set up, the second costs only 60% of a first line's total (setup is shared on the same machine pass, runtime is doubled).
- **Knobs all in PCF**: `labor_gw_setup`, `labor_gw_hour`, `speed_gwperhr`, and `perf.price` are all admin-editable.

The user tuned several times: `speed_gwperhr` 500→250, `perf.price` 0.01→0.03, `labor_gw_setup` 35→50. Each change was a one-file edit in the PHP default + the HTML fallback PCF block.

### 2. Canva fee centralization (commit `3dd837a`)

Extracted three flat-fee rates from per-row `price` fields on `art_opts` into PCF scalars:

| PCF key | Default | Used for (`art.val`) |
|---|---:|---|
| `art_canva_fee` | $10 | 0.04 — flat Canva fee |
| `art_edit_rate` | $75/hr | 2.01 — artwork needs edits |
| `art_design_rate` | $75/hr | 4.01 — design from scratch |

`art_opts` rows are now just `{label, val}` identity metadata — no price column on the Artwork admin tab.

**Brochure artwork calculation** (lines ~350-357):
```js
const art = ART_OPTS.find(x => x.val === c.artwork) || ART_OPTS[0];
const artWork = art.val >= 2;
const ndc = art.val >= 4 ? ((PCF.art_design_rate * sides) * PCF.art_newdesignmodifier) + 15 : 0;
const aec = (art.val >= 2 && art.val < 4) ? PCF.art_edit_rate : 0;
const upc = (art.val > 0.03 && art.val < 1) ? PCF.art_canva_fee : 0; // val 0.04 = Canva
P.artwork = ndc + aec + upc;
```

**Saddle stitch / perfect bound artwork** (similar, but multiplied by N sets and scaled by total pages `tP`):
```js
let ndc = art.val >= 4 ? (PCF.art_design_rate * tP) * PCF.art_newdesignmodifier : 0;
let aec = (art.val >= 2 && art.val < 4)
  ? PCF.art_pagesperhour + ((c.artEditPages/PCF.art_pagesperhour) * PCF.art_edit_rate)
  : 0;
const upc = (art.val > 0.03 && art.val < 1) ? PCF.art_canva_fee : 0;
P.artwork = artWork ? (ndc+aec)*N : upc;
```

**Key semantic:** `upc` (upload-tier flat fee) is **not** multiplied by N or tP. Canva is one-time work regardless of page or set count.

**`art.val` identity convention (same across all calculators):**
- `val >= 4` → design from scratch tier
- `val >= 2 && val < 4` → edits tier
- `val < 2` (specifically `val > 0.03 && val < 1`) → Canva flat fee
- `val <= 0.03` → free upload tiers (no price)

### 3. Asymmetry that motivated the centralization

Before the refactor:

| Value | Type | Where edited |
|---|---|---|
| `art_pagesperhour` (40) | scalar | Production tab → Artwork group |
| `art_newdesignmodifier` (0.5) | scalar | Production tab → Artwork group |
| `art_opts[*].price` | per-row | **Artwork sub-tab → spreadsheet row** |

Admins had to drill into a spreadsheet to change Canva/edit/design rates, while closely-related scalar knobs lived one click away on the Production tab. The refactor put all 5 artwork values in one place.

---

## Shared pricing structure (all 3 calculators)

```js
function calculate(c) {
  // 1. Validation errors → early return { error: [...] }
  // 2. Derive basic values: qty, pressSheets, sides, tP (total pages), etc.
  // 3. Markup: mk = max(backend_maximummarkup - discount, backend_minimummarkup)
  //    (perfect bound and saddle now use 0.80*ln(tS); brochure uses 1.85*ln(tS)-0.5)
  // 4. Per-line-item costs (write to P object):
  //    P.paper, P.frontPrint, P.backPrint, P.cutting, P.folding, P.binding,
  //    P.coat, P.bundle, P.rc (round corner), P.perforation, P.outfold,
  //    P.artwork, P.bleed, P.proof, P.nonInv, P.rush, P.shipping
  // 5. Sum → total
  // 6. Turnaround days: max of all component days (paper, print, bindery,
  //    artwork, ship)
  // 7. Return { total, perUnit, days, P, debug, ... }
}
```

**`P` convention:** `P.x` holds the dollar contribution from line item `x`. The debug panel sums these into groups (`mat`, `lab`, `add`, `art`, `fee`, `disc`) for display.

**Markup application:** the `mk` factor multiplies most line items (materials, printing, coating, bundling, perforation, round corner), but does NOT multiply labor items like `P.folding` (raw cost), `P.artwork` (flat fees already sized), or `P.shipping` (passthrough).

---

## Patterns for adding a new pricing knob

### Pattern A — new PCF scalar (recommended for most values)

**4-file edit:**
1. `pps-config-admin.php` → `pps_default_config()` → `pcf` array: add the key with its default value.
2. `pps-config-admin.php` → `pps_config_tab_production()` → add an entry in the relevant group (e.g., `Labor Rates`, `Artwork`, `Fees`). The production tab renders a grid of `<input name="pcf[key]">` fields with labels.
3. `calc-*.html` → the `const PCF = Object.assign({ ... }, _CFG.pcf || {});` block near the top of each calculator: add the key to the hardcoded defaults for standalone testing.
4. `calc-*.html` → `calculate()`: reference it via `PCF.key`.

### Pattern B — new row in an option array

**2-file edit:**
1. `pps-config-admin.php` → `pps_default_config()` → the relevant array (`papers_nc`, `coatings`, `art_opts`, etc.): add the default row.
2. `calc-*.html` → the `const X_OPTS = _CFG.x_opts || [...]` line: add the row to the fallback.

The admin spreadsheet UI is auto-generated from the array shape — no admin form changes needed if the new row has the same columns as existing rows.

### Pattern C — promote a row-level price to a PCF scalar (the Canva pattern)

Do this when the same value is used in multiple places, or when the admin wants to tweak it alongside other global knobs.

1. Add PCF scalar (Pattern A).
2. Remove the `price` field from the option array rows (Pattern B in reverse).
3. Update the admin tab's column spec to drop the `price` column from the spreadsheet (see `pps_config_tab_artwork()` which now has separate `$art_cols` and `$bleed_cols` because `art_opts` dropped price but `bleed_opts` kept it).
4. Update `calculate()` to reference `PCF.key` instead of `option.price`.
5. **Backward compat:** existing `wp_options` rows may have stale `price` fields — they're harmlessly ignored since `calculate()` no longer reads them. On next admin save, the form writes back `{label, val}` only and the stale field drops out.

---

## Tuning guide — symptom → knob

### Adjusting in production (no code deploy)

1. WordPress admin → PPS Config → Production tab
2. **Brochure Markup** section: `backend_maximummarkup` / `backend_minimummarkup`
3. **Booklet Markup** section: `booklet_maximummarkup` / `booklet_minimummarkup` / `booklet_size_discount`
4. **Perfect Bound Markup** section: `perfectbound_maximummarkup` / `perfectbound_minimummarkup` / `perfectbound_size_discount`
5. Save → takes effect immediately

### Adjusting the preview (GitHub Pages)

Edit hardcoded defaults in the HTML files (lines ~107-138). Push to `pps-pricing-config`.

### Which knob to turn for which problem

| Symptom | Fix |
|---|---|
| Prices too high at small quantities | Lower `maximummarkup` |
| Prices too high at large quantities | Lower `minimummarkup` |
| Curve drops off too fast / cheap at mid-qty | Lower coefficient (0.80 → 0.60) |
| Curve stays high too long | Raise coefficient |
| 8.5×11 still way too expensive | Raise `*_size_discount` (0.15 → 0.25) |
| 8.5×11 now below Vistaprint | Lower `*_size_discount` |

---

## Sim / Node test harness

### Quick sim (saddle-stitch booklet)

```javascript
function calc(qty, pages, imp, mkx, mkn, coef, sd) {
  const ip = 0.0525, cp = 0.01;  // 80lb Gloss Factory + same cover
  const tS = (qty * pages / 4) / (imp / 2);
  const mk = Math.max(mkx - coef * Math.log(tS), mkn);
  let sub = 0;
  sub += ((0.01*2 + 0.05*2) * tS) * mk;           // insidePrint (uniform)
  sub += (((0.05 * tS) * 2) / imp) * mk;           // coverPrint (uniform)
  sub += (ip * tS) * mk;                            // insidePaper
  sub += ((cp * qty) / imp) * mk;                   // coverPaper
  sub += (tS / 600) * 35;                           // press
  sub += ((tS * imp) / 15000) * (45 + mk) + 7.5;   // cutting
  sub += ((((tS * imp) / 2) / 4000) * 50) + 35;    // stitching
  const disc = imp < 4 ? sub * (-sd) : 0;
  return Math.round(sub + disc);
}
```

**Caveat:** this sim is ~15-20% below the real calculator because it omits cover scoring, non-inventory fees, and other line items. Use for relative comparisons, not absolute price predictions.

### Full harness (slice the actual `calculate()`)

The brochure has been tested with a Node harness that slices the `calculate()` function out and runs it in isolation. Works because the pricing engine is self-contained and only depends on PCF constants + input config.

```bash
# Find the function start/end lines
awk '/^function calculate/{s=NR; depth=0} s { n=gsub(/\{/,"{"); depth+=n; n=gsub(/\}/,"}"); depth-=n; if (depth==0 && NR>s) { print NR; exit } }' calc-brochure.html

# Slice the relevant lines (including PCF definition) and run in Node
awk 'NR>=33 && NR<=482' calc-brochure.html > /tmp/pricing-core.js
node -e "
global.window = { innerWidth: 1200, PPS_CONFIG: {} };
global.React = { useState: () => [null, () => {}], useEffect: () => {}, useMemo: (f) => f(), useRef: () => ({ current: null }), useCallback: (f) => f };
const src = require('fs').readFileSync('/tmp/pricing-core.js', 'utf8');
const { calculate, PAPERS_NC } = (new Function(src + '\nreturn { calculate, PAPERS_NC };'))();
// ... construct a test config and call calculate() ...
"
```

The same technique works for saddle stitch and perfect bound, but they use an array for `c.sets` and reference several more `c.*` fields. The test config needs to be fuller.

**Alternative:** admin-override via `PPS_CONFIG.calc.pcf.*` in the test harness to verify that the override path flows through correctly (as done for the Canva fee verification in commit `3dd837a`).

---

## Where to find the artwork block in each calculator

| Calculator | `ART_OPTS` line | `calculate()` artwork block |
|---|---:|---:|
| `calc-brochure.html` | ~150 | ~351-357 |
| `calc-preview-test.html` (saddle) | ~317 | ~445-449 |
| `calc-perfect-bound.html` | ~328 | ~508-512 |

The shapes differ slightly — brochure's edit cost is flat `PCF.art_edit_rate`, but booklets compute `PCF.art_pagesperhour + (editPages/pph * art_edit_rate)` (the edit rate is treated as an hourly wage, setup included).

---

## Known issues / open questions

1. **8pp booklets run hot at high quantities.** Both sizes show +30-50% above Vistaprint at qty 1000, 8pp. Rationale: low page counts are a small slice of orders, and a tighter fit here would push 16pp/32pp below competitors. Accepted.
2. **Cutting formula has a quirk.** `(labor_cutting_hr + mk)` rather than `labor_cutting_hr * mk`. Left as-is to match the brochure calculator's pattern. Not fixed.
3. **Simulation vs reality gap.** The sim underestimates real calculator output. Real positioning is likely tighter than my estimates suggest.
4. **Estimates, not real quotes.** Already documented above — everything is tuned to industry-knowledge estimates.
5. **Perfect bound** has its own two-branch logarithmic discount curve (now superseded by the simple `0.80*ln(tS)` curve, but the old branched form is preserved as a `// ROLLBACK:` comment block in `calc-perfect-bound.html`). Worth understanding before touching its markup math.
6. **Saddle stitch** has a "sets" system (mothballed UI but code preserved) that multiplies several costs by N. The artwork upload-tier fee is deliberately NOT multiplied by N — review similar patterns before assuming a knob should scale by sets.
7. **Roll fold / bifold / accordion-4** 3D previewer fixes are still iterative — the user reported the last few fixes weren't fully correct. The 3D preview is orthogonal to pricing and can be worked on separately.

---

# Rollback reference

Pre-tune-up values, kept verbatim for selective revert. Do not edit this section without intent — its purpose is to document the exact prior state.

## How to roll back

**Option A — full revert (easiest):**
```bash
git revert 07e2127..HEAD   # reverts all pricing commits in order
git push origin pps-pricing-config
```

**Option B — selective revert:** copy the "Original values" below back into the matching files.

### Files touched by the tune-ups

- `calc-preview-test.html` (saddle-stitch booklet)
- `calc-perfect-bound.html` (perfect bound — added 2026-04-14)
- `pps-config-admin.php` (WordPress admin PCF defaults)

## Saddle-stitch — original values (pre-2026-04-11 tune-up)

### PCF defaults (`calc-preview-test.html`)

```javascript
backend_maximummarkup:9, backend_minimummarkup:1.5,
easydiscount_max:1500,
common_discount_max:1000,
// No booklet_* keys existed
// No size_8511_discount existed
```

### Markup curve (line ~401)

```javascript
const dL=(0.6*Math.log(tS))-0.1447;
const mk=Math.max(PCF.backend_maximummarkup-dL,PCF.backend_minimummarkup);
```

### Print markup (asymmetric — original)

```javascript
// insidePrint: BW gets mk, fullcolor addition does NOT
P.insidePrint=c.insideColor==="bw"
  ? ((PCF.printing_black_cost*2)*tS)*mk
  : (((PCF.printing_black_cost*2)*tS)*mk) + ((PCF.printing_fullcolor_cost*2)*tS);

// coverPrint: NO mk at all
P.coverPrint=c.coverColor==="bw"
  ? ((PCF.printing_black_cost*tS)*2)/imp
  : ((PCF.printing_fullcolor_cost*tS)*2)/imp;
```

### Size adjustment

**Did not exist.** No `P.discSize` line item, no "Size Adj." display entry.

### WordPress admin defaults (`pps-config-admin.php`)

```php
'backend_maximummarkup'  => 9,
'backend_minimummarkup'  => 1.5,
'easydiscount_max'       => 1500,
'common_discount_max'    => 1000,
// Admin UI had a single "Markup & Discounts" section (not split)
```

### Standalone header (removed 2026-04-11)

```jsx
<header style={{background:CC.dark}}>
  <div style={{height:3,background:`linear-gradient(90deg, ${CK.cyan} 25%, ${CK.magenta} 25%, ${CK.magenta} 50%, ${CK.yellow} 50%, ${CK.yellow} 75%, ${CK.key} 75%)`}}/>
  <div style={{maxWidth:1180,margin:"0 auto",padding:mob?"10px 14px":"12px 24px",display:"flex",alignItems:"center",gap:10}}>
    <img src={PPS_LOGO} alt="PPS" style={{width:mob?30:38,height:mob?30:38,borderRadius:7,flexShrink:0}} />
    <div>
      <div style={{fontSize:mob?13.5:15.5,fontWeight:700,color:"#fff"}}>Saddlestitch Booklet</div>
      <div style={{fontSize:mob?10:11,color:"rgba(255,255,255,.5)"}}>Priority Print Service</div>
    </div>
  </div>
</header>
```

### Proof modal blank-page restrictions (removed 2026-04-11)

Three guards prevented clicking blank pages:
```javascript
// Thumbnail strip
onClick={() => !p.isBlank && setProofIdx(i)}
// Grid view
onClick={() => { if (!p.isBlank) { setProofIdx(i); setProofView("single"); } }}
// Prev/Next navigation skipped blank pages
while(n>=0 && displayPages[n]?.isBlank) n--;
while(n<displayPages.length && displayPages[n]?.isBlank) n++;
```

## Perfect bound — original values (pre-2026-04-14 tune-up)

### PCF defaults (`calc-perfect-bound.html`)

```javascript
backend_maximummarkup:9, backend_minimummarkup:1.5,
easydiscount_max:1500,
common_discount_max:1000,
// No perfectbound_* keys existed
// No perfectbound_size_discount existed
```

### Markup curve (two-branch, pre-tune-up)

```javascript
const dL = tS >= 1000
  ? 1.1782 * Math.log(tS) - 5.5887
  : 2.5003 * Math.pow(Math.log(tS), 2) - 21.9175 * Math.log(tS) + 34.6437;
const mk = Math.max(PCF.backend_maximummarkup - dL, PCF.backend_minimummarkup);
```

Both the old curve and the asymmetric print-markup code are preserved as `// ROLLBACK:` comment blocks inline in `calc-perfect-bound.html` for quick revert without a git operation.

### Print markup (asymmetric — pre-tune-up)

```javascript
// insidePrint: BW pages get mk; fullcolor surcharge does NOT
const bwCost = ((PCF.printing_black_cost * sp / imp) * mk) * st.qty;
const colorCost = ((PCF.printing_fullcolor_cost / imp) * cp) * st.qty;  // no mk

// coverPrint: no mk on either branch
P.coverPrint = c.coverColor === "bw"
  ? ((PCF.printing_black_cost * 2 * tQ) / imp)
  : ((PCF.printing_fullcolor_cost * 2 * tQ) / imp);
```

### Size adjustment

**Did not exist.** No `P.discSize` line, no "Size Adj." display entry.

### WordPress admin defaults (`pps-config-admin.php`, pre-tune-up)

```php
// No perfectbound_* keys existed; perfect bound shared backend_* with brochure.
// Admin UI had no 'Perfect Bound Markup' section.
```

## Saddle-stitch cover-print formula bug fix — 2026-04-14

**Bug:** saddle-stitch cover print cost scaled with `tS` (inside sheet count), which grows with page count. Covers are one sheet per book — cost should not depend on how many inside pages the book has. Resulted in saddle-stitch overcharging covers by 2–8× at 16–32pp configurations.

**Old formula** (`calc-preview-test.html` line 430 before fix):
```javascript
P.coverPrint = c.coverColor === "bw"
  ? (((PCF.printing_black_cost * tS) * 2) / imp) * mk
  : (((PCF.printing_fullcolor_cost * tS) * 2) / imp) * mk;
```

**New formula:**
```javascript
P.coverPrint = c.coverColor === "bw"
  ? (((PCF.printing_black_cost * 2) * tQ) / imp) * mk
  : (((PCF.printing_fullcolor_cost * 2) * tQ) / imp) * mk;
```

**Impact at 5.5×8.5, fullcolor cover, imp=4:**

| Qty × Pages | Before ($) | After ($) | Drop |
|---|---|---|---|
| 100 × 16pp | 19 | 9 | −$10 |
| 500 × 16pp | 62 | 31 | −$31 |
| 1000 × 32pp | 300 | 75 | −$225 |

Re-tune `booklet_maximummarkup` upward (via WP admin, no code change) if competitor positioning now runs too low at high page counts.

`calcSaddle()` in `calc-perfect-bound.html` was updated in the same commit to mirror the fix for the side-by-side comparison table.

## What changed (current live state)

| Parameter | Before | After |
|---|---|---|
| `backend_maximummarkup` (brochure) | 9 | 15.2 |
| `backend_minimummarkup` (brochure) | 1.5 | 3.5 |
| `booklet_maximummarkup` | (didn't exist) | 8 |
| `booklet_minimummarkup` | (didn't exist) | 1.5 |
| `booklet_size_discount` | (didn't exist) | 0.15 |
| `easydiscount_max` | 1500 | 0 |
| `common_discount_max` | 1000 | 0 |
| Booklet markup curve | `0.6·ln(tS) - 0.1447` | `0.80·ln(tS)` |
| Booklet print markup | asymmetric (mk only on BW portion) | uniform (mk on all print) |
| Size discount | none | 15% off for 8.5×11 (imp<4) |
| Standalone header | present | removed |
| Blank pages in proof modal | not clickable | clickable (render as white) |
| `perfectbound_maximummarkup` | (didn't exist — used `backend_*`) | 8 |
| `perfectbound_minimummarkup` | (didn't exist — used `backend_*`) | 1.5 |
| `perfectbound_size_discount` | (didn't exist) | 0.15 |
| Perfect bound markup curve | two-branch quadratic<1000 / linear≥1000 | `0.80·ln(tS)` (old preserved as comment) |
| Perfect bound print markup | asymmetric (mk only on BW inside) | uniform (mk on all print) |
| Perfect bound size discount | none | 15% off for 8.5×11 (imp<4) |

---

## Production time — threshold-based schedule (2026-06-27)

### Prior formula (ROLLBACK reference)

```
baseDays = Math.floor(tS / PCF.sheetsturnaround)   // sheetsturnaround = 2500
days = Math.max(PCF.minimum_turnaround_days, baseDays + addon turnaround)
```

Linear: every 2,500 sheets added one production day. No upper cap on sheet count.

### New formula

`sheetsToDays(sheets)` — threshold lookup table, shared across all 4 calculators:

| Sheets (13×19 equiv.) | Production days |
|---|---|
| ≤ 1,000 | 0 |
| ≤ 2,500 | 1 |
| ≤ 5,000 | 2 |
| ≤ 7,500 | 3 |
| ≤ 10,000 | 4 |
| ≤ 12,500 | 5 |
| ≤ 15,000 | 6 |
| ≤ 17,500 | 7 |
| ≤ 20,000 | 8 |
| ≤ 30,000 | 9 |
| ≤ 40,000 | 10 |
| ≤ 50,000 | 11 |
| ≤ 60,000 | 12 |
| ≤ 70,000 | 13 |
| ≤ 80,000 | 14 |
| ≤ 90,000 | 15 |
| ≤ 100,000 | 16 |
| > 100,000 | **Quote required** (add-to-cart disabled) |

Add-on turnaround (paper days, vivid, coating, bundling, perforation, etc.) still stacks on top.

`PCF.minimum_turnaround_days` still enforced as the floor.

### Material lead times STACK, they don't accumulate (2026-07-24)

**Rule:** when a job needs more than one supplier-ordered material, those lead times run
**concurrently** — the wait is the **longest** one, not their sum. They are ordered from the
supplier at the same time and arrive in parallel.

```
materialDays = MAX(inside paper, cover paper, envelopes)     // NOT the sum
days = max(minimum_turnaround_days,
           baseDays + materialDays + addonDays + artDays + proofDays)
```

Materials still **precede** production, so `materialDays` is *added* to `baseDays` rather than
maxed against it — you cannot print on paper that has not arrived. Only the several material
leads overlap **each other**.

**In-house finishing stays additive.** Coating, bundling, perforating, round-cornering, vivid
second pass and artwork/proof days run sequentially on the floor, so they continue to sum.

Effect of the change: a job's schedule shortens by `min(lead₁, lead₂)`.

| Calculator | Combined leads | Before | After |
|---|---|---|---|
| Saddle stitch | inside 2d + cover 2d | 4 paper days | 2 |
| Perfect bound | inside 4d + cover 4d | 8 paper days | 4 |
| Greeting card | paper 4d + envelopes 5d | 9 material days | 5 |

**Where implemented** (`pT` = the material stack):
- `calc-preview-test.html` — `pT = MAX(floor(insidePaper.val), floor(cover.val))`; integer part
  of `val` is the lead days (`2.002` → 2d factory order, `0.001` → in stock)
- `calc-perfect-bound.html`, `calc-coupon-book.html` — `pT = MAX(inside.days ?? floor(val), cover.days ?? floor(val))`
- `calc-greeting-card.html` — `materialDays = MAX(paperDays, envDays)`; the `ti.envelopes` UI
  badge shows the **marginal** impact `max(0, envDays − paperDays)` so the badge total still
  equals the real schedule
- `calc-brochure.html`, `calc-letterhead.html`, `calc-postcard.html`, `calc-sticker.html` —
  **unchanged**: each has exactly one material lead (`paperDays`), so `MAX` of one value is a
  no-op. If a second material lead is ever added to these, it must join the same `MAX`.

**ROLLBACK:** restore the summed forms — `pT = insideDays + coverDays` in the three booklet
calcs, and `+ envDays` back into the greeting-card `days` expression (dropping `materialDays`).

`PCF.sheetsturnaround` is no longer used for base production days but remains referenced by some addon turnaround estimates (e.g., vivid second-pass time).

### >100,000 sheet cap

When `sheetsToDays()` returns `null`, `calculate()` early-returns `{error: ["..."]}`, which disables the Add to Order button and shows a "contact us for a custom quote" message. No pricing is computed.

### Files touched

- `calc-preview-test.html`, `calc-perfect-bound.html`, `calc-brochure.html`, `calc-coupon-book.html` — `sheetsToDays()` function added before `calculate()`; early return for >100k; `days` formula updated.

## Materials price floor — server-side validation bound (2026-07-27)

**This is the only pricing arithmetic that lives in PHP, and it is not a quote.** It is a
security bound on `pps_ajax_add_to_cart`, and must never be used to price anything.

### Why it exists

`pps_price` is computed client-side and posted to the cart, where
`woocommerce_before_calculate_totals` applies it verbatim as the line-item price. The only
prior defence was a flat floor — `max($5, 50% x regular_price)` — which does not scale with
the job. With `regular_price` set to $50 on a registry product, a $900 booklet order could be
checked out for $25.

### Rule (owner, 2026-07-27)

Nothing sells below the minimum markup on materials, excluding labour and add-ons:

```
floor = ( (sheet cost + print cost) x total sheets ) x booklet_minimummarkup
```

Implemented in `pps_materials_price_floor()`, mirroring the engine's four material lines:

```
inside_sheets = SUM(qty x pages) / 4 / (imp / 2)
cover_sheets  = SUM(qty) / imp
inside_print  = bw ? black*2 : black*2 + fullcolor*2
cover_print   = bw ? black*2 : fullcolor*2
materials     = (inside_paper + inside_print) x inside_sheets
              + (cover_paper  + cover_print ) x cover_sheets
floor         = materials x booklet_minimummarkup x (1 - sale_discount_pct)
```

### Why these inputs can be trusted

Every input is either server-side config (paper prices, click costs, imposition, markup,
sale) or a customer selection that is **self-enforcing** — qty, pages, trim size and stock
all flow into `PPS-Spec`, so understating one to depress the floor also shrinks the job that
gets produced. The `debug` block in `pps_metadata` is deliberately **not** used: it has no
downstream effect, so lying about it would be free.

### Deliberate design choices

- **Fails open.** Custom trims, unknown stock, unrecognised calculators and malformed
  metadata all return `null` and skip the check. A false rejection costs a real sale; a
  missed check costs margin.
- **Self-cover uses the `cover_same` stub price (~$0.01), not the inside stock.** Those
  sheets are already counted in the inside total. Using the real price here inflates the
  floor several-fold on the commonest configuration.
- **Cover's own minimum markup (1.8) is ignored** in favour of the lower inside figure
  (1.45), so the bound can never exceed a legitimate quote.
- **Tracks the sale.** A site-wide sale genuinely lowers the minimum legitimate price.
- **Booklets only** (saddle / perfect-bound / coupon). Other calc types fail open; extending
  means porting their sheet math.

### Verification (against the live engine, not by inspection)

3,096 configurations driven through the real `calculate()` — every preset size, 25-5,000 qty,
8-100 pages, colour and B&W, self-cover and separate cover, cheapest and dearest stock:

| | |
|---|---|
| Violations (floor > legitimate price) | **0** |
| floor / price — median | 0.37 |
| floor / price — max | 0.895 |

Sale stress test caught a real defect pre-ship: without the `(1 - sale_discount_pct)` term, a
20% sale falsely rejected 7 configurations (floor reached 1.12x price) and 30% rejected 16.
With it, the worst ratio holds at 0.895 at every sale level.

### Limits

Worst case is bounded at roughly materials cost, not eliminated — a tampered price between
the floor and the true total still passes. Tightening further means replicating the markup
and discount curves in PHP, which would rot as those change. The authoritative fix remains
porting the engine for a full server-side recompute.

**Knob:** `pcf.pps_floor_enforce` (default 1). Set to 0 to log without rejecting.

## Human-touch day on art & proofing (2026-07-29)

**Status: implemented in `calc-modern-draft.html` ONLY. Noted here so it can be
applied to the other calculators — it is not in any of them yet.**

### Rule (owner)

Anything that needs a person in the loop before we can print costs **+1 business
day**. The fully self-serve path stays the fastest route and adds nothing.

| Selection | val | Before | After |
|---|---|---|---|
| Upload Art with Order | 0.01 | 0 | **0** — unchanged, deliberately |
| Email Art After Order | 0.02 | 0 | **+1** |
| Artwork already discussed | 0.03 | 0 | **+1** |
| I have a design in Canva | 0.04 | 0 | **+1** |
| Artwork needs edits | 2.01 | `eT` | unchanged (already has a day term) |
| Design from scratch | 4.01 | `dT` | unchanged (already has a day term) |
| Proof & Approve Online | 0 | 0 | **0** — the stated exception |
| Manual Digital Proof | 0.01 | 0 | **+1** |
| Hardcopy Proof | 3.01 | `pE` | unchanged (already has a day term) |

`Upload Art with Order` staying at 0 was an explicit owner decision (2026-07-29):
the literal reading of "all art variables previously 0" would have included it,
which raises baseline turnaround on essentially every order, since it is the
default. The self-serve path pairs with online approval as the fastest route.

### Implementation notes

```js
const humanArt   = (art.val > 0.015 && art.val < 0.045) ? 1 : 0;   // 0.02 / 0.03 / 0.04
const humanProof = (c.proof > 0 && c.proof < 3) ? 1 : 0;           // 0.01
const hT = Math.min(1, humanArt + humanProof);
days = Math.max(PCF.minimum_turnaround_days, Math.floor(base)) + hT;
```

Three things that are easy to get wrong when porting this:

1. **`hT` must sit OUTSIDE the `minimum_turnaround_days` clamp.** Added inside it,
   a typical small job floors at the minimum either way and the day disappears —
   i.e. it would do nothing on exactly the orders it is meant to affect. Verified:
   with it inside, every combination still read 3 days.
2. **Capped at one day total, not one per dimension.** The rule is "+1 day to
   their turnaround", so a Canva link *and* a manual proof together is still +1.
3. **Ranges, not equality.** These vals arrive from admin config as floats.

The `ti` badges attribute the day to whichever control caused it, with the art
side taking precedence — since `hT` is capped at one, showing it on both would
imply two days when only one is charged.

### Verified matrix (saddle, 100 qty / 8pp, in-stock paper)

| Art | Approve Online | Manual Digital | Hardcopy |
|---|---|---|---|
| Upload with Order | **3** | 4 | 4 |
| Email After Order | 4 | 4 | 5 |
| Already discussed | 4 | 4 | 5 |
| Canva link | 4 | 4 | 5 |

### To apply elsewhere

`calc-preview-test`, `calc-perfect-bound`, `calc-coupon-book`, `calc-brochure`,
`calc-letterhead`, `calc-postcard`, `calc-sticker`, `calc-greeting-card`. The flat
calcs compute `artDays` separately from `proofDays`; the same two predicates and
the same after-the-clamp placement apply.
