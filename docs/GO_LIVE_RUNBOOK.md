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

  > **⚠ Gate 1 is NOT yet satisfied — compatibility sync is ON.** Read from
  > staging 2026-08-09 (`wp_get_option`, raw):
  >
  > | Option | Value |
  > |---|---|
  > | `woocommerce_custom_orders_table_enabled` | `yes` |
  > | `woocommerce_custom_orders_table_data_sync_enabled` | **`yes`** |
  > | `woocommerce_feature_custom_order_tables_enabled` | `no` (stale row; the
  >   authoritative flag is the first one) |
  >
  > Sync being on puts this squarely in the **third** bullet below, not the
  > first: orders are written to `wp_wc_orders` *and* mirrored into
  > `wp_posts`/`wp_postmeta`. Since staging is a clone of live, live almost
  > certainly has sync on too — but **this was read on staging, and Gate 1 asks
  > about live.** Nothing here can read live (no production connector, and the
  > sandbox's egress policy blocks both hosts), so the owner must confirm the
  > same two options on live before the pull.
  >
  > Why it matters: the pull list copies the HPOS tables but — correctly, per
  > Gate 2 — **not** `wp_posts`/`wp_postmeta`. With sync on, that leaves the two
  > stores disagreeing on staging: HPOS holds the new orders, the posts mirror
  > does not. WooCommerce's sync can then reconcile in either direction, and the
  > direction that resolves toward the (stale) posts mirror loses order data.
  > Resolve one of these before pulling:
  >
  > 1. **Turn compat sync off on live** after confirming HPOS is authoritative
  >    there, then proceed as plain HPOS (the runbook's own suggestion, and the
  >    cleanest).
  > 2. Pull HPOS tables, then on staging **disable sync before the push** and
  >    let HPOS be the single store, accepting that the posts mirror is stale.
  > 3. Row-level order export/import instead of table copies (most work, least
  >    coupled to sync state).
  >
  > Do not start the freeze window until one is chosen.
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

## Gate 2b — Version freeze through the window

No WooCommerce, WordPress, or plugin updates on either site between the
order-table pull and the verified push. WooCommerce 11 in particular does
schema work on `wp_wc_orders_meta` at update time — a cross-version order-table
copy is an avoidable variable. WC 11 lands for both sites together in the 3.1
release (`docs/PPS_3.1_WC11_PLAN.md`), after go-live is stable.

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

Each group is a candidate, not a verdict — the gate is "plugin not installed".

**The gate has been run (staging, 2026-08-09).** The installed-plugin list (31
entries, active *and* inactive) and the on-disk plugin folders (30) were both
read; the resolved verdicts are in the "Verdict gate" column below, and the
evidence is in "Phase 0 execution status" at the end of this file. Two of the
runbook's original open choices are now decided outright: **Imagify is the live
optimizer** (EWWW absent) and **Forminator is the live form stack** (WPForms and
Elementor both absent).

| Group | Tables | Verdict gate |
|---|---|---|
| Yoast SEO | `wp_yoast_*` (8 tables — `indexable` + `prominent_words` are often huge) | **DROP all 8.** Rank Math 1.0.275 installed; Yoast absent from the plugin list and has no folder. |
| Slider Revolution | `wp_revslider_*` (6) | **DROP.** Absent from the plugin list and no folder. |
| LayerSlider | `wp_layerslider`, `_revisions` | **DROP.** Same — absent, no folder. |
| NBDesigner (old product designer) | `wp_nbdesigner_*` (7) | **DROP.** Absent, no folder. **Its customer-design uploads are handled in section D.** |
| ProjectHuddle | `wp_ph_members`, `wp_ph_thread_members` | **DROP.** Absent, no folder. |
| Ultimate Member VIP | `wp_um_vip_users` | **DROP.** Ultimate Member absent, no folder. |
| JetEngine | `wp_jet_post_types`, `wp_jet_taxonomies` | **DROP.** Absent, no folder, and the CPT gate passes: the only registered post types are `post`, `page`, `attachment`, `product`, `share-cart-urls` (Share Cart URL) and `astra-advanced-hook` (Astra Pro) — none from JetEngine. |
| Groups | `wp_groups_*` (5) | **DROP all 5** (owner decision 2026-08-09: never used). Confirmed absent from the plugin list, so there is nothing to delete first. |
| Save/Share Cart | `wp_wcss_saved_cart`, `wp_wcss_shared_cart` | **DROP — verified.** A *different* plugin, "Share Cart URL for WooCommerce" 2.1.3, is installed, but its bootstrap was read and it does not own these tables: it prefixes everything `scuf_`, stores shared carts in the `share-cart-urls` **custom post type** with `update_post_meta`, and its `register_activation_hook` callback creates **options only** — no `dbDelta`, no `CREATE TABLE`, no `wcss` reference anywhere. `wp_wcss_*` belongs to the uninstalled "WooCommerce Save & Share Cart". |
| Woo File Dropzone (old upload flow) | `wp_woo_file_dropzone` | **DROP.** Absent, no folder; superseded by the Drive upload flow. Uploads in section D. |
| One of the two image optimizers | `wp_ewwwio_images` OR `wp_imagify_*` | **RESOLVED: Imagify 2.3.1 installed, EWWW absent → DROP `wp_ewwwio_images`, KEEP `wp_imagify_*`.** |
| Whichever forms plugins are redundant | `wp_frmt_*` (Forminator) / `wp_wpforms_*` / Elementor `wp_e_submissions*` | **RESOLVED: Forminator 1.56.2 is the only form stack installed. WPForms absent, Elementor absent → KEEP `wp_frmt_*`; DROP `wp_wpforms_*` and `wp_e_submissions*`/`wp_e_events`.** Note the two `*-addons-for-elementor` folders are orphaned directories (§D). |
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

> **Correction (2026-08-09 audit): Code Snippets is not installed** — absent from
> the plugin list and no folder on disk. So any `wp_snippets` table is orphaned
> and its snippets are **not executing**, which changes the handling: nothing in
> it is load-bearing *today*, but it may still describe behavior a past session
> relied on. Read its rows and copy anything meaningful into the repo per the
> CLAUDE.md server-patch rule **before** dropping. Do not bulk-delete unread —
> "not running" is not the same as "not worth reading."
>
> Of the active-plugin log tables listed above, these are confirmed relevant
> (owner plugin installed): `wp_actionscheduler_*` (WooCommerce),
> `wp_woocommerce_log`, `wp_woocommerce_sessions`, `wp_wpmailsmtp_debug_events`
> (WP Mail SMTP 4.9.0), `wp_aiowps_*` (All-In-One Security 5.4.9), `wp_wpr_*`
> (WP Rocket 3.18.3 — clear via the plugin), `wp_mwai_tasklogs` (AI Engine).
> **Moot — owner plugin absent, so these are §A drops, not truncates:**
> `wp_wpforms_logs`, `wp_wpai_request_logs` (WP All Import), `wp_e_events`
> (Elementor), `wp_mailchimp_carts`.

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

  > **Directory map (2026-08-09, read from the plugin source):**
  >
  > | Path under `wp-content/uploads/` | What it is | Verdict |
  > |---|---|---|
  > | `pps-artwork/` | current PPS artwork (`pps_artwork_dir()`) | thin old files |
  > | `pps-calculators/` | PPS general uploads (`PPS_UPLOAD_SUBDIR`) | thin old files |
  > | NBDesigner trees | plugin uninstalled | delete |
  > | Woo File Dropzone trees | plugin uninstalled | delete |
  > | WCPA upload dir | **Woo Custom Product Addons 5.5.0 is INSTALLED and live** | keep the directory, **thin aged files inside it** |
  >
  > **Correction — "pre-Drive WCPA upload dirs" is wrong as written, but only
  > about the directory.** WCPA is still installed and, per the CLAUDE.md
  > coexistence rule, owns the order flow for every non-registry product *today*,
  > so **do not delete the directory itself** (or its `index.php` / `.htaccess`
  > guards) and keep files belonging to recent or open orders. Aged art inside it
  > is a different matter and is safe to delete — this is where the gigabytes are.
  >
  > Verified 2026-08-09: **nothing in the PPS codebase reads legacy artwork
  > files.** In `pps_render_order_item()` the artwork-thumb meta is only read when
  > `$is_pps` is true, and legacy reorder (`pps_build_single_item_reorder_url()`)
  > re-adds the product with the frozen `pps_legacy_unit_price` without restoring
  > artwork. The only reference that can survive is a file URL inside WCPA's own
  > visible line-item meta, so the worst consequence of deleting old art is a dead
  > link in an old order view. Cosmetic, not a break.
  >
  > Use a date cutoff rather than trying to reason per-file: all WCPA/NBDesigner
  > era art predates the PPS calculators taking over (registry migration
  > 2026-07-19), so anything years old is dead weight.
  >
  > **`pps-artwork/` is safer to thin than this section implies.** The order-item
  > writer sets `_pps_artwork_on_drive = yes` whenever the local file is already
  > gone and keeps the path only as a Drive/reorder reference, so for PPS-era
  > orders Drive is authoritative and the local copy is redundant. The accepted
  > "reorder can't auto-restore artwork" consequence really only applies to
  > genuinely pre-Drive files.
  >
  > **Deriving the keep-list** for the ~90-day rule: referenced files appear in
  > order-item meta as `_pps_artwork_path` (relative, starts `pps-artwork/`) and
  > `_pps_artwork_files` (JSON array covering the full approval package). Build
  > the list from those, then delete everything in the directory that is not on
  > it.
  >
  > **Now doable from a Claude session** (as of MCP tools **v1.6.0**, deployed to
  > staging 2026-08-09). It previously was not: the file tools were scoped to
  > `wp-content/plugins` and the theme, and files with no attachment row are
  > invisible to the media tools — and staging has **5 attachments total** against
  > gigabytes of uploaded art, so essentially none of it was manageable.
  >
  > `priority-print-mcp.php` now also provides:
  >
  > | Tool | Use |
  > |---|---|
  > | `pps_uploads_list_files` | scope by `subdirectory`, filter by `min_age_days` / `min_size_kb`, order by size or age; reports `matched_total_bytes` for the full match so you can see the reclaim before deleting |
  > | `pps_uploads_delete_file` | one file |
  > | `pps_uploads_delete_batch` | up to 500 explicit paths per call |
  >
  > Explicit paths only — no glob or pattern deletion — and both delete tools
  > refuse directories, traversal, the `index.php`/`.htaccess` directory guards,
  > and any file still backing a media attachment. This is also what finally makes
  > §E's before/after MB measurable for the uploads tree.
  >
  > Still a Cloudways job: removing the now-empty **directories** themselves, since
  > these tools delete files only.
  >
  > **Automatic retention (MCP tools v1.7.0).** For art that keeps accumulating —
  > the WCPA tree especially — there is now a daily WP-Cron policy instead of a
  > manual pass: `pps_uploads_retention_get` / `_set` / `_run_now`. It deletes files
  > older than `min_age_days` (default **730**, i.e. 2 years) from **one** configured
  > uploads subdirectory, capped per run.
  >
  > It **ships off, with `dry_run` on and no directory**, so it is inert until
  > deliberately configured. Bring-up order:
  >
  > 1. `_set` the `directory` (and `min_age_days` if not 730) — leave `enabled` false.
  > 2. `_run_now` (dry run by default) and read `expired_count` / `expired_mb` /
  >    `samples` / `skipped`. Nothing is deleted.
  > 3. When the report looks right, `_run_now` with `dry_run=false` for the real sweep.
  >
  > Step 4 would be `_set enabled=true, dry_run=false` to let cron maintain it —
  > **but the owner decided on 2026-08-09 to keep this on-demand and leave the cron
  > off.** Don't enable it without asking; run it deliberately instead.
  >
  > Guards: refuses the uploads root (so a blank or `.` directory cannot cascade),
  > floors `min_age_days` at 30 so a typo cannot mean "2 days", skips directory
  > guards and media-attached files with a reason, and caps deletes per run —
  > remaining work resumes on the next run.
  >
  > **Behaviour-carrying options** (un-versioned, so they are written down here per
  > the CLAUDE.md server-patch rule):
  >
  > | Option | Holds |
  > |---|---|
  > | `pps_uploads_retention` | the policy: enabled, dry_run, directory, min_age_days, max_deletes_per_run |
  > | `pps_uploads_retention_log` | last run's report plus cumulative deleted count/bytes |
  >
  > Note WP-Cron only fires on traffic. On a quiet staging site the job may not run
  > on schedule — either use `_run_now`, or point a real Cloudways cron at
  > `wp-cron.php`. On production, normal traffic is enough.
  **Owner decision 2026-08-09: thin the files — delete the old upload trees
  outright. The owner retains offline archives going back years, so no
  pre-delete archiving step is needed.** Known consequence, accepted: a
  legacy reorder that references a deleted file won't auto-restore its
  artwork — the customer re-uploads, or the file is retrieved from the
  offline archive. Current-order artwork lives in Google Drive and is
  unaffected. Practical guidance for the executor: keep uploads referenced
  by orders from the last ~90 days (open/recent jobs), delete the rest of
  the legacy upload trees.
- Deactivated themes and deleted-but-present plugin directories.

  > **🛑 Correction (2026-08-09 audit) — do NOT delete Astra.** This bullet
  > originally read "Astra + child, anything not `pps-theme`". On staging today
  > both `template` and `stylesheet` are **`astra`** — Astra is the **active
  > theme**, `pps-theme` is not in use, and Astra Pro (`astra-addon`) is an
  > installed plugin registering the `astra-advanced-hook` Site Builder post
  > type. Deleting Astra would take the site down and drop whatever Site Builder
  > layouts exist. The original wording assumed the `pps-theme` migration had
  > already happened; it has not. Revisit this bullet only after `pps-theme` is
  > actually the active theme and the Site Builder hooks have been accounted for.
  >
  > **Deleted-but-present plugin directories — confirmed orphans** (folder on
  > disk, no matching entry in the installed-plugin list). These are the safe
  > §D deletions:
  >
  > | Folder | Note |
  > |---|---|
  > | `essential-addons-for-elementor-lite` | Elementor itself is gone |
  > | `premium-addons-for-elementor` | same |
  > | `multi-order-for-woocommerce` | |
  > | `offload-media-cloud-storage` | check no uploads are still served from a CDN/bucket URL before deleting |
  > | `uni-woo-custom-product-options-premium 4.5.3 ` | **note the spaces and the trailing space in the folder name** — quote it in any shell command |
  >
  > Do not touch `woo-custom-product-addons-pro` (WCPA — live, per the CLAUDE.md
  > coexistence rule), `astra-addon`, `spectra-pro`, `ultimate-addons-for-gutenberg`,
  > `share-cart-url-for-woo`, `priority-print-mcp`, or `pps-calculators`.
  >
  > Also present and shipping to production: stray `.DS_Store` files inside
  > third-party plugin folders (3 in `share-cart-url-for-woo` alone). Harmless,
  > and they return on plugin update, so not worth chasing.
- Image-optimizer backup originals (EWWW/Imagify keep pre-optimization copies
  in uploads) — safe to purge once optimization is accepted.
- Cache directories (`wp-content/cache/*`) — purge, they regenerate.
- Stray root/plugin files not in the repo (the 2026-08-01 audit list:
  `_pps_*.php`, `*.bak`, test files) — this is also a pre-push checklist item,
  but sweep it now.

### D-results. Uploads cleanup actually performed (staging, 2026-08-09)

| Scope | Before | After | Freed |
|---|---|---|---|
| **Entire uploads tree** | 46,750 files / 81,078 MB | 25,695 files / 35,498 MB | **21,055 files / 45,580 MB** |
| `wcpa_uploads/` | 7,114 / 59,273 MB | 2,445 / 22,045 MB | 4,667 / 37,228 MB |
| `nbdesigner/` | 18,719 / 8,373 MB | 2,331 / 20 MB | 16,388 / 8,353 MB |
| `pps-artwork/` | 27 / 60 MB | untouched | — |

**The mtimes in this tree are not upload dates.** Everything was re-stamped by a
bulk copy on **2024-09-29** (wcpa 22:09–22:13, nbdesigner 22:06–22:07), so no file
appeared older than ~678 days and a 730-day rule matched *nothing*. Filenames gave
it away (`MCV-2023-Trifold`, `Wedding-Booklet-2024`). The error direction was safe —
clone stamps make files look younger, never older — so `min_age_days=678` was used
as an exact proxy for "existed before the migration", i.e. a real upload date of
Sept 2024 or earlier. Counts at 670 and 678 were identical, confirming a clean gap
with nothing recent caught.

**Useful side effect: the retention policy is now semantically correct.** Every
migration-stamped file is gone, so everything left in `wcpa_uploads/` has a *true*
mtime. The "on 2026-09-29 the whole pre-clone set becomes eligible at once" cliff no
longer exists, and a plain 730-day rule now rolls naturally.

Left behind deliberately: `nbdesigner/` still holds 2,331 post-migration files
(20 MB, mostly optimizer-touched thumbnails) plus its `index.html` guard, and the
now-empty directory trees. Removing those is a Cloudways job — the tools delete
files, not directories.

**Policy left on staging (owner decision 2026-08-09: on-demand, no cron):**
`wcpa_uploads`, `min_age_days=730`, `max_deletes_per_run=500`, **`enabled=false`
(cron unscheduled, `next_run` null)**, `dry_run=true`.

The directory and threshold stay configured so an on-demand sweep is a single
`pps_uploads_retention_run_now` call. `dry_run` is parked back at `true` so that if
anyone ever flips `enabled`, the job starts by *reporting* rather than deleting —
and it costs nothing, because `run_now` already requires an explicit
`dry_run=false` to delete regardless of the stored value.

**Three defects found by running it for real** (not yet fixed — see PR discussion):

1. **No concurrency guard.** A client-side 60s timeout does not stop the PHP run;
   it keeps deleting. Starting another run then races the first. This inflated the
   skip count and made per-run accounting disagree with the observed tree delta
   (cumulative totals were still right).
2. **Misleading skip reason.** Files already removed by that racing run were
   reported as `unlink failed; check filesystem permissions`. **There is no
   permissions problem** — do not go hunting one. The message should distinguish
   "file vanished" from "permission denied".
3. **A DB query per file.** The media-attachment guard calls `get_posts()` for
   every candidate, which is what pushes large runs past 60s. It should pre-fetch
   the attached-path set once per run.

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
| ~~`wp_e_submissions` + `_actions_log` + `_values`~~ | **Skip — Elementor is not installed** (2026-08-09 audit; only orphaned addon folders remain). Nothing is collecting into these. Confirm the same on live, then drop them per §A instead of pulling. |
| `wp_frmt_form_entry` + `_entry_meta` | Forminator entries. **This is the only live form stack — pull these.** |
| ~~`wp_wpforms_payments` + `_payment_meta`~~ | **Skip — WPForms is not installed** (2026-08-09 audit). Drop per §A instead of pulling. |
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

## Decision record — push confirmed (owner, 2026-08-10)

The "push vs forward-deploy" question raised in
`docs/NEXT_SESSION_BRIEF_2026-08-10.md` §3 is resolved: **the wholesale
staging → production push stays the plan of record.** That makes the stale
posts-mirror cleanup below a REQUIRED pre-push step, not an optional tidy-up.

## Pre-push checklist (on staging, during the freeze)

The push sends staging's `wp_options` and files to production, so staging must
be production-configured before the button is pressed:

- [ ] **Stale posts-mirror orders cleared (REQUIRED — owner-side SQL).**
      Staging's `wp_posts` still holds ~4,015 `shop_order` + 161
      `shop_order_refund` rows from the legacy/sync era. Inert on staging now
      that HPOS is authoritative and compat sync is off — but a whole-database
      push sends them to production as phantom orders beside the real HPOS
      ones. In Cloudways DB manager, after the backup, with sync confirmed OFF:
      ```sql
      -- sanity: expect ~4,176 total
      SELECT post_type, COUNT(*) FROM wp_posts
        WHERE post_type IN ('shop_order','shop_order_refund') GROUP BY post_type;
      -- their meta first, then the rows
      DELETE pm FROM wp_postmeta pm JOIN wp_posts p ON p.ID = pm.post_id
        WHERE p.post_type IN ('shop_order','shop_order_refund');
      DELETE FROM wp_posts
        WHERE post_type IN ('shop_order','shop_order_refund');
      ```
      Do NOT touch `shop_order_placehold` rows if any exist (they reserve HPOS
      order IDs). Afterwards re-check `wp_posts` AUTO_INCREMENT is still
      ≥ 86,971 (deleting rows must not lower it; if a tool "optimized" it,
      re-run the ALTER from the auto-increment section). Order notes for the
      stale orders need no cleanup — `wp_comments` was replaced by the pull.
- [ ] **Administrator audit on BOTH sites (owner).** Staging shows 16
      administrators, including `fake_admin`, an unrecognised gmail at an
      adjacent ID, and vendor accounts for plugins that no longer exist
      (CmsMart/NBDesigner, unicpo, AcoWebs). Staging is a clone, so production
      almost certainly matches. Delete or demote everyone you can't name
      before the push (and treat production's copy of this list as a live
      security question today, not a go-live question).
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
   - [ ] **Run `docs/POST_PUSH_PATCHWORK_BRIEF.md` (Mode B).** Cloudways'
         push search-replace rewrites URLs but NOT email addresses, so
         `office@`/`orders@` mailboxes come across mangled to
         `…@woocommerce-70867-4915293.cloudwaysapps.com` (see the 2026-08-10
         aftercare record at the end of this file for the exact spots it
         hit). The brief is the executable version of that record — hand it
         to a fresh session with the production connector; its Mode A is
         the pre-push staging fix that prevents the leak at the source.
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

1. Gate 1: HPOS or legacy on live? **Partly answered — and it surfaced a new
   blocker.** Staging is HPOS with **compatibility sync ON**; see the Gate 1
   warning box. Still needs confirming on live, and one of the three listed
   resolutions chosen, before any pull.
2. Does Cloudways' pipeline support **table-selective pull** on this plan tier,
   and is the push all-or-nothing? (The runbook assumes selective pull,
   whole push.) **Still open — Cloudways-console question, not answerable from
   the WordPress side.** This is the assumption the whole plan rests on; check
   it first, because if the pull is all-or-nothing it would drag `wp_posts` and
   `wp_options` across and violate Gate 2.
3. Where are Rank Math redirects actually edited — live or staging? **Still
   open.** Rank Math keeps redirects in its own table, which no available tool
   can count, so this can't be settled from here.
4. Any tax/shipping config changes made on live since the last pull? **Still
   open** (owner knowledge). For reference, staging's
   `woocommerce_default_country` is `US:AZ`.

---

## Phase 0 execution status (2026-08-09)

**Nothing destructive has been run.** Phase 0's safety rail 1 requires a
Cloudways full backup of staging first, and taking Cloudways backups is outside
what this session can do — so every drop/truncate/delete above is still pending.
What *was* done is the read-only work that resolves the decisions, so the
eventual Cloudways session is mechanical rather than investigative.

### Done

- **Gate 1 re-verified against staging** rather than taken on trust — which is
  how the compatibility-sync problem surfaced. See the Gate 1 warning box; it is
  the one finding that can invalidate the pull plan.
- **§A verdict gate run for every row — all rows closed.** Installed plugins
  (31, active and inactive) and on-disk plugin folders (30) both enumerated, so
  every "drop if uninstalled" is now a definite DROP or KEEP. The last ambiguous
  row, `wp_wcss_*`, was settled by reading the installed share-cart plugin's
  bootstrap: it is `scuf_`-prefixed, CPT-backed, and creates no tables, so the
  `wcss` tables are a different plugin's orphans.
- **Two of the runbook's open choices decided:** Imagify is the optimizer to
  keep (EWWW absent); Forminator is the only live form stack (WPForms and
  Elementor both absent). The latter removes two tables from the freeze-window
  pull list.
- **Two instructions corrected that would have broken the site or lost data if
  followed literally:** deleting Astra (it is the *active* theme, not a
  deactivated one), and treating `wp_snippets` as live behavior (Code Snippets is
  uninstalled, so those snippets are inert — read them before dropping, but they
  are not running).
- **§D orphan-directory list resolved** to five confirmed-safe folder deletions,
  including one with a trailing space in its name that will bite any unquoted
  shell command.
- **Pre-push checklist item verified:** `blog_public = 1` on staging, so
  "Discourage search engines" is already OFF and the push will not carry a
  noindex to production. (Worth noting the flip side: staging is currently
  crawlable, which is its own SEO exposure — see `docs/SEO_CANNIBALIZATION_ANALYSIS.md`.)
- **Stray-file audit (pre-push checklist) came back clean for `pps-calculators/`.**
  Every `pps-*.php` on the server now exists in the repo, including the five
  files the 2026-08-01 audit flagged as agent-authored and un-versioned
  (`pps-intake.php`, `pps-home-probe.php`, `pps-shippo-test.php`,
  `pps-term-html.php`, `pps-login-brand.php`). They have since been brought home
  and several are registered plugins in their own right ("PPS Intake" 0.1.0
  etc.), so they are load-bearing — **do not delete them as strays.** The
  `pps-calculators.php.prehardening.bak` from that audit is gone. Note that
  `pps-calculators/` hosts several distinct registered plugins as sibling files,
  which is why the folder count (30) and plugin count (31) disagree.

### Deliberately not read

`woocommerce_stripe_settings` and `pps_gdrive` hold live API keys and OAuth
secrets. Verifying "Stripe in live mode" and "Drive folder IDs are production's"
means reading options that would pull those secrets into a transcript and risk
them reaching a commit or PR body, against the CLAUDE.md treat-the-repo-as-public
rule. **Both stay owner-verified in the Cloudways/wp-admin UI.**

### Blocked here, needs the Cloudways console

Everything requiring SQL or the pipeline: the `information_schema` size
measurements (so no before/after MB recorded yet), the autoload audit, every
`DROP`/`TRUNCATE`, the core-bloat cleanup, uploads thinning (§D — no filesystem
access outside `plugins/` and `themes/`, and it needs the 90-day order-reference
analysis), the live→staging pull, the auto-increment alignment, and the push
itself. No available tool runs SQL against this database.

---

## Post-push aftercare record (2026-08-10, production, executed via MCP)

The push happened 2026-08-09 evening. Claude-in-Chrome's frozen-state
verification returned STOP with blockers; all were diagnosed and fixed on
production the next morning. What was found and what future pushes must
expect:

### 1. Staging-domain email leakage (the big one — WILL recur on every push)

Cloudways' staging→production search-replace rewrites **URLs** but not
**email addresses** containing the staging domain. Production went live
with every contact mailbox mangled to `…@woocommerce-70867-4915293.cloudwaysapps.com`:

- **4 `wp_options`:** `woocommerce_email_from_address` (the From on every
  store email — deliverability killer), plus the recipient in
  `woocommerce_new_order_settings` / `woocommerce_failed_order_settings`
  (both **enabled** — new-order notifications were silently going to a dead
  address) and `woocommerce_cancelled_order_settings` (disabled).
- **6 content locations, 21 raw occurrences** (several duplicate into UAGB
  FAQ JSON-LD, so Google was served the staging email too): checkout page
  15372, privacy policy 17416 (×4), return/reprint policy 28850, mailers
  page 21027 (×3 incl. schema), Astra Site Builder "Footer Site
  Information" 85236 (×4 — the site-wide footer), Astra Site Builder
  "Product Page Bottom" 34095 (×8 incl. `mailto:` links + schema).

**Fix applied:** domain-restore preserving each local part
(`office@…cloudwaysapps.com` → `office@priorityprintservice.com`,
`orders@…` → `orders@priorityprintservice.com`), via `wp_alter_post`
search-replace + option rewrites. Verified zero occurrences remain.
Credit: a parallel claude.ai session with the production connector ran the
site-wide search that produced the complete location map, including the
Astra Site Builder post type a plain post search misses.

### 2. Other fixes in the same pass

- **Brochures category (term 81):** its description ended with a bare
  `[pps_cat_attributes]`, so the paper/attribute cards never rendered.
  Restored to `[pps_cat_attributes papers="text,cover"
  cover_label="Cardstock (Covers)" coatings="yes" addons="brochure"]`
  (mirrors booklets, addons switched to brochure).
- **`sitemap_index.xml` 404:** Rank Math's sitemap module was confirmed
  active, so the cause was stale rewrite rules; `rewrite_rules` was reset
  to force regeneration on the next request. (The step-8 permalink flush
  covers this when it's actually run.)
- **Build chip "missing" on product pages = FALSE ALARM.** The renderer
  extracts only styles + app code from calculator HTML and never emits the
  chip div; the chip exists solely on GitHub Pages previews. Verification
  criterion on WP pages is the new-build behavior itself (blue-dot paper
  picker, compiled marker in view-source), not the chip. The Chrome brief
  has been corrected.

### 3. Notes for the unfreeze decision

- Owner's real test order **86973** ($148.87 — the expected coupon figure)
  minted above customer order 86961: **order-ID sequencing above the copied
  range is proven, no collision.** Its status is *cancelled*, not
  *refunded* — if the card was actually charged, confirm the Stripe refund
  separately.
- Any order placed between the push and this fix had its new-order
  notification sent to the dead staging address — eyeball WooCommerce →
  Orders for anything unseen (86961, processing, 2026-08-09 17:01 UTC is
  the one to check).
- These fixes are DB-side; **WP Rocket still serves the old HTML** (the
  footer email is on every cached page) until a Clear-and-preload.

### 4. Later same-day findings (Shippo, sitemap, presets — 2026-08-10 midday)

- **Corrected timeline:** the push completed the evening of 2026-08-09 (the
  uniform 09:27 UTC file mtimes were a later file-level sweep, not the push).
  Test orders 86973 and 86982 were both placed on the NEW production.
- **Shippo order feed: never broken.** Shippo's WooCommerce store sync is
  poll-based; 86982 appeared in Shippo a few hours after placement. The WC
  REST key Shippo authenticates with survived the push because staging's
  `wp_woocommerce_api_keys` rows descend from the original live clone. Do
  not panic at a missing order until at least one sync cycle has passed.
- **Calculator→order→Shippo address chain confirmed end-to-end:** the
  calculator-collected address lands in the order's shipping envelope
  (verified on 86982) and arrives in Shippo with the order.
- **Shippo quote-side battery: 18/18 on production** (live token, real
  rates, guest loopback through priorityprintservice.com). Gotcha for
  future diagnosis: `pps_shippo_test_maybe_run()` calls
  `rocket_clean_domain()` when it runs — an 03:26 suite run purged Rocket
  BEFORE the morning's email/term fixes, which is why the late-morning
  re-test still saw stale footer/brochure pages. Varnish is disabled on
  this app; WP Rocket is the only page-cache layer.
- **Sitemap fixed:** the regenerated `rewrite_rules` now carry Rank Math's
  `sitemap_index\.xml` rule and `pps-presets-sitemap.xml`. The 404s seen
  in testing predate the regeneration.
- **Preset "loss" was a false alarm:** `pps_presets` contains exactly one
  row (letterhead) on BOTH sites — the preset system is built but content
  rows were never populated. One preset rule in `rewrite_rules` is the
  correct count. Populating presets is day-2 work.
