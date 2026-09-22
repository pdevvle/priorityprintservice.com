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
| `pps-job-health.php` | **The print file measured on the order, and the exceptions that announce themselves.** At line-item creation (priority 20, after the Job Ticket) it opens the print-ready PDF from the approval package — or the raw PDF when the booklet raw path shipped it — and writes "Print File Check": kind (raster / vector / mixed), pages, sheet size and, for the calculators' own raster files, pixels ÷ inches = effective DPI; under 280 gets `_pps_print_check_warn` and an order note from the order-processed hooks. It cannot see an image that has the right pixel count but was scaled up (87171 measures 300 by count); the two print-DPI test suites cover that before a build ships. Daily cron `pps_job_health_digest` mails the office (via `pps_reorder_contact_recipient()`, never `orders@`) every open job that needs a person: prepress review, low-res print file, staff proof awaiting, hardcopy proof with no usable address, delivery on a closed day, checkout refusals since the last digest. Sends only when non-empty; the last digest is in `wp_options['pps_job_health_last']`. `tools-job-health-test.php` is the gate. |
| `pps-reorder.php` | Guest order lookup (`[pps_order_lookup]` shortcode) and single-item reorder for legacy/WCPA orders. Loaded by `pps-calculators.php`. |
| `pps-term-shortcodes.php` | Everything that makes a `product_cat` archive and `/shop/` look the way they do: category URL routing/redirects, the modern card CSS, the shop masthead, the guided `[pps_cat_wizard]`, the attribute shortcodes and the preset lineup. See "Category page composition" below. |
| `docs/MASTER_PRICING_LOGIC.md` | Single source of truth for pricing strategy, applied values, rollback notes, knob-tuning patterns. **Read before suggesting any formula change.** |
| `docs/PRICING_MATRIX.md` + `docs/pricing-matrix.json` | Captured output: what all 8 calculators actually quote across size, paper and page count (1,816 points), read from the rendered UI rather than the constants. Reference *and* regression gate — re-run before/after any pricing or styling port and diff. Regenerate with `tools-pricing-matrix.mjs`. |
| `ups-zone-map-seed.json` | UPS Ground transit days by 3-digit ZIP prefix (1000 entries) |
| `docs/GO_LIVE_RUNBOOK.md` | The 3.0 go-live: staging de-bloat (Phase 0), selective order-table pull live→staging, freeze-window sequence, auto-increment fix, staging→production push, verification. HPOS confirmed on live. **Read before any go-live or cross-site DB work.** |
| `docs/PPS_3.1_WC11_PLAN.md` | **The release after go-live**: WooCommerce 11 + Action Scheduler 4.0 update for both sites, compatibility test matrix (Drive/AS artwork pipeline is the top risk), default-on feature postures (POS, abandoned-cart stays OFF), hardening riders. Binding rule it carries: **version freeze — no WC/WP/plugin updates on either site during the go-live window**; WC 11 lands in 3.1, both sites together. |
| `docs/PROOFER_BRIEF.md` | **Start here for anything proofing.** Handover brief: file inventory, running the eleven suites (ten live), the old modal's origin/vulnerabilities/per-product usage, the new proofer's design decisions and handshake, modal→proofer parity checklist, the saddle→all-products adaptation plan, invariants, and case studies (incl. order 87152's unscannable QR). |
| `proof-ui-draft.html` | **The new proof surface.** Standalone document, embeddable by a host — see "Proofing" below. Vanilla JS, its own pdf.js/pdf-lib, eleven test suites (ten live — see `docs/PROOFER_BRIEF.md` §1.2). Not a component: the calculator frames it. |
| `pps-html-deploy.php` | How calculators actually reach production. Also owns retention (v1.5.0): after each deploy it prunes superseded extracted scripts and trims the deploy archive. Accepts `calc-*.html` plus `proof-ui-draft.html` — an explicit list, because this directory is writable by a deploy tool.  Watches `wp-content/plugins/pps-calculators/_pending_html/`; the next WP request copies `*.html` into `wp-content/uploads/pps-calculators/`, updates the registry, archives the source under `_pending_html/_archive/`, and logs to `wp_options['pps_html_deploy_log_v2']`. Also hosts the Bulk Upload admin page (`admin.php?page=pps-bulk-upload`). |
| `pps-proof-status.php` | Makes `SelfApproved` mean someone signed off in the proofer, rather than "did not buy a staff proof". Rewrites only that token in PPS-Spec, adds a `PPS-Proof` item meta, notes the order when artwork arrived unapproved. **On staging, NOT in `active_plugins` on either site** — until it is activated, every order still reads `SelfApproved`. |
| `pps-delivery-date-guard.php` | Floors `_pps_delivery_date` to a working day and keeps pi-edd off registry line items. **Deployed and active on both sites 2026-09-15** (pinned to `e0bc851`, 12,311 bytes, added to `active_plugins` immediately after `pps-calculators.php`). Reviewed 2026-09-15: its two pi-edd filter names were verified against the installed plugin and are real, but **the order note was being written from `woocommerce_checkout_create_order_line_item`, where the order has no ID yet** — `add_order_note()` returns 0 without writing, so the one mechanism meant to stop a silent correction was itself silent. The note now waits for `woocommerce_checkout_order_processed` / the Store API twin. Also corrected: pi-edd already switches itself off for virtual products, so it was never the source of the Sunday on order 87105 — that came from our side. `tools-delivery-date-guard-test.php` is the gate (32 checks). |
| `tools-proof-slots-test.mjs`, `tools-prepress-flag-test.mjs` | The two proofer blockers closed on 2026-09-13. The first drives the proofer over its real job message with `slots` and proves per-page uploads land on their pages, beat the whole-file art where they overlap, and survive an out-of-range page. The second reads `pps-calculators.php` for the whole server chain (cart data, session key, item meta, spec token, order note, proof-hash drop) and runs the shipped submit function of all eight compiled calculators to prove the flag is posted when — and only when — the escape hatch was taken. |
| `tools-order-e2e-test.mjs` | The whole order against a fake WordPress: a real PDF through the real uploader and proof modal on the compiled saddle AND perfect-bound builds, then Add to Order against a mocked admin-ajax (bare `-1` for a wrong nonce, JSON otherwise) and a cart page. Proves the nonce retry and the package contents (raw first, no `.html`, previews + manifest listed) where the customer actually meets them. Needs `parity-saddle.html` and `parity-pb.html` in the harness dir. |
| `tools-nonce-strategy-test.mjs`, `tools-order-gates-test.mjs` | Ordering-path gates (2026-09-12 audit). The first runs the SHIPPED `submitToWooCommerce` out of every compiled calculator under a fake browser: baked nonce first, admin-ajax refresh on `-1`, REST fallback, reload message, WAF 403 named as a block, preset slug posted, reference files as supplementary deliverables, a refused supplementary never aborting (the perfect-bound `.html` bug). The second drives the saddle harness: "Upload Art with Order" with no file is stopped, and the mobile bar shows the pricing error instead of a dead button. Run both after touching any calculator's submit path or Panel. |
| `tools-proof-serve.mjs` | Harness for the proofer's suites. They cannot run over `file://` (pdf.js needs a real origin), so this serves the tree on 127.0.0.1:8137 and the pinned libraries under `/vendor/`. Populate `proof-vendor/` with `tools-proof-vendor.mjs` first. |
| `tools-proof-vendor.mjs` | One command to install the proofer's pinned pdf.js/pdf-lib into `proof-vendor/` and restore the calculators' pdf.js afterwards. The two majors cannot share one `node_modules`. |
| `tools-delivery-date-guard-test.php` | Drives `pps-delivery-date-guard.php` against stubs: a Sunday moves to the Monday and the human-readable twin moves with it, a good date is untouched, `2026-13-45` is refused rather than rolled over, a shop that is never open leaves the date alone rather than replacing it with one a year out, and the order note lands exactly once across both checkout hooks. Also pins the two pi-edd filter NAMES and arg counts — a filter that does not exist fails silently and would leave that whole section looking like it worked. |
| `tools-order-blockers-test.mjs` | The two guards from the 2026-09-15 checkout blocker: the registry (not the database) decides whether WCPA owns a product, and a refused checkout on a calculator cart is recorded to `pps_checkout_refusals` instead of costing a silent order. Also pins the things that stop the tripwire becoming the failure it watches for — wrapped, bounded, autoload-off, scoped to PPS carts. |
| `tools-closure-engine-test.mjs` | **The shop closes, and every calculator has to know it.** Static half pins the config path (`_CFG.closures`, never the `pcf` sub-array) and that no bare weekend test survives in the counting loops; behavioural half extracts `isBusinessDay`/`addBusinessDays`/`businessDaysBetween` out of each COMPILED build and runs them against a real holiday. Confirmed to discriminate — against the pre-fix brochure, `addBusinessDays(Wed 25 Nov, 1)` returns Thanksgiving. 72 checks. |
| `tools-flat-print-dpi-test.mjs` | **The print file a flat ships must be the upload at print resolution, not the screen preview blown up.** Drives each compiled flat build like a customer with a two-page PDF carrying a QR code at order 87171's module size, pulls the print-ready PDF out of the mocked upload, and checks: bleed sheet at 300 DPI, QR decodes on every side, modules crisp (mid-grey share under 5% — the old build measured 36%, a half-pixel placement 20%), manifest names the print source. Needs `flat-<calc>.html` harness copies on :8137; `PPS_FLAT_PAGES` selects builds, which is how it was run against the pre-fix brochure to prove it fails there. |
| `tools-book-print-dpi-test.mjs` | The same measurement on the three booklets, because "they render at `DPI / 72`" was a line of code, not a file. An 8-page 6″ × 9″ PDF (misses the raw path by 0.25″, as order 87152 did) with a QR at the customer's module size on every page, through saddle, perfect bound and coupon; every page of the uploaded print-ready file must be the bleed sheet at 300 DPI, decode, and be crisp. First run, 2026-09-21: all three resampled every page through a half-pixel offset (20% mid-grey), and perfect bound + coupon printed the reconciled back cover from its 108 DPI thumbnail (50%) because they picked the PDF page by display index. Both fixed the same day. |
| `tools-job-health-test.php` | Drives `pps-job-health.php` against stubs: a hand-built PDF with 300 DPI page images passes, one with 144 DPI page images (87171's shape) is flagged, a vector PDF is named as such, a non-PDF is refused; the line-item hook writes the check onto the order and the end of the Job Ticket, the raw PDF is measured when there is no print-ready file, image uploads are left alone, a file already moved to Drive reads "not checked"; the note lands exactly once across both order-processed hooks; the digest groups all six exception kinds, ignores non-calculator lines, strips tags, mails only the office and only when non-empty, and a first run does not replay the whole refusal log. |
| `tools-job-ticket-test.mjs` | **The Job Ticket: one block, first on the order, that production runs the job from.** Runs `pps_job_ticket()` under php (calculator pairs first and in order, then art status, files, production start, must-ship, delivery with rush flag, ship-to on one line, shipment estimate; a legacy order still gets a block from its summary; prepress review reads NOT APPROVED) and runs `buildTicket()` out of every compiled build on sample configs (booklets name binding, inside and cover; flats name paper, print and fold; all name finishing, proof type and, for a hardcopy proof, where it goes). |
| `tools-addons-meta-test.mjs` | **Add-ons reach the order as words, on their own line, from every calculator.** Extracts `pps_order_addons()` from the plugin and runs it under php (calculator list used verbatim; a legacy summary keeps its finishing lines and drops paper/colour/rush/ship-to), extracts `buildAddons` plus the option tables it reads from every COMPILED build and checks sample configs against the expected labels, and pins that perfect bound and coupon book now put outfold / perforation / magnetic backer in the summary. Until 2026-09-21 the spec builder skipped every summary line with a colon in it, which silently dropped every flat's "Coating: …", "Perforation: …", "Bundling: …" and "Round Corner: …" from PPS-Spec, and those two booklets never wrote outfold or perforation anywhere a person reads. |
| `tools-slot-upload-test.mjs` | Building a booklet a page at a time, on the compiled saddle AND coupon-book builds: three single-page PDFs land on three different slots and accumulate, a multi-page PDF on a slot still replaces the whole book. `PPS_CALC_PAGE` points it at another build — how the pre-fix one was run to confirm it fails there (it does, 5 checks). |
| `tools-proof-size-check-test.mjs` | The "Built to the ordered size" preflight across six shapes. It warned on every correctly-bled file, because a print file with bleed is trim + 2 × bleed and most tools write no TrimBox — and the check's own no-TrimBox branch could never fire, since pdf-lib answers `getTrimBox()` with the MediaBox when there is none. A check that warns on good files is worse than no check: it feeds the acknowledgment gate, so it teaches people to tick past it. The allowance is only where the page stands in for a missing TrimBox; a declared TrimBox still has to match exactly. |
| `tools-proof-progress-test.mjs`, `tools-proof-blank-pages-test.mjs` | The two staging findings of 2026-09-13. The first records every value the approve readout ever holds via MutationObserver, so a progress bar that is updated but never *painted* fails exactly as a missing one would; it also pins that a failure names the step it stopped at. The second pins that an unsupplied page on a hosted job is blank, flagged and in the manifest — and that standalone still draws the demo booklet. |
| `tools-proof-ui-draft-test.mjs`, `-preflight-`, `-mobile-`, `-style-`, `tools-proof-embed-test.mjs`, `tools-proof-integration-test.mjs` | The proofer's original six suites: engine, PDF preflight, touch layout, **design parity**, the host seam, and the calculator round trip. Five more were added 2026-09-13 (slots, progress, blank pages, size check, prepress flag); `docs/PROOFER_BRIEF.md` §1.2 lists all eleven with the run recipe. Run every live suite after any proofer change. The style suite reads the palette out of `calc-preview-test.html` rather than copying it, so the two documents cannot drift apart, and it fails any rule whose `var()` does not resolve — which is how a whole panel once rendered unstyled without anyone noticing. |
| `pps-theme/` | Custom WordPress theme replacing Astra Pro — owns site chrome, typography, color tokens, WooCommerce shell. Stays out of the calculator plugin's way. `pps-theme/preview.html` is a Pages-served standalone preview of the header. |
| `designer/` | **Spike.** Print-first layout editor (Vite + React + TS) — the document *is* a product; press-PDF export with CMYK, bleed/trim boxes and subset font embedding. Unlike the calculators this is a real build, not a single inline-Babel HTML. `dist/` is committed for Pages. **Read `docs/DESIGNER_SPIKE.md` before touching it** — it records what's proven vs faked and the next steps in order. Run `cd designer && npm test` after any change to `src/export/pdf.ts`. |

## Per-page slot uploads

"Upload pages individually" is a grid of page slots; a file dropped on one is
that page's artwork, and the original File is kept in `slotFilesRef` so full
quality rides to the order and to the proofer as `slots`.

**A single-page PDF on a slot belongs to that slot.** Until 2026-09-13
`handleSlotFile()` sent *any* PDF to `processFiles()`, which replaces the whole
book — so the second single-page PDF wiped the first, only one ever stuck, and
`slotFilesRef` was never written, which meant the proof got art for one page and
nothing for the rest. Single-page PDFs are what design tools export, so this was
the common case, not an edge one. A genuinely multi-page PDF still replaces the
book; that is a different intent.

Affected saddle and coupon-book, which shared the code. Perfect bound already
took page 1 only; the five flats have front/back sheet slots and were always
right. `tools-slot-upload-test.mjs` is the gate, and it runs against both the
saddle and coupon builds.

## Shop closures — the calculators are copies, not modules

Closures live at the **top level** of the injected config, beside `pcf`, never inside
it: `pps_get_closures()` reads `$cfg['closures']` and `pps_get_public_config()` treats
`$cfg['pcf']` as its own sub-array. The calculators read `_CFG.closures`, where
`_CFG = PPS_CONFIG.calc`.

Until 2026-09-19 the five flats read `(_CFG.pcf || {}).closures`, which is `undefined`,
and fell back to `[]`. **`SHOP_CLOSURES` was empty on brochure, postcard, greeting card,
letterhead and sticker for the whole life of those files**, which silently disabled the
two places that did handle closures correctly — the DatePicker's greying-out and the
last-line guard in `quotedDeliveryYMD`. Independently, `getShopToday`,
`addBusinessDays` and `businessDaysBetween` inlined a bare weekend test instead of
calling `isBusinessDay`, so even a populated list would have been ignored by the
arithmetic. Either bug alone would have hidden the other.

Christmas Day was a selectable, quotable, orderable delivery date on those five.
Turnaround ran short by a day per holiday in the window, rush was **undercharged** (a
fatter denominator in `freeDeliveryBizDays / bizDaysToDate`), and `tooSoon` failed to
trip — so the calculator accepted deadlines the shop cannot meet.

**The general lesson, which is the reason this section exists:** these eight files are
copies, so a fix lands in one and not the others and nothing complains. It was found by
extracting every named function from all eight, normalising whitespace and hashing —
38 functions are shared by 6+ files and 19 had drifted. That sweep is worth re-running
after any change to shared machinery. `tools-closure-engine-test.mjs` is the gate.

## The flats printed the screen preview

Brochure, postcard, letterhead, greeting card and sticker rendered an uploaded PDF once,
at upload, with pdf.js at `scale: 2` — 144 DPI, JPEG q0.85 — for the on-screen proof, and
`generateApprovalPackage()` then decoded that JPEG and drew it up onto its 300 DPI canvas,
on a fractional offset that resampled the sheet a second time. The manifest said "300 DPI"
because a constant said 300. Perfect bound and coupon book, which the flats' generator was
copied from, render the page at `DPI / 72` and never had this. It ran from 2026-04-19 until
2026-09-21, when a customer's QR code (order 87171, 0.013″ modules — under 2 px at 144 DPI)
would not scan off the printed brochure.

Now `ppsPrintSourceCanvas()` renders each PDF side again from its own bytes at the print
DPI inside the generator, places it on whole pixels, and the manifest names the source per
side (`print source: pdf page 1 rendered at 300 DPI`). The five flats still have no raw
(vector) path — every flat order ships a raster print file, now genuinely at 300 DPI.
`tools-flat-print-dpi-test.mjs` is the gate; it measures the print file, not the constant.
Same lesson as the closures section above: the flats are copies, and a copy can drop the
one line that matters. See `docs/PROOFER_BRIEF.md` §9.5.

**The booklets were then measured the same way rather than trusted**
(`tools-book-print-dpi-test.mjs`), and two more things fell out on the same day. All three
placed the 300 DPI source at `(canvasW - drawW) / 2`, a fractional offset, so Canvas
resampled every pixel of every rasterised page (20% mid-grey over a QR that should have
none; 0% once placed on whole pixels — the crop-at-100% case is now a 1:1 copy). And
perfect bound + coupon book chose the PDF page to re-render as `i + 1`, the display index:
reconciliation moves the back cover to the last book page, so it pointed past the PDF and
the back cover was printed from its 108 DPI thumbnail (50% mid-grey), and a slot upload or
a shifted page would have printed the WRONG page. They now carry `srcPdfPage` from
extraction and render by it, the fix the saddle took on 2026-08-24 and the copies never
received. Rule that follows from all of it: **a resolution or DPI claim anywhere in this
repo is unverified until a test has decoded the shipped file.**

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

## Proofing — two surfaces, one of them dark

> **`docs/PROOFER_BRIEF.md` is the handover document for all proofing work.** It carries
> the full picture: file inventory, how to run the eleven suites (ten live), the old modal's origin and
> vulnerabilities, the new proofer's design decisions, a modal→proofer feature-parity
> checklist, the plan for adapting it from saddle to all eight products, the invariants,
> and the case studies. Read it before touching `proof-ui-draft.html`, any proof modal,
> or anything named `tools-proof-*`. The section below is the summary; the brief is the
> detail.

There are currently **two** proof UIs, and which one a customer sees is a config
value, not a code path you can read off the file.

- **The built-in modal** in each calculator. This is what every site uses today.
- **`proof-ui-draft.html`**, the reorganised surface. Wired to the saddle
  calculator, off unless **PPS Config → Production → New Proof URL**
  (`PCF.proof_url`) is set to its same-origin uploads URL. Blank is off.

The switch is an admin knob rather than a constant because production's
`pps-calculators.php` is far enough ahead of git that adding a `PPS_CONFIG` key
there would mean surgery on a file that cannot be safely redeployed.

### Why it is framed rather than ported

`ppsOpenProof()` opens it in a same-origin iframe. Three reasons, in the order
they cost you:

1. Approval has a failure that was never root-caused — on 2026-08-12 a real
   multi-page PDF with an orientation mismatch took the whole calculator down.
   A frame boundary makes the worst case a broken proof, not a customer on a
   blank product page mid-order.
2. The calculators load pdf.js 4.10.38 as ESM; the proofer pins 3.11.174 UMD.
   Both claim `window.pdfjsLib`. In one document, one of them loses.
3. The proofer's suites keep testing the artifact that ships, not a port of it.

### The handshake

Same-origin only — these messages carry the customer's artwork and the hash
their approval is bound to. In: `window.PPS_PROOF_JOB` before load, or a posted
`pps-proof:job` after it. Out: `ready`, `error`, `approved`, `approve-failed`,
`escape`, `close`. The host owns everything after approval — upload, order
metadata, cart. The proofer never uploads anything itself.

`ppsProofFilesToOrder()` is the only place the proofer's human file names become
the pipeline's (`PRINT_READY.pdf` → `<base>_print-ready.pdf`, and so on). **The
manifest is the approval marker, not the print-ready PDF** — art needing no
transforms legitimately produces no PDF.

### Not finished — do not enable the knob until these are closed

- ~~The approval checkpoint is missing.~~ **Closed 2026-09-08.** Approve now
  requires a second, explicit acknowledgment whenever anything is flagged, and
  the responsibility sentence sits with the button — it appears nowhere else on
  the page, so do not remove it on small screens. The gate reads `jobFlags()`,
  which scans **every page plus the file-level preflight**, not the page on
  screen: a clean cover in front of you says nothing about page 5. The
  acknowledgment is recorded in the manifest.
- ~~The escape hatch tells nobody.~~ **Closed 2026-09-13.** The calculator posts
  `pps_prepress_review`; PHP stores it as staff-visible `PPS-Prepress-Review`
  item meta (internal-only, off the customer's copy), turns the spec's proof
  token into `PREPRESS-REVIEW` instead of `SelfApproved`, raises an order note
  saying the artwork is NOT approved, and **drops any `pps_proof_hash`** so an
  unapproved job cannot look approved to the imposition tool.
  `tools-prepress-flag-test.mjs` is the gate.
- ~~Per-slot uploads never reach it.~~ **Closed 2026-09-13.** The job message
  carries `slots: [{ page, file }]` alongside `files`; the proofer applies them
  after the whole-file sequence (so a per-page choice wins where both cover a
  page) and ignores a page outside the book. `loadArt`/`loadArtSequence` are now
  genuinely sequential — they used to fire and forget, which is why a second
  batch could not be layered on the first. The slot files also ride to the order
  named `page_NNN_<name>`. `tools-proof-slots-test.mjs` is the gate.
- ~~Unsupplied pages were filled with the demo booklet.~~ **Closed 2026-09-13**
  (found on staging). `SRCget()` drew the prototype's fake Capoeira programme on
  any page with no upload — and `buildPackage()` renders every page into
  PRINT_READY.pdf, so the approval hash bound to it and the press would have
  printed it. `applyJob()` now sets `MODEL.hosted`, and a hosted job gets a white
  page plus one error-level `noart` finding per empty page (which trips the
  acknowledgment gate) and a BLANK PAGES section in the manifest. Standalone
  keeps the placeholder — without it there is nothing to look at.
  `tools-proof-blank-pages-test.mjs` is the gate.
- ~~Approve went silent for tens of seconds.~~ **Closed 2026-09-13.** It renders
  every page at 300 DPI, encodes a JPEG each, assembles a PDF and then does the
  previews, nearly all of it blocking the main thread; the button said
  PREPARING… and the page stopped responding, which reads as a crash. There is
  now a determinate bar, the stage in words, and the percentage on the button —
  and, the half that is easy to forget, **a yielded frame before each long block
  so the readout actually paints**. A failure names the step it stopped at, in
  the message and in `pps-proof:approve-failed`.
  `tools-proof-progress-test.mjs` is the gate.
- ~~The ordered-size check warned on every correct file.~~ **Closed 2026-09-13.**
  A print file with bleed is trim + 2 × bleed and usually carries no TrimBox, so
  measuring the page against the trim alone called every good file wrong — and
  this check feeds the acknowledgment gate, so it was training people to tick
  past the one gate between artwork and a press. The no-TrimBox branch it
  already had could never run: pdf-lib answers `getTrimBox()` with the MediaBox
  when the file has none, so a declared trim has to be read as one that DIFFERS
  from the page. `tools-proof-size-check-test.mjs` is the gate.
- **A no-bleed file raises nothing in the PROOFER.** The calculator catches it
  ("Artwork has content at edges but no bleed area") and that is where the
  customer is told. Inside the proofer the default crop behaviour scales the art
  to fill the bleed, so the geometric bleed check cannot fire — it only catches
  art that is letterboxed. What the customer sees instead is the consequence:
  type warnings near the trim. Worth closing with the "I don't have bleeds"
  answer below rather than another geometric check.
- **Only the saddle calculator is wired.** The other seven still use the modal.
- **The knob exists on staging, not on production.** Staging runs the
  `pps-config-admin.php` that added `proof_url` (118,443 bytes, deployed
  2026-09-13), so **PPS Config → Production → New Proof URL** is live there,
  blank. Production runs the commit before it (117,942 bytes, 2026-09-06) and
  has no field. Turning the proofer on in production means deploying that file
  first, then setting the field by hand — it is not something to write into
  `pps_calc_config` from here, because that option carries live credentials.
  Do not set it anywhere until the brief's §7.0, §7.4 and §7.6 are closed.
- The "I don't have bleeds" answer reaches neither surface; both detect bleed
  from the art and ignore the selection.

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
- **Print File Check + exceptions digest (2026-09-22).** `pps-job-health.php`, see the file table. The check is the last line of the Job Ticket; the digest is the daily email to the office. Both exist because every defect this month was reported by a customer first.
- **Job Ticket (2026-09-22).** The first visible item meta on every order, on the admin notification, the order screen and the customer's receipt alike: every choice as words, then what only the server knows. The calculator posts `ticket` — ordered `[label, value]` pairs from `buildTicket(config, result)`, resolved from its own option tables (product, job name, quantity or sets, size with the custom inches, paper, print, binding, inside, cover, finishing, artwork option, bleed answer, proof type, proof ship-to) — and `pps_job_ticket()` appends art status (NOT APPROVED for a prepress-review order; awaiting staff proof; self-approved, hash-bound), the uploaded file names, production start, must-ship, delivery with the rush flag, the ship-to on one line and the shipment estimate. Words come from the calculator, never guessed from numbers on the server, which is how a self-cover job once read "cardstock" (87202). A legacy build with no `ticket` gets a block from its summary. Same commit: the three booklets now carry `proofAddrSame`/`proofAddr` in the order (the booklet-only gap behind order 87198). `tools-job-ticket-test.mjs` is the gate. Order Summary and PPS-Spec stay as they were; Missive keeps parsing PPS-Spec.
- **Add-ons (2026-09-21).** Every calculator posts `addons` in the metadata — each finishing choice as the words the customer chose ("Coating: UV Gloss (both sides)", "Perforation: 1 Perforation Line", "Outfold: …", "Magnetic Backer: …", "Enhanced Vivid Print") from `buildAddons(config)`. `pps_order_addons()` writes them as a visible **Add-ons** item meta (customer receipt, admin notification, order screen) and as parts of PPS-Spec. It used to read them out of the summary text and skip every line with a colon — meant for Inside:/Cover:/Rush:/Ship to:, it also ate the flats' "Coating: …" lines, so a coating lived only as `coating: 750` in the JSON blob. `tools-addons-meta-test.mjs` is the gate. The flats' proof modal also has **"Render at full resolution (slow to load)"**: ticked, each PDF side is rendered again at 300 DPI with a status bar and replaces the 144 DPI upload preview in the viewport and under the loupe (`tools-flat-print-dpi-test.mjs` covers it).
- Edit mode: atomic add-before-remove for cart item updates; a plain product-page visit clears a stale `pps_edit_key_<pid>` so an abandoned edit cannot delete the next ordinary add
- **Nonces (2026-09-12 ordering audit).** The page-baked nonce is tried FIRST; only a bare `-1` triggers a refresh, and the refresh asks `admin-ajax.php?action=pps_nonces` (honours the login cookie) before falling back to `GET /wp-json/pps/v1/nonces`. Never mint refresh nonces from REST for a logged-in visitor: a REST request without `X-WP-Nonce` runs as user 0, and every checkout creates an account and signs the customer in for 14 days, so the old pre-submit refresh broke uploads and add-to-cart for every returning customer. `tools-nonce-strategy-test.mjs` pins this against the compiled files.
- **Price floor** in `pps_ajax_add_to_cart` (and the standalone `pps-cart-price-floor.php`) is `min(absolute, regular_price × pct)`, as its comment always said; `max()` refused real qty-25 flat orders once regular_price began carrying the qty-10 quote. The materials floor is the one that scales with the job.
- **Preset URLs** inject `productId` (first published registry product for the calc type, or the preset row's `product_id`); the handler also resolves `pps_preset_slug` server-side. Without it every preset add-to-cart said "Invalid product or price."
- Add-to-cart refusals return the WooCommerce notice text ("Could not add to cart: …"); upload refusals name the PHP upload error instead of "No file received."
- `rocket_exclude_js` keeps WP Rocket's minifier off the extracted app script (its quote pairing loses sync on nested template literals); `pps-html-deploy.php` purges the page cache after every successful deploy — on `init`, never from the `plugins_loaded` priority-5 watcher itself: `rocket_clean_domain()` leans on functions WP Rocket only wires at priority 10, and calling it early fataled the deploying request (2026-09-12). Look for the `page-cache-purged` event in `pps_html_deploy_log_v2` after a deploy; its absence means the purge did not happen.
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

**WCPA's global/category forms apply by CATEGORY, so a registry product sitting in a
category WCPA targets is claimed by both systems at once.** Its checkout validation then
refuses the order — *"Addon data missing for product &lt;name&gt;"* — because the calculator
added the line by AJAX and it carries no WCPA form data. The customer sees a correct cart,
a correct price, a full specification, and a checkout that will not complete. `wcpaIgnore`
on the cart item covers WCPA's *cart* hooks; it does **not** cover this.

The per-product tick is "Exclude global forms" (post meta `wcpa_exclude_global_forms`).
It was set on five products during the 2026-07 registry migration and missed on the other
twenty-nine. The gap surfaced on **2026-09-15**, when a customer could not pay for a 9×9
booklet (product 22754) and wrote in to say so — which is the only reason we found out.
Nobody who simply gave up ever appeared in a log.

Two things now hold the line, and the second is the one that matters:

1. The meta is set on all 34 registry products, so the admin screen agrees with behaviour.
2. **`pps-calculators.php` filters `get_post_metadata` for that key and forces `1` for
   anything `pps_get_calculator_for_product()` owns.** The registry decides, not the
   database — a product added to the registry tomorrow is covered the moment it is added,
   with no tick to remember and nothing to re-do after a database refresh from production.

Adding a product to the registry therefore no longer requires touching WCPA at all. If you
ever see "Addon data missing" on a PPS product again, that filter is what to check first.

**And a refused checkout on a calculator cart is now recorded.** We cannot enumerate
every plugin that might one day claim one of our products, so the durable protection is
that the next one announces itself. `woocommerce_after_checkout_validation` at priority
99 writes the product IDs and the refusal messages to
`wp_options['pps_checkout_refusals']` (newest first, capped at 30, autoload off) and to
the PHP error log. **Read it with `wp_get_option( 'pps_checkout_refusals' )` — it is the
first place to look when someone reports "it won't let me order".** The hook only fires
on carts carrying a `pps_metadata`/`pps_price` line, and the whole body is wrapped in
try/catch: a tripwire that breaks checkout would be worse than no tripwire.
`tools-order-blockers-test.mjs` gates both guards.

**Audited 2026-09-15, the other systems that could do the same thing.** Of the plugins
whose per-product meta sits on registry products, only **WCPA** and **pi-edd** are in
`active_plugins`. The `_uni_cpo_*`, `_cpo_*`, `_nbdesigner_*`/`_nbo_*`/`_nbd*` and
`_wooclientzone_*` keys are residue from uninstalled plugins and cannot run — but they
are exactly the shape that caused this, so if one is ever reactivated, check its
per-product ownership flag against the registry before trusting an order path. pi-edd
writes a competing delivery date rather than refusing an order (see
`pps-delivery-date-guard.php`, live on both sites since 2026-09-15). `_virtual` was spot-checked across
saddle, perfect-bound, brochure, sticker and greeting-card products and holds.

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
- **Getting a calculator onto production** is a separate step from Pages, and is
  not automatic. Drop the file into `wp-content/plugins/pps-calculators/_pending_html/`
  — pull-based, `pps_plugin_download_url` against a `raw.githubusercontent.com`
  URL pinned to a commit — then make any request to the site. `pps-html-deploy.php`
  copies it into `wp-content/uploads/pps-calculators/`, updates the registry
  (only `uploaded` moves on an overwrite, so product assignments survive),
  archives the source, and logs the byte count to
  `wp_options['pps_html_deploy_log_v2']`. Verify there, and against
  `pps_uploads_list_files` — not by fetching the page, which the sandbox proxy
  blocks.
- **Retention runs on deploy, and nothing else prunes.** Two directories used to
  grow without limit — the extracted scripts in uploads (about eight per release)
  and `_pending_html/_archive` (one run per deploy). Since 1.5.0 a successful
  deploy prunes both: scripts must be BOTH beyond the newest
  `PPS_HTML_DEPLOY_KEEP_SCRIPTS` (5) for their calculator AND older than
  `PPS_HTML_DEPLOY_SCRIPT_MIN_AGE` (14 days) before they go, because the page
  referencing one can outlive it in a cache; the archive keeps the newest
  `PPS_HTML_DEPLOY_KEEP_ARCHIVES` (20) dated runs. Losing a script is
  self-healing anyway — the extractor writes it back on the next uncached
  render. `tools-html-deploy-retention-test.php` is the gate.
- **A deployed calculator is not necessarily the one being served.** The plugin
  extracts the inline script to `uploads/pps-calculators/js/<name>-<md5-10>.js`
  and enqueues it by that content hash, so a new build is a new URL and the
  script itself can never go stale. The *page* embedding that URL can: WP Rocket,
  Cloudflare and Cloudways all cache it. Logged-in admins bypass those; customers
  do not. After deploying, purge, then confirm the page references the new hash.
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

- Go private protocol: replace files with dummies, flip repo to private. Restore: `git checkout pps-real-backup -- <files>`

## Retired branches

- `gh-pages` (last commit 2026-04-17) — never the live Pages source despite an earlier session's mistaken claim. Archived as `OLD/gh-pages`. Safe to delete locally: `git push origin --delete gh-pages`. Contained an orphan `pb-v2.html` cache-verification duplicate of the perfect-bound calculator with the pre-fix cover-print formula.
