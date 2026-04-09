# PPS Calculator Pricing Philosophy

Reference doc for applying the brochure calculator's pricing patterns to the
other two calculators (saddle stitch, perfect bound). Paste this into a new
chat as context when working on pricing changes.

---

## The 3 calculators

| File | Product | Status |
|---|---|---|
| `calc-brochure.html` | Brochure & flat printing | Most recently refactored — reference implementation |
| `calc-perfect-bound.html` | Perfect bound booklets | Shares proof/preview with saddle stitch |
| `calc-preview-test.html` | Saddle stitch booklets | Oldest, most complete proof/preview system |

All three are self-contained HTML files with React+Babel inline. Each has its
own `PCF`, `calculate()`, `ART_OPTS`, etc. They are **not** a shared module —
changes to pricing philosophy need to be re-applied in each file.

---

## Value flow: wp_options → calculate()

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

**Key insight:** the hardcoded defaults in each HTML file are **only fallbacks**
for standalone preview/testing. In production (inside WordPress), the
`pps_calc_config` wp_options row always overrides them. Admins edit values via
**WP Admin → PPS Config** (tabs: Production, Papers, Finishing, Artwork,
Sizes, Shipping, Tooltips).

---

## The "centralize numeric knobs in PCF" philosophy

**Rule:** if a number might ever need tweaking by an admin, it belongs as a
PCF scalar on the **Production tab**, not buried in a row of a sub-tab
spreadsheet.

### What goes in PCF (scalar constants, Production tab)
- Labor rates (`labor_press_hr`, `labor_bindery_hr`, `labor_cutting_hr`,
  `labor_gw_hour`, `labor_gw_setup`)
- Machine speeds (`press_printsperhour`, `bindery_morgana_impressionperhour`,
  `cutter_cyclesperhour`, `speed_gwperhr`)
- Markup factors (`backend_maximummarkup`, `backend_minimummarkup`)
- Easy discount (`easydiscount_factor`, `easydiscount_max`)
- Fees (`proof_hardcopy_cost`, `proof_digital_cost`, `bleed_minimum`,
  `non_inventory_fee`)
- Turnaround knobs (`minimum_turnaround_days`, `sheetsforlowcosthardcopyproof`)
- Artwork fees: `art_pagesperhour`, `art_newdesignmodifier`, `art_canva_fee`,
  `art_edit_rate`, `art_design_rate`

### What goes in option arrays (Papers/Finishing/Artwork tabs)
- `papers_nc` / `papers_cs` — paper stocks with per-row `val`, `label`, `price`
- `coatings`, `bundling`, `corners`, `perf_opts` — finishing options with
  `val`, `label`, `price`, `count`
- `art_opts`, `bleed_opts` — artwork options
- `size_presets` — sheet size groups
- `closures` — shipping holiday closures

### Red flag to watch for
If you find yourself wanting to change "the price of X" and it's buried as a
`price` field on a single row of an option array, consider whether it should
be promoted to a PCF scalar. Recent example: the Canva fee used to live as
the `price` field of the "I have a design in Canva" row in `art_opts`. It was
promoted to `PCF.art_canva_fee` so admins can edit it on the Production tab
next to other labor/fee constants.

---

## Recent pricing changes on brochure (the philosophy in action)

### 1. Perforation reprice (commits `9625325`, `6004198`, `c87cd8a`, `27095e7`)

**Old formula:** charged perforation setup once per order, regardless of line count.

**New formula (brochure line ~325):**
```js
const perLineCost = Math.ceil(((qty * perf.price) / PCF.speed_gwperhr)
  * (PCF.labor_gw_hour * 2) * mk) + PCF.labor_gw_setup;
const perfMult = perf.count === 2 ? 1.6 : 1.0;  // 60% for each additional line
P.perforation = Math.ceil(perLineCost * perfMult);
```

**Philosophy demonstrated:**
- **Per-line setup fee** (`labor_gw_setup`, currently $50). Each perf line
  carries its own setup.
- **60% rule for additional lines.** After the first line is set up, the
  second line costs only 60% of a first line's total (setup is shared on the
  same machine pass, runtime is doubled).
- **Knobs all in PCF**: `labor_gw_setup`, `labor_gw_hour`, `speed_gwperhr`,
  and `perf.price` (the per-line runtime rate) are all admin-editable.

