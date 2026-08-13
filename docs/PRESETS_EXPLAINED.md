# How presets work

A **preset** is a single row of configuration that publishes a public,
SEO-complete landing page at a root URL — `/{slug}/` — which renders one of
the eight calculators with its form already filled in. No page, no post, no
product. One option row in, one indexable page out.

The point: a product page answers "configure a booklet"; a preset answers
"buy *this* booklet." You can spin up `/church-bulletins/` or
`/8x8-square-booklets/` as a landing page with its own title, meta
description, Open Graph card and Product schema, pointed at the saddle
calculator with size and paper pre-chosen — without touching the catalog.

**Where the data lives:** `wp_options['pps_presets']`, keyed by slug. Edited
in wp-admin via `pps-presets-admin.php`. All the runtime code is in
`pps-calculators.php` (~line 4038 onward).

> **Current reality check:** exactly **one** preset row exists on both
> production and staging — `letterhead`. The system is fully built and
> unused. That is a content gap, not a bug.

---

## 1. The row

```php
'letterhead' => [
  'slug'        => 'letterhead',        // kebab-case, [a-z0-9-]+, ≤80 chars
  'calc'        => 'letterhead',        // which calculator to render
  'title'       => 'Letterhead Printing',
  'description' => 'Custom letterhead printing on premium papers…',
  'image'       => '',                  // absolute URL, used for OG/schema
  'price_from'  => '',                  // float|null — schema offer price
  'currency'    => 'USD',
  'sale_discount_pct' => '',            // per-preset sale, overrides site-wide
  'sale_label'  => '',
  'defaults'    => [ 'productType' => 'letterhead' ],   // pre-fills the form
  'categories'  => [],
  'overrides'   => [],                  // Tier 1 — see §5
  'schema_overrides' => [],             // Tier 2
  'schema_extras'    => [],             // Tier 3
  'faqs'        => [],                  // per-preset FAQ, overrides calc-type default
]
```

`calc` maps to a calculator file through `pps_get_filename_for_calc_type()`:
`saddle` → `calc-preview-test.html`, plus `perfect-bound`, `brochure`,
`coupon`, `letterhead`, `postcard`, `sticker`, `greeting-card`.

`defaults` uses the **same shape as the `_pps_defaults` product postmeta**, so
anything you can pre-set on a product you can pre-set on a preset.

Read with `pps_get_presets()` / `pps_get_preset($slug)`. Both tolerate the
option having been stored as a JSON string (the MCP `wp_update_option`
endpoint serialises arrays that way) and normalise back to an array.

---

## 2. Routing — how `/letterhead/` becomes a page

Four steps, all in `pps-calculators.php`:

1. **`pps_register_preset_rewrite_rules()`** on `init` adds **one rewrite rule
   per slug**, at `top` priority:
   `^letterhead/?$` → `index.php?pps_preset=letterhead`.
   Deliberately *not* a wildcard — a catch-all `^([a-z0-9-]+)/?$` would
   swallow every page and post on the site.
2. **`query_vars`** filter registers `pps_preset` so WordPress keeps it.
3. **`parse_request`** (very early) resolves the slug to a row. Unknown slug →
   a real **404**, not a redirect. A valid slug is stashed in
   `$GLOBALS['pps_active_preset']`, which is the flag every later hook reads.
4. **`the_posts`** injects a **virtual `WP_Post`** — `ID = -1`,
   `post_type = 'page'`, `post_status = 'publish'`, title from the row — and
   flips the query flags (`is_singular`, `is_page`, `found_posts = 1`) so the
   theme runs its ordinary single-page template. Nothing is written to the
   database; the post exists only for the duration of the request.

**Consequence to remember:** new slugs need a **rewrite flush**. Save/delete
in the preset admin flushes automatically, and plugin activation flushes — but
if a slug ever 404s after being added by some other route (a direct option
write, a database push), the fix is Settings → Permalinks → Save.

---

## 3. Rendering the calculator

