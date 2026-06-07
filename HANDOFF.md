# Priority Print Service — Session Handoff

**Date of handoff:** 2026-06-07
**Operator:** Preston Cicala (prestoncicala@gmail.com)
**Source repo:** `pdevvle/priorityprintservice.com`

This document captures the state of an in-progress maintenance session so a fresh
Claude instance can continue without losing context.

---

## TL;DR — Where things stand right now

1. **HPOS migration is complete on production.** Authority flipped, compatibility
   mode still ON (safety net during burn-in).
2. **Staging was cloned from production** (Cloudways app clone). Then we
   redeployed the PHP plugin files from the `production` branch.
3. **All calculator HTMLs and PHP plugin files are deployed to staging from the
   `production` branch.** The plugin's `pps-html-deploy.php` is now the canonical
   HTML deploy mechanism going forward.
4. **The open issue:** React `removeChild` error after artwork approval on the
   saddle stitch calc. A 3-part hardening patch was just deployed
   (`commit d011fa1` on `pps-pricing-config`); awaiting user retest.
5. **Critical reminder:** the user **EXPOSED a WordPress Application Password in
   chat** earlier in the session (for `prestoncicala@gmail.com` on staging). It
   should be rotated. The new password should never be pasted in chat.

---

## Environment

### MCP servers in use (Claude Code on the web)

| Server (UUID prefix) | Site | Purpose |
|---|---|---|
| `b4463a51-…` | priorityprintservice.com | Production WP — Priority Print MCP plugin |
| `1710ca27-…` | woocommerce-70867-4915293.cloudwaysapps.com | Staging WP — Priority Print MCP plugin |
| `c2a3dc6f-…` | Make.com | (Not actively used in this session) |
| `github` | GitHub API | Repo operations |

Each WP MCP exposes the Priority Print MCP Tools plugin, custom-built. It includes:
- WooCommerce: list/get products, categories, orders, update status, add notes
- File ops within plugin directories: `pps_plugin_list_files`,
  `pps_plugin_read_file`, `pps_plugin_write_file`
- Theme file ops scoped to `priority-print` theme directory only
- **`pps_plugin_download_url`** — server-side HTTPS fetch into the plugin dir.
  Used to pull files from GitHub Pages without burning client context. 12MB cap.
- WordPress updates: `pps_wp_check_updates`, `pps_wp_get_plugin_versions`,
  `pps_wp_update_plugin`, `pps_wp_update_theme`, `pps_wp_update_core`
- Generic WP: `wp_get_option`, `wp_update_option`, `wp_count_posts`, `wp_list_plugins`, etc.

If the MCP tools aren't showing up in a fresh session, the operator may need to
reconnect them in their Claude harness. We added tools mid-session and needed
reconnects each time.

### Branches in the repo

| Branch | Role | State |
|---|---|---|
| `production` | **Canonical** — actual deployed code | Authoritative truth |
| `pps-pricing-config` | GitHub Pages source branch | Used as deploy staging — we push files here, then pull via `pps_plugin_download_url` from `https://pdevvle.github.io/priorityprintservice.com/<file>` |
| `claude/gallant-clarke-j6ayj` | Session work branch | Where this session originated |
| Many other `claude/*`, `feat/*`, `experiment/*` branches | Old work, mostly abandoned | Ignore unless investigating history |

**Deploy pipeline this session used:**
1. Edit file locally (on `pps-pricing-config` branch)
2. `git commit` + `git push origin pps-pricing-config`
3. Wait ~30–60s for GitHub Pages to redeploy
4. `pps_plugin_download_url` to fetch from `https://pdevvle.github.io/priorityprintservice.com/<file>` → write to staging's `wp-content/plugins/pps-calculators/<file>`

**.nojekyll exists on the Pages branch** — required so Jekyll doesn't mangle the calculator HTMLs' Babel `{{ }}` syntax.

### Cloudways setup

- Both sites on Cloudways. Operator uses Cloudways UI for everything — does NOT
  use SSH or local terminal.
- Cloudways supports selective DB pulls (table-by-table) between apps on the
  same server. This is the unlocked workflow now that HPOS is the order
  storage.

---

## What was done this session (chronological)

### 1. HPOS Migration on Production

- Took pre-flight inventory (plugin versions met all HPOS thresholds).
- Operator enabled compatibility mode in WC Settings → Advanced → Features.
- Discovered the bulk migration didn't auto-fire — DataSynchronizer reported "0
  pending" because the historical orders were already synced (clone had brought
  them across).
