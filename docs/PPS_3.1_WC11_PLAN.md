# PPS 3.1 — WooCommerce 11 compatibility release (post-rollout)

**Positioning.** PPS 3.0 is the current rollout: modern calculators fleet-wide,
single-source paper catalog, category/shop restyle, coupon revisions, and the
staging → production go-live per `docs/GO_LIVE_RUNBOOK.md`. **3.1 is the first
post-rollout release**, and its centerpiece is the WooCommerce 11.0 update
(which bundles Action Scheduler 4.0) plus the compatibility and hardening work
it forces. Nothing in 3.1 starts until 3.0 is live and stable.

**Version freeze rule (binding during 3.0).** From the moment the go-live
order-table pull is taken until the push is verified, neither site takes a
WooCommerce, WordPress, or plugin update. WC 11 does schema work on update
(including restoring the `meta_key_value` index on `wp_wc_orders_meta`), and a
cross-version order-table copy is an avoidable variable in the one operation
whose only undo is a backup restore. Both sites ride their current WC 10.x
through go-live; WC 11 lands for both in 3.1.

---

## Entry criteria (all must hold before 3.1 starts)

- [ ] 3.0 go-live complete; production stable ≥ 1 week with no open sev-1/2
      issues (checkout, artwork upload, order emails all clean).
- [ ] Fresh Cloudways backups of both applications at 3.1 kickoff.
- [ ] Staging refreshed from production (post-go-live they should be near-twins;
      if orders have accumulated, a quick re-pull of the order tables per the
      runbook keeps tests realistic).
- [ ] Connector access to staging for the executing session.

## Phase A — Pre-update audit (staging connector, read-only)

1. **Version inventory**, recorded in this file when gathered:
   - WordPress core version (WC 11 ships WordPress 6.9 package versions —
     confirm core is 6.9-compatible or update core first): ______
   - PHP version on both apps (changelog fixes span PHP 8.1–8.5; we should be
     on 8.2/8.3): ______
   - Current WooCommerce version: ______
   - WCPA version: ______
