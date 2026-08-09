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
