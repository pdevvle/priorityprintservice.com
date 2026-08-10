# Brief — post-QA staging state, owner-gated items, and the 3.0 retest gate

Written 2026-08-10 at the end of a long session. **Read `CLAUDE.md` first — its
rules bind.** Then skim `docs/GO_LIVE_RUNBOOK.md` (the Gate 1 warning box, the
§D-results block, and the resolved Phase 0 verdicts) and
`docs/CATEGORY_ATTRIBUTES_MIGRATION.md`.

Repo state: everything from that session is on **`claude/shop-category-reorg-mn1kl1`**
(open draft **PR #45**), branched from the integration branch
`claude/optimistic-wozniak-11ql3y`.

---

## 0. Environment facts — do not re-derive these

- **Staging** is `woocommerce-70867-4915293.cloudwaysapps.com`. Confirm with
  `wp_get_option('siteurl')` before any write; the staging and production
  connectors are indistinguishable by tool name.
- **The sandbox cannot reach either site.** The egress policy 403s both hosts at
  CONNECT, so `curl`/WebFetch are useless. Verify through the connector, or use
  the trick that worked: `pps_plugin_download_url` pointed at the site's own URL
  writes the page server-side, then read the byte count. Differential byte
  measurement is reliable — page sizes are deterministic.
- **No tool runs SQL, and there is no Cloudways access.** Every `DROP`,
  `TRUNCATE`, `ALTER`, table pull, backup and push is owner-side.
- **The connectors flap constantly.** `PPS_Production` appeared and dropped ~5
  times and was never usable. Re-search with ToolSearch before concluding a tool
  is missing; a fresh chat picks connectors up more reliably than toggling.
- **HPOS:** authoritative on both sites. **Compat sync is now OFF on both**
  (production by the owner; staging by this session — it was still on *after* the
  pull, which was the live risk).
- **Production orders were pulled to staging** and verified intact (order 86961:
  billing, shipping, line items, four notes incl. Stripe refs). `wp_posts`,
  `wp_postmeta`, `wp_terms*`, `wp_options` and `wp_users` were **not** pulled —
  Gate 2 held, verified by canary (see §4).
- **Auto-increment aligned** — `wp_posts` and `wp_wc_orders` set to 86,971.
  `wp_comments` (25,608) and `wp_woocommerce_order_items` (44,762) self-corrected
  on insert and needed nothing. The first test order should be **86,971**; if one
  lands inside 86,948–86,961, stop.
- **Outbound email is muted on staging** (WP Mail SMTP → Do Not Send). Real
  customer addresses are in the order data now. Do not un-mute.
- **Uploads cleaned:** 46,750 files / 81,078 MB → 25,695 / 35,498 MB, i.e.
  **45,580 MB freed**. Details and the mtime caveat in the runbook §D-results.
- **MCP tools v1.7.0** is deployed to staging: `pps_uploads_list_files`,
  `pps_uploads_delete_file`, `pps_uploads_delete_batch`, plus retention
  (`pps_uploads_retention_get` / `_set` / `_run_now`). Source is
  `priority-print-mcp.php` in the repo.
- **Retention cron is OFF by owner decision** — on-demand only. Directory and
  threshold stay configured (`wcpa_uploads`, 730 days). Do not enable it.
- **Category reorg is deployed** and all nine term descriptions migrated to
  `[pps_cat_attributes]`.

---

## 1. Blocked on the owner — do NOT attempt via connector

Each of these was investigated and handed over for a specific reason. Don't
rediscover them.

| Item | Why the connector can't do it |
|---|---|
| **WP Rocket: disable Delay JS + Automatic Lazy Rendering** | `wp_rocket_settings.consumer_key` reads back as `"********"` and there is no way to tell a transit mask from the stored value. `wp_update_option` writes the whole option, so a read-modify-write risks storing asterisks over the license key. Separately, **there is no lazy-render key in the option at all** in 3.18.3 — that toggle exists only in the UI. |
| **Delete the 50lb row** | Confirmed still present: `pps_calc_config.papers_nc[3]`, `val: 2.001`, "50lb Offset Smooth Opaque". Owner fix is one click (PPS Config → Papers → delete → Save). Rewriting a ~300-line pricing structure to drop one row is disproportionate, and it would round-trip a live API token (see §5). |
| **Disable New Relic browser monitoring** | Cloudways panel. Half the freeze cluster. |
| **`nbdesigner/` remainder** | 2,331 post-migration files (~20 MB) plus every now-empty directory. The uploads tools delete files, not directories. |
| **16 administrator accounts** | See §4 — needs human judgement about who is who. |

---

## 2. Your tasks

### A. Verify the deployed QA fixes render (needs a browser or the owner)

Three calculator files were deployed from pinned `4012e8f` and accepted by the
deploy engine (archives `2026-08-10-020053` / `-020056` / `-020106`). Byte counts
matched the fetches, so the right bytes are live — but nobody has *looked*:

- On saddle / perfect-bound / coupon: switching Preset Sizes → Custom Size from
  the 5.5×8.5 preset should show **Width 5.5 / Height 8.5** (QA saw 8.5/5.5).
- Perfect-bound's Full Color pill should be the **linear tri-colour gradient**,
  not the conic colour wheel.

### B. The freeze-cluster retest — this is the 3.0 blocker gate

Only meaningful **after** the owner's Rocket + New Relic changes. Re-run QA's
failing paths on staging: interact with a calculator immediately after load (no
~30s freeze), toggle accordions (no blank gaps with content present in DOM), and
click the cart's "Proceed to checkout" **button** (it should navigate). Go-live
should not be scheduled until this passes.

### C. Is the pulled order data actually usable for testing?

Never done, and it's the point of the pull. On a recent calculator order, check
`_pps_artwork_path`, `_pps_artwork_files`, `_pps_artwork_on_drive` and the
PPS-Spec string Missive parses; then confirm the imposition queue populates
(`pps-imposition.php` admin page).

**Expect some misses and don't panic:** this session deleted 37,228 MB from
`wcpa_uploads/` and 8,353 MB from `nbdesigner/`. WCPA-era orders may reference
files that no longer exist. That was authorised (the owner keeps offline
archives) and nothing in the PPS codebase reads legacy artwork — verified:
`pps_render_order_item()` only reads artwork meta when `$is_pps`, and legacy
reorder re-adds the product with the frozen `pps_legacy_unit_price`. Worst case
is a dead link in an old order view. Current-order artwork lives in Drive.

### D. Close Gate 1 on production (read-only, four calls)

Still unverified because the production connector never held. When it does:
`siteurl` first to confirm the target, then
`woocommerce_custom_orders_table_enabled`, `..._data_sync_enabled` (owner says
now off — confirm), and `blog_public`. **Read-only. Do not write to production.**

### E. Fix three defects in the v1.7.0 retention code before it reaches production

Found by running it for real. None risks data loss; all three produce confusing
logs or slow runs:

1. **No concurrency guard.** A client-side 60s timeout does not stop the PHP run —
   it keeps deleting. Starting another run races the first. Add a transient lock
   so a second run refuses while one is active.
2. **Misleading skip reason.** Files already removed by a racing run were
   reported as `unlink failed; check filesystem permissions`. **There is no
   permissions problem.** Distinguish "vanished" from "permission denied".
3. **A `get_posts()` per candidate file** in the media-attachment guard — this is
   what pushes large runs past 60s. Pre-fetch the attached-path set once per run.

### F. Production rollout, when it comes

Order matters. `docs/CATEGORY_ATTRIBUTES_MIGRATION.md` has the atts table.

1. Deploy `pps-term-shortcodes.php` from a pinned commit **before** any term
   description references `[pps_cat_attributes]`, or the raw shortcode text
   prints on the page.
2. Then apply the same term-description edits.
3. MCP tools v1.6.0/v1.7.0 is a separate pinned-commit deploy.

---

## 3. Still-open strategic question: push vs forward-deploy

The runbook's plan of record is pull-orders-then-push-staging-over-production.
Two findings argue against the wholesale push:

- `wp_posts` still holds **staging's stale 4,015 `shop_order` rows** (+161
  refunds). Inert now that sync is off, but a whole-database push sends them to
  production as phantom orders alongside the real ones. Clear them first, or
  don't push the database.