`the_content` at priority 5 (guarded on `is_main_query()`, `in_the_loop()`,
and a single-fire static so it can't double-render) hands off to
`pps_render_preset_calculator()`, which does the same job as the product-page
path:

- parses the calculator HTML into `styles` + `app_code`
- scopes the CSS to `#pps-calculator-wrap` (rewriting `* {` and `body {`)
- emits `window.PPS_CONFIG` with `calc` config, tooltips, logo, zone map
- emits the mount point, then the app — **external enqueued file** for
  compiled builds, DOMContentLoaded-wrapped inline only as a write-failure
  fallback

Preset-specific injection on top of that:

- **`config.defaults` = the preset's `defaults`** — this is what pre-fills the
  form. Calculator JS reads `PPS_CONFIG.defaults`.
- **Per-preset sale**: a non-empty `sale_discount_pct` / `sale_label` is
  written into `config.calc.pcf`, because the calculators only ever read
  `PCF.sale_*`. So one preset can run a sale the rest of the site isn't.

---

## 4. SEO — the part that justifies the whole system

When `$GLOBALS['pps_active_preset']` is set, the plugin emits a complete head
for that URL: `<title>`, meta description, canonical, robots, 5 Open Graph
tags, 4 Twitter Card tags, and JSON-LD for **Product, BreadcrumbList,
LocalBusiness, FAQ and WebApplication** — plus a `<noscript>` fallback in the
footer carrying the title, description and a spec table, so crawlers that
don't run JS still see substance.

**The dedupe problem, and the fix.** Yoast and Rank Math would otherwise write
their own title and description over ours. Filters at **priority 999** force
their output to match the preset's resolved values —
`wpseo_title|metadesc|canonical|robots|opengraph_*|twitter_*` and
`rank_math/frontend/title|description|canonical|robots`. `pps_preset_resolved_field()`
is the single resolver those filters share, so there is one answer to "what is
this page's title" regardless of which plugin asks.

The plugin also **suppresses WooCommerce/Yoast/Rank Math/AIOSEO/SEOPress
Product schema** on preset URLs (via `pps_is_calculator_owned_url()`), so
there is exactly one Product node per page rather than three fighting ones.

---

## 5. Three tiers of override

Escalating power, use the lowest that does the job.

**Tier 1 — simple fields** (`overrides`). ~26 sanitised scalars that patch
individual values without touching JSON: `meta_title`, `meta_description`,
`og_image`, `breadcrumb_label`, `schema_name`, `schema_sku`,
`schema_description`, `schema_brand`, `schema_category`, `schema_image`,
`schema_url`, `schema_additional_type`, `schema_audience`, `schema_color`,
`schema_material`, `schema_disambiguating_description`, `schema_gtin`,
`schema_mpn`, `offer_price`, `offer_price_high`, `offer_price_currency`,
`offer_availability`, `offer_price_valid_until`, `offer_item_condition`,
`offer_url`, `rating_value`, `rating_count`, `rating_best`, `rating_worst`.
Each has a declared sanitiser and range; out-of-range values are dropped, not
clamped silently into nonsense.

**Tier 2 — replace a whole JSON-LD block** (`schema_overrides`). One of
`product`, `faq`, `breadcrumb`, `localbusiness`, `webapp`. You supply valid
JSON-LD, it replaces ours wholesale. Validated on save; invalid JSON is
rejected with an error rather than stored.

**Tier 3 — additional blocks** (`schema_extras`). Arbitrary extra JSON-LD
appended to the page — HowTo, Video, whatever the page warrants.

**Security note:** override-derived emission uses `JSON_HEX_TAG`, which escapes
`<` and `>`. That is what stops a crafted override from closing the
`<script>` tag and injecting markup.

**FAQ resolution order:** preset's own `faqs` → calc-type defaults from
`pps_default_faqs()` / `wp_options['pps_faqs']` → **nothing emitted**. A calc
type with no FAQs emits no FAQ script at all, which is better than emitting
another product's FAQs.

---

## 6. Sitemaps

`/pps-presets-sitemap.xml` is the single source of truth — a custom XML
endpoint listing every preset URL. It is then advertised three ways so
whichever SEO plugin is active finds it:

- `PPS_Presets_Sitemap_Provider` registered with **WP core sitemaps**
- `wpseo_sitemap_index` filter when **Yoast** is active
- `rank_math/sitemap/index/entries` filter when **Rank Math** is active
- appended to `robots.txt` if not already referenced

Presets also appear in `/llms.txt` under a `## Presets` section, with each URL
and description, for AI search engines.

---

## 7. Attribution through to the order

`PPS_CONFIG.presetSlug` rides along in the add-to-cart payload →
`woocommerce_add_cart_item_data` stores `pps_preset_slug` on the cart line →
checkout writes **`_pps_preset_slug`** (hidden) and **`Preset`** (visible) onto
the order item.

So you can answer "which landing page produced this order" in wp-admin, in the
Google Sheet, and in Missive — which is the whole reason to run preset
landing pages rather than duplicate product pages.

---

## 8. Gotchas

- **A preset is not a product.** No inventory, no product ID, no catalog
  presence. It routes to a calculator that adds a *registry product* to the
  cart. Presets and the `pps_get_registry()` product list are separate things.
- **Never point a preset at a WCPA-owned product family.** Same rule as the
  registry: WCPA products must not appear in `pps_presets` rows.
- **Slug collisions are silent.** The rewrite rule is registered at `top`, so
  a preset slug that matches an existing page slug will shadow that page. Check
  before naming.
- **Term/description content is un-versioned; preset rows are too.** They live
  only in `wp_options`. A repo checkout does not tell you what presets exist —
  read `pps_presets` on the site.
- **The 404 is deliberate.** An unknown slug under a registered rule 404s
  rather than redirecting, so a deleted preset stops ranking instead of
  silently bouncing traffic.

## 8b. Three traps found in the admin path (2026-08-12)

Found by source review before anyone pasted a real preset into the form.
Two were code bugs and are **fixed**; the third is a content gap that is
still open.

### Fixed — the defaults form silently lowercased every key

`pps_sanitize_defaults_blob()` ran WordPress's `sanitize_key()` over the
**keys** of the defaults blob. `sanitize_key()` lowercases. So anything saved
through the Presets admin form came back mangled:

| You type | It stored | Calculator reads |
|---|---|---|
| `sizeLabel` | `sizelabel` | nothing |
| `insidePaperType` | `insidepapertype` | nothing |
| `coverPaper` | `coverpaper` | nothing |

The calculator reads `PPS_CONFIG.defaults` by **exact key**, so the preset
loaded with an empty form and **no error anywhere** — not in the admin, not
in the console, not in the page source. The failure was completely silent.

The one live row, `letterhead`, escaped this only because it was written
straight to the option rather than through the form — which is also why
nobody hit it. The moment presets get created the intended way, every
camelCase field would have gone dead.

**Fix:** keys are now charset-restricted (`[A-Za-z0-9_-]`, everything else
stripped) with **case preserved**, and a key that sanitises to nothing is
dropped instead of being stored under `''`. The charset restriction is what
provides the safety — these keys are emitted into a JSON blob, never into SQL
or markup — so lowercasing was never buying anything.

Verified against a realistic blob: camelCase preserved, `×` (U+00D7)
preserved in values, nested `sets[]` intact, junk keys stripped, empty key
dropped.

### Fixed — the admin placeholder taught the wrong field name

The defaults textarea suggested `{"qty": 250, "pages": 16, "size": "5.5x8.5"}`,
which is wrong twice: the field is **`sizeLabel`**, not `size`, and the value
must be the **exact label including the `×` multiplication sign (U+00D7)**,
not a lowercase `x`. Anyone following the placeholder produced a preset that
silently ignored the size.

Now reads:
`{"sizeLabel": "5.5×8.5", "qty": 250, "pages": 16, "insidePaperType": "100lb Gloss Text"}`

Canonical field names, from the PPS Defaults product meta box (same shape):
`productType`, `sizeLabel`, `foldType`, `qty`, `pages`, `bindDir`,
`insideColor`, `coverColor`, `insidePaperType`, `coverMode`.

### Open — only saddle has default FAQs

`pps_default_faqs()` ships **7 FAQs for `saddle` and zero for the other
seven calc types** (`perfect-bound`, `brochure`, `coupon`, `letterhead`,
`postcard`, `sticker`, `greeting-card`).

That is deliberate in the sense that emitting saddle's FAQs on a sticker page
would be worse — the resolver correctly emits **no FAQ block at all** rather
than the wrong one. But the practical effect is that **every non-saddle
preset and product page ships with no FAQ schema** unless someone supplies
FAQs per preset or fills `wp_options['pps_faqs']` for that calc type in the
SEO admin tab.

This is a content task, not a bug: seven sets of FAQ copy. Until it is done,
expect no FAQ rich results on anything but saddle stitch.

## 9. If you're adding presets (the practical path)

1. wp-admin → PPS Calculators → Presets → add a row: slug, calc, title,
   description, image, `price_from`, and the `defaults` JSON that pre-fills
   the form.
2. Save — this flushes rewrite rules, so `/{slug}/` is live immediately.
3. Visit the URL; confirm the calculator renders with your defaults applied.
4. View source: one Product schema, canonical on the real domain, title
   matching the preset.
5. Only reach for Tier 1 overrides if the derived values are wrong, and Tiers
   2–3 only when a whole block needs replacing.
6. Check `/pps-presets-sitemap.xml` lists it.


---

# Defaults from a shared quote link (2026-08-13)

`pps-defaults-url.php` turns a calculator "Copy link" URL into a defaults
blob, so you build a landing state by clicking rather than by hand-writing
JSON.

**Workflow:** configure the job in the calculator → **Copy link** → paste into
the product's **PPS Defaults → Apply from quote link** → Save. The calculator
guarantees the exact paper values, the `×` in size labels and the enum
spellings, so the whole class of "I typed `x` instead of `×` and the size
silently didn't apply" disappears.

## The reader

`pps_defaults_from_url( $url )` returns `defaults`, `price`, `unknown`,
`error`. It accepts a full URL, a bare query string, or a `?a=b` fragment.

**`pps_defaults_param_map()` is the whitelist** — 27 params covering the
booklet family, each with the cast the calculator itself applies (`qty` int,
`vividPrint` bool, `bundling` float, `insidePaper` deliberately left a string
because the calculator matches it loosely against config rows). Anything not
in the map is ignored and reported back, so a pasted URL cannot inject
arbitrary keys. UTM/gclid/fbclid are skipped silently.

Only the three booklet calculators emit a share link today. The five flat
calculators have no "Copy link" yet — when they get one, add their params to
the map and this reader serves them with no other change.

## Precedence on save

Three sources, increasing precedence, so what you can see always beats what
you can't:

1. the pasted quote link (bulk, one-shot — applied then cleared)
2. the **Advanced defaults (JSON)** box (anything the named fields can't express)
3. the eleven named fields (visible in the form)

The pasted URL is kept in `_pps_defaults_source` as an audit trail, since a
DB-only config change otherwise leaves no record. An admin notice after save
reports what actually landed.

**The 11-field ceiling was never a runtime limit** — the defaults reader has
always `json_decode`d a string value, so arbitrary keys already flowed
through to `PPS_CONFIG.defaults`. Only the form was the constraint.

## Price at these defaults

The share link now carries `&q=<total>`, and pasting it fills **Price at these
defaults**, which writes `_regular_price` / `_price`. That drives the
catalogue card and the Product schema's `lowPrice` (previously a hardcoded
`50`, which understated most products).

**Display only.** The charged price is always recomputed at add-to-cart from
`pps_price`, so a stale or wrong figure here can never overcharge a customer —
it can only misadvertise, which is why an explicitly typed value always wins
over one carried in a link.