The user tuned these knobs several times: `speed_gwperhr` 500→250,
`perf.price` 0.01→0.03, `labor_gw_setup` 35→50. Each change was a one-file
edit in the PHP default + the HTML fallback PCF block.

### 2. Canva fee centralization (commit `3dd837a`)

Extracted three flat-fee rates from per-row `price` fields on `art_opts` into
PCF scalars:

| PCF key | Default | Used for (`art.val`) |
|---|---:|---|
| `art_canva_fee` | $10 | 0.04 — flat Canva fee |
| `art_edit_rate` | $75/hr | 2.01 — artwork needs edits |
| `art_design_rate` | $75/hr | 4.01 — design from scratch |

`art_opts` rows are now just `{label, val}` identity metadata — no price
column on the Artwork admin tab.

**Brochure artwork calculation** (lines ~350-357):
```js
const art = ART_OPTS.find(x => x.val === c.artwork) || ART_OPTS[0];
const artWork = art.val >= 2;
const ndc = art.val >= 4 ? ((PCF.art_design_rate * sides) * PCF.art_newdesignmodifier) + 15 : 0;
const aec = (art.val >= 2 && art.val < 4) ? PCF.art_edit_rate : 0;
const upc = (art.val > 0.03 && art.val < 1) ? PCF.art_canva_fee : 0; // val 0.04 = Canva
P.artwork = ndc + aec + upc;
```

**Saddle stitch / perfect bound artwork** (similar, but multiplied by N sets
and scaled by total pages `tP`):
```js
let ndc = art.val >= 4 ? (PCF.art_design_rate * tP) * PCF.art_newdesignmodifier : 0;
let aec = (art.val >= 2 && art.val < 4)
  ? PCF.art_pagesperhour + ((c.artEditPages/PCF.art_pagesperhour) * PCF.art_edit_rate)
  : 0;
const upc = (art.val > 0.03 && art.val < 1) ? PCF.art_canva_fee : 0;
P.artwork = artWork ? (ndc+aec)*N : upc;
```

**Key semantic:** the `upc` (upload-tier flat fee) is **not** multiplied by N
or tP. Canva is one-time work regardless of page or set count.

**`art.val` identity convention (same across all calculators):**
- `val >= 4` → design from scratch tier
- `val >= 2 && val < 4` → edits tier
- `val < 2` (specifically `val > 0.03 && val < 1`) → Canva flat fee
- `val <= 0.03` → free upload tiers (no price)

### 3. Noting a prior asymmetry that motivated the fix

Before the centralization:
| Value | Type | Where edited |
|---|---|---|
| `art_pagesperhour` (40) | scalar | Production tab → Artwork group |
| `art_newdesignmodifier` (0.5) | scalar | Production tab → Artwork group |
| `art_opts[*].price` | per-row | **Artwork sub-tab → spreadsheet row** |

Admins had to drill into a spreadsheet to change Canva/edit/design rates,
while closely-related scalar knobs lived one click away on the Production
tab. The refactor put all 5 artwork values in one place.

---

## Shared pricing structure (all 3 calculators)