- Wrote a diagnostic shim (`hpos-sync.php`) gated by a secret token + admin cap.
  Confirmed `wp_wc_orders` had 4,004 orders + 161 refunds, fully populated.
- Operator flipped the HPOS authority radio on the WC Features screen.
- Verified: `woocommerce_custom_orders_table_enabled = yes`,
  `woocommerce_custom_orders_table_data_sync_enabled = yes` (kept compat mode
  ON as safety net).
- Burn-in monitoring continues. Compat mode is meant to be turned off after 1–2
  weeks of clean operation.

**WCPA email-meta issue discovered post-migration:** WCPA per-item config not
appearing on emailed receipts immediately after the HPOS flip. Investigation
showed the item meta IS in `wp_woocommerce_order_itemmeta`; the issue was
WCPA's email render path. **The operator reported this resolved on its own** —
likely a stale Object Cache Pro entry. No code change was applied for that issue.

### 2. Staging cloned from production

Operator did this via Cloudways Application Clone. Then we set about deploying
the latest plugin code to staging.

### 3. Plugin file deployment to staging

The `pps-calculators` plugin on production was running a 5KB stub of
`pps-calculators.php` (a "go-private-protocol" reduction). The real 188KB
plugin needed to be restored.

We deployed each file via the `pps-pricing-config` → GitHub Pages → download_url
pipeline. Initially I deployed from `pps-pricing-config` not realizing
`production` was the canonical branch. Files deployed:
- `pps-calculators.php` (190 KB, production version)
- `pps-cart-price-floor.php` (4.3 KB, NEW security plugin)
- `pps-config-admin.php` (84 KB, production version + PHP 8.2 fix preserved)
- `pps-gdrive.php`, `pps-presets-admin.php`, `pps-reorder.php`
- `pps-html-deploy.php` (10 KB, NEW — canonical HTML deploy plugin)
- 4 calc HTMLs in plugin root: `calc-preview-test.html` (saddle stitch),
  `calc-perfect-bound.html`, `calc-brochure.html`, `calc-coupon-book.html`
- `ups-zone-map-seed.json`

### 4. Calculator HTMLs → Uploads dir + registry seeded

The plugin loads calc HTMLs from `wp-content/uploads/pps-calculators/`, not the
plugin dir. We:
- First tried a one-shot shim `move-htmls.php` — blocked by AIOS / direct PHP
  file access security.
- Wrote a self-installing hook directly in `pps-calculators.php` that fires on
  `init` and copies HTMLs from plugin dir to uploads dir + seeds
  `wp_options['pps_calculators_registry']`.
- Later REPLACED that hook with a self-SYNC version that detects updated HTMLs
  by mtime and refreshes registry display names with the `BUILD` chip from the
  calc HTML head.
- Even later, REMOVED that custom hook entirely when we discovered the
  `production` branch had `pps-html-deploy.php` as the canonical deploy
  mechanism. The user assigned product IDs to each calc in the admin UI.

### 5. PHP 8.2 TypeError fix

Production tab in the Central Config admin crashed with
`Uncaught TypeError: Unsupported operand types: string + int` because
`pps-config-admin.php` line 1212 had `is_float( $val + 0 )` where `$val` could
be a string like `"America/Phoenix"`. Fix:
```php
$step = ( is_numeric( $val ) && ( is_float( $val + 0 ) || strpos( (string) $val, '.' ) !== false ) ) ? '0.001' : '1';
```
**This fix is on `pps-pricing-config` branch but NOT yet merged into
`production`.** Production runs PHP 7.x and didn't hit the bug; staging is on
PHP 8.2.

### 6. Discovered the canonical branch divergence

The user instinctively asked "are there unmerged changes lurking?" and the
answer was: **yes, `pps-pricing-config` was significantly behind
`production`.** Diff showed:
- `pps-cart-price-floor.php` (87 lines) — only on production
- `pps-html-deploy.php` (270 lines) — only on production
- Inline price-floor security in `pps-calculators.php` (~33 lines) — only on
  production
- Updated coupon book single-axis markup config in `pps-config-admin.php` — only
  on production

We then `git checkout origin/production -- <files>` to bring production's
versions onto `pps-pricing-config`, preserved the PHP 8.2 fix on top, committed,
and pushed. Then re-deployed those files to staging via the same pipeline.

### 7. Active issue (as of handoff): React `removeChild` error on artwork approval

