# PPS Product Calculators

WordPress/WooCommerce plugin for Priority Print Service — pricing calculators with integrated artwork proofing.

## Operator environment

The repository owner does NOT use Claude Code locally and has no intention of installing it. All work happens through Claude on the web. This means:

- **Never tell the user to "run this from your local machine"** as the resolution to a sandbox issue — they have no local terminal.
- **Never assume they can git fetch, merge, or push locally.** If a push from the sandbox fails (403 etc.), surface the failure and ask the user how to proceed, or suggest using a GitHub PR/web UI instead. Don't hand them a CLI command and call it done.
- **Always do git pushes from this environment.** If a destination branch is blocked by sandbox permissions, that's a real problem to flag, not something to off-load to the user.
- Mirror this expectation in every new chat about this codebase.

## Architecture
- Self-contained React calculators (HTML files with inline Babel)
- PHP plugin handles cart, orders, REST API, SEO, Google Drive, tooltips
- All calculators share: shipping/rush engine, zone map, RichTip tooltips, debug panel, DatePicker
- Logo loaded from PPS_CONFIG.logoUrl (injected by PHP, not embedded)
- Zone map embedded in HTML for standalone testing, overridden by PHP in production

## Files
| File | Purpose |
|------|---------|
| `calc-preview-test.html` | Saddle stitch booklet calculator — most mature, has full proof/preview modals, approval package generation, magnifier, 3D book preview |
| `calc-perfect-bound.html` | Perfect bound booklet calculator — mixed color per-set, perfect binding labor, outfold, perforation, finishing cuts |
| `calc-brochure.html` | Brochure & flat printing calculator — 9 fold types, 3D fold preview (7 proven fold renderers), SheetPreview with front/back upload |
| `calc-coupon-book.html` | Coupon book calculator — registered as the `coupon` calc type in `pps-calculators.php`, has its own FAQ schema slot. |
| `brochure-fold-previewer.html` | Reference: standalone 3D fold previewer tool (vanilla JS, 1453 lines). Source for the proven fold rendering engine now integrated into calc-brochure.html |
| `pps-calculators.php` | WP plugin: cart/orders, SEO schemas (Product/LocalBusiness/FAQ/WebApp/BreadcrumbList), preset routing + virtual-post render, per-preset SEO emission + dedupe filters, sitemap providers (WP/Yoast/RM), noscript fallback, llms.txt with presets section, reorder, edit mode, PPS-Spec/PPS-Production-Start for Missive, per-product defaults, tooltips injection, logo URL |
| `pps-config-admin.php` | Admin config page with tabs: Production, Papers, Finishing, Artwork, Sizes, Shipping, SEO (GBP rating + per-calc-type FAQs) |
| `pps-presets-admin.php` | Admin CRUD for `wp_options['pps_presets']` — list view + edit form. Each preset gets fields (slug, calc, title, desc, image, price_from, currency, defaults JSON) plus collapsible accordions for Tier 1 field overrides, Tier 2 schema-block overrides, Tier 3 extra schema blocks, and per-preset FAQ override. |
| `pps-gdrive.php` | Google Drive OAuth (credentials in wp_options, not source code), artwork upload with idempotent retry, thumbnail generation |
| `imposition-tool.html` | Browser-based auto-imposition tool (React + pdf-lib): vector-preserving sheetwise step-and-repeat onto press sheets, mirrors calculator pricing imp exactly. Works standalone (drag & drop) or inside wp-admin. See `docs/IMPOSITION_TOOL.md`. |
| `pps-imposition.php` | wp-admin host for the imposition tool (PPS Calculators → Imposition): iframe app + AJAX bridge (order queue w/ parsed spec, Drive artwork proxy-download, imposed-PDF upload back to the order folder via existing Drive OAuth) |
| `pps-reorder.php` | Guest order lookup (`[pps_order_lookup]` shortcode) and single-item reorder for legacy/WCPA orders. Loaded by `pps-calculators.php`. |
| `docs/MASTER_PRICING_LOGIC.md` | Single source of truth for pricing strategy, applied values, rollback notes, knob-tuning patterns. **Read before suggesting any formula change.** |
| `docs/PRICING_MATRIX.md` + `docs/pricing-matrix.json` | Captured output: what all 8 calculators actually quote across size, paper and page count (1,816 points), read from the rendered UI rather than the constants. Reference *and* regression gate — re-run before/after any pricing or styling port and diff. Regenerate with `tools-pricing-matrix.mjs`. |
| `ups-zone-map-seed.json` | UPS Ground transit days by 3-digit ZIP prefix (1000 entries) |
| `pps-theme/` | Custom WordPress theme replacing Astra Pro — owns site chrome, typography, color tokens, WooCommerce shell. Stays out of the calculator plugin's way. `pps-theme/preview.html` is a Pages-served standalone preview of the header. |
| `designer/` | **Spike.** Print-first layout editor (Vite + React + TS) — the document *is* a product; press-PDF export with CMYK, bleed/trim boxes and subset font embedding. Unlike the calculators this is a real build, not a single inline-Babel HTML. `dist/` is committed for Pages. **Read `docs/DESIGNER_SPIKE.md` before touching it** — it records what's proven vs faked and the next steps in order. Run `cd designer && npm test` after any change to `src/export/pdf.ts`. |

