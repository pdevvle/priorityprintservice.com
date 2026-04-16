# Pricing Approach & Reference

This document captures the pricing strategy applied to the PPS calculators, the reasoning behind each decision, and what a new Claude session needs to know to continue tuning or troubleshooting.

**Live branch:** `pps-pricing-config` (GitHub Pages deploys from here)
**Last major tune:** 2026-04-11
**Rollback reference:** `pricing-rollback.md`

---

## Goal

Position PPS pricing **slightly above** the most expensive major competitor (Vistaprint) at every size/quantity tier. This premium positioning signals quality while staying within the band where competitive shoppers will still convert.

Target range: **+5% to +15%** above Vistaprint.

---

## Architecture of the Pricing System

### Where values live

Each calculator HTML file has hardcoded PCF defaults. In production, WordPress injects `window.PPS_CONFIG.calc.pcf` which overrides those defaults via `Object.assign`. So:

- **Standalone preview** (GitHub Pages): uses HTML hardcoded defaults
- **Production WordPress site**: uses admin config from `pps-config-admin.php`

**Both must match** or production will silently override the hardcoded values. This bit us once — lesson learned.

### Key files

| File | Contains |
|---|---|
| `calc-preview.html` | Saddle-stitch booklet calculator + hardcoded PCF defaults |
| `calc-brochure.html` | Brochure calculator + hardcoded PCF defaults |
| `calc-perfect-bound.html` | Perfect-bound booklet calculator |
| `pps-config-admin.php` | WordPress admin PCF defaults + admin UI |
| `pps-calculators.php` | Injects `PPS_CONFIG` into every product page |

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

---

## Why Brochure & Booklet Need Different Params

**This is the critical insight.** Naively copying the brochure values broke the booklet calculator, produced +40-200% overpricing.

### Brochure tS range

```
tS = qty / imp
```

At qty 25, 8.5×11 trifold: tS ≈ 12
At qty 2500, 8.5×11 trifold: tS ≈ 1250

**Range: ~12 to ~1250**

### Booklet tS range

```
tS = (qty * pages / 4) / (imp / 2)
```

At qty 25, 8pp, 5.5×8.5: tS = 25
At qty 1000, 32pp, 8.5×11: tS = 8000

**Range: ~25 to ~8000** — much larger because page count is in the formula.

### Why this matters

A markup curve tuned for tS=12..1250 (brochure) decays to the floor long before it reaches tS=8000 (booklet). Applying the brochure's steep coefficient (1.85) to booklets makes the markup drop off way too fast AND the floor (3.5) stays too high at those huge tS values — result: way too expensive at every quantity.

**Solution:** each product gets its own PCF keys so they can be tuned independently.

---

## The Booklet-Specific Problem: Imposition Penalty

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

**Decision:** apply a size-based discount specifically for 8.5×11 to bring it closer to competitors while keeping a single curve.

---

## Final Applied Values

### Brochure (`calc-brochure.html`) — changes from an earlier session

```
backend_maximummarkup: 15.2
backend_minimummarkup: 3.5
easydiscount_max:      0
markup curve:          dL = 1.85 * ln(tS) - 0.5
print markup:          uniform (applied to all print costs)
baseCost:              removed ($35 was always added)
```

**Expected positioning:** +1% to +12% above Vistaprint.

### Booklet (`calc-preview.html`) — this session

```
booklet_maximummarkup: 8
booklet_minimummarkup: 1.5
booklet_size_discount: 0.15  (15% off for imp<4 sizes)
easydiscount_max:      0
common_discount_max:   0
markup curve:          dL = 0.80 * ln(tS)
print markup:          uniform
```

**Expected positioning:**
- 5.5×8.5: +3-53% (tighter for thicker books, hot for 8pp at high qty)
- 8.5×11: +1-49% with size discount

### Perfect Bound (`calc-perfect-bound.html`) — 2026-04-14

```
perfectbound_maximummarkup: 8
perfectbound_minimummarkup: 1.5
perfectbound_size_discount: 0.15  (15% off for imp<4 sizes)
easydiscount_max:      0
common_discount_max:   0
markup curve:          dL = 0.80 * ln(tS)     // was two-branch: quadratic<1000, linear≥1000
print markup:          uniform                // was asymmetric (mk only on BW inside)
```

Mirrors the saddle-stitch booklet architecture: new dedicated `perfectbound_*` PCF keys so it can be tuned independently of brochure (previously shared `backend_*` keys). Old two-branch curve and asymmetric print markup are preserved inline as commented-out `// ROLLBACK:` blocks for quick revert without a git operation.

### WordPress admin (`pps-config-admin.php`)

Defaults updated to match all calculators:
- `backend_*` keys: brochure values (15.2 / 3.5)
- `booklet_*` keys: booklet values (8 / 1.5 / 0.15)
- `perfectbound_*` keys: perfect bound values (8 / 1.5 / 0.15)
- Admin UI split into four sections: **Brochure Markup**, **Booklet Markup**, **Perfect Bound Markup**, **Discounts**

