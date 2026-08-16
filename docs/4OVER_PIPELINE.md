# The 4over price pipeline

Refinement of the proposed workflow: scheduled Chrome capture → drop location →
plugin ingest. The shape is right. Three decisions inside it have a clear answer,
and one part of the spot-check idea would under-detect changes in exactly the
cells that cost the most money.

---

## 1 · Drop location: Google Drive, not git

**Git is disqualified, and not narrowly.** These are 4over's *cost* prices to
you. The repo is public, and CLAUDE.md already carries the rule:

> treat every branch as public: don't commit pricing figures, strategy, or
> credentials anywhere in the repo.

Publishing supplier costs lets any competitor compute your margin on every
product you resell. A private repo would fix the exposure but then the plugin
needs a token on the server to read it — a new credential to manage, rotate and
leak.

**Drive wins on a second count anyway: the plugin already speaks it.**
`pps-gdrive.php` has working OAuth with credentials in `wp_options`, and it
already moves customer artwork daily. The ingest becomes "read one more file
from a folder you already have access to" rather than a new integration.

Drive keeps file revisions, so you do not lose the version history git would
have given you.

## 2 · Direction: the plugin pulls, on cron. Not a session pushing.

A pipeline that needs a Claude session running to complete is a pipeline that
stops the first week nobody starts one. WP-Cron pulling from Drive runs whether
anyone is watching or not.

This is the same shape as the Business Profile rating sync built on 2026-08-15,
and it should inherit that file's central discipline verbatim:

> **A failed fetch changes nothing.** Record the error, keep serving the last
> good data.

A network blip must never blank a price matrix, because a blanked matrix is a
product that cannot be quoted — or worse, one that quotes zero.

## 3 · One file per product, and the plugin publishes the work list

**One 4over product page → one JSON file → one matrix** (owner, 2026-08-15).
Not one catalogue file, and the difference is not tidiness: a session that dies
at product 30 of 40 corrupts one product rather than the whole catalogue,
ingest becomes incremental so one failure does not hold back the rest, Drive's
revision history becomes per-product, and concurrent captures cannot collide.

```
PPS 4over Pricing/            ← one flat folder
  manifest.json               ← written by the PLUGIN: the work list
  round-business-cards.json   ← written by CHROME: one per product
  standard-business-cards.json
  _rejected/                  ← ingest moves gate failures here, with a reason
```

Flat rather than nested by family: the plugin lists one folder rather than
walking a tree. Family grouping comes from inside each file, and **the filename
is a convenience, never an identity** — `family` and `key` in the file are
authoritative, so a rename cannot break anything.

The plugin rewrites `manifest.json` with every product it serves, its 4over URL,
its matrix ages and its last-verified date. Chrome reads it to know what to
check and writes results back beside it, so adding a product is one action in
wp-admin rather than two edits in two places that can drift.

**A file disappearing from Drive does not delete a stored matrix.** Removing a
product is a deliberate act in wp-admin, never a side effect of a failed sync.

The full field-by-field contract is `docs/4OVER_MATRIX_SCHEMA.md`.

---

## 4 · The spot-check needs to probe the right cells

This is the part I would change.

"Check a few, and if none differ leave it alone" is the right instinct, but the
test capture shows a naive sample would be close to blind:

| Run | UV | UV front only | Aqueous |
|---|---|---|---|
| 250 | 7.56 | 7.56 | 7.56 |
| 500 | 15.12 | 15.12 | 15.12 |
| 1,000 | 19.65 | 19.65 | 19.65 |
| … | | | |
| 25,000 | 231.37 | 237.42 | 222.31 |

**Coating is free below 2,500.** A spot-check that samples low run sizes is
sampling nine cells that are duplicates of each other, on the cheapest orders you
sell. It would pass while a 15% rise at 25,000 — worth real money on every large
order — went undetected.

### The four probes worth taking

1. **Highest run size × each coating** (3 probes). These are where the
   coatings actually diverge, and where a change costs the most per order.
2. **The lowest tier** (1 probe). Catches an across-the-board change, and is
   the cell most customers see.