## Shared Components (in each calculator HTML)
- `PCF` — pricing constants object, overridable via PPS_CONFIG.calc
- `calculate()` — pricing engine (different per product type)
- `Panel` — price/qty/availability display (desktop sidebar + mobile compact)
- `Sel`, `Pil`, `TxtNum`, `Sec` — form components matching Astra theme style
- `TurnBadge` — turnaround impact badges (magenta, "+N days")
- `RichTip` — media-rich tooltips (text/image/video/youtube), reads from PPS_CONFIG.tips via tipKey
- `InfoTip` — simple text tooltips (legacy, still used in proof modal)
- `DatePicker` — custom calendar with rush zone coloring
- `FitToggle` — art transform controls (Crop/Fill/Fit/Stretch/Scale/Rotate 90°)
- `DebugPanel` — calculation breakdown with turnaround/shipping/SEO schema debug
- Zone map (1000 entries) embedded inline, overridden by PHP

## Saddle Stitch Calculator (calc-preview-test.html)
- **Status:** Most complete. Full proof/preview system.
- **Pricing:** Saddle stitch binding, stitching labor, two-staple auto/opt-in
- **Proof modal:** Bleed/trim/safety/spine guides, magnifier with guides, hi-res 300 DPI render
- **Preview modal:** 3D closed book + open spread views with drag-to-rotate
- **Art transforms:** Crop/Fill/Fit/Stretch/Scale/Rotate with approval package generation (4 deliverables: raw file, print-ready PDF, preview JPEGs with guides, manipulation manifest)
- **Sets:** Mothballed (internal logic preserved, UI commented out)

## Perfect Bound Calculator (calc-perfect-bound.html)
- **Status:** Pricing engine complete. Proof/preview inherited from saddle stitch.
- **Pricing:** Perfect binding (2-up/1-up), 3 finishing cuts, outfold, perforation (GW machine)
- **Unique:** Mixed color per-set (Full Color/Greyscale/Mixed with color+BW page inputs), two-branch logarithmic discount curve, $40 base rate
- **Spine:** Calculated from page count (visible in 3D preview)

## Brochure Calculator (calc-brochure.html)
- **Status:** Pricing engine complete. 3D fold preview integrated.
- **Pricing:** 9 fold types, folding labor, 3 difficult fold surcharge tiers, coating with sides option
- **SheetPreview:** Front/back upload slots, PDF auto-extraction (page 1=front, page 2=back)
- **Proof modal:** Bleed/trim/safety + fold line overlays, FitToggle art transforms
- **Preview modal:** Proven 3D CSS fold renderer (7 fold types from brochure-fold-previewer.html), view step buttons, anti-clipping translateZ math
- **Template:** jsPDF with drawDashedLine/drawDashedRect, grey bleed zone, fold lines

## Imposition Tool (imposition-tool.html + pps-imposition.php)
- **Engine runs entirely in the browser** (pdf-lib) — no Python, no server-side PDF work. `window.__ppsImpose` exposes the engine for headless tests/console debugging.
- `flatGrid`/`flatImp`/`stickerImp` are **verbatim ports of the calculators' imp functions** — if a calculator's imp math ever changes, update the tool's copy in the same commit (parity is the core invariant: production must place exactly what pricing promised).
- v1 scope: flat calcs (brochure/postcard/letterhead/greeting-card) + stickers (step-and-repeat, 1–2 sides) **and saddle stitch booklets** (printer-spread signature pagination, spine gets no bleed, auto-tumble per press flip edge, cover slug-labelled; saddle preset imp table embedded — authoritative over calcCustomImp). Perfect-bound/coupon refuse cleanly (v2). No creep compensation yet.
- Known pricing quirk: custom long-narrow flats (long edge ~9.5–12.5″, small short edge) can price an imp that can't physically fit 2-across on 18.5″ usable; tool refuses unless the operator ticks "allow best physical fit" (result is flagged MISMATCH in UI + slug). Details in `docs/IMPOSITION_TOOL.md`.
- Output: `IMPOSED_Order-<id>_<job>_<trim>_<imp>up_<sheet>.pdf` filed into the same Drive order folder; the admin queue shows an IMPOSED badge when one exists.

