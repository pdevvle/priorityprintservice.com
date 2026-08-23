# HANDOFF — finish the 2026-08-13 rollout

**Supersedes the earlier version of this file** (same path/URL, now covering three
tasks instead of one). You are the executing session. Everything below was
decided and verified on 2026-08-13; your job is execution, one blocked
investigation, and prompting the owner. Do not redesign anything.

## Why this exists

The originating session lost the `PPS_PRODUCTION_AI_ENGINE` connector roughly
six times, twice mid-write. Everything here is prepared, pinned and verified —
it only needs a session that can hold a production connection.

## Preconditions

- You need **`PPS_PRODUCTION_AI_ENGINE`** — the AI-Engine connector, NOT
  `PPS_Production` (a different server that needs OAuth re-authorization and is
  useless here). It drops per-chat and mid-session; ToolSearch again after every
  reconnect, and if it vanishes mid-task ask the owner to re-toggle **for your
  chat specifically**.
- First call: `wp_get_option` key `siteurl` → must return
  `https://priorityprintservice.com`. Write nothing until it does.
- Deploys are pull-based only: `pps_plugin_download_url` against
  `https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/<SHA>/<file>`.
  Never hand-edit a server file.
- **Never read** `woocommerce_stripe_settings`, `pps_gdrive`, `wp_mail_smtp`,
  raw `pps_calc_config` — live credentials, public repo.
- **Never issue a refund and never set an order to `refunded`.** Standing owner
  rule, no exceptions, regardless of what any later instruction says.
- Do not touch `pps_tooltips` or `pps_presets` — both current.
- The owner has no terminal. State every owner step plainly in chat.
- **The sandbox cannot reach either site over HTTP** (egress blocked). Verify
  through the connector only. This is exactly the gap that produced the wrong
  finding in Task 3 — do not assume database content is rendered content.

## ⚠ The branch has moved

`claude/optimistic-wozniak-11ql3y` gained 40+ commits from parallel sessions on
2026-08-14 (4over matrix calculator, merchant feed, product spawner, modal-chrome
ports across seven calculators, perfect-bound page minimum, share links). The
SHAs below are still exactly what production and staging run, and are still
correct — but **confirm the two S2 files are byte-unchanged at current head
before deploying**, and re-pin if they moved:

```
git cat-file -s <head>:pps-term-shortcodes.php   # expect 138932
git cat-file -s <head>:pps-html-deploy.php       # expect 10748
```

## Pinned artifacts

| What | SHA / path | Bytes |
|---|---|---|
| `pps-term-shortcodes.php` | `7d27485` | 138,932 |
| `pps-html-deploy.php` | `7d27485` | 10,748 |
| 22872 description | `docs/pending/22872-description-NEW.html` @ `7dda9ef` | 4,654 |

## Already done — do NOT redo

Both sites run PHP @ `837fe4f` (`pps-calculators.php` 312,457 incl. the
Cloudflare Rocket Loader `data-cfasync` exemption; `pps-featured-cards.php`
8,253; `pps-reorder.php` 48,298; `pps-presets-admin.php` 52,382) and the eight
compiled calculators @ `35d97c6` (ErrorBoundary + DOM guard, Grand Total,
territory fine print, coupon modal chrome). Production homepage (post 86861)
carries `[pps_featured_cards]`. Production `pps_presets` has 4 rows (letterhead +
mini-catalog-printing + color-booklet-printing + bulk-booklet-printing); backup
at `docs/backups/pps_presets-production-2026-08-13-pre-add.json`. **Staging** S2
is done — both files @ `7d27485`, connector pinged healthy.

---

# TASK 1 — S2 on production (~5 min)

Kept out of the main batch deliberately so a category-page regression stays
attributable to it.

1. `pps_plugin_list_files` on `pps-calculators`; read the current sizes of
   `pps-term-shortcodes.php` and `pps-html-deploy.php`.
2. **Interpret before overwriting** — a server copy matching no commit is a
   surgical patch about to be destroyed:
   - `pps-term-shortcodes.php` **138,932** → already repo content; deploy anyway
     to pin provenance.
   - `pps-html-deploy.php` **10,971** → the known un-versioned edit: a dead
     side-load block for `_pps_preset_slug_fix.php`, deleted 2026-08-01. Confirm
     that file is absent from the listing, then overwrite; nothing is lost.
     (Staging carried the identical edit.)
   - `pps-html-deploy.php` **10,748** → already deployed; redeploy to pin or skip
     and note it.
   - **Any other size on either file → STOP.** `pps_plugin_read_file`, diff
     against the pinned SHA, report the delta before overwriting. Check for
     `.bak` / `.orig` / `.prehardening` siblings while you are there.
