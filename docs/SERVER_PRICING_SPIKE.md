# Server-authoritative pricing — scope for an implementation run

**Status:** scoped, not started. **Owner decision required before starting** — see §11.

This document deliberately names *which* constants must move without restating their
values or the strategy behind them. `docs/MASTER_PRICING_LOGIC.md` remains the source
of truth for the maths; read it first, and do not copy figures out of it into any file
that could reach a published branch.

---

## 1. Problem

Every calculator computes its own price in the browser. `PCF` carries the cost basis
and the markup curve, and `calculate()` applies them. Both were reachable from the
console until 2026-08-01 (see the sealing commit); they are now inside an IIFE, but
the constants are still plain text in view-source, because the file is shipped as JSX
and transpiled client-side.

The exposure that matters is not the price list — a competitor can get those by using
the site like a customer. It is the **cost basis and margin band**, which turn guessing
into arithmetic. Measured before sealing: 72 quotes across 9 page counts × 8 quantities
harvested in 52 ms, with no request reaching the server, so nothing could rate-limit or
even observe it.

A second, related weakness: the cart trusts a price the client computed.
`pps_ajax_add_to_cart()` reads `$_POST['pps_price']` (pps-calculators.php:1447). Two
compensating controls exist — `pps_materials_price_floor()` (:1265) and
`pps_cart_tripwire()` (:1375) — but both are backstops against a *tampered* client
price, not a substitute for computing it ourselves.

## 2. Goal

The server computes the authoritative price. The client keeps quoting instantly.

## 3. Non-goals

- Hiding the calculator's *existence*, structure, or option set. All customer-visible.
- Obfuscating client code. Speed bumps, not protection; not worth the debuggability cost.
- Removing the price floor or tripwire. They get cheaper to run, not obsolete (§8).
- Changing pricing behaviour. This migration must be **numerically identical** — any
  change in output is a bug, not an improvement, and is caught by §9.

## 4. The constraint that shapes the design

The instant quote is the product. A network round-trip on every keystroke — quantity,
page count, paper, coating — would gut it. Any design that makes the customer wait for
a server response before seeing a number is a regression, regardless of how secure it is.

## 5. Proposed architecture: two-tier

**Tier 1 — client estimate (unchanged UX).** The browser keeps a *reduced* engine that
produces the number shown while configuring. It knows sheet counts, imposition, page
counts, turnaround, shipping — the physical model. It does **not** know the markup
curve. It applies an opaque, coarse rate table injected via `PPS_CONFIG` (see §6) that
is good enough to display and deliberately not good enough to reverse-engineer margin.

**Tier 2 — server authority.** A REST endpoint recomputes the price from the same spec
and returns the real figure. Called on a debounce (~400 ms after the config settles),
on section collapse, and unconditionally before add-to-cart. The displayed price
reconciles to the server figure when it arrives.

The add-to-cart path stops accepting a price at all: it posts the *spec*, and the server
prices it. `pps_price` becomes advisory telemetry, or is dropped.

### Why not "server-only, no client engine"
Tried mentally and rejected: every interaction becomes a round-trip, the debug panel and
the quantity-projection table (which prices 8+ configurations at once) become 8 requests,
and the calculator stops working in the standalone/GitHub Pages preview the whole
development workflow depends on.

### Why not "obfuscate the constants"
They have to be correct to price correctly, so they have to be present. Any
transformation the client can invert, an attacker can invert.

## 6. What moves, what stays

**Moves to the server (must not appear in any shipped HTML):**
- `backend_maximummarkup`, `backend_minimummarkup`
- `booklet_maximummarkup`, `booklet_minimummarkup`, `booklet_markup_coef_tS`
- `booklet_cover_maximummarkup`, `booklet_cover_minimummarkup`, `booklet_cover_markup_coef`
- `booklet_8up_markup_bonus`, `booklet_surcharge`, `booklet_size_discount`
- `bw_discount_rate`, `easy_discount_rate`, `easydiscount_max`, `common_discount_max`
- `non_inventory_fee`, `sale_discount_pct`
- the per-paper `price` field in `papers_nc` / `papers_cs` (cost basis)
- `printing_fullcolor_cost`, `printing_black_cost`, and the `labor_*` rates

**Stays client-side (physical/UX model, not margin):**
- sheet and imposition maths, page counts, size presets, spine calculations
- turnaround day arithmetic, transit map, shop closures, cutoff logic
- everything in the proofing and artwork pipeline
- `minimum_turnaround_days`, `two_staple_threshold`, `sheetsturnaround`

