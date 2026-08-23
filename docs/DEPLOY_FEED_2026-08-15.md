# Deploy card — the Merchant Center feed has never been on production

> **DONE.** Executed 2026-08-15 04:21 UTC by another session; all five files
> verified on production at the byte counts below. Remaining work moved to
> `docs/ROLLOUT_SPAWNER.md` — two files, plus the permalink flush this card
> called for, which still needs confirming.

Merchant Center says **"File not found."** That is literal:
`/pps-product-feed.xml` does not exist on the live site, because none of the
code that serves it has ever been deployed. It was all written in a session
whose `PPS_PRODUCTION_AI_ENGINE` connector had already dropped.

Nothing is wrong with the feed. It has simply never been installed.

## What to deploy

Branch `claude/optimistic-wozniak-11ql3y`, commit **`56082ee`**.
Pull-based only — `pps_plugin_download_url` against
`https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/56082ee/<file>`,
relative path `pps-calculators/<file>`. `mcp_ping` after each write.

| Order | File | Bytes | Note |
|---|---|---|---|
| 1 | `pps-catalog.php` | 15,324 | **new** |
| 2 | `pps-product-feed.php` | 21,520 | **new** — serves the feed |
| 3 | `pps-gbp-sync.php` | 11,568 | **new** — inert until a Places key is set |
| 4 | `pps-calculators.php` | 330,649 | loaders, `pps_product_price_facts()`, llms.txt |
| 5 | `pps-config-admin.php` | 116,490 | new SEO fields |

Satellites before `pps-calculators.php`. All three requires are
`file_exists`-guarded so the order cannot fatal, but this way there is never a
window where the loader points at a file that is not there yet.

### Before overwriting, check first

Standing rule, CLAUDE.md. Production should currently read
**`pps-calculators.php` 322,038** and **`pps-config-admin.php` 111,697**.
**A size matching no commit means someone edited in place — stop and read it
before overwriting.**

`pps-config-admin.php` deserves a second look: production's copy came from
`claude/woocommerce-domain-search-ly4vff` (111,697), not from this branch. The
116,490 here is exactly that file plus the new SEO fields — a strict superset,
verified by a clean three-way merge — so deploying it loses nothing. That
branch was still at `9d859b2` as of 2026-08-15; if it has moved since, re-check
before overwriting.

## Then, in order

1. **Settings → Permalinks → Save** on production. The feed registers its own
   rewrite rule; without a flush the URL 404s exactly as it does now.
2. Open `https://priorityprintservice.com/pps-product-feed.xml` in a browser.
   Expect XML. If it still 404s, the flush did not take.
3. Open `https://priorityprintservice.com/pps-product-feed.xml?debug=1` as an
   admin. This says how many products are in the feed, which are left out and
   why, and flags per-item problems Google rejects on.
4. Only then re-fetch in Merchant Center.

## What to expect on the first look

The feed carries a product only if it has **a price and a featured image**.
Everything else is reported, not enforced. If the count comes back low, `?debug=1`
names each omission — most will be "no price set on the product", fixed on the
product's PPS Defaults tab by pasting a quote link.

## Two things the deploy cannot fix

Both are Merchant Center account settings, and either will fail the whole file
regardless of how good the XML is:

- **Shipping.** With no shipping service configured at the account level and no
  per-item value, every item errors on "Missing value: shipping". Merchant
  Center → Settings → Shipping. (A feed-level fallback exists behind
  `seo.feed_shipping_price`, but the account setting is the right place.)
- **Tax**, for US accounts, same pattern.

Also confirm the domain is **verified and claimed** in Merchant Center.

## Rollback

Delete the three new files and re-deploy `pps-calculators.php` and
`pps-config-admin.php` from the previous SHA. The three requires are
`file_exists`-guarded, so removing the files is a complete, clean rollback of
the feed, the catalog and the rating sync in one step.
