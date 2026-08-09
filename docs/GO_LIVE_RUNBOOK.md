# Go-Live Runbook — staging → production via Cloudways

Plan of record (owner, 2026-08-09): selectively pull order data live → staging,
then push the entire staging site → production through Cloudways'
staging-to-production pipeline. This runbook makes that plan safe. **Read the
three gates first — gate 1 can invalidate the whole table list.**

---

## Gate 1 — Confirm HPOS before anything else (single most important check)

WooCommerce stores orders in one of two places:

- **HPOS** (High-Performance Order Storage): orders live in `wp_wc_orders` +
  `wp_wc_orders_meta` + `wp_wc_order_addresses` + `wp_wc_order_operational_data`.
- **Legacy**: orders and refunds are rows in **`wp_posts`** (`shop_order`) with
  their data in **`wp_postmeta`**.

Check on the **live** site: `WooCommerce → Settings → Advanced → Features →
Order data storage`, or read `wp_options.woocommerce_custom_orders_table_enabled`
(`yes` = HPOS). Also note whether "compatibility mode" (sync to posts) is on.

- **HPOS on** → the table-copy plan works. Proceed.
  **✔ Confirmed by owner 2026-08-09: live is on HPOS.**
- **Legacy (posts) on** → **STOP. The plan as written cannot work**, because the
  new orders live inside `wp_posts`/`wp_postmeta`, and pulling those two tables
  wholesale would overwrite months of staging content work (products, `_virtual`
  flags, PPS Defaults, pages, media). Options in that case: enable HPOS + sync
  on live first (its own migration, verify before proceeding), or a row-level
  order export/import instead of table copies. Get help before improvising.
- **HPOS + compatibility sync on** → orders exist in BOTH stores. Treat as
  legacy for safety (the posts copies matter), or turn off compat sync on live
  after verifying HPOS is authoritative, then proceed as HPOS.

## Gate 2 — Never pull `wp_posts` / `wp_postmeta` / `wp_terms*` / `wp_options` from live

Those tables are where all the staging-era work lives: product catalog changes,
the 34 registry products' `_virtual` flags and PPS Defaults, category **term
descriptions** (the hand-authored category pages), term meta, page content,
`pps_calc_config` / `pps_presets` / `pps_tooltips` / `pps_faqs`. Pulling any of
them from live erases staging's months of work. They flow the other way, on the
final push.

## Gate 3 — The freeze window is not optional

The final push **replaces production's database with staging's**. Any order,
customer, or form submission that lands on live between your pull and your push
is silently destroyed by the push. Sequence is therefore: freeze live checkout →
pull → verify → push → verify → unfreeze, in one sitting. Keep the window short
(realistically 30–60 minutes). Do it in your lowest-traffic hour.

---

## Phase 0 — Prepare staging: remove bloat (days BEFORE the freeze window)

Everything on staging — files and database — becomes production at push time,
so cleaning staging now is cleaning the future live site. It also shrinks the
push and shortens the freeze window. Do this well ahead of go-live so the site
can be re-verified afterward at leisure.

### Safety rails (non-negotiable)

1. **Cloudways full backup of staging first.** Also note Cloudways backup
   retention is finite — anything you might *ever* need again gets archived
   off-host (zip to Google Drive) before deletion, not just "it's in a backup."
2. **Measure before deciding.** Get real table sizes and let size drive effort:
   ```sql
   SELECT table_name, ROUND((data_length+index_length)/1024/1024,1) AS mb,
          table_rows
   FROM information_schema.tables
   WHERE table_schema = DATABASE()
   ORDER BY (data_length+index_length) DESC LIMIT 30;
   ```
   Record the top 30 before and after. Don't spend an afternoon dropping 40
   tiny tables if three log tables are 95% of the bloat (they usually are).
3. **Deactivate + delete the defunct plugin BEFORE dropping its tables** —
   otherwise the plugin quietly recreates them. Drop tables only for plugins
   confirmed absent from the plugins list (active AND inactive).
4. **`DROP` is for defunct plugins' tables. `TRUNCATE`/purge is for active
   plugins' logs.** Never drop a table belonging to an active plugin.
5. After each stage: load the calculators, a category page, /shop/, wp-admin
   orders list, and run one test add-to-cart on staging.

### A. Defunct-plugin tables — verify, then deactivate/delete plugin, then DROP

Each group is a candidate, not a verdict — the gate is "plugin not installed":

