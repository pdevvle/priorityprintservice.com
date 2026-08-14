# Fast product creation — spec

**The instinct was right: presets were the wrong shape.** Not because they look
different from the rest of the site — because they cannot take money.

This spec replaces them with real products, and collapses product creation from
eleven steps across four screens to one screen with three inputs.

---

## 0 · The finding that reframes this — preset URLs cannot add to cart

`pps-calculators.php:4767`, in the preset render path:

```php
// No productId — preset render does not target a single WC product.
// The cart layer should fall back to a calc-type → product map; that
// mapping is wired in a follow-up PR alongside per-line preset slug
// capture in order meta.
```

**That follow-up PR never landed.** There is no calc-type → product map anywhere
in the codebase. Meanwhile every calculator posts the field unguarded:

```js
fd.append("product_id", PPS.productId);      // undefined on a preset URL
```

and the handler rejects it (`pps-calculators.php:1886`):

```php
$product_id = intval( $_POST['product_id'] ?? 0 );
if ( ! $product_id || $price <= 0 ) {
    wp_send_json_error( 'Invalid product or price.' );
}
```

So a preset page renders a working calculator, quotes correctly, lets the
customer configure and upload artwork — and then fails at **Add to Cart** with
*"Invalid product or price."*

Four such URLs are live on production right now: `/letterhead/`,
`/mini-catalog-printing/`, `/color-booklet-printing/`, `/bulk-booklet-printing/`.
Three were added on 08-13 specifically to earn search traffic.

**This is the worst possible failure mode.** You spend the SEO effort, win the
click, hold the customer's attention through the whole configurator — and lose
them at the exact moment of intent, with an error message that reads like their
fault. It is strictly worse than not having the page.

> **Verify before acting.** Two minutes: open `/bulk-booklet-printing/`,
> configure anything, click Add to Order. If it carts successfully, something
> supplies the ID that this read of the code missed and the priority drops — but
> the spec below still stands on its own merits.

### Immediate mitigation, ahead of anything else here

Pick one, today:

- **Fastest:** point the four preset slugs at their nearest real product with a
  Rank Math redirect. URLs keep working, traffic converts, zero code.
- **Correct:** finish the deferred map — a `calc → product ID` fallback in the
  preset render so `productId` is populated. Perhaps 20 lines. But it leaves you
  with landing pages that transact against a *different* product than the one
  they describe, which muddies analytics and Shopping.
- **Best:** spawn a real product from each preset's defaults and redirect the
  preset URL to it. That is this spec, applied to what already exists.

---

## 1 · Why "just make them products" is the right answer

You asked this two sessions ago and the answer held up: a preset was never a
cheaper product page, it was a *thinner* one. Concretely, a real product has
things a preset structurally cannot:

| | Preset (virtual `WP_Post`, ID −1) | Real product |
|---|---|---|
| Add to cart | **No** — no product ID | Yes |
| Merchant Center / Shopping feed | **No** — nothing to feed | Yes |
| Reviews, ratings | No | Yes |
| Woo analytics, per-product revenue | No | Yes |
| Category archive, related products | No | Yes |
| Theme chrome consistency | Fought for it | Free |
| Cost to create today | 1 admin form | 11 steps, 4 screens |

The last row is the entire reason presets existed. **Fix that row and the case
for presets disappears.**

---

## 2 · What creating a PPS product costs today

| # | Step | Screen | Silent failure if skipped |
|---|---|---|---|
| 1 | Title, slug | Add New Product | — |
| 2 | Long description | " | Thin page; nothing for AI to extract |
| 3 | Short description | " | — |
| 4 | Featured image + gallery | " | No Shopping eligibility (image required) |
| 5 | Categories, tags | " | Orphan page, no internal links |
| 6 | **Virtual checkbox** | " → General | **Shipping plugins engage; PPS loses control of the cart** |
| 7 | Price | " | Schema/card show nothing |
| 8 | PPS Defaults (link/JSON/fields) | " → PPS Defaults tab | Calculator opens on global defaults |
| 9 | **Add ID to registry** | **PPS Calculators admin** | **No calculator renders at all** |
| 10 | SEO title, meta description | Rank Math panel | Auto-generated, usually poor |
| 11 | Publish | " | — |

Steps 6 and 9 are the dangerous ones: both fail *silently*, both are on screens
you have already left, and both are mechanical — exactly what should be
automated.

---

## 3 · The spawner

**`PPS Calculators → New Product`.** One screen. Three inputs.

### Input 1 — Quote link *(required)*

Paste the URL from any calculator's **Save configuration** button.

This single field determines three things you currently set by hand:

- **which calculator** — from the URL path (`calc-brochure.html` → brochure)
- **every default** — `pps_defaults_from_url()` already does this, in production
- **the price** — the `&q=` total the share links now carry

The parser, the whitelist and the sanitiser are all built and deployed
(`pps-defaults-url.php`, 9,067 bytes). The spawner is mostly an assembly job on
top of it.

### Input 2 — Product name *(required)*

Slug auto-derives, stays editable. This is the only piece of judgement the form
asks for.

### Input 3 — Copy from *(dropdown, pre-selected)*

An existing product to inherit description, short description, featured image,
gallery, categories and tags from. Defaults to that calculator's canonical
product, so it is usually correct without touching it.

Cloning the *copy* is what makes this fast. A new booklet product is 90% the
same page as an existing booklet product; the 10% that differs is the part you
want your attention on.

### One button — **Create draft**

Atomically:

