# The 4over matrix file — contract

One 4over product page → one JSON file → one matrix. This file is the interface
between the scheduled Chrome capture and the plugin's ingest; both sides read
only this document, so neither drifts.

---

## 1 · One file per product, not one catalogue file

Confirmed 2026-08-15. It is the better shape, and not just tidier:

- **A bad capture corrupts one product, not the catalogue.** With a single file,
  a session that dies at product 30 of 40 either writes a truncated catalogue or
  writes nothing.
- **Ingest becomes incremental.** One product failing its gates does not hold
  back the thirty-nine that passed.
- **Drive's revision history becomes per-product.** "When did Square's prices
  move?" is answerable by looking at one file's versions rather than diffing
  forty products out of a monolith.
- **Concurrent captures cannot collide.** Two runs touching different products
  touch different files.
- **A `verified_at` bump touches one small file**, which matters because the
  spot-check path (`docs/4OVER_PIPELINE.md` §4) runs far more often than a full
  rebuild.

### Folder layout

```
PPS 4over Pricing/            ← one Drive folder, flat
  manifest.json               ← written by the PLUGIN: the work list
  round-business-cards.json   ← written by CHROME: one per product
  standard-business-cards.json
  square-business-cards.json
  foldover-business-cards.json
  _rejected/                  ← ingest moves gate failures here, with a reason
```

**Flat, not nested by family.** The plugin lists one folder rather than walking
a tree, which is one Drive call and no recursion. Family grouping comes from
*inside* each file, so a file can be renamed or moved without breaking anything.

**The filename is a convenience, never an identity.** `family` and `key` inside
the file are authoritative. A mis-named file still ingests correctly; a file
whose *contents* declare the wrong key is the only way to actually break this,
and the gates catch it.

---

## 2 · The file

```jsonc
{
  "schema": 1,                        // REQUIRED. Bump only on a breaking change.

  "family": "business-cards",         // REQUIRED. Groups products in the selector.
  "key": "round",                     // REQUIRED. Unique within the family. Stable forever —
                                      //   it is what _pps_defaults.product and ?product= name.
  "label": "Round",                   // REQUIRED. What the selector shows.
  "group": "Die-cut shapes",          // optional. <optgroup> inside the family.

  "product": "Circle Business Cards", // REQUIRED. 4over's own name; shown as the H1.
  "url": "https://4over.com/circle-business-cards",   // REQUIRED. Where it was captured from.

  "captured_at": "2026-08-15",        // REQUIRED. Last FULL walk. ISO date, UTC.
  "verified_at": "2026-08-15",        // REQUIRED. Last spot-check that matched. See §4.
  "max_age_days": 45,                 // optional, default 45. Past this the calculator
                                      //   refuses to quote rather than quoting stale.

  "production_days": 4,               // REQUIRED. 4over's own turnaround, in business days.
                                      //   Delivery = this + 1 handling + 3 transit.

  "fixed": {                          // REQUIRED. Everything NOT variable, shown as chips.
    "size": "2\" x 2\"",
    "shape": "Round",
    "stock": "14PT C2S"
  },

  "trim": {                           // REQUIRED. Drives the proofer's geometry.
    "w": 2, "h": 2,                   //   inches, the FLAT sheet
    "shape": "round",                 //   "round" → circular trim + safety guides
    "fold": null                      //   or {"axis":"h","at":2} → dashed crease at 2" down
  },

  "unit_weight_g": 1.02,              // optional but wanted. Lets ingest sanity-check the
                                      //   shipping estimate against actual weight.

  "dimensions": [                     // REQUIRED, ORDERED. This IS the form.
    {
      "key": "colorspec",             //   must match the keys used in `rows`
      "label": "Printed sides",
      "control": "select",            //   "select" | "pill" | "qty"
      "options": [
        { "v": "40", "t": "4/0 — full colour front only" },
        { "v": "44", "t": "4/4 — full colour both sides" }
      ]
    },
    {
      "key": "run_size",
      "label": "Quantity",
      "control": "qty",               //   `options` is a bare number array for qty
      "options": [250, 500, 1000, 2500]
    }
  ],

  "rows": [                           // REQUIRED. The capture, one row per probed cell.
    { "colorspec": "40", "coating": "UV", "run_size": 250, "cost": 9.00 }
  ],

  "add_ons": [                        // optional
    { "key": "proofs", "label": "Digital proof",
      "note": "PDF proof before production", "price": 5.00 }
  ],

  "capture": {                        // optional but strongly wanted — see §5
    "settled": true,
    "verify_pass_pct": 10,
    "verify_mismatches": 0,
    "duration_s": 214
  }
}
```

### `rows`, not `cells`

The capture naturally produces rows and a human can read them. The runtime wants
a flat `"40|UV|250"` map. **PHP builds the map at ingest**; the file stays
capture-shaped.

Keeping the wire format honest to how the data was gathered is what makes a
mis-capture legible when someone opens the file to find out what went wrong.

### `cost`, never `price`

Every number in this file is **what 4over charges us**. Retail is computed at
quote time as `cost × markup + ship(cost)`. Calling the field `price` invites
someone to put a retail figure in it, and that error prices every order at
roughly half.

