# Pricing Rollback Reference

Original pricing configuration **before** the 2026-04-11 booklet pricing tune-up.
Keep this file as a reference in case you need to revert any changes.

## How to roll back

**Option A — full revert (easiest):**
```bash
git revert 07e2127..HEAD   # reverts all pricing commits in order
git push origin pps-pricing-config
```

**Option B — selective revert:** copy the "Original values" below back into the matching files.

---

## Files touched

- `calc-preview-test.html` (booklet calculator)
- `calc-perfect-bound.html` (perfect bound calculator — added 2026-04-14)
- `pps-config-admin.php` (WordPress admin PCF defaults)

## Original values (pre-tune-up)

### Booklet calculator (`calc-preview-test.html`) — PCF defaults

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

---

## Perfect Bound — original values (pre-2026-04-14 tune-up)

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

---

## Saddle-stitch cover print formula bug fix — 2026-04-14

**Bug:** saddle-stitch cover print cost scaled with `tS` (inside sheet count), which grows with page count. Covers are one sheet per book — cost should not depend on how many inside pages the book has. Resulted in saddle-stitch overcharging covers by 2–8× at 16–32pp configurations.

**Old formula (calc-preview-test.html line 430 before fix):**
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

`calcSaddle()` in calc-perfect-bound.html was updated in the same commit to mirror the fix for the side-by-side comparison table.

---

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

## Note on competitor pricing data

All Vistaprint comparison numbers used during the tune-up were **Claude's industry-knowledge estimates**, not scraped or live quotes. If real-world positioning feels wrong after deployment, adjust `booklet_maximummarkup`, `booklet_minimummarkup`, or `booklet_size_discount` via the WordPress admin (no code change needed) — both calculators read these via `PPS_CONFIG.calc`.