Four probes instead of twenty-seven, aimed at the cells that carry information
rather than a random sample of a table that is three-quarters duplicates.

**Any probe differing → rebuild that product's matrix in full.** Do not patch
individual cells; a supplier who changed one number probably changed the shape.

### Record verification even when nothing changed

If the spot-check passes and the matrix is not rebuilt, still write back a
`verified_at` date. Otherwise the age counter keeps climbing on a matrix that
was confirmed current last week, and the calculator eventually refuses to quote
something perfectly good.

Two dates on every matrix, and they mean different things:

- `captured_at` — when the full 27-cell walk last ran
- `verified_at` — when a spot-check last confirmed it still matches

## 5 · Report the direction of a change, not just that there was one

A price **rise** means you are selling below the margin you think you have —
urgent, and it is losing money on every order placed until the matrix updates.

A price **drop** means you are leaving money on the table — worth knowing, not
an emergency.

Both trigger a rebuild. Only the first deserves to interrupt someone.

---

## 6 · Do not let the plugin trust the file

The blob is written by an automated browser session that can fail halfway,
mis-read an AJAX-updated price, or hit a 4over page that changed shape. The
ingest is the last place to catch that, so it gates before publishing:

| Gate | Rejects |
|---|---|
| **Schema** | missing keys, wrong types, no rows |
| **Completeness** | cell count ≠ the product of the declared dimensions |
| **Monotonicity** | unit price rising with quantity — physically wrong, and the signature of a stale read |
| **Magnitude** | any cell moved more than a configured threshold since the last accepted version |
| **Freshness** | `captured_at` older than the file it would replace |
| **Capture quality** | `capture.verify_mismatches` non-zero — the second pass disagreed with the first, which is a systematic timing error and not something to partially trust |

The monotonicity gate is worth the effort specifically: the test capture passes
it cleanly in all three coatings, which is good evidence the capture method
works — and it is exactly what would fail if a probe read a price before the
page finished updating.

**A failed gate keeps the previous matrix and flags for review.** It never
publishes a partial or suspect set. Losing a refresh cycle costs nothing; a bad
matrix costs money on every order until someone spots it.

---

## 7 · The instruction that most affects data quality

For the Chrome task itself, one thing matters more than the rest:

> After changing a control, **wait for the price element to actually change**
> before reading it. Do not use a fixed delay.

If the configurator updates its price by AJAX, a read that lands early returns
the *previous* selection's price. The signature is a cell that equals its
neighbour when its neighbours differ from each other — which is precisely what
the test capture shows at 5,000 × UV-front-only, and possibly at 15,000 ×
Aqueous.

Both may be genuine quirks in a hand-maintained table. But until a capture with
a proper settle condition reproduces them, they are unresolved, and they are the
reason for the gate above.

Second: a **verification pass over ~10% of cells**, re-probed in a different
order. Must reproduce the first pass exactly or the run is discarded. Cheap
insurance against a systematic timing error that a monotonicity check would not
catch.

---

## 8 · No API — so this pipeline is the plan

Confirmed 2026-08-15: 4over does not offer resellers a pricing API. Everything
above is therefore the build, not a workaround pending something better.

That raises the stakes on §5 and §6 rather than changing them. With no
authoritative source to reconcile against, the captured matrix *is* the only
record of what a job costs, and the ingest gates are the only thing standing
between a bad capture and quoting below cost.

Two additions that follow from having no API:

**Capture the production turnaround as a field.** Delivery is now
`production + 1 + 3` business days (see `docs/4OVER_REPLACEMENT.md`), so the
production figure is part of the product's data and has to come off the same
page as the prices. A turnaround that changes without the matrix changing would
otherwise go unnoticed — add it to the four spot-check probes as a fifth check.

**Reconcile against a real invoice, periodically.** Since nothing can be
verified programmatically, the only ground truth is what 4over actually bills.
Worth comparing one live order against its quoted cost each month — it is the
only check that catches a whole-catalogue drift the gates would pass, because
the gates only compare a capture against the previous capture, never against
reality.