2. **WCPA compatibility check — the one third-party unknown.** WC 11 removes
   the product block editor and block templates PHP API, with compatibility
   shims added specifically to avoid third-party fatals (#65500, #65503,
   #67138). WCPA is legacy and coexists with PPS by design. Grep the WCPA
   plugin source on staging for references to the removed APIs
   (`product_block_editor`, block template registration, the removed REST
   controllers). Shims should hold, but a fatal here takes down product pages —
   verify, don't hope.
3. **Snapshot the Scheduled Actions state** (counts by status and group,
   especially `pps-gdrive`) so post-update anomalies are measurable against a
   baseline.

## Phase B — Update on staging

1. Cloudways backup of staging immediately before.
2. Update WordPress core first if required by the Phase A inventory, then
   WooCommerce to 11.0.x. Note: **Action Scheduler 4.0 migrates with it** —
   after its schema migrates, rolling WC back does not cleanly roll AS back.
   The pre-update backup is the rollback for both.
3. Immediately after update:
   - [ ] Site loads; no fatals in the WooCommerce and PHP error logs.
   - [ ] **Flush permalinks** (Settings → Permalinks → Save). The per-preset
         root-level `/{slug}/` rewrite rules depend on it, and WC 11 also
         refreshes archive rules on shop-permalink changes (#65967).
   - [ ] WooCommerce → Status: no red flags; database update (if prompted)
         completed.

## Phase C — Compatibility test matrix (staging)

The matrix is ordered by exposure, worst failure mode first.

| # | Area | Why it's at risk | Test | Pass condition |
|---|---|---|---|---|
| 1 | **Drive artwork pipeline** | Runs on Action Scheduler (`pps_process_artwork_upload`, group `pps-gdrive`, 10-retry ladder) and AS jumped a major version. Failure is silent: order completes, file never lands. | Place an order with an artwork upload. Watch Status → Scheduled Actions. | File appears in the Drive order folder; the `pps-gdrive` action completes (not pending/failed pile-up); retry ladder untouched. |
| 2 | **Checkout, both address paths** | Classic cart/checkout churned in 11.0 (a script-blocking change shipped and was reverted in-release, #67363; place-order submission fix with shipping-address blocks, #65933). | Full checkout, once with billing-only, once via "Ship to a different address?". | Order places both ways; no console errors; place-order button submits first click. |
| 3 | **Single-address invariant** | #65633 lets *empty* session fields override prior values; #65544 stops saving `shipping_method` to customer meta — both sit on top of our add-to-cart address write-through (the "two ship-to addresses" fix). | Add to cart with a calculator address; open checkout logged-in and as guest. | Exactly one address shown, the calculator's; no blank field wins over a filled one; delivery date consistent. |
| 4 | **Cart page** | Our cart-collapse CSS fix lives on the classic cart; WC touched cart styling/quantity controls. | Load cart at 1180px / 768px / 375px with a coupon-book line. | Full-width form, product name on one line, no overflow. |
| 5 | **Edit Specs / reorder round-trip** | Order meta and HPOS internals moved (corruption-guard changes #66506/#66554/#66837 — relevant with Cloudways object cache). | Edit a coupon order (bind style, sheets, front/back); reorder a legacy order. | All fields restore; `_pps_*` meta intact; prices unchanged. |
| 6 | **Emails / receipt** | Email item-name alignment changed (#66141); our receipt is deliberately trimmed of internal meta. | Trigger order confirmation + admin new-order mail. | Receipt shows customer-facing spec only; no `_pps_*` leakage; PPS-Spec intact in admin/Missive copy. |
| 7 | **Preset URLs + SEO surface** | Rewrite/permalink handling changed; our schemas suppress core output on calculator-owned URLs. | Load 2 preset URLs + a calculator product page; view source. | Pages render, single set of PPS schemas, canonical/robots correct. |
| 8 | **Make.com order feed** | REST surface gained v4 endpoints; scenario must be indifferent. | Let the test order flow to the WooCommerce→Sheets scenario. | Row delivered, fields mapped. |
| 9 | **Category pages / shop** | No direct WC change expected — regression guard only. | Load /shop/ + one category with wizard. | Masthead, wizard, paper cards with dots/specs, attributes below grid. |

## Phase D — New default-on behavior: decisions and posture

WC 11 turns things on. Each needs an explicit posture, not a default drift.

1. **Point of Sale is now always-on** (#65573): settings page, product
   visibility controls, transactional emails, and a **catalog feed generated
   into uploads via Action Scheduler** (#65860, #65563).
   - Audit what it scheduled and what it wrote to `wp-content/uploads`.
   - If the feed can be disabled, disable it — PPS sells via calculators, not
     POS, and we just de-bloated uploads. If not disableable, add the feed
     directory to the uploads-retention notes so it never reads as mystery
     bloat, and exclude it from any future cleanup sweeps (it self-regenerates).
2. **Cancelled-order admin emails now fire for pending → cancelled** (#65855).
   PPS parks orders in pending during proof review; staff cancelling stale
   pendings will now generate admin mail. **Owner decision:** keep (visibility)
   or suppress via the standard email toggle. Default if no answer: keep for
   one month, then decide with data.
3. **Abandoned-cart recovery** (#66393) is behind an off-by-default flag —
   **verify it is off and keep it off.** A PPS order legitimately sits pending
   during proofing; a "finish your checkout" nudge to someone who already
   ordered is corrosive. Revisit only if a true pre-payment abandonment segment
   is identified (and then scope it to carts, never proof-stage orders).
4. **Customer email verification + guest-order linking** (#65822, #65971)
   duplicates part of `[pps_order_lookup]`. **Owner decision:** which is the
   customer-facing story? Recommended: let core verification exist (it only
   activates when a shopper confirms email ownership), keep `[pps_order_lookup]`
   as the promoted path for guests, revisit consolidation in 3.2. No code
   change in 3.1 either way.
5. **Backorder notification setting** (#65891), POS staff capabilities
   (flagged off), Settings UI experiments: no action — confirm flags/settings
   sit at defaults and move on.

## Phase E — Hardening riders (small, made timely by this update)

1. **Artwork-upload terminal-failure alert.** The AS 4.0 bump is exactly when
   the silent failure mode would bite. After the 10th failed
   `pps_process_artwork_upload` attempt, add an order note **and** an admin
   email/Missive ping. An invisible failure in the one pipeline production
   depends on should page a human. (Small addition in `pps-gdrive.php`,
   repo-first, deployed pinned.)
2. **Uploads-directory protection re-audit.** WC 11 adds a Site Health check
   for uploads protection (#65757). Cross-check against the 2026-08-01
   incident: the magic-byte validation and `.htaccess` execution guard that
   were lost to a whole-file deploy. Confirm whether any later session restored
   them to the repo; if not, 3.1 is where they come home — this time
   repo-first, per the CLAUDE.md rule they inspired.
3. **Structured-data suppression spot-check.** WC 11 changed core Offer
   emission for variations (#66043). Our `pps_is_calculator_owned_url()`
   suppression should make this moot on calculator pages — verify one page's
   source to confirm core's Product schema still doesn't leak through.

## Phase F — Production update

Only after C and D are green on staging.

1. Low-traffic window; Cloudways backup of production.
2. Same sequence as Phase B (core first if needed → WC 11 → permalink flush).
3. Re-run matrix rows 1, 2, 3, 6, 8 against production (the cheap, high-value
   subset — including one real order, refunded after, per the go-live runbook
   pattern).
4. Watch for 48 hours: Scheduled Actions health, error logs, inbox for
   unexpected POS/cancelled-order mail.
5. Rollback: restore the Phase F backup. Do not attempt a WC-only version
   rollback after the AS/HPOS schema has migrated.

## Exit criteria

- [ ] Both sites on WC 11.0.x, same version, permalinks flushed.
- [ ] All matrix rows green on staging; subset green on production.
- [ ] Phase D postures recorded (each either decided by owner or default noted).
- [ ] Phase E riders shipped (or explicitly deferred to 3.2 with a line here).
- [ ] Version inventory table above filled in.
- [ ] This file updated with outcomes; anything surprising written into
      CLAUDE.md if future sessions must know it.

## Explicitly out of 3.1

Blocks cart/checkout migration, Store API adoption, v4 Orders/Refunds API
adoption in Make.com, the WCPA→PPS consolidation, guest-lookup consolidation
(3.2 candidate), and any pricing or calculator feature work — 3.1 is a
compatibility release; it ships boring.

## Known-inapplicable WC 11 changes (checked against our stack, for the record)

Blocks/Store API checkout fixes (classic checkout here), decimal stock and
backorder logic (virtual products, PPS owns availability), the tax-inclusive
price-calculation revert (#67161; not our tax configuration), reserved
order-item meta keys (#65471; `_pps_*` keys are written programmatically, not
via the admin Add-meta button), `woocommerce_cart_item_product` fatal guard
(we don't hook it), `product_shipping_class` going private (virtual products),
SKU duplication, geolocation HTTPS switch, admin a11y/contrast work, and the
Settings UI/Design-System churn (our admin screens are custom).