**`per_unit` is not carried.** It is `cost / run_size`, it was derived in the
original capture too, and a stored copy is one more thing that can disagree.

---

## 3 · What the plugin does with it

1. Lists the Drive folder, reads every `*.json` that is not `manifest.json`.
2. Runs the five gates (`docs/4OVER_PIPELINE.md` §6) **per file**.
3. On pass: expands `rows` into a cell map, stores under `wp_options`, keyed
   `family/key`.
4. On fail: **keeps the previous matrix**, moves the file to `_rejected/` with a
   reason, and flags it in the admin.
5. Rewrites `manifest.json` with every product it serves, its URL, its ages and
   its last-verified date — so the next Chrome run reads its work list from the
   plugin rather than a hand-maintained copy.

A file that disappears from Drive does **not** delete a stored matrix. Removing
a product is a deliberate act in wp-admin, not a side effect of a failed sync.

---

## 4 · The two dates mean different things

| Field | Set when |
|---|---|
| `captured_at` | a full walk of every cell completed |
| `verified_at` | a spot-check ran and **matched** — no rebuild needed |

A spot-check that finds nothing still writes `verified_at`, or the age counter
climbs on a matrix confirmed current last week and the calculator eventually
refuses to quote something perfectly good.

A spot-check that finds a difference triggers a full rebuild, which sets both.

---

## 5 · Capture quality, recorded in the file

`capture.settled` asserts the run waited for the price element to *change*
rather than sleeping a fixed interval. That distinction is the difference
between good data and data with the previous selection's price scattered
through it — see `docs/4OVER_PIPELINE.md` §7, and the two suspicious cells in
the original Circle capture that remain unresolved because of it.

`verify_mismatches` must be `0`. A non-zero value means the second pass
disagreed with the first, and the ingest should reject the file outright rather
than gate individual cells: a systematic timing error is not something to
partially trust.

---

## 6 · Worked example

`round-business-cards.json`, trimmed, **synthetic costs**:

```json
{
  "schema": 1,
  "family": "business-cards",
  "key": "round",
  "label": "Round",
  "group": "Die-cut shapes",
  "product": "Circle Business Cards",
  "url": "https://4over.com/circle-business-cards",
  "captured_at": "2026-08-15",
  "verified_at": "2026-08-15",
  "production_days": 4,
  "fixed": { "size": "2\" x 2\"", "shape": "Round", "stock": "14PT C2S" },
  "trim": { "w": 2, "h": 2, "shape": "round", "fold": null },
  "unit_weight_g": 1.02,
  "dimensions": [
    { "key": "colorspec", "label": "Printed sides", "control": "select",
      "options": [{ "v": "40", "t": "4/0 — front only" },
                  { "v": "44", "t": "4/4 — both sides" }] },
    { "key": "coating", "label": "Coating", "control": "pill",
      "options": [{ "v": "UV", "t": "UV (print side)" },
                  { "v": "AQ", "t": "Aqueous" }] },
    { "key": "run_size", "label": "Quantity", "control": "qty",
      "options": [250, 500, 1000] }
  ],
  "rows": [
    { "colorspec": "40", "coating": "UV", "run_size": 250,  "cost": 9.00 },
    { "colorspec": "40", "coating": "UV", "run_size": 500,  "cost": 18.00 },
    { "colorspec": "40", "coating": "UV", "run_size": 1000, "cost": 23.50 },
    { "colorspec": "40", "coating": "AQ", "run_size": 250,  "cost": 9.00 },
    { "colorspec": "40", "coating": "AQ", "run_size": 500,  "cost": 18.00 },
    { "colorspec": "40", "coating": "AQ", "run_size": 1000, "cost": 23.50 },
    { "colorspec": "44", "coating": "UV", "run_size": 250,  "cost": 9.00 },
    { "colorspec": "44", "coating": "UV", "run_size": 500,  "cost": 18.00 },
    { "colorspec": "44", "coating": "UV", "run_size": 1000, "cost": 23.50 },
    { "colorspec": "44", "coating": "AQ", "run_size": 250,  "cost": 9.00 },
    { "colorspec": "44", "coating": "AQ", "run_size": 500,  "cost": 18.00 },
    { "colorspec": "44", "coating": "AQ", "run_size": 1000, "cost": 23.50 }
  ],
  "add_ons": [
    { "key": "proofs", "label": "Digital proof",
      "note": "PDF proof before production", "price": 5.00 }
  ],
  "capture": { "settled": true, "verify_pass_pct": 10, "verify_mismatches": 0 }
}
```

**Every row is emitted even when the value repeats.** Colorspec does not move
the price on this product and coating is free below 2,500, so most of these are
duplicates — but the completeness gate counts `rows` against the product of the
dimension lengths, and a capture that skipped cells because it guessed they
would repeat is indistinguishable from one that crashed halfway.

---

## 7 · Never in this file

- **Retail prices, markup, or margin.** Costs only. The markup is policy and
  lives in Central Config, where it can change without a re-capture.
- **Anything that reaches a customer.** These files are supplier costs; the
  wp_options they land in are never bulk-copied between sites, and none of it is
  rendered outside the staff debug panel.
- **A real file committed to this repo.** The repo is public (CLAUDE.md). Sample
  files in docs are synthetic and stay synthetic.