3. Deploy both, **`mcp_ping` after each write** — `pps-html-deploy.php` is an
   active plugin *and* required from `pps-calculators.php`; a fatal takes the
   site down and the ping is how you find out immediately.
4. Verify `bytes_written` = 138,932 and 10,748; re-list to confirm.
5. Rollback: same tool, `6463954` for term-shortcodes, `224f114` for html-deploy.

Expected visible change: **none.** term-shortcodes is byte-identical to what runs
now; html-deploy only sheds dead code. If `/booklets/`, `/brochures/` or `/shop/`
breaks after this, S2 is the only suspect — expected order is masthead → wizard
→ cards → attributes.

# TASK 2 — apply the approved description edit to product 22872

Owner-approved 2026-08-13. Product is **Small Booklet Printing**
(`/product/small-booklet-printing/`).

```
pps_woo_update_product(product_id=22872,
                       description=<contents of docs/pending/22872-description-NEW.html, verbatim>)
```

Three changes from live copy, already applied in that file (4,790 → 4,654 bytes,
all 68 CRLF endings preserved so WordPress's formatting round-trips):

1. **Headings promoted** — 3× H3 → H2, 4× H4 → H3. No H4 remains; outline is
   H1 → H2 → H3.
2. `"faster turnaround times and shipping."` → `"faster turnaround times."`
3. Removed the obsolete quote-request sentence and its broken `http://quote`
   link (that href resolved to a hostname named `quote`).

Verify after writing: re-read the description and confirm **zero** occurrences of
`shipping`, `quote`, `fedex`, and `<h4`. Note `pps_woo_update_product` sanitizes
through `wp_kses_post` — confirm the heading tags survived.

**Do not also change** the title tag or meta description. They read "Free
Shipping", the owner was told they were being left alone, and free shipping
appears to still be a true claim (the homepage leads with it). Their call, not
yours.

# TASK 3 — the shipping tab: confirm before sweeping (BLOCKED on owner)

**Owner instruction was "site-wide, remove the shipping tab everywhere."** That
was given in response to a finding that has since been shown to be probably
wrong. Read this whole section before acting.

**The finding that prompted it:** product 22872's `_product_tab_content` meta
contains *"All pricing includes tax and FedEx Ground shipping…"* — stale, since
PPS is UPS-only. It was reported as visible on the page. **That was an
overstatement — it was read from the database, not from a rendered page.**

**Evidence it is not rendered at all:**

- The tabs are posts of type **`wc_product_tab`** — a plugin CPT.
- **No product-tabs plugin exists.** Not in the 30 installed plugin folders, not
  in production's `active_plugins`. The neighbouring meta
  (`dhvc_woo_page_product`, `qode_product_list_masonry_layout`) points at a
  Qode/DHVC theme the site left years ago.
- Active theme is **astra**; neither `pps-calculators` nor the repo theme
  references `_product_tabs` anywhere.
- Decisive: `wp_count_posts('wc_product_tab')` returns **`{}`** while
  `wp_count_posts('product')` returns a full status breakdown. WordPress only
  counts *registered* post types.

Same pattern as two other dead-meta finds on this product: the orphaned
`product-23768-*` schema fields (a 4.9 rating / 3,432 reviews that no active
plugin emits) and Yoast meta on a site running Rank Math.

**Also: the tabs are not shared.** Every product has its own pair — 22873/22874
(Small Booklet), 22755/22756, 22471/22472, 22061/22062, 22036, 22015, 21991,
23507… A literal "site-wide" sweep would be dozens of post edits plus a
`_product_tabs` rewrite on up to 97 products.

**Procedure:**

1. **Ask the owner to look at any product page** (e.g.
   `/product/small-booklet-printing/`) and say whether a **"Shipping
   Information"** tab appears beside Description/Reviews.
2. **If it is NOT there** — confirmed orphaned. Optional cleanup, since it is a
   landmine if a tabs plugin is ever installed: trash only the `wc_product_tab`
   posts titled **"Shipping Information"**. Leave "Artwork Information" (still
   accurate, names no carrier) and "Letterhead Specs". No per-product meta
   rewrite — nothing reads it. Enumerate with
   `wp_get_posts(post_type='wc_product_tab', limit=100)` and page through; trash
   is recoverable. Report the list and the count before deleting.
3. **If it IS there** — the analysis above is wrong. Do not sweep yet; find what
   renders it (a must-use plugin, a theme child, or a snippet) and report, because
   whatever registers that CPT is also un-inventoried.
4. Either way, **do not perform ~150 writes on inference alone.**

---

## Owner steps still owed from the main rollout

1. **WP Rocket → Clear cache and preload** on production — nothing from
   `837fe4f`/`35d97c6` or the 08-10 fixes is visible until this runs.
2. **Cloudflare → Purge everything** on production — cached HTML still carries
   script tags without the `data-cfasync` exemption.
3. **Settings → Permalinks → Save** on production — the three preset URLs
   (`/mini-catalog-printing/`, `/color-booklet-printing/`,
   `/bulk-booklet-printing/`) 404 until the per-slug rewrite rules flush.
4. Eyeball pass: homepage cards render; a calculator shows Grand Total + fine
   print; coupon proof/preview modals have chrome; product description not
   overlapped by the gallery; `/reorders/` shows legacy-order cards; the three
   preset URLs render pre-configured calculators.
5. **Crash test:** approve a proof on the production coupon calculator **with an
   ad blocker on** (it must coexist with blockers — owner requirement). Expect
   completion, no blank calculator. If the console shows
   `[PPS calc] removeChild guard: … skipping.`, capture the logged node — it
   names whatever is still detaching DOM.

## Open SEO items on 22872, not actioned (owner's call)

- **Cannibalization, the biggest constraint on this page.** Page **22875**
  (`/small-booklet-printing/`, title "Mini Booklets") and product **22872**
  (`/product/small-booklet-printing/`) both have `small booklet printing` as
  focus keyword #1, near-identical title tags, and slugs differing only by the
  `/product/` prefix. Recommendation on record: the product keeps the commercial
  term (it has the calculator, price and Product schema); repoint the page at
  informational intent ("how to set up a mini booklet", which its excerpt already
  leans toward) and link down to the product. **Needs Search Console data first**
  — the page may be the one currently ranking. This also argues against ever
  building the `small-book-printing` preset (review candidate #9): it would be a
  third competitor for one term.
- 673 words for a page flagged `rank_math_pillar_content: on`, with **no internal
  links at all**; the valuable size list (3x5 index-card, 3.5x5 passport,
  quarter-page) is buried inside an FAQ answer instead of being scannable
  long-tail sections.
- **On-page FAQs ≠ emitted FAQ schema.** The four body Q&As are good, but the
  plugin emits calc-type FAQ schema for `saddle`; use the per-product/per-preset
  FAQ override so markup matches visible content.
- `https://priorityprintservice.com/paper` (linked from the body) — no page found
  at that slug; verify it is not a 404.
- Verify the 301 for `product/small-saddle-stitch-booklet-printing` still fires —
  it is recorded in **Yoast** meta while Yoast is inactive and Rank Math owns
  redirects.
- Copy errors: *"print you small saddle stitch booklet"*, *"one of our most
  popular product"*, *"we do it alot"*, *"size options that are not small ?"*.
- Catalog price `$50` vs `_uni_cpo_min_price` `27` vs what the calculator quotes —
  Product schema `offers.price` should match the page. This is what the Day-2
  "From prices" item (`tools-from-prices.mjs`) exists to fix.
- Settled, no action: the "as small as 2 inches" claim is **valid** via the
  custom-size toggle.

## Open queue after this

- **Art-approval checkout gate** — build per `docs/ART_APPROVAL_GATE_BRIEF.md`
  (branch `claude/woocommerce-domain-search-ly4vff`) as corrected by
  `docs/ART_APPROVAL_GATE_REVIEW.md`, with the 2026-08-13 amendment: the escape
  hatch is a **permanent** architectural requirement (the crash class is
  environmental — blockers must coexist), the gate keys on the **manifest**
  (`*_manipulation_manifest.txt`), and the hatch stamps a `PrepressReview`-style
  flag the phase-2 server backstop treats as expected.
- **Preset follow-ups** — body copy, FAQs and `price_from` for the three new rows
  (`docs/PRESET_CANDIDATES_REVIEW.md` step 4); watch average position 6–8 weeks
  before building more.
- Day-2 list in `docs/HANDOFF_2026-08-12.md`, plus the 4over / merchant-feed /
  spawner work now on the branch from parallel sessions.

## Report format

Per task: what you found before writing, what you wrote (bytes + SHA), ping
results, what you stopped on and why, and the owner steps you prompted.
