# Shippo Integration — Test Regime

The Shippo stack has three parts: server-side REST proxies in
`pps-calculators.php` (the only place the API token lives), the token-stripping
`pps_get_public_config()` guard, and (pending) the calculator-side debounced
transit fetch. The regime tests each where it actually runs.

**Spend note:** every test uses Shippo's rating + address APIs, which are
**free**. Nothing in the codebase purchases labels. A full server run makes
~4 external calls; repeat lookups hit the 30-day cache.

## Layer 1 — on-server live suite (`pps-shippo-test.php`)

Runs ON staging (the sandbox has no egress to api.goshippo.com and no route to
the staging frontend — the server has both). Driven entirely through options,
so the MCP bridge can run it without wp-admin:

1. Set option `pps_shippo_test_trigger` = any non-empty string.
2. Make any WordPress request (the next request's `wp_loaded` runs the suite,
   consumes the trigger, takes a 120 s lock).
3. Read option `pps_shippo_test_results`.

| ID | Asserts |
|---|---|
| A1–A2 | Token + 5-digit origin ZIP configured in Central Config |
| A3 | `pps_get_public_config()` exists (stale-deploy canary) |
| A4–A5 | Browser config payload contains **no token** and no recipient email — the leak-regression guard |
| B1 | Raw Shippo rates call: egress OK, auth OK (401 = rotated/bad key), ≥1 rate, a *ground* service present |
| C1 | `transit-estimate` open to guests, returns `transit_days` |
| C2 | NY transit days sane (1–8; static map says 5) |
| C3 | Repeat lookup returns `cached:true` (30-day transient — no spend) |
| C4 | ZIP `123` → 400 |
| C5 | Token removed (via option filter, config untouched) → clean 501 |
| C6 | Per-IP rate limit trips at the cap → 429 (counter pre-seeded — no spend) |
| D1 | `validate` refuses guests (401/403) |
| D2 | `validate` proxies a real address check when logged in |

Deterministic: clears its own test-ZIP caches (`pps_transit_US_10001`,
`_60601`) at the start of each run; C6 pre-seeds `pps_transit_rl_<ip>` instead
of burning 20 lookups. Failure classification is in each test's `detail`
(e.g. B1 distinguishes egress failure vs 401 bad token vs zero rates =
no carrier enabled on the Shippo account).

## Layer 2 — client-wiring acceptance (`tests/shippo/`)

Headless Playwright harness with `window.fetch` mocked — defines the contract
the (not-yet-written) calculator wiring must meet: exactly one debounced call
per typed ZIP, response swaps the static transit days, server errors fall back
to the static map, `shippo_enabled:false` never calls. `--acceptance` mode is
the merge gate for the wiring change, per calculator file. Setup in
`tests/shippo/README.md`.

## Cadence

- **After rotating the Shippo key** (required after the 2026-07-19 page-source
  exposure): rerun Layer 1 — A1 + B1 prove the new key.
- **After any change to the shipping endpoints or `pps_get_public_config`**:
  rerun Layer 1 before and after deploying to staging (A3 catches a stale
  deploy).
- **When the client wiring lands**: Layer 2 `--acceptance` per wired
  calculator + a Layer 1 rerun.
- **Before any production sync**: full Layer 1 on staging; the runner ships
  in-repo but is inert without its trigger option.

## Baseline (2026-07-19)

**Layer 1 on staging: 14/14 PASSED, 5.0 s, PHP 8.2.31.** Highlights: raw call
HTTP 201 with 35 rates in 2.5 s (ground = "UPS Ground Saver 2d $6.40" to
85027); guest transit-estimate for NY 10001 returned **4 days where the static
map says 5** (the live data is already correcting the over-promise); cache,
400/501/429, and validate-gating all clean; browser payload confirmed
token-free with `shippo_enabled:true`. Token in use was the exposed
`…4ef2` key — **rerun A1+B1 after rotating it.**

**Layer 2 locally: client wiring LANDED and gated — `--acceptance` ALL PASS on
all 8 calculators (24/24 scenarios), 2026-07-19.** Each calc makes exactly one
debounced call per typed ZIP, swaps the live `transit_days` into the pricing
memo (via the `pps-live-transit` event + `ltTick` dep), falls back to the
static map on any error, and makes zero calls when `shippo_enabled` is false.
Earlier the harness baseline correctly reported "client not wired" — that gap
is closed; rerun `--acceptance` after any change to the shipping section or
`getTransitDays`.

Latest server results always live in the `pps_shippo_test_results` option on
staging.