## PHP Plugin Key Features
- **Presets** (`wp_options['pps_presets']`): each row publishes a public URL at `/{slug}/` (root-level, no prefix) that renders the appropriate calculator with `PPS_CONFIG.defaults` populated. Individual rewrite rules are registered per preset slug (not a wildcard). Routing via `pps_preset` query var; virtual `WP_Post` injected via `the_posts`; calculator HTML rendered via `the_content` filter. Cart line items capture `_pps_preset_slug` for analytics. Preset CRUD lives in pps-presets-admin.php. All URL redirects (e.g. old `/booklets/*` paths) are managed in Rank Math, never in PHP or .htaccess.
- **Per-preset SEO**: when on a preset URL, plugin emits per-preset `<title>`, meta description, canonical, robots, OG (5 tags), Twitter Card (4 tags), Product/BreadcrumbList/LocalBusiness/FAQ/WebApplication JSON-LD, plus a noscript fallback in the footer. Dedupe filters at priority 999 force Yoast / Rank Math output to match (covers `wpseo_title|metadesc|canonical|robots|opengraph_*|twitter_*` and `rank_math/frontend/title|description|canonical|robots`).
- **Schema overrides per preset** (three tiers): Tier 1 simple field overrides (meta_title, meta_description, og_image, schema_name, schema_sku, breadcrumb_label); Tier 2 per-block JSON-LD wholesale replacement (product, breadcrumb, localbusiness, faq, webapp); Tier 3 arbitrary additional JSON-LD blocks. Override-derived emission uses `JSON_HEX_TAG` to escape `<` and `>` for script-tag-breakout protection. Per-preset FAQ override available alongside calc-type defaults.
- **Sitemaps**: `PPS_Presets_Sitemap_Provider` registered with WP core sitemaps; when Yoast active, `wpseo_sitemap_index` filter adds `/pps-presets-sitemap.xml` reference; when Rank Math active, `rank_math/sitemap/index/entries` filter does the same. Custom XML at `/pps-presets-sitemap.xml` is the single source of truth.
- SEO: suppresses WooCommerce/Yoast/Rank Math/AIOSEO/SEOPress Product schemas on calculator pages AND preset URLs (via `pps_is_calculator_owned_url()`), injects own Product+LocalBusiness+FAQ+WebApp+BreadcrumbList schemas via `pps_emit_*_schema()` helpers (parameterized)
- LocalBusiness `aggregateRating` populated from Google Business Profile rating mirrored manually in the SEO admin tab (`seo.gbp_rating_value`, `seo.gbp_review_count`, `seo.gbp_url`); only emitted when both rating and review count are valid (rating in (0, 5], count > 0).
- FAQ schema is calc-type-aware (saddle/perfect-bound/brochure/coupon). Defaults live in `pps_default_faqs()`; admin overrides stored in `wp_options['pps_faqs']` keyed by calc type. Calc types with no defaults and no saved entries emit no FAQ `<script>` (better than emitting wrong-calc FAQs).
- Noscript fallback with static content for crawlers (calculator product pages AND preset URLs — preset version pulls title + description + spec table from the preset row)
- llms.txt endpoint at /llms.txt for AI search engines, with a "## Presets" section listing every preset URL + description
- Order meta: PPS-Spec (pipe-delimited spec string) and PPS-Production-Start for Missive parsing
- Edit mode: atomic add-before-remove for cart item updates
- Reorder: base64-encoded config in URL, restores all settings including artwork path
- Per-product defaults: "PPS Defaults" tab in WooCommerce product editor
- Tooltips: centralized in `wp_options['pps_tooltips']`, AJAX-saved (no admin tab UI in this repo), injected as PPS_CONFIG.tips
- GDrive: credentials in wp_options (not source), idempotent upload with retry, artwork path preserved for reorder

## Security (audited, 33+ bugs fixed)
- No credentials in source code (OAuth moved to wp_options)
- All POST handlers have nonce verification
- All admin functions have current_user_can checks
- REST API endpoints require is_user_logged_in
- OAuth flow has CSRF state parameter
- All user input escaped on output (esc_html, esc_url)
- Path traversal checked on artwork paths
- CSV parser bounded against DoS (max 999 ZIP prefix)
- img.onerror on all Image() constructors
- FileReader.onerror on all FileReader instances
- resp.ok check on fetch before .json() parse
- parseInt NaN validation on URL params
- Reorder type coercion (Number() on all numeric, strict boolean)
- Edit mode atomicity (add before remove)

## Pricing changes

