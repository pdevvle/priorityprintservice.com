# EXECUTE: Staging→Production push patchwork (run on EVERY future push)

You are the executing session. This brief exists because the 2026-08-09
go-live push shipped a set of predictable defects that were diagnosed and
fixed by hand on 2026-08-10 (full record: end of `docs/GO_LIVE_RUNBOOK.md`).
Cloudways' pipeline will do the same things again on the next push. Your job
is to run the same patchwork without re-diagnosing it. Do not re-litigate;
do not ask permission for steps listed here; owner-marked steps get said
plainly in chat because **the owner does not read briefs**.

## The one-paragraph why

Cloudways' staging→production search-replace rewrites **URLs** but not
**email addresses**, so every mailbox that was domain-mangled on staging
(`office@…`, `orders@…` at the staging domain) ships to production intact
and broken: the store's From address fails SPF/DKIM, new-order
notifications go to a dead mailbox, and the site-wide footer + FAQ schema
show customers (and Google) a `cloudwaysapps.com` email. On 2026-08-10 that
was 4 `wp_options` + 21 occurrences across 6 content locations. Separately:
rewrite rules ship stale (sitemap 404s until flushed), page caches ship
stale, and the push faithfully carries every content gap staging had.

## Two modes — know which you are in before any write

- **MODE A — pre-push prevention, on STAGING**, days or hours before the
  freeze. Fix the emails at the source so the push ships clean data.
- **MODE B — post-push sweep, on PRODUCTION**, inside the freeze window,
  after the push and before unfreeze. Verify (or fix, if Mode A was
  skipped) and run the rest of the patchwork.

Run Mode A when a push is scheduled; run Mode B always. If Mode A ran,
Mode B's email sweep should find zero — run it anyway; it is the proof.

**Hard gate before any write, both modes:** `wp_get_option` key `siteurl`
on the connector you loaded. Mode A requires the Cloudways staging URL;
Mode B requires `https://priorityprintservice.com`. Wrong answer → stop,
you have the wrong connector. Connectors flap — re-ToolSearch, or ask the
owner for a fresh chat. Expect the push to have invalidated the production
connector's application password (it lives in `wp_usermeta`, which the push
replaces): if production tools 401, tell the owner to re-provision and
re-auth `PPS_PRODUCTION_AI_ENGINE`.

**Never read** `wp_mail_smtp`, `woocommerce_stripe_settings`, `pps_gdrive`,
raw `pps_calc_config` (live credentials — treat the transcript as public).
**Never write** `wp_rocket_settings` or `pps_tooltips`.

**The staging domain string:** in 2026 it is
`woocommerce-70867-4915293.cloudwaysapps.com`. If staging has been
rebuilt since, the number changes — read `siteurl` on the staging
connector for the current value and sweep for THAT. A paranoia search for
bare `cloudwaysapps.com` catches historical strays.

---

## MODE A — pre-push email fix on staging

⚠ **Never blanket-replace the staging domain on staging.** `siteurl`,
`home`, and in-content media URLs legitimately carry it there. Fix ONLY
email-address contexts, with a regex that captures the local part:

1. Find: `wp_get_posts` with `search` = the staging domain, run once per
   post type: `page`, `post`, `product`, **`astra-advanced-hook`** (Astra
   Site Builder — the site-wide footer and product-page-bottom blocks live
   here and a default search MISSES them), `wp_block` (reusable blocks).
2. For each hit, `wp_alter_post` on `post_content` with `regex: true`,
   search `([A-Za-z0-9._%+-]+)@woocommerce-70867-4915293\.cloudwaysapps\.com`
   (substitute the current staging domain), replace
   `$1@priorityprintservice.com`. This preserves each mailbox's local part
   and touches nothing but emails.
3. Options (read each array first, change ONLY the named key, write the
   full array back):
   - `woocommerce_email_from_address` (scalar) → `orders@priorityprintservice.com`
   - `woocommerce_new_order_settings` `.recipient` → `office@priorityprintservice.com`
   - `woocommerce_failed_order_settings` `.recipient` → `office@priorityprintservice.com`
   - `woocommerce_cancelled_order_settings` `.recipient` → `office@priorityprintservice.com`
4. Note for the record: a future re-clone of staging from production will
   re-mangle all of these. Mode A is per-push, not one-time.

## MODE B — post-push sweep on production

On production a **blanket literal replace is safe** — nothing on the live
site may legitimately reference the staging domain.

