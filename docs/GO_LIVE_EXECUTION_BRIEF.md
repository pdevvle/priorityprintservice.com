# EXECUTE: PPS 3.0 go-live — TODAY

You are the executing session. The owner has authorized taking staging live
**today, now** — do not re-litigate decisions, do not ask permission for steps
listed here, do not defer work that this brief assigns to you. Everything
below was decided and verified across prior sessions; your job is execution.
Owner is present and will do the owner-marked steps when you say go.

**Preconditions you need:** the `PPS_STAGING_AI_ENGINE` connector. First call:
`mcp_ping`, then `wp_get_option` key `siteurl` — proceed only if it returns
the Cloudways staging URL (`woocommerce-70867-4915293.cloudwaysapps.com`).
If tools are missing, ToolSearch again; connectors flap. The sandbox cannot
reach either site over HTTP — verify through the connector only.

Read `CLAUDE.md` if you need background. `docs/GO_LIVE_RUNBOOK.md` is the
authority for anything ambiguous here. Do NOT read `pps_gdrive`,
`woocommerce_stripe_settings`, or raw `pps_calc_config` (live credentials).
Never write `wp_rocket_settings`. Don't touch `pps_tooltips`. Nothing deploys
to PPS-Production tools at any point — the "push" is the owner's Cloudways
button.

---

## PHASE 1 — Deploy the pending fix set to staging (you, ~10 min)

Every "unchanged" defect in the final QA re-test is unchanged because THIS
deploy never ran. It is all pinned and verified.

**A. Five PHP files @ integration-branch commit `4ab317c`** — via
`pps_plugin_download_url`, url
`https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/4ab317c/<file>`,
relative_path `pps-calculators/<file>`:

1. `pps-calculators.php` (296KB+ — billing prefill fix, /pps/v1/nonces, cache-purge hooks)
2. `pps-config-admin.php`
3. `pps-presets-admin.php`
4. `pps-reorder.php` (PII stripped from URL payloads)
5. `priority-print-mcp.php` (v1.9.0 — includes read-only `woo_get_order_meta`)

**B. Eight COMPILED calculators from `pps-pricing-config` commit `2df6543`**
— NOT from the integration branch (integration carries JSX source; compiled
pages have no in-browser Babel and go interactive in ~0.3s vs ~5-6s). Same
tool, url
`https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/2df6543/<file>`,
relative_path `pps-calculators/_pending_html/<file>`:

`calc-preview-test.html`, `calc-perfect-bound.html`, `calc-coupon-book.html`,
`calc-brochure.html`, `calc-postcard.html`, `calc-letterhead.html`,
`calc-greeting-card.html`, `calc-sticker.html`

**C.** `mcp_ping` (fires the HTML deploy hook). Then `pps_plugin_list_files`
on `pps-calculators/_pending_html` — all 8 must have moved into a fresh
`_archive/<timestamp>/`; pending root retains nothing. Confirm the deployed
byte counts matched the fetches.

**D. Tell the owner:** "Phase 1 deployed — do your Rocket step now." (Owner:
WP Rocket → File Optimization → uncheck Delay JavaScript execution; Media →
uncheck Automatic lazy rendering; save; **Clear and preload cache**. The
cache clear also flushes the old Babel pages.)

## PHASE 2 — Staging verification (you + owner, ~20 min)

**You:**
- `woo_get_order_meta` on a recent pulled production order (owner can give an
  ID; otherwise `pps_woo_list_orders` and pick a recent one): confirm
  `PPS-Spec` / `_pps_*` meta is present and readable. This closes the last
  open question about the order pull.
- `wp_get_option` `blog_public` — must be `1`.
- `pps_woo_list_orders`: identify QA test orders (IDs 86971+, small totals,
  tester name) and set each to `cancelled` via `pps_woo_update_order_status`
  so they don't launch as live orders. List what you cancelled.

**Owner (the GATE — clean browser profile, incognito, extensions off):**
- Any calculator: responds to the FIRST click, no ~30s stall, no blank
  sections on accordion toggles, no 50lb paper, blue dots + spec labels show.
- Preset→Custom from 5.5×8.5 shows Width 5.5 / Height 8.5.
- Add to cart with a full address → checkout shows THAT address prefilled
  (not empty, not Arizona).
- Coupon math: $157.53 → −$8.66 → $148.87 holds product→cart→checkout.
- Cart "Proceed to checkout" navigates.

