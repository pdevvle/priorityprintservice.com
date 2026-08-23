# PPS SEO — Cannibalization Analysis & Preset Consolidation Plan

> **Handoff note (read first).** This is a strategy/analysis document, not a spec to execute
> blindly. It captures a Search-Console cannibalization review of priorityprintservice.com and
> how the plugin's **preset SEO system** should be used to consolidate competing pages. **Update 2026-07-14: partly executed — the nav has been consolidated onto WooCommerce *category archives* (not presets); see the Progress section directly below. Redirects not yet shipped.** All redirects are executed in **Rank Math**, never in PHP/.htaccess.
> Before acting, resolve the open questions in §8 with the site owner.
>
> Source data lived in the analysis session's scratchpad (GSC export → `gsc-pages.csv`,
> `gsc-queries.csv`, `gsc-cannibalization.csv`, `redirect-map.csv`). Those are ephemeral; the
> tables that matter are embedded inline below so this document is self-contained.

---

## Progress — reconciliation with the WooCommerce-category work (2026-07-14)

**Chosen consolidation vehicle: WooCommerce category archives, not presets.** The plan below was written around minting *presets* as the canonical hubs (still the most SEO-complete page type — see §3). In execution the operator chose to consolidate onto the **WooCommerce product-category archives** (`/booklets/`, `/brochures/`, …). Those render the full landing experience — WooCommerce's default `woocommerce_taxonomy_archive_description()` runs each category's term description (hero + `[pps_cat_wizard]`/`[pps_cat_papers]` shortcodes) through `do_shortcode` on the archive, and there's no custom taxonomy template overriding it — so they work as hubs. The preset-specific guidance below still stands as an alternative/complement, but the categories are now the live hubs.

### ✅ Done (live on staging)
- **Primary nav repointed to category archives.** 18 menu items that pointed at standalone marketing Pages now point at their WC category archive: Booklets, Brochures, Square Brochures, Postcards, Flyers, Business Cards, Business Forms, Door Hangers, Notepads, Rack Cards, Stationery, Presentation Folders→Folders, Menus (all populated), plus EDDM, Mailers, Signs & Banners, Stickers (empty — the `[pps_cat_wizard]` in the description funnels to a form when no product exists), plus the coupon item. Titles / parents / order / child items preserved. Clean URLs (`/booklets/`, no `/product-category/` base).
- **Coupons promoted to a top-level category.** The former "Coupon Booklets" sub-category of Booklets was moved to top-level (`parent 0`) and renamed **"Coupons"** (slug unchanged, so no URL break).

