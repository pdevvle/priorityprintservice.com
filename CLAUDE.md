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
| `pps-paper-report.php` | **PPS Calculators → Paper Report.** Open jobs (processing/on-hold/pending) whose inside, cover or flat stock is not inventoried, with the lead time each carries — paper lives in the line item's `_pps_metadata`, so this is the only place it is queryable. Read-only; cached in `wp_options['pps_paper_report']`, refreshed hourly by cron (`pps_paper_report_refresh`) plus staff-request gap cover. Classification: live config row → the paper snapshot on the order → val tier (<1 inventoried, 1.xx special order, 2.xx factory). |
| `pps-reorder.php` | Guest order lookup (`[pps_order_lookup]` shortcode) and single-item reorder for legacy/WCPA orders. Loaded by `pps-calculators.php`. |
| `pps-term-shortcodes.php` | Everything that makes a `product_cat` archive and `/shop/` look the way they do: category URL routing/redirects, the modern card CSS, the shop masthead, the guided `[pps_cat_wizard]`, the attribute shortcodes and the preset lineup. See "Category page composition" below. |
| `docs/MASTER_PRICING_LOGIC.md` | Single source of truth for pricing strategy, applied values, rollback notes, knob-tuning patterns. **Read before suggesting any formula change.** |
| `docs/PRICING_MATRIX.md` + `docs/pricing-matrix.json` | Captured output: what all 8 calculators actually quote across size, paper and page count (1,816 points), read from the rendered UI rather than the constants. Reference *and* regression gate — re-run before/after any pricing or styling port and diff. Regenerate with `tools-pricing-matrix.mjs`. |
| `ups-zone-map-seed.json` | UPS Ground transit days by 3-digit ZIP prefix (1000 entries) |
| `docs/GO_LIVE_RUNBOOK.md` | The 3.0 go-live: staging de-bloat (Phase 0), selective order-table pull live→staging, freeze-window sequence, auto-increment fix, staging→production push, verification. HPOS confirmed on live. **Read before any go-live or cross-site DB work.** |
| `docs/PPS_3.1_WC11_PLAN.md` | **The release after go-live**: WooCommerce 11 + Action Scheduler 4.0 update for both sites, compatibility test matrix (Drive/AS artwork pipeline is the top risk), default-on feature postures (POS, abandoned-cart stays OFF), hardening riders. Binding rule it carries: **version freeze — no WC/WP/plugin updates on either site during the go-live window**; WC 11 lands in 3.1, both sites together. |
| `pps-theme/` | Custom WordPress theme replacing Astra Pro — owns site chrome, typography, color tokens, WooCommerce shell. Stays out of the calculator plugin's way. `pps-theme/preview.html` is a Pages-served standalone preview of the header. |

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
- `PAPER_DESC` + `paperInv` + `PaperNote` — paper descriptions, inventory blue-dot, and legend. **Fallbacks only**: the runtime source of truth is `pps_paper_meta_defaults()`/`pps_paper_enrich()` in `pps-config-admin.php`, which injects `desc`/`days`/`inv` onto every paper row in `PPS_CONFIG.calc` and feeds the category wizard + `[pps_cat_papers]` cards from the same rows. Copy changes touch `docs/PAPER_CATALOG.md`, `pps_paper_meta_defaults()`, the calculators' embedded maps, and `pps_default_tooltips()` in the same commit (see docs/PAPER_CATALOG.md for the full chain).