- The alternative — **forward-deploy** the plugin files, PPS options
  (`pps_calc_config`, `pps_presets`, `pps_tooltips`, `pps_faqs`), term
  descriptions and product meta (`_virtual`, PPS Defaults) — eliminates the
  freeze window, the order pull, the auto-increment landmine and the
  app-password breakage. Not yet priced out; doing so needs production reads.

---

## 4. Verification canaries that already exist

Cheap ways to prove a pull or push did not clobber non-order data:

- **`pps_uploads_retention`** exists only on staging (created 2026-08-09). If
  `wp_options` were ever replaced from production, it disappears.
- **The flyers term description** contains `[pps_cat_attributes papers="text,cover" …]`.
  Production still has the old inline shortcodes, so a term-table pull reverts it.
- **`_virtual` on product 33672** should be `yes`.
- **Media count is 5** — essentially nothing in the uploads tree has an
  attachment row, which is why the media tools can't see customer artwork.
- **`wp_posts` `shop_order` count is 4,015** with breakdown
  38/6/3790/15/103/62/1.

**Security finding, not pull damage:** staging has **16 administrator accounts**,
including `fake_admin` and `mitchhamstrung@gmail.com` at adjacent IDs (434/435),
vendor support accounts for plugins that no longer exist (`CmsMart` → NBDesigner,
`unicpo` → Uni Woo, `AcoWebs` → WCPA), several freelancer logins, and
`shahidanjum` duplicated. Since staging was cloned from production, **production
almost certainly carries the same set.** Worth auditing before go-live; add it to
the pre-push checklist.

