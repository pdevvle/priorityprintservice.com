# ChatGPT for Business — brief for a dedicated session

Written 2026-11-11, the day after OpenAI opened **Businesses in ChatGPT** (beta, 2026-11-10).
Owner's goal: **PPS as an early adopter** — be claimed, fed and measurable in ChatGPT before
other print shops are. Read this whole brief, then `CLAUDE.md`, before doing anything.

---

## 1. What exists outside our control (as of 2026-11-11)

Mostly from press coverage of a two-day-old beta plus OpenAI's developer docs. **Section 6 lists
what to re-check against openai.com first** — do not build on a claim that is only in a vendor blog.

| Thing | What it is | PPS relevance |
|---|---|---|
| **Business Hub** (`chatgpt.com/business-hub`) | Free. Claim the business, verify (business phone, business email, DNS TXT, or Shopify), then a **Persona identity check of a person** before anything can be published. Editable: website, description, category, logo, cover photo, delivery policy, return policy (OpenAI reviews edits). **A published profile cannot be unpublished.** | The front door. Owner-only step (identity). |
| **Product feed** | CSV or JSONL, OpenAI's spec: 9 required fields — `item_id`, `title`, `description`, `url`, `brand`, `seller_name`, `image_url`, `price`, `availability`; ~40 optional (`product_category`, `shipping`, `return_policy`, `accepts_returns`, `return_deadline_in_days`, `group_id` + `variant_dict` for variants, `is_eligible_search` default true, `is_eligible_checkout` default off). One `price` per row (no ranges). US upload by default. | Our "from" prices fit; our products are configurable, so each row is a landing page with a from-price, not a SKU. |
| **Insights** | 28 days: mentions (ChatGPT referencing the website), website clicks, discovery topics; hidden below a threshold. No access to chats. | The measurement we cannot get anywhere else. |
| **Business Agents** | US ecommerce / online-service businesses with a website; reviewed separately. A labelled chat on the business profile answering product/policy questions from what the business supplies and its written guidelines. **Cannot buy, book, or look up orders.** Must say it is AI, must not invent facts or pressure. | Second step after the claim. |
| **Instant Checkout / ACP** | Buying inside ChatGPT, launched 2025 — reported wound down in early 2026; OpenAI now steers apps to **external checkout** (merchant's own site). | Skip. A job with artwork, proof and a delivery date does not belong in a chat checkout. |
| **ChatGPT apps** (Apps SDK, MCP) | A business's own tool inside ChatGPT. | Optional later phase (§4 phase 4). |
| **Ads** | Separate, paid. | Not until Insights shows volume. |

## 2. What PPS already has (verified 2026-11-11)

- **Crawlers allowed.** `OAI-SearchBot`, `ChatGPT-User` and `GPTBot` all get 200 on a calculator
  page; `robots.txt` blocks only `/wp-admin/`. Cloudflare sits in front — re-check this if it ever
  changes.
- **`/llms.txt`** (in `pps-calculators.php`) and per-page Product / LocalBusiness / FAQ JSON-LD.
  The public contact email on all of them is Office@ (2026-10-27) — keep it that way.
- **A Google Merchant feed**, live: `/pps-product-feed.xml`, **32 items**. Code: `pps-product-feed.php`
  (live 22,793 bytes = `claude/design-it-online` HEAD of that file, `32126d5`; **not on this branch**),
  rows from `pps_catalog_report()` in `pps-catalog.php` (live 15,324 bytes, same branch). Prices come
  from `pps_product_price_facts()` (`pps-calculators.php` ~line 5424) — the SAME resolver the product
  schema uses, so feed and page cannot disagree. Read the file's header comment: it explains why.
- **Preset pages** (`wp_options['pps_presets']`, `/{slug}/`) — the best landing pages; already in the
  sitemap and `/llms.txt`.
- **Pre-filled calculator links** — the calculators read `?qty=&pages=&size=…` and the reorder
  config; a link can open a priced job.
- **A customer-service assistant** (`pps-assistant.php`) — its knowledge and tone are a starting
  point for the Business Agent's instructions.
- **Production Analytics** (`pps-analytics.php`) — the place for a "from ChatGPT" figure.

## 3. Rules for this work (from `CLAUDE.md`, restated because they bite here)

- The repo is **public**: no credentials, no internal pricing strategy. The feed's public "from"
  prices are already public (Google feed, product pages) — fine. No owner personal email anywhere.
- Code goes to git first, deployed pull-based by pinned SHA (`pps_plugin_download_url` against
  `raw.githubusercontent.com/.../<sha>/...`); compare live file size with repo history before
  overwriting; never write a PHP file to the server that is not in the repo.
- `pps-product-feed.php` and `pps-catalog.php` live on `claude/design-it-online`. Either work on
  a copy of the live bytes and bring the file onto the working branch, or coordinate with that
  branch — do not deploy an older copy over the live one.
- Never read `pps_calc_config` raw, `woocommerce_stripe_settings`, `pps_gdrive`, `wp_mail_smtp`,
  `pps_vt_api_key`; never write `pps_calc_config`.
- No WP/WC/plugin updates (version freeze). Customers are never blocked at checkout.
- The owner works only through Claude on the web — never hand them a CLI step.

## 4. The plan

**Phase 0 — owner claims the business (this week, ~1 h).** Only the owner can: it needs their
ChatGPT account and a Persona identity check. Help them with the wording before they publish (a
profile cannot be taken down): description, category (printing / commercial printing), logo,
cover photo, delivery policy → `/shipping/`, returns → the return/reprint policy page. Verification:
business email (Office@) or the shop phone; DNS TXT also possible (domain is on Cloudflare).

**Phase 1 — OpenAI product feed (1–2 days, Claude).**
- A sibling endpoint to the Google feed — e.g. `/pps-openai-feed.jsonl` (and/or CSV) — built from
  `pps_catalog_report()` and `pps_product_price_facts()`, never a second price calculation. One
  test pins: same item set and same price as the Google feed for every product.
- Rows: the 32 catalog products **plus preset pages** (each preset URL is a product landing page
  with its own defaults; price = the preset's `price_from` or the resolver for its product — decide
  and say which). `brand`/`seller_name` = Priority Print Service; `availability` = `in_stock`;
  `product_category`; shipping = free ground (US) — match the site's wording; returns from the
  policy page; `is_eligible_checkout` left off.
- Description: what the product is in plain words, never a price promise beyond "from".
- Find out how Business Hub ingests a feed (file upload only, or a URL it fetches). If upload
  only, the owner uploads the generated file; record the refresh routine.
- Gate: a PHP test that lints every row against the 9 required fields and string IDs, and checks
  the price-parity rule; a run against the live catalog.

**Phase 2 — Business Agent (the week after, Claude drafts, owner approves).**
Knowledge: products and what each is for, papers and turnaround, free-shipping / rush rules in
words (no formulas), proofing, artwork options, reorders, policies, the FAQs (`wp_options['pps_faqs']`
and `pps_default_faqs()`). Instructions: never quote an exact price — send the customer to the
calculator or the matching preset link for a live price; repeat orders → `/reorders/`; anything
unusual → the quote form; never promise a delivery date. Preview with real questions before
publishing.

**Phase 3 — measure (alongside phase 2, Claude).** Confirm on real orders how ChatGPT traffic is
marked (ChatGPT links commonly carry `utm_source=chatgpt.com`; WooCommerce's order attribution
records the source — verify both on production, read-only). Add a "orders from ChatGPT" count and
revenue to Production Analytics, next to Business Hub's Insights.

**Phase 4 — PPS app inside ChatGPT (optional, only with traffic and an explicit go-ahead).**
"Price 500 saddle-stitched 16-page booklets" → a PPS tool returns an indicative price and a link
to the calculator with that job filled in; payment, artwork and proof stay on the site. The catch:
prices exist only in the browser (`calculate()` in each compiled calculator). A first version can
return the pre-filled link without a number; a priced version needs the calculator engine run
server-side, which must stay byte-for-byte the same math (see the "calculators are copies" section
of `CLAUDE.md`).

**Phase 5 — ads.** The owner's call, once Insights shows ChatGPT sends real customers.

## 5. Decisions the owner has to make

1. Claim this week? Verify by email or phone?
2. Publish a Business Agent under "no exact prices — send them to the calculator"?
3. Preset pages in the feed — yes/no (recommended yes).
4. Phase 4 app — later, or never?

## 6. Re-check before relying on it (the brief's weakest points)

- Every Business Hub / Business Agent detail in §1 came from coverage dated 2026-11-10/11 — confirm
  on openai.com / help.openai.com (field list, verification methods, "cannot unpublish",
  agent eligibility and price).
- The feed spec version and whether Business Hub fetches a URL or only takes uploads.
- That Instant Checkout really is retired (sources disagree on the date) — irrelevant to the plan
  unless it has come back.
- How ChatGPT referrals appear in WooCommerce on this site.

## 6a. What the re-check found (2026-11-11, against OpenAI's own pages)

developers.openai.com was read directly; openai.com and help.openai.com refuse this sandbox (403),
so items marked † rest on search-engine extracts of those pages, not a full read.

| Brief said | OpenAI says | Verdict |
|---|---|---|
| Verify by phone, email, DNS TXT or Shopify; Persona check of a person before publishing † | Same four methods; Persona required "before you can publish business information changes, view insights, or create an eligible business agent". Claiming is "initially available to eligible businesses with a website". | Right |
| Editable: website, description, category, logo, **cover photo**, delivery, returns; OpenAI reviews edits † | Help article lists website, description, category, logo, delivery policy, return policy — **no cover photo**. Edits are reviewed. Claiming gives no exclusive control; OpenAI may show a listing whether or not it is claimed. | Cover photo not confirmed |
| A published profile cannot be unpublished † | "OpenAI Support is unable to delete, unpublish, or hide a published profile." Only drafts can be deleted. | Right |
| How Business Hub ingests a feed: unknown | † **CSV upload, a link to a hosted feed file, or a Shopify feed.** Fetch schedule, size limits and refresh cadence for a link: not stated. (SFTP push with parquet/jsonl.gz/csv.gz is the separate Commerce channel, not Business Hub.) | Settled: give it the CSV link |
| 9 required fields; `price` one value; `is_eligible_search` default true, checkout default off | Confirmed (`/commerce/specs/file-upload/products`; `/commerce/specs/spec` is a 404 now). `availability` is exactly `in_stock`, `out_of_stock`, `pre_order`, `backorder`, `unknown`. `price` = `79.99 USD`. No version string, no `item_id` length limit. `title` ≤150 and `description` ≤5,000 are guidance. | Right; details added |
| `shipping` = free ground | `shipping` "requires feed-specific setup"; **`shipping_price` `0.00 USD`** means free, and omitting it means *unknown*, not free. Never both. | Corrected — the feed sends `shipping_price` |
| `group_id` + `variant_dict` for variants | Only for real variants of one product as the site presents it (`listing_has_variations` true, a `variant_dict`, group ≠ item id). | Presets are NOT grouped |
| — | `mpn`: "do not invent a value". | The OpenAI feed sends a real SKU only (the Google feed's `PPS-<id>` fallback stays a Google rule) |
| Business Agent: US ecommerce/online service with a website; cannot buy/book/look up orders; must say it is AI, no invented facts † | Confirmed; plus the industry rules of the Business Agents Policy, and the business is responsible for the agent's outputs. **Cost: not stated anywhere** (sponsoring it as an ad is separate and paid). | Right; cost unknown |
| Insights: 28 days, mentions, clicks, discovery topics, threshold † | 28 days, mainly mentions and website clicks; thresholds not published; needs Persona. "Discovery topics" not confirmed. | Mostly right |
| Instant Checkout wound down | † OpenAI's March 2026 post: "moving away from a standalone Instant Checkout experience" toward discovery + merchant checkout; the developer docs still say it is "available to approved partners". | Deprioritised, not clearly retired — irrelevant: checkout stays off |

## 6b. Phase 1 as built (branch `claude/chatgpt-business`)

- `pps-openai-feed.php` — `/pps-openai-feed.csv` (give Business Hub this link) and
  `/pps-openai-feed.jsonl`; `?debug=1` as an admin names everything left out. Loaded by one guarded
  require in `pps-calculators.php`.
- **Products:** the Google feed's, through `pps_catalog_report()` with its three requirements;
  `price` = g:price (list), `sale_price` = g:sale_price. **One deliberate difference:** a product
  with a contract price list (`_pps_contract`, e.g. Armadillo 35765) is left out — it is one
  customer's job and its public price is a placeholder.
- **Presets (decision 3 — owner 2026-11-11: "products only". OFF by default;
  `wp_options['pps_openai_feed'] = {"presets": true}` turns them on):** when on, they are priced as their own page's Product schema (`overrides.offer_price`, else
  `price_from`); a preset without one is left out, never given its product's price. Image: the
  preset's, else its product's featured image (never the site logo). Today only
  `bulk-booklet-printing` ($109.04) has a page price; the other three are left out until a
  "Price from" is set on them.
- **Every row:** brand + seller Priority Print Service, `in_stock`, `condition` new,
  `shipping_price` 0.00 USD, `accepts_returns` false + the policy URL (the page says "All sales
  final"), privacy + terms URLs, search on, checkout off, `product_category` "Printing Services >
  <first WooCommerce category>", plain-text description (list items become sentences) ending
  "The price shown is the starting price for the job this page opens with; the online calculator
  prices any size, paper, quantity and finishing live."
- **Gate:** `tools-openai-feed-test.php` (fake catalog: lint, item set, every price vs the real
  Google renderer, presets, contract exclusion; swapping in the effective price fails 10 checks).
  `--snapshot` runs it over the real catalog read from production (registry option, Store API,
  presets) against the live `/pps-product-feed.xml`: 31 rows (presets off), all clean, all prices
  equal (2026-11-11, after the fixes below). `--live <jsonl> <xml> [csv]` lints feeds fetched from the site after deploy.
- **Found in the live catalog and FIXED on production 2026-11-11 (owner: "fix the issues"):** three
  products carried a per-piece price as the product price, so every feed and schema said "from
  $0.25". Each now carries the grand total its own calculator shows when the page opens (250 pieces,
  read from the live page in a browser), in both `_regular_price` and `_pps_defaults_price`, so
  the schema, the Google feed and this feed agree:

  | Product | Was | Now |
  |---|---|---|
  | 23527 Foldover Greeting Card | 0.25 | 150.83 |
  | 23559 Note Card | 0.15 | 111.23 |
  | 21873 Standard Letterhead | 1.00 | 93.37 |

  Safe for checkout: the add-to-cart floor is the SMALLER of $5 and half the product price.
  Standard Letterhead's short description was door-hanger copy ("Heavy-Duty Door Hangers with
  Extra Gloss Coating / DIY Designer On-Page / Shipping: Free UPS Ground Shipping / Size:
  3.5x8.5, 4.25x11"); it now reads "Custom letterhead printed on premium text-weight papers,
  including linen and opaque stocks. / Size: 8.5x11, 8.5x14 / Shipping: Free UPS Ground
  Shipping". These are content edits (production is the source of truth for content); the
  values above are the rollback. If the calculators' pricing changes, re-read these three opening
  totals — nothing updates them automatically.
- **Still for the owner:** registry ids 86472, 86887, 86468 are not published; 85177 "Sample Test"
  has no price; sticker 86465 has no featured image (both left out of every feed).
- **Phase 0 wording:** `docs/CHATGPT_BUSINESS_PROFILE.md`.

## 7. Sources used

- OpenAI product feed reference — https://developers.openai.com/commerce/product-feeds/spec
- OpenAI product feed spec (current) — https://developers.openai.com/commerce/specs/file-upload/products (`/commerce/specs/spec` now 404s)
- Setting up your business on ChatGPT — https://help.openai.com/en/articles/20001635-setting-up-your-business-on-chatgpt
- Business Agents policy — https://openai.com/policies/business-agents-policy/
- Businesses in ChatGPT policy and terms — https://openai.com/policies/businesses-in-chatgpt/ , https://openai.com/policies/businesses-in-chatgpt-terms/
- OpenAI checkout guidance for apps — https://developers.openai.com/plugins/build/monetization
- OpenAI, "Buy it in ChatGPT" (2025) — https://openai.com/index/buy-it-in-chatgpt/
- Businesses in ChatGPT, deep dive — https://flaviocopes.com/businesses-in-chatgpt/
- FoneArena — https://www.fonearena.com/blog/496812/openai-rolls-businesses-chatgpt.html
- Relevant Audience — https://www.relevantaudience.com/geo/openai-businesses-in-chatgpt-business-hub-business-agents/
- IT Brief — https://itbrief.ca/story/openai-launches-chatgpt-business-hub-for-companies
- Hypotenuse (Instant Checkout in 2026) — https://www.hypotenuse.ai/blog/chatgpts-instant-checkout-the-next-phase-of-agentic-commerce
- Lengow — https://blog.lengow.com/chatgpt-wanted-to-become-the-worlds-biggest-shop
- Digital Applied — https://www.digitalapplied.com/blog/ai-agentic-commerce-discover-in-ai-buy-on-site-2026
