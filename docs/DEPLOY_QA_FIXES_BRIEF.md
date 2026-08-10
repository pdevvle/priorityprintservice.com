# Brief — deploy the QA-round fixes to staging + apply the freeze-cluster config

For a session with the **PPS_STAGING_AI_ENGINE** connector. Everything is
committed on `claude/optimistic-wozniak-11ql3y`; deploy pinned to commit
**`4012e8f4f7ac1ffe66968d6afbafc931035d3af9`**. Read CLAUDE.md first — its
rules bind.

## Staging state (so you don't redo or miss)

Staging already runs the 2026-08-09 deploys @ `b7e8b46`: all modern
calculators (saddle = promoted draft), blue dots / "Popular" legend / paper
spec labels / 50lb removal (code side), and current copies of
`pps-calculators.php`, `pps-term-shortcodes.php`, `pps-config-admin.php`
(JSON-guard included), `pps-reorder.php`. **No PHP deploy is needed.** Pending
are exactly two commits' worth of calculator HTML, plus config-side work from
the QA triage.

## Task 1 — deploy 3 calculator files (the code fixes)

Carries the perfect-bound gradient fix (`f3abae7`) and the custom-size seeding
fix (`4012e8f`, QA's "W/H swap").

For each of `calc-preview-test.html`, `calc-perfect-bound.html`,
`calc-coupon-book.html`, call `pps_plugin_download_url` with:

- url: `https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/4012e8f4f7ac1ffe66968d6afbafc931035d3af9/<file>`
- relative_path: `pps-calculators/_pending_html/<file>`

Then `mcp_ping` once (any WordPress request fires the deploy hook), and
confirm via `pps_plugin_list_files` on `pps-calculators/_pending_html` that
the three files moved into a fresh `_archive/<timestamp>/` (that's the engine
accepting them — pending root should not retain them).

Verify (browser or owner click-through): on the saddle/PB/coupon pages,
switching Preset Sizes → Custom Size from the 5.5×8.5 preset shows
**Width 5.5 / Height 8.5** (previously 8.5/5.5); perfect-bound's Full Color
pill is the linear tri-color gradient, not the conic color wheel.

## Task 2 — WP Rocket: the freeze-cluster remediation (config, highest value)

QA's ~30s freezes, blank gaps with content present in DOM, tiled rendering,
and the dead cart "Proceed to checkout" click all fit **Delay-JS + Lazy
Render** holding scripts until first interaction (which then pays the ~600KB
in-browser Babel compile) with the failing New Relic chunk amplifying the boot
storm. The cart page carries zero PPS JavaScript (verified) — nothing of ours
can eat that click.

Apply on staging: **exclude from Delay JS and disable Lazy Render** for the
calculator product pages, all preset URLs (root-level `/{slug}/`), `/cart/`,
and `/checkout/` — or simply turn both features off on staging.

⚠️ **Write hazard.** Rocket settings live in `wp_options['wp_rocket_settings']`
as a PHP array, and the MCP `wp_update_option` endpoint has previously
serialised array payloads as JSON strings (see the `pps_get_registry()` /
`pps_get_tooltips()` guard comments — Rocket has no such tolerance and a
string write likely breaks it). Protocol: `wp_get_option` (raw) → archive the
value to a repo/scratch backup → modify → `wp_update_option` → **re-read and
confirm it comes back as a structured object, not a quoted string**; restore
the backup if not. If you can't confirm a clean array round-trip, stop and
have the owner make the same changes in wp-admin → WP Rocket (checkboxes +
exclusion textareas) — that path is always safe. Clear Rocket's cache after.

## Task 3 — checkout disclaimer copy

QA found: "reflect both the Time and The cost for both production and
transit". The string is **not in the repo** — it's DB content. Locate it
(likely the checkout page content or a Woo/checkout setting; search page
content via `get_pages`/`wp_get_posts` for "production and transit") and
replace the sentence with:

> "reflect both the time and cost of production and transit"

## Task 4 — 50lb row in saved config (only if it still shows)

Code-side removal is deployed, but **saved config wins over defaults**: if
`wp_options['pps_calc_config']` (`papers_nc`) still carries the
`val: 2.001` / "50lb Offset Smooth Opaque" row, the calculators still offer
it. Preferred fix: owner deletes the row in **PPS Config → Papers → Save**
(one click, no write hazard). Connector alternative: same read-modify-write
protocol as Task 2, same JSON-string gate — this option carries every admin
price; corrupting it silently reverts all pricing to defaults.

## Owner-side (not this session's — but say it got done)

- **Disable New Relic browser monitoring on staging** (Cloudways panel). The
  retry-looping NR chunk is half the freeze cluster.

## After Tasks 2 + owner NR toggle: re-test the cluster

Re-run QA's failing paths on staging: interact with a calculator immediately
after load (no 30s freeze), accordion toggles (no blank gaps), and the cart's
"Proceed to checkout" **button** (navigates). This retest is the 3.0 blocker
gate — the go-live shouldn't be scheduled until it passes.

## Do NOT

- Re-deploy any PHP file — staging PHP is current; a re-deploy from an older
  SHA would revert the tooltip accessor and JSON guards.
- Touch `wp_options['pps_tooltips']` — synced and verified (6 keys, native
  array). The 5 redundant kept keys are harmless; dropping them is optional
  and owner-approved only.
- Write any wp_option without the read-back-as-array gate above.
- Deploy anything to PPS-Production — this brief is staging-only.

## Report back

Files deployed (with archive timestamp), Rocket changes applied (or handed to
owner), disclaimer fixed (where it lived), 50lb outcome, and the freeze-cluster
retest verdict.