If the gate fails on anything, STOP and report — do not proceed to the push.

## PHASE 3 — Pre-push staging prep (~20 min)

**Owner, Cloudways DB manager — REQUIRED stale-order cleanup:**
```sql
SELECT post_type, COUNT(*) FROM wp_posts
  WHERE post_type IN ('shop_order','shop_order_refund') GROUP BY post_type;
-- expect ~4,015 + ~161. Then:
DELETE pm FROM wp_postmeta pm JOIN wp_posts p ON p.ID = pm.post_id
  WHERE p.post_type IN ('shop_order','shop_order_refund');
DELETE FROM wp_posts WHERE post_type IN ('shop_order','shop_order_refund');
-- do NOT touch shop_order_placehold. Then confirm:
-- wp_posts AUTO_INCREMENT still >= 86971 (information_schema or SHOW TABLE STATUS)
```

**Owner, staging wp-admin:** Users → delete/demote every administrator you
can't name (`fake_admin`, the stranger gmail, CmsMart/unicpo/AcoWebs vendor
accounts, stale freelancers). Staging's user table BECOMES production's at
push — this one pass cleans both sites.

## PHASE 4 — The freeze window (owner drives, you verify; 45–60 min)

Order is mandatory. Owner actions unless marked.

1. Maintenance/freeze live checkout (lowest-traffic moment available).
2. **Cloudways full backups of BOTH applications.** This is the only rollback.
3. **Fresh pull of the order tables live → staging** (yesterday's pull is
   stale; any order placed on live since then would otherwise be destroyed by
   the push). Same table list as the runbook: wp_wc_orders, wp_wc_orders_meta,
   wp_wc_order_addresses, wp_wc_order_operational_data,
   wp_woocommerce_order_items, wp_woocommerce_order_itemmeta, wp_comments,
   wp_commentmeta, wp_users, wp_usermeta, payment tokens ×2, wdr ×2,
   wc_order_stats + 4 lookups, plus the form-entry tables if the owner wants
   them (Forminator is the live stack).
   ⚠ The users pull just re-imported production's admin set — owner re-runs
   the admin cleanup from Phase 3 (it's 2 minutes the second time).
4. Auto-increment re-align (owner, SQL): set `wp_posts` and `wp_wc_orders`
   AUTO_INCREMENT to (highest live order ID + 10).
5. **You:** `woo_get_order` the newest live order ID (owner reads it from
   live admin) — it must open intact on staging with items + notes. Also
   `woo_get_order_meta` it: PPS-Spec present if it was a calculator order.
6. Owner, on staging: Stripe to **LIVE keys/mode**; WP Mail SMTP **un-mute**
   (it is currently Do-Not-Send — pushed as-is, production would send no
   email). These flip only now, inside the freeze, so no tester can transact.
7. **PUSH** staging → production (Cloudways, files + database).
8. Owner, on production wp-admin: Settings → Permalinks → Save (preset URL
   rewrite rules); WP Rocket clear cache.
9. **Live verification** (owner browser; you assist if the PPS-Production
   connector holds — it flaps, don't block on it):
   - Homepage, /shop/, a category page, a calculator page render; build chip
     reads `BUILD 2026-08-09 · MODERN`; first click responsive.
   - **Place one small real order** (real card): new order ID lands ABOVE the
     copied range; confirmation email arrives; Drive folder gets the artwork;
     PPS-Spec on the order; Make.com sheet row appears. Then refund it.
   - robots.txt / sitemap reachable; site is indexable.
10. Unfreeze. Watch error logs + Scheduled Actions for the first hour.
11. Aftercare: the push replaced production's connector-auth rows — the
    PPS-Production connector will need re-authorizing; likewise re-check any
    application passwords. Note it, don't fight it during the window.

## Known-deferred (do NOT attempt today)

Per-product PPS Defaults seeding, catalog "From" prices
(`tools-from-prices.mjs` plan), Reorders/WCPA duplicate fields, Express
Checkout wallet test, Shippo token rotation decision, `nbdesigner/` empty
dirs, retention cron (stays OFF).

## Report format

Phase-by-phase: deployed byte counts + archive stamp; order-meta smoke result;
cancelled test orders; gate verdict; freeze-window timeline with the test
order ID and its verification results; anything skipped and why.
