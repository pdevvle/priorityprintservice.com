# Claude-in-Chrome brief — verify the LIVE site during the go-live freeze

You are Claude running in the owner's Chrome, verifying **production
(priorityprintservice.com)** immediately after the staging→production push,
**while the site is still frozen** and before customers are let back in. The
owner is beside you. Your job: run this checklist fast (~15 min), report
pass/fail per item with a screenshot of anything red, and STOP the moment you
hit a hard failure — the owner decides rollback, not you.

## Rules

- You verify; you don't fix. **No wp-admin settings changes, no plugin
  toggles, nothing destructive.**
- **Never enter payment card data.** The one real test order is the owner's
  hands on the keyboard; you verify before and after it.
- Expected oddities of the frozen state: checkout may be blocked/maintenance
  for the public until the owner unfreezes — if a page says so, that's
  correct, note it and continue. Stripe must say **live** — if you see "test
  mode" at checkout, that is a **hard failure** (flag immediately; it means a
  pre-push flip was missed and real customers wouldn't be charged).
- Known-benign, do not report as defects: `analytics.google.com/g/collect`
  returning 503; the "JQMIGRATE" console notice.

## 1. Render sweep (hard failures if any page is broken)

Open each; confirm it renders styled, no white screen, no PHP errors:

- `/` (homepage), `/shop/` (modern product cards, masthead)
- `/product-category/booklets/` and `/product-category/brochures/` — masthead
  → wizard → product cards → paper/attribute cards below the grid. Note the
  load feel: these two have a known ~1.8s history; just record slow/fast.
- Two calculator product pages (e.g. the 8×8 booklet and a brochure) and one
  preset URL if the owner names one.

**On each calculator page — the single most important check of this brief:**
- The **calculator itself renders**: "INSTANT QUOTE" heading, form sections,
  and a real dollar Estimated Total. An empty area under the product title =
  **hard failure** (this exact defect shipped twice on staging; the fix is in
  this push, and this is its production proof).
- **Do NOT look for a build chip** — WP product pages never render one (the
  PHP extracts only styles + app code; the chip exists solely on the GitHub
  Pages previews). Its absence is correct, not a defect. Freshness is proven
  by the new-build behavior itself: blue-dot paper picker, spec labels, and
  (if in doubt) view-source containing `tools-compile-calcs.mjs` with no
  `babel.min.js` include.
- Click something immediately — first click responds, no multi-second stall.
- Open the Paper dropdown: blue dot 🔵 on stocked papers, specs in
  parentheses like "(5pt · 104gsm — Best for writing)", **no** "50lb Offset
  Smooth Opaque".
- Scroll: the floating total/Add-to-Order bar must NOT be visible at page
  top; it appears only after you scroll **past** the price panel.
- Console: no red errors — specifically no `React is not defined`, no
  `ChunkLoadError`.

## 2. Order path up to payment (one pass)

1. On a booklet calculator: set a size, pick a paper, enter a full shipping
   address (owner's own) with ZIP, note the Estimated Total.
2. Apply discount code `501c32024` — total drops 5.5%, a "YOU PAY" figure
   appears alongside the pre-discount Estimated Total.
3. Add to Order → cart:
   - Line item shows the configuration; totals match; coupon shows −5.5%.
   - The coupon field in the cart is **visible** without clicking anything.
   - Right-click/copy the "View/Edit Specs" link: it must be a **short**
     `?cart_key=<token>` URL. If you see a long base64 blob containing a
     name/street — hard failure (PII regression).
   - Click **Proceed to checkout** — it must navigate on the first click.
4. Checkout:
   - The address you typed in the calculator is **prefilled**; State shows
     your entry, not Arizona, not empty-after-entry.
   - Coupon figures carried over; Stripe card element mounts; **"live" not
     "test"**.
5. **Stop and hand to the owner** for the real card entry. After they submit:
   - Order-received page shows; note the order number — it must be **at or
     above the threshold the owner gives you** (~86,98x). A number below it =
     hard failure (ID collision).
   - Owner checks: confirmation email arrived; the order appears in wp-admin
     with a PPS-Spec; artwork (if uploaded) reached Google Drive; the
     Make.com sheet gained a row. You just record their answers.
   - Owner refunds the order in admin. Done.

## 3. SEO/infra spot-checks (30 seconds each)

- View-source on a calculator product page: exactly one set of Product
  schema; a canonical URL pointing at priorityprintservice.com (not the
  cloudwaysapps staging domain — staging-domain leakage anywhere in source is
  a hard failure).
- `/robots.txt` loads and does NOT disallow everything; no
  `<meta name="robots" content="noindex">` on the homepage.
- `/llms.txt` loads. `https://priorityprintservice.com/sitemap_index.xml`
  loads (Rank Math; rewrite rules were reset 2026-08-10 to fix its 404 — if
  it still 404s after a WP Rocket clear, report it).
- One old URL spot-check if the owner has one handy (301s to the new slug).

## 4. Report

A short table: item → PASS/FAIL/NOTED, screenshots for every FAIL, and a
one-line verdict: **"safe to unfreeze"** or **"stop — <reason>"**. Hard
failures anywhere in §1's calculator checks, test-mode Stripe, PII links,
staging-domain leakage, or an order-ID collision mean "stop." Slow archives,
GA 503s, and cosmetic notes do not block unfreezing — list them for day-2.