**Judgement call for the run:** the machine-rate constants (`press_printsperhour`,
`cutter_sheetsperhour`, …) are cost-adjacent but also drive turnaround. Prefer keeping
them client-side and moving only the money they feed into. Flag if that proves impossible.

## 7. API contract (draft)

```
POST /wp-json/pps/v1/price
body: { calc: "saddle"|"perfect"|"brochure"|..., spec: { ...the same object buildMetadata() already produces... } }
200:  { total, perUnit, breakdown[], currency, spec_hash, issued_at, signature }
4xx: { error, field }
```

- `spec_hash` + `signature` (HMAC over the spec + total + a server-side secret) let
  add-to-cart verify the client is submitting a price this server actually issued,
  without another recompute. Short TTL (~15 min) so stale quotes cannot be replayed.
- Reuse the spec shape from `buildMetadata()` so there is one vocabulary, not two.
- Rate-limit per IP. Unlike today, this is now meaningful — a scraper must pay for
  every quote and is visible in the logs. Mirror the limiter already used at
  pps-calculators.php:1061 and :2249.

## 8. Interaction with the existing controls

- `pps_materials_price_floor()` — keep. It becomes a sanity check on our own arithmetic
  rather than a defence against the client, which is a better use for it. Its
  documented fail-open behaviour should be revisited once the price is ours: failing
  open made sense when it was a backstop, less so when it is the last check.
- `pps_cart_tripwire()` — keep initially, in flag mode, to detect clients still posting
  prices after the migration. Retire once traffic is clean.
- **Note for the run:** these two are the only things standing between a tampered POST
  and a real order today. Do not remove either until the server price is live and
  verified in production, not just staging.

## 9. Verification — this is the hard requirement

The migration must be numerically identical. Before changing anything:

1. Build a fixture sweep from the existing client engine — every calculator × a matrix
   of size, quantity, page count, paper, colour, coating, finishing, artwork and proof
   options. Aim for 2,000+ configurations per calculator. The harness in
   `/tmp/.../scratchpad` that harvested 72 quotes in 52 ms is the starting point; it
   drives `calculate()` directly in a headless page.
2. Freeze that as a golden file, committed **off** any published branch.
3. After the server engine exists, replay every fixture through it and diff to the cent.
   Zero tolerance. Any delta is a port bug.
4. Keep the golden file as a regression gate for future pricing changes — this is worth
   having regardless of whether the migration ships.

Also verify: quote latency under debounce, behaviour with the endpoint unreachable
(should degrade to the client estimate with a clear "final price at checkout" note, not
a broken form), and that the standalone Pages preview still functions with no WordPress.

## 10. Phasing

| Phase | Deliverable | Ships? |
|---|---|---|
| 0 | Golden fixture sweep + replay harness | No behaviour change |
| 1 | PHP pricing engine, ported from `calculate()`, passing the sweep | Dark, unused |
| 2 | `/pps/v1/price` endpoint + signature + rate limit | Dark, unused |
| 3 | Client calls it on debounce, reconciles display, logs disagreements | Behaviour visible |
| 4 | Add-to-cart posts spec, not price; server prices the line item | Cutover |
| 5 | Strip margin constants from shipped HTML; coarse table replaces them | The actual win |

Phases 0–2 carry no customer risk and can land independently. The win only arrives at
phase 5 — **a run that stops at phase 4 has done work without closing the leak.**

## 11. Decisions needed from the owner before starting

1. **Repository visibility.** The repo is public. Everything above is readable on
   github.com regardless of what Pages serves, including full history. Going private
   is the single highest-value action; note GitHub Pages from a private repo needs a
   paid plan for a public site.
2. **Latency budget** for the reconcile round-trip — what is acceptable before the
   displayed price settles?
3. **Offline behaviour** — if the endpoint is down, quote from the client estimate with
   a caveat, or refuse to quote?
4. **Scope** — all nine calculators, or saddle stitch first as a pilot?

## 12. Risks

- **Silent numeric drift** during the port. Mitigated entirely by §9; skipping the
  golden sweep is the one way this goes badly wrong.
- **Double maintenance** — two engines to keep in step until phase 5 removes the
  client's margin knowledge. Keep phases 3–5 close together.
- **Preset and reorder paths** carry prices through URLs and order meta; both need
  re-pricing on the server rather than trusting the stored figure.
- **The imposition tool** ports `flatGrid`/`flatImp`/`stickerImp` verbatim from the
  calculators (see CLAUDE.md). Those are physical, not margin, so they stay — but if
  any imp function moves server-side, the tool's copies move in the same commit.