---

## 5. Hazards — read before writing anything

- **`wp_rocket_settings`** — see §1. Don't write it.
- **`pps_calc_config`** — carries every admin price **and a live Shippo API
  token** (`shippo_live_…`) in `pcf.shippo_api_token`. The code handles it
  correctly (`pps_get_public_config()` strips it at both `window.PPS_CONFIG`
  emission sites, and `pps-shippo-test.php` self-tests for the leak), so nothing
  is publicly exposed. **But reading the raw option pulls a live payment-adjacent
  credential into your transcript.** That happened in this session while verifying
  the 50lb row; rotation is pending an owner decision. If you only need to check
  a paper row, prefer the admin UI or accept the exposure knowingly.
- **`pps_gdrive` and `woocommerce_stripe_settings`** — deliberately never read.
  Credentials.
- **`wp_update_option` array round-trip is safe** in v1.7.0 — probed with nested
  objects/lists/ints/bools and it returned structured data, not a JSON string.
  The older hazard noted in `DEPLOY_QA_FIXES_BRIEF.md` no longer applies. The
  blocker for Rocket is the masked key, not serialisation.
- **Never pull `wp_posts` / `wp_postmeta` / `wp_terms*` / `wp_options`** from
  production (Gate 2) — that's the catalog, `_virtual` flags, PPS Defaults, term
  descriptions and all PPS config.
- **Do not re-deploy PHP from an older SHA** — staging PHP is current; an older
  deploy reverts the tooltip accessor and the JSON guards.
- **Do not touch `pps_tooltips`** — synced and verified.
- **Do not enable the retention cron.** On-demand by owner decision.
- One inert residue: option `pps_array_roundtrip_probe`, blanked (there is no
  delete-option tool). Safe to ignore or drop.

---

## 6. Report back

Which owner-gated items landed; the A/B verdicts (the retest is the go-live
gate); what §C found about artwork/spec/imposition on the pulled orders; Gate 1
on production; whether E shipped as v1.8.0; and any decision on push vs
forward-deploy.