| Group | Tables | Verdict gate |
|---|---|---|
| Yoast SEO | `wp_yoast_*` (8 tables — `indexable` + `prominent_words` are often huge) | Site runs Rank Math. If Yoast is uninstalled, drop all 8. |
| Slider Revolution | `wp_revslider_*` (6) | Custom theme doesn't use it. Verify no page renders a slider. |
| LayerSlider | `wp_layerslider`, `_revisions` | Same. |
| NBDesigner (old product designer) | `wp_nbdesigner_*` (7) | Superseded by PPS calculators. Verify nothing links to designer pages. **Its customer-design uploads are handled in section D.** |
| ProjectHuddle | `wp_ph_members`, `wp_ph_thread_members` | Feedback tool — defunct if uninstalled. |
| Ultimate Member VIP | `wp_um_vip_users` | Defunct if UM uninstalled. |
| JetEngine | `wp_jet_post_types`, `wp_jet_taxonomies` | Defunct if uninstalled — verify no CPTs/taxonomies in use came from it first. |
| Groups | `wp_groups_*` (5) | **Owner decision 2026-08-09: never used — drop all 5** (and delete the plugin if installed). |
| Save/Share Cart | `wp_wcss_saved_cart`, `wp_wcss_shared_cart` | Drop if uninstalled. |
| Woo File Dropzone (old upload flow) | `wp_woo_file_dropzone` | Superseded by the Drive upload flow. Uploads in section D. |
| One of the two image optimizers | `wp_ewwwio_images` OR `wp_imagify_*` | Two optimizers are installed; keep the active one, drop the other's tables. |
| Whichever forms plugins are redundant | `wp_frmt_*` (Forminator) / `wp_wpforms_*` / Elementor `wp_e_submissions*` | Three form stacks exist. Identify which forms are actually live; drop the uninstalled stacks' tables. Live-collected submissions in the KEEP stack are pulled fresh from live anyway (see pull list). |
| Misc: `wp_sm_sessions`, `wp_event_hours`, `wp_tm_tasks`/`_taskmeta`, `wp_vi_wbe_history`, `wp_wt_iew_*`, `wp_pmxe_*` | Identify the owning plugin first (`grep` the plugins dir for the table name); drop only when the owner plugin is confirmed gone. Import/export history (`wt_iew`, `pmxe`) is safely droppable even if the plugins stay — they're job history, keep the `_template`/`_templates` rows if the plugins remain. |

### B. Active-plugin log/queue purge — TRUNCATE, don't drop

Usually the real bulk:

- `wp_actionscheduler_actions` + `_logs`: delete `complete`/`failed`/`canceled`
  rows (or Tools → Scheduled Actions → delete finished). Often the single
  biggest table on any Woo site. Keep `pending`.
- `wp_woocommerce_log`, `wp_wpmailsmtp_debug_events`, `wp_aiowps_audit_log` +
  `_debug_log` + `_events`, `wp_wpforms_logs`, `wp_wpai_request_logs`,
  `wp_mwai_tasklogs`: truncate.