---

## Structural Fixes Applied to Both Calculators

### 1. Uniform print markup

Originally, print costs had asymmetric markup:
- Inside print BW → `×mk`, but fullcolor addition had no markup
- Cover print → no markup at all

This structurally limited how much the markup curve could influence total price. Fixed by multiplying `mk` uniformly across all print costs.

### 2. Disabled `easydiscount` and `common_discount`

These were ad-hoc discount caps meant to keep small/common configs competitive. With the new curve and size adjustment, they're redundant and created non-monotonic pricing weirdness. Set both max caps to 0.

### 3. Size-based discount (booklet only)

New `P.discSize` line item in the breakdown:
- Applies 15% off the subtotal when `imp < 4` (i.e., 8.5×11 and larger)
- Shows as "Size Adj." in the price breakdown
- Hides automatically for 5.5×8.5 (imp=4)

---

## Competitor Data — The Honest Truth

**All "Vistaprint" comparison numbers used during tuning were Claude's industry-knowledge estimates, NOT scraped real-world prices.**

Why:
- This environment can't reach competitor sites (network blocked)
- Vistaprint blocks automated requests even from unrestricted environments
- Their pricing is session-token-based, not URL-deterministic

What this means for a new session:
- The estimates are in the right ballpark (printing industry pricing is well-understood)
- Real prices probably within ±15% of estimates
- If real-world positioning feels wrong post-launch, adjust PCF values via WordPress admin (no code change needed)

**If you get real quotes**, share them with the format:
```
size × pages × qty → VP price
5.5×8.5, 16pp, 100 → $X
5.5×8.5, 16pp, 1000 → $X
```
I can re-tune the curve around real data points.

---

## How to Tune Further

### Adjusting in production (no code deploy)

1. WordPress admin → PPS Config → Production tab
2. **Brochure Markup** section: `backend_maximummarkup` / `backend_minimummarkup`
3. **Booklet Markup** section: `booklet_maximummarkup` / `booklet_minimummarkup` / `booklet_size_discount`
4. Save → takes effect immediately

### Adjusting the preview (GitHub Pages)

Edit hardcoded defaults in the HTML files (lines ~107-138). Push to `pps-pricing-config`.

### Which knob to turn for which problem

| Symptom | Fix |
|---|---|
| Prices too high at small quantities | Lower `maximummarkup` |
| Prices too high at large quantities | Lower `minimummarkup` |
| Curve drops off too fast / cheap at mid-qty | Lower coefficient (0.80 → 0.60) |
| Curve stays high too long | Raise coefficient |
| 8.5×11 still way too expensive | Raise `booklet_size_discount` (0.15 → 0.25) |
| 8.5×11 now below Vistaprint | Lower `booklet_size_discount` |

### Sim script (for sanity checks)

The `calc` function structure is reproducible in Node.js:
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

---

## Known Issues & Trade-offs Accepted

1. **8pp booklets run hot at high quantities.** Both sizes show +30-50% above Vistaprint at qty 1000, 8pp. Rationale: low page counts are a small slice of orders, and a tighter fit here would push 16pp/32pp below competitors. Accepted.

2. **Cutting formula has a quirk.** `(labor_cutting_hr + mk)` rather than `labor_cutting_hr * mk`. Left as-is to match the brochure calculator's pattern. Not fixed.

3. **Simulation vs reality gap.** The sim underestimates real calculator output. Real positioning is likely tighter than my estimates suggest.

4. **Estimates, not real quotes.** Already documented above — everything is tuned to industry-knowledge estimates.

---

## Files Modified This Session (in `pps-pricing-config` branch)

| File | Change |
|---|---|
| `calc-preview.html` | Markup params, curve, uniform print markup, size discount, build timestamp, header removal, blank-page proofing |
| `pps-config-admin.php` | Brochure defaults updated, booklet_* keys added, admin UI split |
| `CLAUDE.md` | Added deployment instructions |
| `pricing-rollback.md` | New — pre-tune-up values for reverting |
| `pricing-approach.md` | New — this document |

## For a New Chat Session

**To pick up where this left off, read:**
1. `CLAUDE.md` — deployment branch + architecture
2. `pricing-approach.md` — this file, the pricing strategy
3. `pricing-rollback.md` — what the old values were

**Key facts to remember:**
- Deploy to `pps-pricing-config` ONLY (GitHub Pages)
- Brochure uses `backend_*` keys; booklet uses `booklet_*` keys
- Vistaprint comparison data is estimates, not scraped
- Simulation underestimates by ~15-20%
- Both the HTML file AND `pps-config-admin.php` must be updated when changing defaults (PHP overrides HTML in production)