```js
function calculate(c) {
  // 1. Validation errors → early return { error: [...] }
  // 2. Derive basic values: qty, pressSheets, sides, tP (total pages), etc.
  // 3. Markup: mk = max(backend_maximummarkup - discount, backend_minimummarkup)
  //    (perfect bound has a two-branch logarithmic curve — unique)
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

**`P` convention:** `P.x` holds the dollar contribution from line item `x`.
The debug panel sums these into groups (`mat`, `lab`, `add`, `art`, `fee`,
`disc`) for display.

**Markup application:** the `mk` factor multiplies most line items
(materials, printing, coating, bundling, perforation, round corner), but
does NOT multiply labor items like `P.folding` (raw cost), `P.artwork` (flat
fees already sized), or `P.shipping` (passthrough).

---

## Common patterns when adding a new pricing knob

### Pattern A: new PCF scalar (recommended for most values)

**4-file edit:**
1. `pps-config-admin.php` → `pps_default_config()` → `pcf` array: add the key
   with its default value.
2. `pps-config-admin.php` → `pps_config_tab_production()` → add an entry in
   the relevant group (e.g., `Labor Rates`, `Artwork`, `Fees`). The production
   tab renders a grid of `<input name="pcf[key]">` fields with labels.
3. `calc-*.html` → the `const PCF = Object.assign({ ... }, _CFG.pcf || {});`
   block near the top of each calculator: add the key to the hardcoded
   defaults for standalone testing.
4. `calc-*.html` → `calculate()`: reference it via `PCF.key`.

### Pattern B: new row in an option array

**2-file edit:**
1. `pps-config-admin.php` → `pps_default_config()` → the relevant array
   (`papers_nc`, `coatings`, `art_opts`, etc.): add the default row.
2. `calc-*.html` → the `const X_OPTS = _CFG.x_opts || [...]` line: add the
   row to the fallback.

The admin spreadsheet UI is auto-generated from the array shape — no admin
form changes needed if the new row has the same columns as existing rows.

### Pattern C: promote a row-level price to a PCF scalar (the Canva pattern)

Do this when the same value is used in multiple places, or when the admin
wants to tweak it alongside other global knobs.

1. Add PCF scalar (Pattern A).
2. Remove the `price` field from the option array rows (Pattern B in reverse).
3. Update the admin tab's column spec to drop the `price` column from the
   spreadsheet (see `pps_config_tab_artwork()` which now has separate
   `$art_cols` and `$bleed_cols` because art_opts dropped price but
   bleed_opts kept it).
4. Update `calculate()` to reference `PCF.key` instead of `option.price`.
5. **Backward compat:** existing wp_options rows may have stale `price`
   fields — they're harmlessly ignored since `calculate()` no longer reads
   them. On next admin save, the form writes back `{label, val}` only and
   the stale field drops out.

---

## Testing strategy (the brochure harness)

The brochure has been tested with a Node harness that slices the `calculate()`
function out and runs it in isolation. This works because the pricing engine
is self-contained and only depends on the PCF constants + input config.

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

The same technique works for saddle stitch and perfect bound, but they use
an array for `c.sets` and reference several more `c.*` fields. The test
config needs to be fuller.

**Alternative testing:** admin-override via `PPS_CONFIG.calc.pcf.*` in the
test harness to verify that the override path flows through correctly (as
done for the Canva fee verification in commit `3dd837a`).

---

## Quick reference: where in each calculator is the artwork block?

| Calculator | `ART_OPTS` line | `calculate()` artwork block |
|---|---:|---:|
| calc-brochure.html | ~150 | ~351-357 |
| calc-preview-test.html (saddle) | ~317 | ~445-449 |
| calc-perfect-bound.html | ~328 | ~508-512 |

The shapes differ slightly — brochure's edit cost is flat `PCF.art_edit_rate`,
but booklets compute `PCF.art_pagesperhour + (editPages/pph * art_edit_rate)`
(the edit rate is treated as an hourly wage, setup included).

---

## Philosophy TL;DR

1. **If it's a number, put it in PCF on the Production tab.** Don't bury
   it in a row of a sub-tab spreadsheet.
2. **Option arrays are for IDENTITY (label+val), not for PRICING.** Prices
   should be referenced via PCF lookups keyed on `val` ranges.
3. **Hardcoded HTML defaults are only fallbacks.** In prod, wp_options
   always overrides. But keep them in sync so standalone preview and fresh
   installs behave correctly.
4. **Backward compat is free.** `calculate()` reading PCF scalars can
   safely ignore stale `price` fields on saved option rows.
5. **Each calculator is self-contained.** A change to shared pricing
   philosophy needs to be applied in all 3 HTML files. They will NEVER be
   a shared module — that's the deliberate architecture.

---

## Open questions / known issues

- **Perfect bound** has its own two-branch logarithmic discount curve that
  doesn't follow the simple `max(maximummarkup - discount, minimummarkup)`
  pattern. Worth understanding before touching its markup math.
- **Saddle stitch** has a "sets" system (mothballed UI but code preserved)
  that multiplies several costs by N. The artwork upload-tier fee is
  deliberately NOT multiplied by N — review similar patterns before
  assuming a knob should scale by sets.
- **Roll fold / bifold / accordion-4** 3D previewer fixes are still iterative
  — the user reported the last few fixes weren't fully correct. The 3D
  preview is orthogonal to pricing and can be worked on separately.
