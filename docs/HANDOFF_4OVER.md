# HANDOFF — build out the 4over trade-product system

You are the executing session. The design is settled and a working draft
calculator exists. Your job is to build the parts that do not exist yet, in the
order below. **Do not redesign the settled decisions** — they were reasoned
through with the owner over a long session and each carries its reason in the
docs.

## Preconditions

- Branch **`claude/optimistic-wozniak-11ql3y`**. Source HEAD when this was
  written: **`809ab6e`**.
- A **second session works this branch concurrently.** Rebase before every push,
  and **never publish a `dist/` built before your last rebase** — the compiled
  output goes stale the moment someone else's calculator change lands.
- For anything touching the live site you need the **`PPS_PRODUCTION_AI_ENGINE`**
  connector (the AI-Engine one). First call `wp_get_option` key `siteurl`; it
  must return `https://priorityprintservice.com` before you write anything.
- Deploys are **pull-based only** — `pps_plugin_download_url` against a raw
  GitHub URL pinned to a commit. Never hand-edit a server file.
- The owner has **no terminal**. Say every step they must take plainly in chat.

## Read these first, in this order

| Doc | What it settles |
|---|---|
| `docs/4OVER_REPLACEMENT.md` | The analysis. Why the matrix is the model, the markup, the shipping bands, the delivery formula, the freight question |
| `docs/4OVER_MATRIX_SCHEMA.md` | **The file contract.** Implement against this, not against prose elsewhere |
| `docs/4OVER_PIPELINE.md` | Capture → Drive → ingest. The gates, the spot-check, the folder layout |
| `docs/4OVER_CALCULATOR_BRIEF.md` | What is built in `calc-4over.html` vs what is stubbed |

## What exists

- **`calc-4over.html`** — 1,061 lines, works, verified in headless Chromium.
  Catalogue-driven: one page, four sample products, form generated from each
  product's own dimensions.
- **A live preview**:
  https://pdevvle.github.io/priorityprintservice.com/calc-4over.html
  (compiled, published to `pps-pricing-config` at `16b582a`)
- Registered with `tools-compile-calcs.mjs`, so `node tools-compile-calcs.mjs`
  builds it alongside the other eight.

## What does not exist

Everything server-side, and the artwork pipeline. See §3 of the calculator
brief for the full list.

---

## Build order

### 1 · Resolve the two open capture questions — before writing any PHP

Both invalidate work done after them, so they come first.

**Is the 7-day turnaround selectable?** The Circle capture found it in the Specs
tab with no control. If it is reachable some other way, every captured number is
the 4-day price and the matrix is missing an axis. One product's worth of
checking now versus re-capturing forty later.

**Does the captured price include freight?** Almost certainly not. Settle it
with four Shippo quotes at the weights derived in `docs/4OVER_REPLACEMENT.md`:
~1.2 lb, ~13.2 lb, ~25.8 lb, and ~64 lb across 3 parcels, against estimates of
$10.00 / $20.00 / $20.00 / $34.71. **Check both ends, not just the tail** — at
250 units shipping is 132% of gross margin, so the bottom is fragile even though
the absolute dollars are small.

### 2 · `pps-4over-matrix.php` — storage, injection, admin

- One stored matrix per product, keyed `family/key`
- `rows` → flat `"40|UV|250"` cell map at ingest
- Inject `PPS_CONFIG.catalog` on a 4over product page, parallel to how
  `pps-calculators.php` injects `PPS_CONFIG.defaults`
- `_pps_4over_family` per product decides which catalogue a page receives — a
  business card page must not offer postcards
- Admin screen: every matrix, its `captured_at`, `verified_at`, age, staleness

Register the calculator: add it to `pps_get_calc_type_for_filename()` with calc
type `fourover`.

### 3 · `pps-4over-ingest.php` — the Drive pull

**Where the folder ID lives:** `pps_calc_config → fourover_drive_folder_ids`,
an array. It is deliberately not in the repo — if the folder is link-shared the
ID is effectively the password to every supplier cost in it, and this repo is
public. Ask the owner for it, or read it from config on the server; do not
commit it. The first folder was supplied on 2026-08-15 and should already be
there.

Take a list rather than a single ID: the owner may keep one folder per family
or one for everything, and since `family` comes from inside each file the
ingest can just merge whatever it finds across all configured folders.

Model it on **`pps-gbp-sync.php`**, which is built and tested, and inherit its
central rule verbatim:

> **A failed fetch changes nothing.** Record the error, keep serving the last
> good data.

A blanked matrix is a product that cannot be quoted, or worse quotes zero.

Then the six gates from `docs/4OVER_PIPELINE.md` §6. A failed gate keeps the
previous matrix and moves the file to `_rejected/` with a reason. And write
`manifest.json` back so Chrome reads its work list from the plugin.

### 4 · Artwork — the biggest gap in the calculator

Port from `calc-brochure.html`: pdf.js loading, `FitToggle`, Drive upload with
the idempotent retry, `generateApprovalPackage()` and the approve flow.

Two things to carry over exactly:

- **The approval marker is the manifest** (`*_manipulation_manifest.txt`), never
  the print-ready PDF. Well-prepared art needing no transforms produces no PDF
  by design, so keying on the PDF rejects correctly-approved orders.
  `docs/ART_APPROVAL_GATE_REVIEW.md` has the whole story.
- Every `Image()` and every `FileReader` needs `onerror`. Audited list.

### 5 · Shippo, then the migration

Wire real rates for `AK, HI, PR, GU, VI, AS, MP`. Then migrate one WooCommerce
variable product end to end, place a real order through it, and reconcile
against what 4over actually invoices — the only check that catches a
whole-catalogue drift, since the gates only ever compare a capture to the
previous capture and never to reality.

---

## Traps

**`remapSelection()` is the part most likely to be "simplified" into a bug.**
Two 4over products rarely share an option set, so copying state verbatim across
a product switch leaves something like `coating:"UVFR"` selected on a product
with no such cell — the lookup misses and the customer sees an error for a
choice they never made. If you keep one assertion, keep this one: **Round at
UVFR / 25,000, switched to Square, must land on UV / 10,000** with no error and
a correct price.

**Real 4over costs must never enter this repo.** It is public. The embedded
`SAMPLE_CATALOG` is synthetic and stays synthetic — all four products. Real
costs live in `wp_options` and arrive as `PPS_CONFIG.catalog`.

**Cost, markup and the shipping split never reach a customer** — including
`pps_metadata`, which surfaces on the order screen, in the customer email and in
the Missive spec. Staff see them in the debug panel only.

**Never write a literal `</script>` inside the `text/babel` block.** Escape it
`<\/script>`. This has broken the build before and the symptom is
indistinguishable from a Jekyll failure.

**Verify by driving the UI, not by reading the code.** That is how the draft was
checked and how `tools-pricing-matrix.mjs` and `tools-min-price.mjs` work: it
measures what a customer is actually shown.

---

## Report format

What you found on the two open questions in §1, what you built, what you
deployed (bytes and SHA), the byte counts you found before each overwrite,
anything you stopped on and why, and which owner steps you prompted for.