### ⏳ Pending (operator action)
- **Import the 301 maps into Rank Math** (all live in the repo under `docs/seo/`):
  - **`docs/seo/redirect-map-pages-to-categories.csv`** — the bulk. Generic service/marketing Pages → category hubs (16 rows). Retires the now-orphaned standalone Pages (`/custom-booklet-printing/`, `/brochure-printing-services/`, …).
  - **`docs/seo/redirect-map-service-to-product.csv`** — the two exceptions where a specific product owns the term: `/small-booklet-printing/` → `/product/small-booklet-printing/` and `/business-form-printing-services/` → `/product/2-part-carbonless-forms/`.
  - **`docs/seo/redirect-map-product-generics.csv`** — the deeper layer (3 rows, all **CONFIRM-before-import** because they retire *live product* URLs). Generic products → the most productive product in their category. (`cheap-booklet-printing` was intentionally left out — owner's call to keep it live.) See the deeper-consolidation section below.
  - **Not yet imported.** After importing service/marketing Pages, set them to Draft/Trash. Product-generic redirects also require setting the retired *products* to Draft/private so they leave the catalog.
- **Higher-risk redirect to watch:** `/coupon-pads-coupon-booklets/` ranks ~pos 12 (~376 clicks for "coupon pads"). Resolved to **keep the category target** (`/coupon-booklets/`) — it's dual-intent (pads + booklets) and only the Coupons category spans both. But the payoff depends on the Coupons hub actually targeting "coupon pads"; monitor after redirect.
- **Empty categories still need products** (EDDM, Mailers, Signs & Banners, Stickers) to appear in any auto-generated category lists and to give the archive a product grid; the wizard-funnel is the interim.
- **Stickers page — RESOLVED (no redirect).** Page 21486 is a **2019 draft** (never published; the `/?page_id=21486` menu URL is why it had no pretty permalink). Nothing to 301 — dropped from the map. The `/stickers/` slug is free for the category to own. If the draft is ever published it must *not* use the `stickers` slug (it would collide with the category archive) — trash it instead.

### 🔜 The deeper consolidation — product-level generics (map drafted, CONFIRM before import)
The menu/redirect work retires the *service-page* layer. The **product-level generics** — `/product/low-cost-brochure-printing/`, `/product/coupon-booklet-printing/`, `/product/low-price-business-cards/` — still compete for the head terms. This is the next layer.

**Rule (owner-stated 2026-07-15): a *productive* page folds into the *most productive product* in its category, not the thin category archive.** A category archive is a weak SEO target (no Product schema, no price); the highest-clicks product is a proven ranker, so concentrating a generic's link juice there gives the best ranking payoff. Non-productive stragglers can still go to the category hub; the pages carrying real authority go to the winner product.

Per-category winner (highest GSC clicks) and the generic that folds in — delivered as `docs/seo/redirect-map-product-generics.csv`:

| Category | Winner product (KEEP — becomes the head-term target) | Generic → winner | Confidence |
|---|---|---|---|
| Brochures | `/product/trifold-brochure-printing/` (201 clk, flagship fold) | `/product/low-cost-brochure-printing/` (58 clk, 131k impr) | good |
| Coupons | `/product/custom-coupon-book-printing/` (781 clk) | `/product/coupon-booklet-printing/` (164 clk) | highest |
| Business cards | `/product/cheap-business-card-printing/` (Standard, generic flagship) | `/product/low-price-business-cards/` | high — but merge ONLY this one; the ~18 material/shape/finish specialists stay |

**Three notes the operator must weigh:**
1. **These retire LIVE products.** 301'ing `/product/low-cost-brochure-printing/` removes it from the catalog. Weigh the SEO consolidation against lost sales; set each retired product to Draft/private on redirect.
2. **Booklets: `cheap-booklet-printing` is deliberately left alone (owner's call 2026-07-15).** It was the one ⚠️ semantic-stretch merge (cheap≠small); at 687 clk / pos 31 it earns its keep, so it stays live alongside the `small-booklet-printing` winner — no redirect. The Booklets consolidation is therefore just the SERVICE page `/small-booklet-printing/` → the product (already mapped).
3. **Flyers & notepads have no clear winner to fold into.** Flyer's click leader *is* the generic (`budget-flyer-printing`, 137 clk) — nothing to fold it into, so retain it as the de-facto flyer hub. Notepad has no flagship product (spiral 185 clk is a *specialist*; the generics sit at 140/15 clk) — leave notepad generics on the `/notepads/` category hub rather than force a product merge. Both excluded from the CSV pending a decision.

### ⚠️ Redirect-target rule — service page → product when a product owns the term
**General principle (owner-stated 2026-07-15):** *when a service page overlaps significantly with a **product** page, 301 it to the product* (passing its link juice to the transactional page that owns the term) — **not** to the generic category. The discriminator is **which page owns the service page's exact intent**:

- **A specific product owns the exact term** → 301 to the **product**. Test: a product's name/slug is a twin of the service page's term, or the product is the sole owner of that named intent.
- **The intent is a generic head term** → 301 to the **category hub**. The rich category archive (hero + `[pps_cat_wizard]` + paper/coating tables) is purpose-built to rank for "brochure printing", "door hanger printing", etc. A *single-product* category still wins here when its lone product is a **specific SKU** rather than the term owner.

Applied to the audited service pages, this yields exactly **two** service→product redirects (in `docs/seo/redirect-map-service-to-product.csv`); everything else goes to the category hub (`docs/seo/redirect-map-pages-to-categories.csv`):

| Service page | 301 target | Why |
|---|---|---|
| `/small-booklet-printing/` | **`/product/small-booklet-printing/`** | Exact slug-twin; product owns "small/mini booklet" (1,152 clk, pos 34) inside a 9-product Booklets category. Generic `/booklets/` would split the mini intent off its owner. "Mini Booklets" menu item repointed. |
| `/business-form-printing-services/` | **`/product/2-part-carbonless-forms/`** | Single-product category whose lone product is literally named **"Business Forms"** (the exact term) — the product *is* the owner. Owner picked "split business forms out" 2026-07-15. |
| `/door-hanger-printing/` | `/door-hangers/` (category) | Single-product category, but the lone product is a **specific SKU** ("4.25×11 Standard Cut"); the rich hub owns the generic "door hanger printing". |
| `/rack-card-printing/` | `/rack-cards/` (category) | Single-product category, but the lone product is a **specific SKU** ("Budget Rack Cards"); the rich hub owns "rack card printing". |
| `/coupon-pads-coupon-booklets/` | `/coupon-booklets/` (category) | **Dual-intent** (pads + booklets); only the 3-product Coupons category spans both. No single product is the twin, so category wins. Monitor "coupon pads" (pos ~12) after redirect. |
| `/custom-booklet-printing/`, `/brochure-printing-services/`, `/online-flyer-printing-service/`, … | their category hub | Generic marketing pages, no specialized owning product. |

### ✅ Tag 26/27 disposition — retain-vs-preset judgment (2026-07-19)

The operator tagged every undecided product 26 or 27 in WooCommerce (38 distinct; 26428 and 25086 carry both tags). Decision rule: a ranking specialist is never folded (§5), and **per-product defaults (the "PPS Defaults" product meta box) give preset-style preconfiguration on the product page itself** — so "retain + calculator with defaults" dominates "redirect to a new preset" for every established page. Result: **no new presets minted from this set**; presets stay reserved for the greenfield §3b candidates.

| Group | Products | Action |
|---|---|---|
| ① Already calc-wired — retain (16) | 20178*, 20256, 20272, 20297, 22650, 22673, 22247, 22872, 22754, 17429, 21301, 33670†, 33714, 21873, 21901, 22122 | Nothing to do. *20178 trifold = winner / 301 target — optimize. †33670 8x8: optional later migration to an `/8x8-booklet-printing/` preset ONLY with copy ported (§5 caution). 33714 presentation: weak (15 clk, pos 43) but distinct intent + already wired → lean retain (the old §6 "301 to preset-hub" verdict predates categories-as-hubs). |
| ③ 301 → existing winner (3) | 33672 → `/product/trifold-brochure-printing/`; 23506 → `/product/custom-coupon-book-printing/`; 17128 legacy Letterhead → `/product/standard-letterhead/` | First two are already rows in `redirect-map-product-generics.csv` (CONFIRM before import); pull both from the calc registry when retired. 17128: check GSC first — if `/product/letterhead/` holds the equity, invert (move the calc registration to 17128, retire 21873 instead). |
| ② Retain + APPLY calculator (10) | **MIGRATED 2026-07-19 (8 of 10):** 24107 gate3·8.5×11 (pilot), 26428 z3·8.5×11, 24103 accordion4·8.5×14 (stale draft-form WCPA pointer 31379 cleared as part of the flip), 24108 roll4·8.5×14, 24110 dparallel4·8.5×14, 24109 dgate4·11×17, 25086 menus flat·8.5×14 → **brochure**; 23559 note cards flat·5×7 → **postcard** (its defaults consumption was ported for this; greeting-card was rejected — GC presets price the folded spread, wrong for flat cards, and it keeps the envelope add-on out of reach for now). **Squares completed 2026-07-19:** square presets 3×3–9×9 (owner-specified, 1" steps, "Square" optgroup) added to the brochure + postcard calcs with imps computed by each calc's own imposition fn; 24095 square flyers → brochure flat·6×6 and 24309 square postcards → postcard flat·5×5 migrated — **all 10 of 10 done.** | Old Uni-CPO configurators were already dead (plugin deactivated); per-product WCPA meta verified empty on every migrated ID. Defaults live in `_pps_defaults` post meta. Cart-test each page on staging. |
| ④ Stay WCPA — no calc fits (3) | 24715 waterproof menus, 24458 plastic flyers, 19641 door hangers | Synthetic stock / plastic / die-cut hole aren't supported by any calc. Revisit only if the config gains those capabilities. |
| ⑤ Parked (6) | 17173 business forms; 17158 + 22439 + 21242 notepads; drafts 21251 spiral booklets, 21188 magnetic notepads | 17173 = the 301 *target* of the business-forms service page — retain as-is (carbonless has no calc). Notepads await the §6 notepad decision (two generics → `/custom-notepad-printing/`, spiral retained). Drafts stay draft (no live URL). |

Dependency discovered while wiring the pilot: `calc-brochure.html` did not consume `PPS_CONFIG.defaults` (only `productType`), so neither per-product defaults nor preset `defaults` JSON could pre-fill fold/size/qty on the brochure family — fixed 2026-07-19 (defaults now honored for `foldType`, `sizeLabel`, `qty`, validated against the option lists; `_DR` derivative modes still win).

---

## TL;DR

- **Cannibalization pattern:** generic SERVICE pages and generic "cheap/small/custom" PRODUCT
  pages compete for head terms (e.g. "booklet printing", "brochure printing"), stranding them at
  positions 30–55 despite huge impressions. **Specialized** products (a fold type, a size, a
  material, a shape) rank fine and are *not* the problem.
- **The preset system is the consolidation vehicle.** A preset page is the most SEO-complete page
  the plugin can produce (self-canonical, index/follow, full JSON-LD, forces Yoast/Rank Math to
  match). It is the ideal canonical hub to fold the generics into.
- **Presets are currently greenfield.** No preset URL draws any GSC traffic today, so presets are
  a clean lever, not a current offender — *provided* they're governed (one preset per distinct
  search intent, never a family of near-duplicates for one head term).
- **Core rule:** **consolidate shared intent, migrate distinct intent.** Fold generics into a hub;
  give each specialist its own page (optionally its own preset), never fold a distinct-intent
  winner into the generic hub.
- **Actionable clusters with a preset lever:** brochure, booklet, flyer (flyer runs on the
  brochure calc). Coupon = light touch. Notepad & business-card have **no matching calculator**,
  so they're product/service merges only.

---

## 1. Goal

Primary: **discern keyword cannibalization** (multiple owned pages competing for one query) and
decide which pages to **compile into a preset + 301** vs **retain**. Secondary: factor in the
plugin's preset SEO capability as the consolidation mechanism.

## 2. Data sources & caveats

- **GSC export** (Pages + Queries, last-16-months). 497 page rows, 1000 query rows.
- **Caveat — no query×page join.** The export gives per-page metrics and per-query metrics
  separately, not "which query drove which page." Cluster assignments are inferred from URL slug +
  position spread + impression profile. This is strong for slug-obvious specialists (e.g.
  `/product/gate-fold-brochure/` owns "gate fold brochure") but for a definitive "these two pages
  split query X" you must pull the query→page breakdown in GSC for the specific pair.
- **Caveat — staging is stale.** The staging mirror had ~79 products vs ~247 product URLs in GSC
  and was missing top pages, so it could not be used as a faithful "what's live" list. Treat GSC
  URLs as the source of truth for what's indexed.
- **Caveat — preset list unread.** `wp_options['pps_presets']` could not be read during analysis
  (option read was approval-gated; staging preset sitemap 403'd). GSC confirms **no preset ranks**,
  so this only affects whether a given hub already exists vs needs minting (see §8).

## 3. How the preset SEO function works (code reference)

Presets live in `wp_options['pps_presets']`; each row publishes a **root-level** URL `/{slug}/`
that renders the appropriate calculator with `PPS_CONFIG.defaults` populated. On a preset request,
`pps-calculators.php` emits (function on `wp_head`, ~line 3719):

| Emission | Behavior | Configurable? |
|---|---|---|
| `<link rel="canonical">` | **Hard-coded to the preset's own URL** (`pps_get_preset_url($slug)`, ~L3746) | **No** |
| `<meta name="robots">` | **Hard-coded `index, follow, max-image-preview:large`** (~L3749) | **No** |
| `<title>`, meta description | From preset row / Tier-1 override | Yes |
| OG (5 tags), Twitter (4 tags) | From preset row | Yes |
| Product + BreadcrumbList + LocalBusiness + FAQ + WebApplication JSON-LD | Parameterized emitters | Yes (3 override tiers) |
| Yoast / Rank Math dedupe | Filters at priority 999 force `wpseo_*` / `rank_math/frontend/*` title, description, canonical, robots, OG, Twitter to match the preset (~L4019–4049) | — |

Tier-1 overrides cover only: `meta_title`, `meta_description`, `og_image`, `schema_name`,
`schema_sku`, `breadcrumb_label`. **There is no override for canonical or robots.**

**Implication that drives the whole plan:** a preset cannot be minted "quietly." Every preset is a
loud, self-canonical, fully-schema'd, index/follow page by construction. That makes it (a) the
strongest possible consolidation hub, and (b) a self-cannibalization risk if minted per-variant on
overlapping head terms.

## 3b. Preset pattern — plain-language explainer (start here if the pattern is fuzzy)

A "preset" is **two things in one row** of `wp_options['pps_presets']`: a **saved calculator
configuration** *and* an **SEO landing page**. That dual nature is what makes it confusing.

**The row holds:** a `slug`, which `calc` it runs, the `defaults` (size, pages, fold… — the config),
and a few SEO fields (title, description, image, price).

**What that one row does:**
1. **Pre-fills a calculator** to a specific configuration, and
2. **Mints a URL** — `/{slug}/`, root-level (no `/product/` or `/product-category/` prefix).

**When someone (or Google) hits `/{slug}/`** there is *no real WP page* behind it — the plugin
catches the URL, injects a virtual `WP_Post`, renders the **pre-filled calculator**, and emits the
full SEO set (title, self-canonical, `index,follow`, OG, Twitter, 5 JSON-LD blocks, noscript) while
**forcing Yoast/Rank Math to match** (see §3). That's why it's "the most SEO-complete page the
plugin can produce."

### The three landing-page types — pick one per search intent
The entire plan is just choosing the right page type for each intent:

| Type | URL shape | Use for | Example intent |
|---|---|---|---|
| **Category archive** | `/booklets/` | the **hub** for a broad head term (browse grid + wizard) | "booklet printing" |
| **Product** | `/product/gate-fold-brochure/` | a specific **catalog SKU / specialist** (transactional) | "gate fold brochure" |
| **Preset** | `/5.5x8.5-booklet-printing/` | a specific **calculator configuration** that deserves its own ranked page but isn't a catalog SKU | "5.5×8.5 booklet printing" |

**Rule of thumb:** broad head term → **category**; specific catalog item → **product**; specific
calculator setup you want to rank on its own → **preset**.

### Why we analyzed presets but you're using categories
The original plan (below) made **presets the head-term hubs** because they out-signal everything
else. In execution the operator chose the **WooCommerce category archives** as the hubs instead
(they render the hero + `[pps_cat_wizard]` + paper tables and are the nav destinations). So today
**presets are unused / greenfield** (§4.2 — no preset URL ranks). That doesn't retire the pattern;
it **re-slots** it: presets are no longer the hubs, they're the lever for the *next* layer —
**specific-configuration pages** a category is too broad for and no single product owns.

### First 3 preset candidates worth minting (validate volume in GSC first)
Each is a distinct calculator config, targets a term nothing currently owns cleanly, and follows the
§7 governance ("one preset per distinct intent; mint only to own a term and 301 the losers in").

| Preset URL | Calc + defaults | Target term(s) | Rationale / what it owns |
|---|---|---|---|
| `/8.5x11-booklet-printing/` | saddle, 8.5×11 (opens to 11×17) | "8.5x11 booklet printing", "letter-size booklet" | Explicit-size intent. `/booklets/` is the broad hub and `small-booklet-printing` owns "small/mini" — the **full-letter size has no owning page**. |
| `/5.5x8.5-booklet-printing/` | saddle, 5.5×8.5 (half-letter / digest) | "5.5x8.5 booklet", "half letter booklet", "digest booklet" | The most common booklet size; strong explicit-size intent; no dedicated product. |
| `/legal-size-brochure-printing/` | brochure, 8.5×14 (tri- or bi-fold) | "legal size brochure", "8.5x14 brochure" | A distinct **format** the fold-specialist products (all letter-size) don't cover. *(Least certain — validate volume before minting.)* |

**How to mint one:** `pps-presets-admin.php` → add a row (slug, calc, defaults JSON, title/desc/
image/price), then optionally use the Tier-1/2/3 accordions to fine-tune its schema. It goes live at
`/{slug}/` immediately and enters `/pps-presets-sitemap.xml`. Then 301 any weaker competitor into it.

**Governance reminder (§7):** never mint a *family* of near-duplicate presets for one head term
(`/cheap-`, `/affordable-`, `/low-cost-booklet-printing/`) — that's self-cannibalization. One
intent, one preset.

## 4. Findings

### 4.1 Presets are the most SEO-complete page type
See §3. A preset out-signals a bare WooCommerce product (which relies on Yoast/RM defaults).

### 4.2 Presets are currently greenfield
Searching the GSC export for calculator/preset-style URLs returns nothing with traffic — only
`/product-builder/` (0 clicks) and `/create-your-own/` (0 clicks), both legacy WCPA pages, not PPS
React presets. Every cannibalization cluster is a fight among `/product/*` pages and root-level
SERVICE pages; presets aren't in that fight yet.

### 4.3 The cannibalization clusters

Head-term competitors, worst offenders first. `SERVICE` = root-level info/landing page;
`product-generic` = "cheap/small/custom/budget" product; `product-specialized` = fold/size/shape/
material/binding variant.

**Brochure** (head "brochure printing")
- Generic dead weight: `/product/low-cost-brochure-printing/` (131k impr, pos 41, 58 clk),
  `/brochure-printing-services/` (pos 35).
- Specialists ranking well (keep): 4-panel accordion pos 7, double-gate pos 6, gate-fold pos 7,
  roll-fold pos 11, square variants pos 15–20, `/mini-brochures/` pos 10.
- Latent giant: `/product/trifold-brochure-printing/` — 153k impr at pos 36 (biggest upside page).

**Booklet** (head "booklet printing / custom booklet printing")
- Generics splitting the head: `/product/small-booklet-printing/` (242k impr, pos 34, **1152 clk**),
  `/product/cheap-booklet-printing/` (313k impr, pos 31), `/small-booklet-printing/` (SERVICE, near-
  identical slug to the product — textbook self-cannibalization), `/custom-booklet-printing/` (pos 58).
- Specialists (keep): square pos 33, 8x8 pos 20, saddle-stitch pos 31, perfect-bound pos 58.
- Informational (keep, different intent): `/how-to-format-a-booklet-for-print/` pos 13.

**Coupon** (mild) — `/product/custom-coupon-book-printing/` (pos 18, 781 clk) already a fine owner;
only `/product/coupon-booklet-printing/` is clearly redundant. `/coupon-pads-coupon-booklets/`
(pos 12) owns "coupon pads" — distinct, keep.

**Flyer** — no strong generic today; `/product/budget-flyer-printing/` (pos 51) + `/online-flyer-
printing-service/` are weak generics; `/product/plastic-flyers/` and `/product/square-flyer/`
(pos 16) are specialists.

**Notepad** — `/product/custom-spiral-notepads/` (pos 28, spiral specialist) + generics
`cheap-personalized` / `custom-promotional` + SERVICE `/custom-notepad-printing/` (pos 54).

**Business card** — mostly *healthy differentiation*: ~18 material/shape/finish specialists each own
a distinct modifier term. Only real redundancy: `/product/low-price-business-cards/` duplicates
`/product/cheap-business-card-printing/`.

## 5. Core principle: consolidate shared intent, migrate distinct intent

Cannibalization is *multiple pages fighting for one term*. The fix depends on whether pages share
an intent:

- **Shared intent** (cheap / small / custom / budget / low-cost booklet) → all answer "I want
  inexpensive booklets." **Fold them into one hub.**
- **Distinct intent** (8x8 / square / perfect-bound / saddle-stitch booklet) → each answers a
  different query. **Give each its own page.** Never fold a distinct-intent page into the generic
  hub.

### The 8x8 lesson (worked example)
"8x8 is just a default-size change on the same calc — perfect preset material, so redirect it into
a preset." Half right. Two operations wear the same words:

- **(A) Migrate** `/product/8x8-booklet-printing/` → its **own** `/8x8-booklet-printing/` preset
  (size defaulted to 8×8; `meta_title`/`schema_name`/`breadcrumb_label` = "8x8 Booklet Printing").
  This *upgrades* the page while keeping its "8x8 booklet printing" targeting. **Correct.**
- **(B) Fold** `/product/8x8-booklet-printing/` → the **generic** `/booklet-printing/` hub. **Wrong**
  — 8x8 cannibalizes nothing; the generic hub ranks *worse* for "8x8 booklet printing" than a
  dedicated page, so you'd delete a pos-20 asset (299 clicks) and resolve zero cannibalization.

**Caution when migrating a winner:** a preset's crawlable body is the calculator's noscript
fallback (title + description + spec table) + schema — thinner than a rich product page. For a
losing generic that's all upside; for a ranking specialist, **port the product copy into the preset
description + FAQ override** or you trade rich-content pos-20 for thin-content pos-30. Also confirm
WCPA vs PPS ownership (migrating a WCPA product changes the checkout flow).

## 6. Per-cluster consolidation plan (redirect map)

`301` targets are created in **Rank Math**. `PRESET-HUB` = mint (or designate) one preset as
canonical owner. `RETAIN` = leave live (owns a distinct term). `HOLD/PHASE-2` = high-authority,
migrate only after the hub ranks.

| Cluster | Source page | Clk | Pos | Verdict | Target |
|---|---|---|---|---|---|
| brochure | **`/brochure-printing/` (mint, brochure calc)** | — | — | PRESET-HUB | — |
| brochure | `/product/low-cost-brochure-printing/` | 58 | 41 | 301 | **`/product/trifold-brochure-printing/`** (winner product, 201 clk — per the product-generics rule; was `/brochure-printing/`). Updated 2026-07-15. |
| brochure | `/brochure-printing-services/` | 24 | 35 | 301 | `/brochure-printing/` |
| brochure | `/brochure-printing-arizona/` | 1 | 28 | 301 | `/brochure-printing/` |
| brochure | `/square-brochure-printing/` | 87 | 32 | REVIEW | 301 or retain as square aggregator |
| brochure | `/product/trifold-brochure-printing/` | 201 | 36 | RETAIN + OPTIMIZE | — (owns "trifold brochure") |
| brochure | `/mini-brochures/` + all fold/shape specialists | — | 6–20 | RETAIN | — |
| booklet | **`/booklet-printing/` (mint, saddle calc)** | — | — | PRESET-HUB | — |
| booklet | `/product/cheap-booklet-printing/` | 687 | 31 | **RETAIN** | — (owner chose to leave it alone 2026-07-15: 687 clk at pos 31 earns its keep; not folded into small-booklet or the category) |
| booklet | `/small-booklet-printing/` (SERVICE) | 224 | 45 | 301 | **`/product/small-booklet-printing/`** (the product, **not** the generic hub/category) — near-slug-twin of the product, which owns "small/mini booklet" (1,152 clk, pos 34); sending it to the generic hub would split the mini intent off the page that owns it. (Corrected 2026-07-14.) |
| booklet | `/custom-booklet-printing/` | 27 | 58 | 301 | `/booklet-printing/` |
| booklet | `/product/presentation-booklet-printing/` | 15 | 43 | 301 | `/booklet-printing/` |
| booklet | `/product/small-booklet-printing/` | 1152 | 34 | **KEEP — booklet winner/hub** | — (resolved 2026-07-15: this IS the booklet head-term target; the `/small-booklet-printing/` SERVICE page folds into it. cheap-booklet stays live alongside it — owner's call.) |
| booklet | `/how-to-format-a-booklet-for-print/` | 19 | 13 | RETAIN | — (informational) |
| booklet | 8x8 / square / saddle-stitch / perfect-bound | — | 20–58 | RETAIN → optionally MIGRATE to own preset | — |
| coupon | `/product/custom-coupon-book-printing/` | 781 | 18 | HUB / PROMOTE | — |
| coupon | `/product/coupon-booklet-printing/` | 164 | 19 | 301 | `/product/custom-coupon-book-printing/` |
| coupon | `/coupon-pads-coupon-booklets/` (SERVICE) | — | 12 | 301 | `/coupon-booklets/` (Coupons category — dual-intent, spans pads+booklets; see reconciliation "Redirect-target rule". Monitor "coupon pads" after redirect.) |
| coupon | `/product/coupon-tear-pad-printing/` | — | 17 | RETAIN | — (specialist "coupon pads" product) |
| flyer | **`/flyer-printing/` (mint, brochure calc)** | — | — | PRESET-HUB | — |
| flyer | `/product/budget-flyer-printing/` | 137 | 51 | 301 | `/flyer-printing/` |
| flyer | `/online-flyer-printing-service/` | 17 | 22 | 301 | `/flyer-printing/` |
| flyer | `/product/plastic-flyers/`, `/product/square-flyer/` | — | 16–38 | RETAIN | — |
| notepad | `/custom-notepad-printing/` (SERVICE) | 76 | 54 | HUB / PROMOTE (no calc) | — |
| notepad | `/product/cheap-personalized-notepads/` | 140 | 37 | 301 | `/custom-notepad-printing/` |
| notepad | `/product/custom-promotional-notepad-printing/` | 15 | 40 | 301 | `/custom-notepad-printing/` |
| notepad | `/product/custom-spiral-notepads/` | 185 | 28 | RETAIN | — (spiral specialist) |
| notepad | `/product/notepad-sample/` | 0 | — | NOINDEX or 301 | — (test product) |
| business-card | `/product/low-price-business-cards/` | 0 | 48 | 301 | `/product/cheap-business-card-printing/` |
| business-card | all material/shape/finish specialists | — | — | RETAIN — **do not merge** | — |

## 7. Governance rules for presets

1. **One preset per distinct search intent**, each with a unique target keyword. A preset is a
   commitment to compete (canonical + robots are hard-coded), so mint one only when it will *own* a
   term and you'll 301 the losers into it.
2. **Never a family of near-duplicate presets for one head term** (e.g. `/cheap-`, `/affordable-`,
   `/low-cost-booklet-printing/`). Size/variant options belong *inside* one calculator preset as
   dropdowns, not as separate presets.
3. **Same calc, different intent = OK.** `/brochure-printing/` and `/flyer-printing/` both run on
   the brochure calc but target different queries — that's the rule working, not breaking it.
4. **Migrating a ranking page is higher-stakes than folding a loser** — port copy into the preset
   description + FAQ override, preserve the target keyword, monitor for the migration dip.
5. **All redirects in Rank Math.** Never PHP or `.htaccess`.

## 8. Open questions / inputs needed

1. **WCPA vs PPS ownership** of each 301-listed `/product/*` page. Redirecting a WCPA-configurator
   product into a PPS calculator preset changes checkout. (PPS and WCPA never share product IDs.)
2. **Booklet judgment call:** phase-2 the 1,152-click `/product/small-booklet-printing/` into the
   hub, or make that product the hub and skip a booklet preset.
3. **Existing preset list** — read `wp_options['pps_presets']` (slug + calc + target keyword) to
   flag each existing preset as owner / redundant / needs-differentiation. GSC shows none rank, so
   this only tells us whether a hub already exists vs must be minted.
4. **Query→page confirmation** in GSC for any specialist pair you're unsure shares a query.

## 9. Constraints & guardrails

- Redirects: **Rank Math only**, never PHP/.htaccess.
- Do **not** add WCPA-active product IDs to `pps_get_registry()`, `wp_options['pps_presets']`, or
  the "PPS Defaults" meta box.
- `priority-print` MCP = **staging**; `PPS-Production` MCP = **production** (do not use Production
  for dev/testing).
- Pricing math lives only in the calculator HTML; `pps-calculators.php` has no pricing logic.