1. `wp_insert_post()` — product, **status `draft`**
2. Copy from template: `post_content`, `post_excerpt`, `_thumbnail_id`,
   `_product_image_gallery`, `product_cat` + `product_tag` terms
3. **`_virtual` = `yes`, unconditionally** — owner rule 2026-07-19, never a checkbox again
4. `_pps_defaults` ← parsed blob; `_pps_defaults_source` ← the link
5. `_pps_defaults_price` ← `&q=` total → `_regular_price` + `_price`
6. **Append the new ID to `pps_get_registry()[<calcfile>]['products']`** — the
   step that today lives on a screen you have already closed
7. Prefill `rank_math_title` / `rank_math_description` from the name and defaults,
   if Rank Math is active — a starting point to edit, not a final answer
8. Redirect to the product edit screen with a notice itemising everything it set

**Draft, never published.** The spawner handles what is mechanical and leaves the
page in front of you with only the parts that need a human: the H1, the
description, and the SEO panel. Which is where you said you want your attention.

### Registry write — the one part that needs care

`pps_get_registry()` returns an option keyed by calculator filename with a
comma/space-separated ID string (`pps-calculators.php:242`). The spawner must
read-modify-write it, and two admins saving at once would lose one of the
writes. Re-read immediately before appending, and append rather than
reconstructing the string.

---

## 4 · Calculator selection, on the product screen

Separate from the spawner and worth doing regardless — it is the "default
calculator selection" half of the problem.

Today, changing which calculator a product uses means going to the PPS
Calculators admin and editing a comma-separated list of post IDs on a different
screen from the product. There is no way to look at a product and see which
calculator it uses.

**Add a `Calculator` dropdown to the PPS Defaults tab** that reads and writes the
registry for you:

- shows the current binding (or *"None — this product will not render a calculator"*,
  which is worth seeing explicitly)
- lists the eight calculators
- on save, removes the ID from its old key and appends to the new one

Small change, and it makes the binding *visible* — which matters more than the
saved clicks, because an unbound PPS product currently looks completely normal
in the admin right up until a customer opens it.

---

## 5 · Bulk mode — phase 2

Once single-product creation works, the same code takes a textarea:

```
Mini Catalog Printing        | https://…/calc-preview-test.html?size=…&qty=250&q=284.50
Full Color Booklet Printing  | https://…/calc-preview-test.html?size=…&qty=100&q=196.20
Wedding Program Printing     | https://…/calc-preview-test.html?size=…&qty=150&q=161.75
```

One row per product, `Name | link`. Creates N drafts.

**This is what presets were reaching for** — standing up ten SEO landing pages in
one sitting — except the output is ten pages that can take money, appear in
Shopping, and accrue reviews.

---

## 6 · Migrating the four existing presets

Do this after the spawner exists, or by hand now if the mitigation in §0 is not
enough.

For each preset row:

1. Spawn a product from the preset's `defaults` (they are already valid
   defaults blobs — see `wp_options['pps_presets']`).
2. Carry the preset's `overrides` across: `meta_title` → `rank_math_title`,
   `meta_description` → `rank_math_description`, `breadcrumb_label`, `schema_name`.
3. **Redirect the preset URL to the new product URL in Rank Math.** All URL
   redirects are managed in Rank Math, never in PHP or `.htaccess` — CLAUDE.md.
   This preserves whatever authority the slug has accrued.
4. Delete the preset row.

`/letterhead/` is the odd one: its defaults are just `{"productType":"letterhead"}`,
so it is an empty shell. It can be redirected to the existing letterhead product
and deleted without spawning anything.

Back up `pps_presets` first. There is already one at
`docs/backups/pps_presets-production-2026-08-13-pre-add.json`.

---

## 7 · What to keep from the preset system

Not all of it was wasted, and two pieces should survive the migration:

**The three-tier schema override system** (`pps-presets-admin.php`) is genuinely
good and has no equivalent on products. Tier 1 field overrides, Tier 2 per-block
JSON-LD replacement, Tier 3 arbitrary extra blocks. **Port the same three
accordions to the product edit screen** rather than discarding them — that is
where per-page schema control belongs anyway, and it is the SEO surface you
actually want to spend attention on.

**`pps_defaults_from_url()`** is the load-bearing piece of this whole spec. It
was built for presets and products both; only its caller changes.

---

## 8 · Build order

| # | Step | Why here |
|---|---|---|
| 1 | Verify §0 against the live site | Two minutes, and it sets the priority for everything else |
| 2 | Mitigate the four dead preset URLs | Revenue, today |
| 3 | Calculator dropdown on the product screen | Small, independent, makes the binding visible |
| 4 | The spawner, single-product | The main event |
| 5 | Migrate the four presets | Now cheap |
| 6 | Port the three-tier schema overrides to products | Recovers the one thing presets did better |
| 7 | Bulk mode | Only worth it once you trust step 4 |

Steps 1–2 are today. Steps 3–4 are the build. Everything after is cleanup.

---

## 9 · What this does *not* solve

Being explicit, because a spawner can look like it solves more than it does.

- **It does not write your copy.** Descriptions are the substance search engines
  and LLMs actually read; cloning a template gets you a starting point, not a
  page worth ranking. This is deliberate — it is the work you said you want your
  attention free for.
- **It does not make you eligible for Google Shopping.** That needs a product
  feed, which does not exist yet in any form. See the SEO map for what is
  actually required.
- **It does not fix thin pages at scale.** Ten spawned near-duplicates with
  swapped nouns is a doorway-page pattern and can be actively harmful. The
  spawner removes the mechanical cost so each page can carry *more* unique
  substance, not so you can make more pages with less.