Before suggesting any formula change, PCF default change, or new pricing knob, read `docs/MASTER_PRICING_LOGIC.md`. It is the single source of truth for the pricing engine — strategy, applied values, rollback reference, and the patterns for adding new knobs. Pricing math lives only in the calculator HTML files; `pps-calculators.php` contains no pricing logic, only config injection.

## WCPA — parallel coexistence

PPS React calculators and the legacy WCPA plugin run side-by-side on the same WooCommerce install. They never share product IDs.

- A product appears in `pps_get_registry()['<filename>']['products']` → React calc owns it (pricing, cart, shipping/rush, Google Drive, schemas, edit specs, reorder).
- A product is NOT in the registry → WCPA (or any other addon plugin) owns it. Zero PPS code runs on its product page or its cart/order flow. All PPS cart hooks short-circuit on missing `pps_metadata` / `pps_price` keys.
- Reorders of legacy WCPA-era orders are handled by `pps_handle_single_item_reorder()` in `pps-reorder.php` with the original unit price frozen via `pps_legacy_unit_price`.
- The `_pi_*` admin-meta hider in `pps-reorder.php` is the only globally-firing PPS hook; it's defensive cleanup of WCPA's leaked-visible internal keys, not a coupling.

**Do NOT add WCPA-active product IDs to the PPS calculator registry** — that would route them through both systems simultaneously and likely double-bill or break the cart. WCPA products should not appear on any of: `pps_get_registry()` entries, `wp_options['pps_presets']` rows, or the "PPS Defaults" product meta box. WCPA products will not use the integrated Google Drive uploads or shipping/turnaround logic by design.

**Every product assigned a PPS calculator MUST be a WooCommerce *virtual* product** (`_virtual` = `yes`, owner rule 2026-07-19). The calculator collects the shipping address itself and PPS owns shipping/turnaround; marking the product virtual keeps WooCommerce's own shipping machinery (and coexisting addon/shipping plugins) out of the cart/checkout for these items. Flipping `_virtual` is part of the registry-migration checklist — set it in the same change that adds the product ID to `pps_get_registry()`. All 34 registry products were flipped on staging 2026-07-19.

## Branch & Deploy

- **Pages source branch:** `pps-pricing-config` — GitHub Pages serves directly from the root of this branch. All calculator changes must be pushed here. No separate deploy step.
- **`.nojekyll` is MANDATORY** on `pps-pricing-config`. Without it, Pages runs Jekyll, which silently breaks the build because the inline JSX/Babel inside the calculator HTML contains `{{ }}` that Jekyll tries to parse as Liquid templates. Symptom: your pushes never appear on the preview URL even though the file on GitHub looks correct. **Never delete `.nojekyll`.**
- **Never write a literal `</script>` inside the `<script type="text/babel">` block** — not even inside a JS string, template literal, or JSX prop. The HTML parser scans script content byte-by-byte and closes the outer block at the first `</script>` it sees, regardless of JS quoting. Symptom is identical to the Jekyll one (build stamp updates but the page renders as a wall of source text), so it gets misdiagnosed. The canonical fix is escape it as `<\/script>` — JavaScript treats `\/` as `/` at runtime so any HTML you generate still serializes cleanly, but the HTML parser's close-tag matcher misses the backslashed form. This bit me on 2026-05-08 in the `buildPreviewHtml` template literal; if you embed HTML strings that contain `</script></body></html>` (e.g. self-contained downloadable HTML), always backslash the slash. Same rule applies for any future HTML-builder helper.
- Do NOT push to `website` — it's unrelated to the preview.
- **Preview URLs** (served by GitHub Pages from `pps-pricing-config`):
  - https://pdevvle.github.io/priorityprintservice.com/calc-preview-test.html (saddle stitch)
  - https://pdevvle.github.io/priorityprintservice.com/calc-perfect-bound.html (perfect bound)
  - https://pdevvle.github.io/priorityprintservice.com/calc-brochure.html (brochure)
  - https://pdevvle.github.io/priorityprintservice.com/calc-coupon-book.html (coupon book)
- Each calculator has a build-stamp chip in the bottom-right corner. After a push, wait ~60 seconds for Pages to rebuild, then hard-refresh (Cmd/Ctrl+Shift+R) or use an Incognito window. If the chip still doesn't update, verify `.nojekyll` exists on `pps-pricing-config` root — that's the #1 cause of "my push didn't show up."
- Go private protocol: replace files with dummies, flip repo to private. Restore: `git checkout pps-real-backup -- <files>`

## Retired branches

- `gh-pages` (last commit 2026-04-17) — never the live Pages source despite an earlier session's mistaken claim. Archived as `OLD/gh-pages`. Safe to delete locally: `git push origin --delete gh-pages`. Contained an orphan `pb-v2.html` cache-verification duplicate of the perfect-bound calculator with the pre-fix cover-print formula.