- `wp_woocommerce_sessions`: truncate (transient carts; staging's are test junk).
- `wp_mailchimp_carts`, `wp_queue`, `wp_failed_jobs`: truncate.
- WP Rocket (`wp_wpr_*` incl. `rucss_used_css`): clear via the plugin (purge +
  clear used CSS) rather than SQL; it regenerates.
- `wp_e_events` (Elementor events log): truncate if present.

**Do NOT touch:** `wp_mwai_files`/`_filemeta`/`_mcp_oauth_*` (the AI-engine /
connector auth — truncating OAuth tables severs Claude's access), `wp_snippets`
(Code Snippets **carry live site behavior** — audit its contents and copy
anything load-bearing into the repo per the server-patch rule, but never bulk
delete), anything `wp_wc_*`/`wp_woocommerce_*` structural, and all PPS options.

### C. WordPress-core bloat

- Post revisions, auto-drafts, trashed posts/comments, orphaned postmeta and
  term relationships (WP-Optimize/Advanced DB Cleaner can do all of these, or
  standard cleanup SQL).
- **Expired transients** in `wp_options`, and an autoload audit:
  ```sql
  SELECT ROUND(SUM(LENGTH(option_value))/1024/1024,1) AS autoload_mb
  FROM wp_options WHERE autoload='yes';
  ```
  If autoload_mb is over ~3–5 MB, list the biggest autoloaded rows and flip
  defunct plugins' leftovers to `autoload='no'` / delete their option rows.
  Orphaned options from every plugin in section A can go.

### D. File-side bloat (this ships to production too)

- **Old customer artwork uploads** (`wp-content/uploads/` trees from the
  NBDesigner era, Woo File Dropzone dirs, any pre-Drive WCPA upload dirs, and
  legacy local PPS artwork if any predates the Drive flow).
  **Owner decision 2026-08-09: thin the files — delete the old upload trees
  outright. The owner retains offline archives going back years, so no
  pre-delete archiving step is needed.** Known consequence, accepted: a
  legacy reorder that references a deleted file won't auto-restore its
  artwork — the customer re-uploads, or the file is retrieved from the
  offline archive. Current-order artwork lives in Google Drive and is
  unaffected. Practical guidance for the executor: keep uploads referenced
  by orders from the last ~90 days (open/recent jobs), delete the rest of
  the legacy upload trees.
- Deactivated themes (Astra + child, anything not `pps-theme` and one default
  fallback like twentytwentyfour) and deleted-but-present plugin directories.
- Image-optimizer backup originals (EWWW/Imagify keep pre-optimization copies
  in uploads) — safe to purge once optimization is accepted.
- Cache directories (`wp-content/cache/*`) — purge, they regenerate.
- Stray root/plugin files not in the repo (the 2026-08-01 audit list:
  `_pps_*.php`, `*.bak`, test files) — this is also a pre-push checklist item,
  but sweep it now.

### E. Verify and record

- Re-run the size query; record before/after MB in this file.
- Full staging smoke test: all nine calculators, a category page with wizard,
  /shop/, add-to-cart → checkout reaches payment, wp-admin order screens,
  Drive upload, the connector still authenticates (mwai untouched).
- Take a fresh Cloudways backup of the cleaned staging — this becomes the
  "known-good clean base" for the go-live window.

---

## The pull list (live → staging), assuming HPOS

Table-replacement semantics work here because staging's copies of these tables
are disposable (stale pull + test orders you want gone anyway). This is not a
"merge" — live's versions replace staging's wholesale, which is exactly right.

**Orders — required:**

| Table | Why |
|---|---|
| `wp_wc_orders` | The orders themselves (incl. refunds) |
| `wp_wc_orders_meta` | Order meta — includes `pps_metadata`, PPS-Spec inputs |
| `wp_wc_order_addresses` | Billing/shipping addresses |
| `wp_wc_order_operational_data` | Order state internals |
| `wp_woocommerce_order_items` | Line items (both storage modes use this) |
| `wp_woocommerce_order_itemmeta` | Line-item meta — the PPS spec per item |
| `wp_comments` + `wp_commentmeta` | Order notes are comments (`order_note`) |
| `wp_users` + `wp_usermeta` | Customers created at checkout since the pull |

**Orders — strongly recommended:**

| Table | Why |
|---|---|
| `wp_woocommerce_payment_tokens` + `_tokenmeta` | Customers' saved payment methods |
| `wp_wdr_order_discounts` + `wp_wdr_order_item_discounts` | Per-order discount history |
| `wp_wc_order_stats`, `wp_wc_order_product_lookup`, `wp_wc_order_tax_lookup`, `wp_wc_order_coupon_lookup`, `wp_wc_customer_lookup` | Analytics. Pulling them is simpler than rebuilding; they're self-contained per order. (Alternative: skip and run WC → Status → Tools → regenerate.) |

**Not orders, but collected on live since your pull — decide deliberately:**

| Table | What you lose if you skip it |
|---|---|
| `wp_e_submissions` + `_actions_log` + `_values` | Elementor form submissions (quote requests, contact forms) |
| `wp_frmt_form_entry` + `_entry_meta` | Forminator entries |
| `wp_wpforms_payments` + `_payment_meta` | WPForms payment records |
| `wp_rank_math_redirections` (+`_cache`) | Redirects added on live since the pull — CLAUDE.md says redirects are managed in Rank Math, so whichever site is where you actually edit them is canonical. If that's live, pull these. |
| `wp_woocommerce_tax_rates` + `_locations`, `wp_woocommerce_shipping_zone*` | Only if tax/shipping config was changed on live since the pull |

**Deliberately NOT pulled:** `wp_posts`, `wp_postmeta`, `wp_terms*`,
`wp_options` (Gate 2); `wp_woocommerce_sessions` (live carts — transient,
acceptable loss); `wp_actionscheduler_*` (pending live jobs — pulling them onto
a running staging risks staging's cron firing customer emails; if you want
pending jobs preserved, pull them **during the freeze** and keep staging's cron
disabled until the push completes); everything else on the list (SEO indexables,
caches, image-optimizer logs, sliders) is regenerable or plugin bookkeeping.

---

## The auto-increment landmine (do this on staging after the pull)

Even under HPOS, WooCommerce reserves each order ID with a placeholder row in
`wp_posts` — meaning **order IDs are minted from the posts auto-increment**.
Staging's `wp_posts` doesn't have live's placeholder rows, so after go-live the
posts counter can sit BELOW the copied orders' IDs → the next real order tries
to reuse an existing order ID → checkout breaks with duplicate-key errors.

After the pull, on **staging**, in Cloudways' DB manager:

```sql
-- Find the ceiling
SELECT GREATEST(
  (SELECT COALESCE(MAX(id),0)  FROM wp_wc_orders),
  (SELECT COALESCE(MAX(ID),0)  FROM wp_posts)
) + 10;

-- Use that number in ALL of these (replace 999999):
ALTER TABLE wp_posts     AUTO_INCREMENT = 999999;
ALTER TABLE wp_wc_orders AUTO_INCREMENT = 999999;

-- Same idea for the other copied stores:
SELECT MAX(comment_ID) FROM wp_comments;    -- then ALTER wp_comments
SELECT MAX(ID) FROM wp_users;               -- then ALTER wp_users
SELECT MAX(order_item_id) FROM wp_woocommerce_order_items;  -- then ALTER it
```

Cheap insurance; skipping it is how the first post-launch order fails.

---

## Pre-push checklist (on staging, during the freeze)

The push sends staging's `wp_options` and files to production, so staging must
be production-configured before the button is pressed:

- [ ] **Search engines**: `Settings → Reading` — "Discourage search engines"
      must be OFF (`blog_public = 1`). Pushing a noindexed options table to
      production is an SEO catastrophe.
- [ ] **Payments**: gateway (Stripe etc.) in LIVE mode with live keys on
      staging. A test-mode options table pushed to production stops revenue.
- [ ] **Email**: SMTP settings are production's (right from-domain, right key).
- [ ] **Webhooks/API**: `wp_wc_webhooks` + `wp_woocommerce_api_keys` push from
      staging — the Make.com WooCommerce→Sheets scenario and any other
      consumers must be re-pointed/re-verified after go-live.
- [ ] **Google Drive**: `pps_gdrive` credentials in options push from staging —
      confirm the Drive folder IDs are the production ones, not test folders.
- [ ] **File audit**: staging `wp-content/plugins/` vs the repo — no stray
      agent-authored `_pps_*.php`, `.bak`, or test files ride to production
      (CLAUDE.md server-patch rule; audit last done 2026-08-01).
- [ ] **Backups**: Cloudways full backup of BOTH applications, taken now.

## The sequence

1. Announce/plan the window. Put live checkout in maintenance (freeze).
2. Cloudways backups of both apps.
3. Pull the table list above, live → staging.
4. Run the auto-increment alignment on staging.
5. Verify on staging wp-admin: order count matches live, newest live order is
   present and opens cleanly (items, addresses, totals, notes), a spot-check
   customer account exists.
6. Run the pre-push checklist.
7. Cloudways push staging → production (files + database). Cloudways handles
   URL search-replace; still verify.
8. Post-push, on production:
   - [ ] Flush permalinks (`Settings → Permalinks → Save`) — the preset URLs
         register per-slug rewrite rules and need it.
   - [ ] Clear caches (WP Rocket tables pushed stale — purge; object cache).
   - [ ] Open 3 recent real orders — intact. Admin order list count sane.
   - [ ] **Place a real test order end-to-end** (then refund it): proves
         checkout, payment in live mode, new order ID mints ABOVE the copied
         range, PPS-Spec/Missive meta, Drive upload, confirmation email.
   - [ ] Calculator pages, preset URLs, category pages, /shop/ render.
   - [ ] robots.txt / sitemap / llms.txt reachable; site is indexable.
   - [ ] Reconnect anything authenticating to the site: the AI-engine/MCP
         application passwords live in `wp_usermeta` — the users pull + push
         may have invalidated them; re-provision and re-auth the connectors.
   - [ ] Make.com scenario delivers on the test order.
9. Unfreeze. Watch the first organic orders come in.

**Rollback**: restore production from the step-2 Cloudways backup. That is the
whole plan B — which is why step 2 is not skippable.

---

## Open questions to resolve before scheduling the window

1. Gate 1: HPOS or legacy on live? (Decides everything.)
2. Does Cloudways' pipeline support **table-selective pull** on this plan tier,
   and is the push all-or-nothing? (The runbook assumes selective pull,
   whole push.)
3. Where are Rank Math redirects actually edited — live or staging?
4. Any tax/shipping config changes made on live since the last pull?