1. **Search everything** (the 2026-08-10 map came from exactly this sweep):
   `wp_get_posts` `search` = staging domain × post types `page`, `post`,
   `product`, `astra-advanced-hook`, `wp_block`; the four email options
   above plus paranoia reads of `woocommerce_email_from_name` and
   `woocommerce_stock_email_recipient`; `pps_get_sidebars_widgets` then the
   `widget_text` / `widget_custom_html` / `widget_block` option rows for
   any widget in use; `pps_get_custom_css`; nav menus if anything looks off.
2. **Fix content:** per hit post, `wp_alter_post` on `post_content`,
   literal (no regex): search = staging domain, replace =
   `priorityprintservice.com`. It replaces all occurrences and reports the
   count — including the copies UAGB duplicates into FAQ JSON-LD and
   `mailto:` hrefs.
3. **Fix the 4 options** exactly as in Mode A step 3.
4. **Verify:** re-run the searches — every one must return zero; re-read
   the options. The known 2026-08-10 locations, for orientation (post IDs
   survive the push since the DB moves wholesale — but trust the SEARCH,
   not this list, content moves): checkout 15372, privacy 17416, returns
   policy 28850, mailers 21027, Site Builder "Footer Site Information"
   85236, "Product Page Bottom" 34095.
5. **Rewrite rules:** `wp_update_option` key `rewrite_rules` value `""`
   (empty string). This is the MCP equivalent of Settings → Permalinks →
   Save; rules regenerate on the next page load with the preset slugs and
   Rank Math's `sitemap_index.xml` included. Do it even if the owner says
   they saved permalinks — it is free.
6. **`blog_public` must be `1`.** If staging was ever set to discourage
   crawlers, the push carries noindex to production. One read; fix if 0.
7. **Notification gap:** `pps_woo_list_orders` (`status: any`) and note
   every order created between the push timestamp and your option fix —
   their new-order notifications went to the dead staging mailbox. Name
   them to the owner in chat so nothing goes unseen/unprinted.
8. **Category attribute audit:** `pps_woo_get_category` for `booklets` and
   `brochures` (and any category that has since adopted the section) — the
   description must end with a fully-parameterized shortcode, e.g.
   brochures: `[pps_cat_attributes papers="text,cover"
   cover_label="Cardstock (Covers)" coatings="yes" addons="brochure"]`
   (booklets same with `addons="saddle"`). A bare `[pps_cat_attributes]`
   renders nothing. This is not a push artifact — the push ships staging's
   content gaps faithfully — but post-push is when it gets caught.
9. **Do NOT flag a missing build chip.** WP product pages never render
   one; the chip exists only on GitHub Pages previews. Freshness on WP
   pages = blue-dot paper picker + `tools-compile-calcs.mjs` marker in
   view-source, no `babel.min.js`.

## Owner steps — SAY THESE IN CHAT, in this order, when Mode B is done

1. **WP Rocket → Clear cache and preload.** Every fix above is
   database-side; cached pages keep serving the staging footer email until
   this runs. Nothing you did is visible before it.
2. **WP Mail SMTP:** confirm it is un-muted (staging keeps it
   Do-Not-Send; pushed as-is, production sends no email at all), confirm
   its From Email is not a staging address, send a test email. (You cannot
   read that option — it can hold API keys. Owner's eyes only.)
3. **Stripe must be in live mode** — owner-verified in the dashboard/
   checkout, same never-read rule.
4. If you hit 401s: re-provision the `PPS_PRODUCTION_AI_ENGINE`
   application password (the push replaced `wp_usermeta`).
5. Hand `docs/CHROME_LIVE_VERIFICATION_BRIEF.md` to Claude-in-Chrome for
   the frozen-state verification, with the current order-ID threshold
   (highest existing order + 1, from `pps_woo_list_orders`).

## Record it (same session, not later)

Append a dated aftercare record to `docs/GO_LIVE_RUNBOOK.md` — what the
sweep found (locations + counts), options before/after, orders in the
notification gap, anything new this brief doesn't cover — commit and push
it on the working branch. If this push surfaced a NEW recurring defect,
add it to this brief in the same commit. A patchwork that only exists in a
chat transcript is one push away from being re-diagnosed from scratch.

## Report format

Mode + siteurl proof; search hit map with counts; replacements applied
(post ID → count) and the zero-result verification; options before/after;
`blog_public` value; rewrite reset done; notification-gap order IDs;
category audit verdicts; owner steps stated; runbook record commit SHA.