## Saddle Stitch Calculator (calc-preview-test.html)
- **Status:** Most complete. Full proof/preview system.
- **Pricing:** Saddle stitch binding, stitching labor, two-staple auto/opt-in
- **Proof modal:** Bleed/trim/safety/spine guides, magnifier with guides, hi-res 300 DPI render
- **Preview modal:** 3D closed book + open spread views with drag-to-rotate
- **Art transforms:** Crop/Fill/Fit/Stretch/Scale/Rotate with approval package generation (4 deliverables: raw file, print-ready PDF, preview JPEGs with guides, manipulation manifest)
- **Sets:** Mothballed (internal logic preserved, UI commented out)
- **Single composition engine (2026-08-24, phase 1 of proof parity):** `composePageCanvas()` is the ONLY place page composition math lives — proof modal, magnifier lens, grid view, thumbnails, 3D preview, print-ready PDF and preview JPEGs all take their pixels from it; screen and print differ only in the `dpi` argument, so CSS can never make the proof disagree with the print (the 87032 class of bug). If composition needs to change, change that one function. Approval is bound to `SHA-256` of the print-ready bytes: manifest records it, order line item carries `_pps_proof_hash`, and the imposition tool hashes what it downloads from Drive and refuses to impose on mismatch (bulk never overrides; the interactive override tick flags the output filename `_UNAPPROVED`). Flagged preflight checks (warn/fail) must be explicitly acknowledged before Approve unlocks, and the acknowledgment is recorded in the manifest. **Regression gates:** `tools-parity-saddle.mjs` + `tools-parity-extended.mjs` + `tools-parity-findings.mjs` (setup in the first one's header) — run all three, themed, after any change to proof/composition/PDF code. **Invariants the 2026-08-24 adversarial audit added:** pages carry `srcPdfPage` and the composition engine's vector branch renders by it, never by display index (slot replacements and reconciled page orders would otherwise print wrong pages); the raw-file skip path requires no transforms AND no reconciliation AND no slot files; `skippedGeneration` reports the branch actually taken; every approval-voiding path revokes the PARENT's `artFiles.approved` via `emitArtwork`/`revokeApproval` (transforms, spec changes, Review button, slot changes, artwork-option switch, mid-generation changes); `pps_proof_hash` posts only when `proofHashOf` names a successfully-uploaded deliverable; **the approval gate applies only to `proof === 0`** (manual/hardcopy proofs are staff-approved — gating them blocked paid-proofing uploads on all 8 calculators). **Hidden layers (2026-08-25):** `ppsAnalyzePdfRisks()` in all 8 calculators reports optional-content groups switched OFF in an uploaded PDF (pdf.js honours the off state so proof and print agree, but a downstream engine that drops `/OCProperties` would print them — bit the imposition tool once); on the saddle it is also a warn-level preflight check, so it trips the acknowledgment gate and is recorded in the manifest (`tools-parity-layers.mjs` is the regression test). Other 7 calculators still composite the proof in CSS — porting them is the remaining proof-parity work.

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

## Category page composition

A `product_cat` archive is assembled from three sources, in this render order:

1. **The term description** — hand-authored per category, stored in the database, printed
   by WooCommerce *before* the product loop. `pps-term-shortcodes.php` runs shortcodes
   inside it (`add_filter('term_description', 'do_shortcode', 11)`). Holds the
   `.pps-cat-hero` masthead markup, the optional `.pps-cat-usps` bar, the
   `[pps_cat_wizard]` guided picker, marketing prose, and `[pps_cat_attributes]`.
2. **The WooCommerce product loop** (`ul.products`) — the product cards.
3. **Plugin hooks after the loop** — `woocommerce_after_shop_loop` priority 15 emits the
   "More {Category} Options" preset lineup, priority 20 flushes the attributes section;
   `wp_footer` priority 20 emits the tooltip modal.

Intended reading order: **masthead → wizard → prose → product links → attributes → footer.**

### The attributes section renders from a hook, not where it is written

The paper / coating / turnaround / add-on blocks belong *after* the product links, but the
term description is printed *before* them. So `[pps_cat_attributes]` is a marker: on a
product-category archive it prints nothing where it sits, records its atts, and
`pps_cat_flush_attributes()` emits the section after the preset lineup. On any other page
it renders inline like a normal shortcode.

- Per-category variation rides on **shortcode atts, not term meta** — `papers="text,cover"`,
  `cover_label`, `coatings="yes"`, `addons="<calc>"`, `turnaround`, plus `*_heading`
  overrides. Config stays in the term description next to the rest of the per-category
  content instead of splitting across two un-versioned stores.
- **Section headings live in PHP** (`pps_cat_render_attributes()`), not in the term
  description. Markup echoed from a hook sits outside `.term-description`, so it would not
  pick up that scoped `h2` styling — hence the `.pps-cat-attributes` CSS rules.
- The individual `[pps_cat_papers]` / `[pps_cat_coatings]` / `[pps_cat_turnaround]` /
  `[pps_cat_addons]` shortcodes are still registered and render inline wherever they appear.
  They now delegate to `pps_cat_render_*()` functions shared with the deferred section, so
  a change to one block shows up in both paths.
- The queue is keyed by att signature and latches after it prints. Necessary because
  **the term description is expanded roughly four times per page load** (SEO plugins build
  meta/OG descriptions from it), which is exactly why the old inline attribute blocks were
  emitted ~4× and the deferred section is emitted once. Migrating a category drops ~10–15KB
  of duplicated markup from its page.

**Term descriptions are un-versioned content.** They live only in the database, so a repo
checkout does not describe what a category page actually renders — read the live term
description (`pps_woo_get_category`) before reasoning about a category's layout.

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

## Server-side patches must come home the same session

**A fix that exists only on a server is not a fix. It is a countdown to the next
deploy.** This cost us the artwork-upload hardening on 2026-08-01: magic-byte
validation and an `.htaccess` execution guard were applied surgically to staging via
published insertion blocks, the blocks were deleted once applied, and the repo copy of
`pps-calculators.php` never had them. Deploying that file for an unrelated reason
silently reverted both. Nothing failed, nothing logged, and the site kept working with
its upload defences gone.

The rule, in order of preference:

1. **Change the repo first, then deploy.** This is the normal path. Surgical
   server-side edits are for emergencies only.
2. **If you must patch a server directly, merge it into the repo in the same
   session** — before you close the task, not "next time". A patch that survives only
   as a `.txt` artifact in a deleted commit is already lost.
3. **Never delete the deploy artifact until the repo carries the change**, not merely
   until the server does. "Patch complete" means merged, not applied.

### Before overwriting any file on a server

Whole-file deploys are the mechanism that destroys surgical patches, so check first:

- Compare the server file's size against the repo version, and against repo history:
  `for c in $(git log --format=%H -25 -- <file>); do ... git cat-file -s ...; done`
  A size that matches **no** commit means the server copy has been edited in place —
  stop and find out what is in it before overwriting.
- **Treat a `.bak`, `.orig` or `.prehardening` file beside a live file as a red flag.**
  It is the fingerprint of someone patching surgically. That file is the *pre*-patch
  state, so it cannot restore the patch — it only proves one happened.
- Prefer pull-based deploys (`pps_plugin_download_url` against a raw commit URL) so the
  deployed bytes are pinned to a reviewable commit, and rollback is the same call with
  a different SHA.

### Concurrent sessions: the server's copy must be an ANCESTOR of yours

The size check above only catches a file edited *by hand*. It does not catch the more
common failure now that several Claude sessions run at once: the server holding a
**real commit from somebody else's branch**. The size matches a genuine commit, the
check passes, and the deploy silently reverts work that was live and being used.

This happened on 2026-08-27. One session deployed `pps-calculators.php` from `6b75f46`
at 22:52. That commit did not carry another session's two `require_once` lines, so
`pps-pay-link.php` and `pps-quickbooks.php` stopped loading and `/pps/v1/pay-link`
ceased to exist. Missive's webhook got a 404 and reported only "failed to send
webhook"; the feature's own diagnostics stayed empty because none of its code ran. The
outage lasted until somebody happened to GET the route. **Nothing alerted. Nothing
logged. A paying feature was simply gone.**

So before overwriting a shared PHP file, ask the question the size check does not:

```bash
SIZE=<bytes reported by pps_plugin_list_files>
for c in $(git log --all --format=%H -100 -- <file>); do
  [ "$(git cat-file -s $(git rev-parse $c:<file>) 2>/dev/null)" = "$SIZE" ] && echo "$c"
done
# then, for whatever that returns:
git merge-base --is-ancestor <server-commit> HEAD && echo SAFE || echo "STOP — merge first"
```

- **SAFE** means the server is running something you already contain; deploying moves it
  forward.
- **STOP** means the server has work your branch does not. Fetch and merge that branch
  first, verify the merged byte count equals *both* contributions, and deploy the merged
  file. Never "fix" this by redeploying your own copy — that is the same fault pointed
  the other way.
- A size matching **several** commits is ambiguous; read the file and compare a hash
  rather than guessing.

### Pay Link and QuickBooks load themselves — do not "restore" the requires

`pps-pay-link.php` and `pps-quickbooks.php` are **separately activated plugins**,
listed in `active_plugins` as `pps-calculators/pps-pay-link.php` and
`pps-calculators/pps-quickbooks.php`. This is the same pattern
`pps-assistant.php`, `pps-assistant-webhook.php`, `pps-html-deploy.php` and
`pps-intake.php` already use — several plugin files sharing one directory, each
with its own `Plugin Name` header.

They are NOT required from `pps-calculators.php`, and must not be. They were,
and twice — 2026-08-27 22:52 and 2026-08-29 00:10 — another session redeployed
that file from a branch without the two `require_once` lines. Both times the
modules stopped existing: `/pps/v1/pay-link` 404d, Missive reported only
"failed to send webhook", and none of the module's own diagnostics fired
because none of its code ran. A money-taking feature was silently offline for
hours, twice, and nothing alerted.

Loading themselves is what makes that impossible: no deploy of
`pps-calculators.php` can unload a plugin WordPress activates directly.

- **Do not add `require_once` lines for them back into `pps-calculators.php`.**
  Both files guard with `PPS_PAYLINK_LOADED` / `PPS_QBO_LOADED` and would
  return early rather than fatal, but the guard is a safety net, not a design.
- **If either stops working, check `active_plugins` first.** A missing entry is
  the failure; the fix is re-adding it, not re-adding a require.
- **Anything that must survive an unrelated deploy belongs in one of these two
  files**, not in `pps-job-quote.php` or `pps-calculators.php`. The receipt's
  shipping-address filter lives in `pps-pay-link.php` for exactly this reason.

### Pay links tell you when they are broken — ask them first

`GET /wp-json/pps/v1/pay-link` is the one-call diagnosis, unauthenticated on
purpose:

| Response | Meaning |
|---|---|
| `200 {"ok":true,"status":"ok"}` | Loading and able to mint. |
| `503 {"ok":false,"status":"degraded"}` | Loading, but something it needs is missing. |
| `404 rest_no_route` | `pps-pay-link.php` is not loading at all — check `active_plugins`. |

`pps_paylink_health()` holds the checks (both `active_plugins` entries,
`pps_quote_create()`, `pps_is_business_day()`, the line product and its
`_virtual` flag, the shared secret). The endpoint reports *that* it is degraded
without saying *what* is wrong; the detail goes to `admin_email` and the
outcome log. The hourly `pps_paylink_healthcheck` cron mails **once per distinct
failure**, not once per hour — if you add a check, keep that property.

The gap it cannot close is its own absence: if the file stops loading, nothing
in it runs, which is exactly what happened twice. Only an outside poller
watching for any non-200 covers both shapes.

### Running more than one session at a time

- **One deploy lane.** Feature branches are fine; deploying from them is not. Merge into
  the integration branch and deploy *that* commit, so production only ever receives bytes
  from one lineage. Two branches deploying the same file is the whole problem.
- **Split by file, not by feature.** Two sessions editing `pps-calculators.php` for
  unrelated reasons will collide no matter how careful each is.
- **Merge early.** A branch open all day drifts far enough that a merge stops being
  routine. Land it while the diff is small.
- **Say so in the handoff.** A session that deploys a shared file should record what it
  deployed and from which SHA, so the next one can run the ancestry check without
  archaeology.
- **A feature that reaches production before it is merged is on loan.** Until the change
  lives on the shared branch, every unrelated deploy of those files can take it away —
  and the symptom will not look like a deploy problem. Prefer merging over deploying from
  a branch; when that is not possible, treat the merge as unfinished work, not paperwork.

### Applies equally to

Anything edited outside version control and relied upon: `wp_options` values that
carry behaviour (`pps_calc_config`, the registry, the tooltips table,
`pps_uploads_retention` — the automatic uploads-cleanup policy, documented in
`docs/GO_LIVE_RUNBOOK.md` §D), one-shot
`_pps_*` helper files, and files added to `active_plugins` by hand. If it changes what
production does and it is not in the repo, it is one deploy from being undone.

### This rule is addressed to Claude, not the operator

The owner has no local terminal and does not SSH into the server. Every un-versioned
file that has ever appeared in `wp-content/plugins/pps-calculators/` was written by a
Claude session through the MCP write tools. When a future session finds one, the
question is not "who did this" — it is "which of my predecessors, and is the change
still needed". Audit found on 2026-08-01: `_pps_force_deploy.php`,
`_pps_preset_slug_fix.php`, `pps-coupon-test.php`, `pps-calculators.php.prehardening.bak`
and `pps-intake.php` — all agent-authored, none in git.

The practice that prevents it:

- **Do not write a PHP file to the server that does not exist in the repo.** Commit it
  on the working branch first, then deploy it with `pps_plugin_download_url` against a
  raw URL pinned to that commit. The deployed bytes are then reviewable and the rollback
  is the same call with an older SHA.
- **A file named to look temporary is not exempt** — `_pps_*` and "self-deletes after…"
  are what the last four looked like, and none of them ever deleted itself, because
  nothing loaded them. Naming a file disposable does not dispose of it.
- **For diagnostics, read; don't drop.** `pps_plugin_read_file`, `wp_get_option` and the
  calculator's own debug panel answer nearly everything a scratch `_pps_diag.php` would,
  and leave nothing behind for a malware scanner to flag as a backdoor.
- **A `.bak` is a patch you are about to lose.** Repo history already holds every prior
  version; a backup beside a live file only records that someone edited in place.

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

## Update pipeline (read this before changing anything on a live site)

**`docs/UPDATE_PIPELINE.md` is the operating model post-go-live.** The short
version, because it overturns the assumption the go-live left behind:

> Git is the source of truth for code. **Production** is the source of truth
> for content, config, orders and money. **Staging is a disposable test bed
> that refreshes downward from production.**

**The Cloudways staging→production push is retired for routine work.** It
replaces production's database wholesale and silently destroys every order
placed since the last pull (Gate 3, `docs/GO_LIVE_RUNBOOK.md`). It is a
rebuild tool, not a deploy tool.

Four lanes, four mechanisms:

- **Code** (plugin PHP, calculators) → git, deployed pull-based by pinned SHA
  to staging, verified, then the same SHA to production.
- **Content** (pages, term descriptions, product copy) → authored directly on
  production. Reversible, no build step, cannot break the cart.
- **DB config** (`pps_presets`, `pps_tooltips`, `pps_faqs`) → authored on
  production, or promoted **one option at a time** with
  `wp_get_option` → `wp_update_option`. **Never bulk-copy `pps_calc_config`
  between sites — it carries live credentials.**
- **Plugin/core updates** → staging first, then production. This is what
  staging is actually for.

Sequencing across lanes: **code before the content that depends on it**
(an unregistered shortcode renders as literal text on your homepage).

## Branch & Deploy

- **Pages source branch:** `pps-pricing-config` — GitHub Pages serves directly from the root of this branch. All calculator changes must be pushed here. No separate deploy step.
- **`.nojekyll` is MANDATORY** on `pps-pricing-config`. Without it, Pages runs Jekyll, which silently breaks the build because the inline JSX/Babel inside the calculator HTML contains `{{ }}` that Jekyll tries to parse as Liquid templates. Symptom: your pushes never appear on the preview URL even though the file on GitHub looks correct. **Never delete `.nojekyll`.**
- **Never write a literal `</script>` inside the `<script type="text/babel">` block** — not even inside a JS string, template literal, or JSX prop. The HTML parser scans script content byte-by-byte and closes the outer block at the first `</script>` it sees, regardless of JS quoting. Symptom is identical to the Jekyll one (build stamp updates but the page renders as a wall of source text), so it gets misdiagnosed. The canonical fix is escape it as `<\/script>` — JavaScript treats `\/` as `/` at runtime so any HTML you generate still serializes cleanly, but the HTML parser's close-tag matcher misses the backslashed form. This bit me on 2026-05-08 in the `buildPreviewHtml` template literal; if you embed HTML strings that contain `</script></body></html>` (e.g. self-contained downloadable HTML), always backslash the slash. Same rule applies for any future HTML-builder helper.
- **Calculators publish COMPILED (2026-08-10).** `node tools-compile-calcs.mjs`
  transpiles each calculator's inline JSX (`@babel/preset-react`) into `dist/`
  (gitignored) and strips the Babel Standalone include. **Publish branches and
  staging `_pending_html` deploys carry the `dist/` output** under the same
  filenames; the integration branch keeps JSX source. QA measured the
  in-browser transpile at ~5–6s of main-thread blocking per page load; compiled
  pages go interactive in ~0.2–0.4s (93–96% faster, pricing parity verified).
  Never edit a compiled file (its script block opens with a DO-NOT-EDIT
  marker) — edit the source and rebuild. The `</script>`-inside-Babel rule
  below still applies to SOURCE, and the tool refuses to emit any output that
  contains a literal close tag.
- Do NOT push to `website` — it's unrelated to the preview.
- **Preview URLs** (served by GitHub Pages from `pps-pricing-config`):
  - https://pdevvle.github.io/priorityprintservice.com/calc-preview-test.html (saddle stitch)
  - https://pdevvle.github.io/priorityprintservice.com/calc-perfect-bound.html (perfect bound)
  - https://pdevvle.github.io/priorityprintservice.com/calc-brochure.html (brochure)
  - https://pdevvle.github.io/priorityprintservice.com/calc-coupon-book.html (coupon book)
- Each calculator has a build-stamp chip in the bottom-right corner. After a push, wait ~60 seconds for Pages to rebuild, then hard-refresh (Cmd/Ctrl+Shift+R) or use an Incognito window. If the chip still doesn't update, verify `.nojekyll` exists on `pps-pricing-config` root — that's the #1 cause of "my push didn't show up."
- **Publish surface:** the `pages-public` branch is what Pages should serve — an orphan branch carrying only the nine calculators, `pps-theme/preview.html` and `.nojekyll`. Pages serves the *entire tree* of whatever branch it publishes from, so publishing `pps-pricing-config` also served every `.php` file as plain text (Pages doesn't execute PHP), plus `docs/MASTER_PRICING_LOGIC.md` and `CLAUDE.md`. Calculator changes get cherry-picked to `pages-public`; nothing else goes on it.
- **Go private (deferred by owner 2026-08-01 until the build is finished).** When ready:
  1. Confirm the GitHub plan allows Pages from a private repo, or the preview URLs go dark.
  2. Flip repo visibility. This is the action that matters — while the repo is public, everything is readable on github.com regardless of what Pages serves, **including full history**, so deleting a file from HEAD does not unpublish it.
  3. Only then is the older dummy-swap dance unnecessary. Legacy restore path, if used: `git checkout pps-real-backup -- <files>`
  - Until then, treat every branch as public: don't commit pricing figures, strategy, or credentials anywhere in the repo.

## Retired branches

- `gh-pages` (last commit 2026-04-17) — never the live Pages source despite an earlier session's mistaken claim. Archived as `OLD/gh-pages`. Safe to delete locally: `git push origin --delete gh-pages`. Contained an orphan `pb-v2.html` cache-verification duplicate of the perfect-bound calculator with the pre-fix cover-print formula.