The saddle stitch calc throws a `Failed to execute 'removeChild' on 'Node'`
error after artwork approval. Loading bar completes, but the pricing form
vanishes (no alert because the error is during React's commit phase).

**Diagnosis path:**
- User tested in incognito → bug reproduces → not a browser extension.
- The error is a classic DOM-mutation-vs-React reconciliation conflict.
- Suspected sources: WP Rocket (lazy load + delay JS), WCPA frontend bundle,
  and the calc HTML's own runtime `<script>` injection via `ensureJsPDF()`.

**Hardening patch deployed (commit `d011fa1` on `pps-pricing-config`):**
1. `wp_enqueue_script` jsPDF — makes the runtime `ensureJsPDF()` fallback a
   no-op (global already loaded), eliminates the runtime script injection.
2. `wp_dequeue_script` / `wp_dequeue_style` WCPA frontend handles on
   calc-owned product pages AND preset URLs.
3. Filters disable `do_rocket_lazyload`, `do_rocket_lazyload_iframes`,
   `do_rocket_delay_js` on calc-owned pages.

**Awaiting user retest.** If the bug persists, the next escalation is a
React ErrorBoundary added to `calc-preview-test.html` so the calc surfaces
"please refresh" rather than vanishing silently.

---

## File state on staging (as of handoff)

In `wp-content/plugins/pps-calculators/`:

```
pps-calculators.php       192,493   production + hardening patch
pps-cart-price-floor.php    4,345   production version (security)
pps-config-admin.php       84,224   production + PHP 8.2 fix
pps-gdrive.php             28,363   production version
pps-html-deploy.php        10,290   production version (canonical deploy plugin)
pps-presets-admin.php      50,132
pps-reorder.php            35,435   production version
ups-zone-map-seed.json      8,001
calc-brochure.html        278,480
calc-coupon-book.html     320,554
calc-perfect-bound.html   367,300
calc-preview-test.html    468,255   (saddle stitch — currently failing on approval)
hpos-sync.php                  17   inert stub
move-htmls.php                 17   inert stub
pps-calculators-stub-attempt   17   inert stub
pps-db-diag.php            13,298   pre-existing diagnostic plugin
```

`wp_options['pps_calculators_registry']` populated with all 4 calcs, BUILD
labels in names, and product assignments:

```
calc-preview-test.html  →  Saddle Stitch Booklets · 2026-05-23 · SS-JSPDF-FALLBACK
                            products: 33670, 17429, 33714, 22872
calc-perfect-bound.html →  Perfect Bound Booklets · 2026-05-25 · PB-OUTFOLD-C
                            products: 21301
calc-brochure.html      →  Brochures · 2026-05-20 · BR-RIGHTANGLE
                            products: 20272, 22247, 86472, 20178
calc-coupon-book.html   →  Coupon Books · 2026-05-30 · CB-SALE-ADDONS-PARITY
                            products: 23506, 35286, 35689, 86468
```

---

## Architectural notes (gotchas a fresh assistant should know)

### 1. The "Go private protocol" stub

CLAUDE.md mentions a "Go private protocol" where files are replaced with dummies
and the repo flipped to private. Production was running the 5KB stub of
`pps-calculators.php` (a dummy). The full 188KB version exists on the
`production` branch in git and was deployed to staging this session. Whether
production currently has the full version or the stub is **unverified** —
should be checked before any prod work.

### 2. Calc HTML parser drops everything except `<style>` and `<script type="text/babel">`

`pps_parse_calculator_html()` extracts only those two blocks. The `<script src>`
tags in the calc HTML head (jsPDF, React CDNs) are DROPPED at render. The PHP
plugin enqueues React, React-DOM, PDF.js, Babel separately. **As of the patch
just deployed, jsPDF is now also pre-enqueued.**

### 3. Three deploy mechanisms exist for calc HTMLs

- **Admin Upload UI** in `pps-calculators.php` — file picker, registers in
  registry, copies to uploads dir
- **`pps-html-deploy.php`** — canonical mechanism (production). Drops files in
  `_pending_html/`, runs on `plugins_loaded`, copies to uploads, archives source,
  logs to `wp_options['pps_html_deploy_log_v2']`
- **MCP-driven** (this session) — push to `pps-pricing-config` branch, pull
  via `pps_plugin_download_url` server-side from GitHub Pages

### 4. WCPA coexistence pattern

Per CLAUDE.md and the code: PPS calculators and the legacy WCPA plugin coexist.
A product is owned by ONE or the other:
- If product ID is in `pps_calculators_registry[<file>]['products']` → React
  calc owns it
- Otherwise WCPA (or whatever else) owns it

WCPA's JS is loaded site-wide. The hardening patch dequeues it ONLY on
calc-owned product pages (and preset URLs).

### 5. Self-sync hook ≠ canonical deploy

I built a `pps_calculators_registry` self-sync hook before discovering
`pps-html-deploy.php`. The self-sync hook was REMOVED in the final state. Do
not bring it back. `pps-html-deploy.php` is the canonical deploy mechanism.

### 6. Branch hygiene to know

- **Push code edits to `pps-pricing-config`** to deploy via the Pages →
  download_url pipeline.
- **Merge `pps-pricing-config` into `production`** when changes are ready for
  live deploy. (As of handoff, the recent hardening patch is on
  `pps-pricing-config` but NOT yet on `production`.)
- **Don't push to `website` branch** — unrelated to the preview pipeline.
- **`gh-pages` branch is archived** (`OLD/gh-pages`).

---

## Currently OPEN issues

1. **React removeChild error on artwork approval (saddle stitch calc)**
   - Hardening patch just deployed, awaiting retest.
   - If still broken: write a React ErrorBoundary into `calc-preview-test.html`,
     and inspect what other server-side JS is mutating DOM (Imagify? Astra?
     New Relic Browser?).
2. **Compat mode burn-in (production)**
   - HPOS is authoritative, compat mode is ON. After 1–2 weeks of clean
     operation, the operator should flip compat mode OFF in WC Features.
3. **Hardening patch needs merging into `production` branch**
   - Currently lives only on `pps-pricing-config` (commit `d011fa1`).
4. **PHP 8.2 fix to `pps-config-admin.php` needs merging into `production`**
   - Inline `is_numeric()` guard around `$val + 0`. Production runs older PHP
     so doesn't hit the bug, but it's safer everywhere.
5. **Cart-price-floor side-load plugin (`pps-cart-price-floor.php`)** is a
   duplicate of inline logic now in `pps-calculators.php`. It's harmless but
   can be retired.
6. **The WordPress Application Password the operator pasted in chat earlier**
   needs to be rotated. The Automattic WP MCP server config they were testing
   used it. Rotate via WP Admin → Users → Profile → Application Passwords.

---

## Recommended next actions

1. **Retest the saddle stitch artwork approval flow** with a hard refresh
   (`Cmd/Ctrl+Shift+R`) to confirm the hardening patch fixed the removeChild
   error.
2. **If fixed:** merge `pps-pricing-config` → `production` and arrange a
   Cloudways pull/deploy for live customers to benefit.
3. **If not fixed:** investigate what other server-side JS is touching DOM.
   Add a React ErrorBoundary to `calc-preview-test.html` as a defensive
   measure regardless.
4. **Rotate the exposed Application Password.**
5. **Plan burn-in cutover** for HPOS compat mode (1–2 more weeks).
6. **Test the WP Migrate DB Pro / selective Cloudways sync workflow** for
   pulling fresh order data from prod to staging without clobbering the
   site-side state changes.

---

## Files / options worth knowing

- `wp_options['pps_calc_config']` — main pricing config (PCF defaults, papers,
  finishing, etc.)
- `wp_options['pps_calculators_registry']` — calc → product ID mapping
- `wp_options['pps_html_deploy_log_v2']` — deploy plugin's log
- `wp_options['pps_html_deploy_pending_attachments']` — Media Library deploy
  trigger (empty by default)
- `wp_options['pps_presets']` — calc presets / `/booklets/<slug>/` URLs
- `wp_options['pps_tooltips']` — RichTip tooltip content
- `wp_options['pps_ups_zone_map']` — UPS zone map (1000 ZIP prefixes)
- `wp_options['woocommerce_custom_orders_table_enabled']` — HPOS authority flag
- `wp_options['woocommerce_custom_orders_table_data_sync_enabled']` — compat mode
- `wp_options['pps_addons_visibility']` — per-calc add-on availability matrix
- `wp_options['pps_faqs']` — per-calc-type FAQ content

---

## Closing notes for the next assistant

Read CLAUDE.md at the repo root before doing anything. It captures the
operator's working style (no terminal, no local CLI), branch hygiene
(`.nojekyll`, `<\/script>` escapes), and architectural invariants.

Be conservative with the production WordPress MCP. Reads are safe; writes
should be confirmed with the operator. The Priority Print MCP plugin we run on
both sites exposes a `pps_plugin_download_url` tool that lets you deploy
file-based changes from GitHub Pages with zero context cost — use it.

If the operator pastes credentials in chat, flag the exposure and treat
the credential as compromised. Never echo it back.
