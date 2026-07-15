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
- **Import the Page → category 301s into Rank Math.** Delivered as `redirect-map-pages-to-categories.csv` (17 clean rows + 1 needing the Stickers page's real slug). These retire the now-orphaned standalone Pages (`/custom-booklet-printing/`, `/brochure-printing-services/`, …) onto the category hubs. **Not yet imported.** After importing, set the old Pages to Draft/Trash.
- **Higher-risk redirect to watch:** `/coupon-pads-coupon-booklets/` ranks ~pos 12 (~376 clicks for "coupon pads") — the one page §6 said to *keep*. Its 301 to `/coupon-booklets/` only pays off if the Coupons category actually targets "coupon pads"; monitor after redirect.
- **Empty categories still need products** (EDDM, Mailers, Signs & Banners, Stickers) to appear in any auto-generated category lists and to give the archive a product grid; the wizard-funnel is the interim.
- **Stickers page slug** — its menu URL was `/?page_id=21486` (no pretty permalink); confirm its real URL or whether it's unpublished (then no redirect needed).

### 🔜 Not yet started — the deeper consolidation
The menu/redirect work retires the *service-page* layer. The **product-level generics** §6 flagged — `/product/cheap-booklet-printing/`, `/product/low-cost-brochure-printing/`, `/product/coupon-booklet-printing/`, etc. — still compete for the head terms and should also fold into the same category hubs (301 in Rank Math), with the specialist products retained. That's the next layer.

### ⚠️ Redirect-target rule — not every service page goes to the category
A service page whose intent is **owned by a specialized product** must 301 to **that product**, not the generic category/hub — otherwise the specific intent splits off the page that ranks for it.
- **`/small-booklet-printing/` → `/product/small-booklet-printing/`** (not `/booklets/`). Near-slug-twin; the product owns "small/mini booklet" (1,152 clk, pos 34). §6 corrected. It is **not** in the category CSV, so add it as a **separate service→product 301** in Rank Math, and repoint (or drop) the "Mini Booklets" menu item that still links it.
- **Review `/coupon-pads-coupon-booklets/`** — the CSV maps it to `/coupon-booklets/`, but it ranks ~pos 12 for "coupon pads" and may itself be the owner (→ *keep*), or belong on `/product/coupon-tear-pad-printing/` if that's the real "coupon pads" owner. Decide before importing.
- Generic marketing pages with no specialized owning product (`/custom-booklet-printing/`, `/brochure-printing-services/`, `/online-flyer-printing-service/`, …) correctly go to the **category** hub.

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
| brochure | `/product/low-cost-brochure-printing/` | 58 | 41 | 301 | `/brochure-printing/` |
| brochure | `/brochure-printing-services/` | 24 | 35 | 301 | `/brochure-printing/` |
| brochure | `/brochure-printing-arizona/` | 1 | 28 | 301 | `/brochure-printing/` |
| brochure | `/square-brochure-printing/` | 87 | 32 | REVIEW | 301 or retain as square aggregator |
| brochure | `/product/trifold-brochure-printing/` | 201 | 36 | RETAIN + OPTIMIZE | — (owns "trifold brochure") |
| brochure | `/mini-brochures/` + all fold/shape specialists | — | 6–20 | RETAIN | — |
| booklet | **`/booklet-printing/` (mint, saddle calc)** | — | — | PRESET-HUB | — |
| booklet | `/product/cheap-booklet-printing/` | 687 | 31 | 301 | `/booklet-printing/` |
| booklet | `/small-booklet-printing/` (SERVICE) | 224 | 45 | 301 | **`/product/small-booklet-printing/`** (the product, **not** the generic hub/category) — near-slug-twin of the product, which owns "small/mini booklet" (1,152 clk, pos 34); sending it to the generic hub would split the mini intent off the page that owns it. (Corrected 2026-07-14.) |
| booklet | `/custom-booklet-printing/` | 27 | 58 | 301 | `/booklet-printing/` |
| booklet | `/product/presentation-booklet-printing/` | 15 | 43 | 301 | `/booklet-printing/` |
| booklet | `/product/small-booklet-printing/` | 1152 | 34 | **HOLD / PHASE-2** | `/booklet-printing/` (after hub ranks) — or make THIS the hub |
| booklet | `/how-to-format-a-booklet-for-print/` | 19 | 13 | RETAIN | — (informational) |
| booklet | 8x8 / square / saddle-stitch / perfect-bound | — | 20–58 | RETAIN → optionally MIGRATE to own preset | — |
| coupon | `/product/custom-coupon-book-printing/` | 781 | 18 | HUB / PROMOTE | — |
| coupon | `/product/coupon-booklet-printing/` | 164 | 19 | 301 | `/product/custom-coupon-book-printing/` |
| coupon | `/coupon-pads-coupon-booklets/`, `/product/coupon-tear-pad-printing/` | — | 12–17 | RETAIN | — |
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
