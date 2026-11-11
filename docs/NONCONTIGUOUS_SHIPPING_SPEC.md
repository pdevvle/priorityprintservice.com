# Non-contiguous shipping — UPS-rated cost and transit for AK, HI, territories

**Owner decision (2026-08-11):** serve AK/HI and US territories, priced from a
**live UPS rate** (never FedEx, never USPS), with the **UPS transit days
replacing the static ground-days estimate** for those destinations.

## The liability this closes

Both AK and HI are already in `pps_seed_zone_map()` at **7 transit days**, and
the pricing model treats shipping as free. So today a Honolulu customer gets a
**free-shipping quote with a 7-business-day promise** — on a lane UPS does not
serve by ground at all. Every quote to those states is currently both underpriced
and undeliverable as promised.

## What already exists (most of this is wiring, not new machinery)

`POST /pps/v1/shipping/transit-estimate` (pps-calculators.php ~3510) already:

- calls Shippo `/shipments/` with origin ZIP → destination ZIP/state/country
- **filters to UPS only**, excluding Ground Saver and SurePost (owner rule
  2026-07-19) — other carriers on the Shippo account are ignored entirely
- prefers true UPS Ground, else falls back to the **slowest** remaining UPS
  service as a ground stand-in
- returns `transit_days`, `carrier`, `service`, `amount`, `parcels`,
  `est_weight_lb`
- caches 30 days per destination, rate-limits per IP, degrades to `null`
  (calculator keeps its static estimate) when no UPS rate exists

Verified live on production 2026-08-10: 18/18, real UPS rates, guest-reachable.
So this spec is mostly **using the `amount` we already fetch** and **trusting
`transit_days` over the static map** for one class of destination.

## Destination classes — the rate response IS the classifier

**Owner rules (2026-08-11): no margin on the UPS rate, and any address UPS
will not deliver to is treated as foreign.** Together those collapse the
design to two rules and remove the hardcoded territory list from the runtime
path entirely:

| Class | Test | Behaviour |
|---|---|---|
| **Contiguous** | 48 states + DC | Unchanged: free ground, static map + live transit |
| **Rated** | UPS returns a rate | Charge that rate **at cost, no margin**; use that rate's transit days |
| **Foreign** | UPS returns no rate | No self-serve checkout — "Contact us for a shipping quote" |

Asking UPS is better than maintaining a list, because UPS's own coverage is
the truth and it changes without telling us. APO/FPO/DPO (military mail is
USPS-only), American Samoa, the Minor Outlying Islands, and the Freely
Associated States all fall out as **foreign** automatically — no rate comes
back, so no quote is offered. Guam, Puerto Rico, USVI and the Northern
Marianas fall out as **rated** when UPS prices them, and silently become
foreign if UPS ever stops.

The exhaustive list below is therefore **reference, not logic** — useful for
copy, QA fixtures, and knowing what to expect, but nothing branches on it.

### Exhaustive US non-contiguous reference list

- **Non-contiguous states:** AK (Alaska), HI (Hawaii)
- **Inhabited territories:** PR (Puerto Rico), VI (US Virgin Islands),
  GU (Guam), AS (American Samoa), MP (Northern Mariana Islands)
- **Minor Outlying Islands (UM):** Baker, Howland, Jarvis, Johnston Atoll,
  Kingman Reef, Midway, Navassa, Palmyra, Wake
- **Military ZIPs:** AA (Americas), AE (Europe/Mideast/Africa), AP (Pacific) —
  USPS-only, so always foreign under the UPS-only rule
- **Freely Associated States** (USPS-domestic but *not* US territories):
  FM (Micronesia), MH (Marshall Islands), PW (Palau)

## Changes required

### 1. Rate selection must pair days and price from ONE rate

The current fallback picks the **slowest** UPS service. Once the rate is also
the price, slowest-vs-cheapest matters, and mixing them is a correctness bug:
quoting 2nd Day Air's transit against 3 Day Select's price promises a speed
that was not purchased.

Rule: **for rated destinations select the cheapest UPS service that serves the
lane, then take `estimated_days` and `amount` from that same rate object.**
Contiguous behaviour is unchanged (prefer true Ground).

### 2. Ground-days offset

For rated destinations the static `pps_seed_zone_map()` value is a placeholder
only. When a live rate exists, its `estimated_days` **replaces** the map value
in `freeDeliveryBizDays = bufferedProdDays + transitDays`. When no live rate
exists, the destination is foreign and no date is promised at all — the 7-day
AK/HI map entries should be raised to a conservative stand-in (AK 7, HI 8) purely
as a pre-address placeholder, never as a committed date.

### 3. Cost enters the Grand Total, not checkout

The price card now reads *"Includes all applicable taxes, fees, and shipping."*
Any AK/HI/territory surcharge must therefore be **inside** the quoted total. A
checkout-added surcharge would make that line false. The rated amount flows
through the same `shipping` object the engine already returns, at cost.

### 4. Config knobs (Central Config → Shipping)

| Key | Default | Meaning |
|---|---|---|
| `noncontig_enabled` | `false` | Master switch for rated destinations |
| `noncontig_quote_email` | (admin) | Where "contact us for a shipping quote" requests land |

**No margin knob by owner decision** — the UPS rate is passed through at
cost. Note the consequence: UPS rate drift between quote and label is absorbed
by the shop, and the quote is only as fresh as the 30-day cache. If that
proves painful, the fix is shortening the cache for rated destinations, not
quietly reintroducing a markup.

### 5. Failure posture

A Shippo timeout on a contiguous order is harmless (static map covers it). On a
rated destination it is **not** — there is no safe fallback price. On rate
failure the calculator must show "we'll confirm shipping for this destination"
and route to the quote path rather than quoting free shipping. **Never fall back
to $0 for a rated destination.**

## Pricing-table implications

- **The Grand Total label change did not break `tools-pricing-matrix.mjs`** —
  verified: the scraper reads the quantity-tier `<tbody>` rows, not the total
  label.
- The matrix runs against GitHub Pages previews, where `PPS_CONFIG` has no
  Shippo token, so `shippo_enabled` is false and no live rate is fetched. The
  matrix therefore captures the **print price**, not landed cost — that boundary
  should be stated in `docs/PRICING_MATRIX.md` so a future session does not read
  a matrix number as a customer-facing total.
- Add a small **destination slice** to the matrix (one AK ZIP, one HI ZIP, one
  contiguous control) once rating is live, so surcharge regressions are caught.
  This requires the matrix harness to run against a PHP-backed environment
  rather than Pages, or to stub the rate endpoint.

## Copy shipped 2026-08-11 (ahead of the rating work)

Price card subline now carries an asterisk, with fine print at the foot of the
panel:

> *Printing bound for AK, HI, PR, VI, Guam, other U.S. territories, APO/FPO/DPO
> addresses, or non-U.S. destinations may carry additional shipping cost.*

This is deliberately live **before** rating is built: it makes the current
free-shipping quote to those states a disclosed possibility rather than a
promise, which narrows the exposure while the work is scheduled.

## Build order

1. Destination classifier + the fine print (fine print **done**, 2026-08-11).
2. Cheapest-serving-rate selection, days and price from one rate object.
3. Rated cost into the engine's `shipping` object, inside the Grand Total.
4. Transit-days override for rated destinations; conservative map placeholders.
5. Foreign path for unservable lanes.
6. Matrix destination slice.

## Implemented for pay links, 2026-11-04 (not yet for the calculators)

The **pay-link quote page** now does for foreign destinations what this spec
describes for rated US ones. The calculators still do not — items 1–6 of the
build order above remain open for them.

What shipped, and where it differs from the spec above:

| Spec says | Pay links do | Why |
|---|---|---|
| Rated vs foreign decided by whether UPS returns a rate | Same | Asking UPS beats maintaining a country list |
| Surcharge **inside** the quoted total | **Added on top**, as its own WooCommerce shipping line | A pay link's price is one figure an operator typed in a conversation; it was quoted before any address existed, so it cannot already contain the freight. Shown itemised: printing + shipping = total |
| Cheapest serving rate, days and price from one rate object | Same | The domestic endpoint picks the *slowest* UPS service, which is fine for a duration and a correctness bug once it is also the price |
| 30-day cache | **3 days** | That figure is a duration; this one is a price. Fuel surcharges move. `pps_quote_intl_ttl()` |
| No margin | Same | Owner rule: passed through at cost |
| Never fall back to $0 | Same, enforced | No rate means no order. `pps_quote_ship_charge()` returns `WP_Error` and the page disables its own submit button |

**The weight is the whole constraint.** A rate needs a parcel weight and nothing
in a pay link has one — the calculators derive it from sheets and paper stock, an
operator-typed link carries a description and a price. So it is supplied
explicitly as `~18lb`, and a link without one offers no country choice at all.
The parcel split is a **verbatim port** of the 45 lb / 19×13×6 carton math in
`pps-calculators.php`; if that rule changes, change both in the same commit.

**The existing transit endpoint could not be used.** `POST
/pps/v1/shipping/transit-estimate` strips the postcode to digits and 400s under
five, so `M5V 3L9` becomes `53`, and it is hardwired to `country: US`. It also
lives in `pps-calculators.php`, which this lane must not deploy. Hence
`POST /pps/v1/quote/ship-quote` in `pps-job-quote.php`. The page's domestic
transit line is now guarded to US destinations — a German `10115` is five digits
and would otherwise have come back with a confident US ground estimate.

**Labels are still bought by hand.** `pps-missive-ship.php` refuses non-US
outright ("International labels need customs forms — buy them in Shippo"), so
an international order carries an order note saying so. Duties are DDU: the page
tells the customer the carrier collects import duties and taxes from the
recipient.

Regression gate: `tools-tests/quoteintl.php` (108 assertions, mutation-tested).

### UPS will not rate an international lane without a customs declaration

Caught on **2026-11-04, before deploy**, by rating a real lane against the live
Shippo account instead of trusting the code. Phoenix → Toronto, 30 lb, parcel
and addresses only:

| | |
|---|---|
| Rates returned | **15 — FedEx, USPS, DHL. Not one UPS.** |
| UPS said | `111549 Hard: Invalid Shipment Contents Value`<br>`111210 Hard: The requested service is unavailable between the selected locations` |
| Shippo said | `Attribute "customs_declaration" must not be empty` |

The response looked healthy. Rates came back. But this code filters to UPS by
owner rule, so **every foreign address would have been refused as unservable**
and the feature would have read as "no carrier serves Canada" — with nothing in
any log pointing at the real cause.

The same lane **with** a declaration returns four UPS services:

| UPS service | price | days |
|---|---|---|
| **Standard℠** | **$53.08** | 4 |
| Worldwide Expedited® | $100.33 | 2 |
| Saver® | $127.55 | 1 |
| Worldwide Express® | $137.29 | 1 |

So the owner's UPS-only rule is sound internationally — the request was simply
incomplete. `pps_quote_intl_customs()` now builds it. What it carries, and why:

- **The declared value is the print price, in full.** UPS prices partly on it
  (that is the `111549`), and it is what the recipient is assessed duty on.
  Under-declaring to spare them is fraud.
- **`DDU`** — the recipient pays duties, which is exactly what the payment page
  tells them. `DDP` would bill the shop for duties nobody quoted.
- **`RETURN`**, not `ABANDON` — an undeliverable job comes home.
- **`NOEEI_30_36`** for Canada, `NOEEI_30_37_a` elsewhere, per Shippo's guidance.
- **A generic commodity** (`Printed material`, HS `4901.99`) rather than the
  operator's spec. FedEx rejected a spec-derived description as inconsistent
  with its HS code, and a customs declaration is not the place to discover that.
  **This declaration is for RATING ONLY** — the real paperwork is filled in by a
  human when the label is bought in Shippo.

Two related corrections the same test forced:

- **The origin is now a full address** (`pps_quote_intl_origin()`, from the
  WooCommerce store address, with the configured Shippo origin ZIP still
  winning). The domestic lookup sends zip + country and Shippo is content; an
  export is a different question and could not be verified in that thin form.
- **The destination carries street and recipient name.** The street is in the
  cache key as well as the request, because a residential surcharge is
  address-driven; the name is deliberately *not*, because it cannot change a
  rate and keying on it would miss the cache for every new customer at a
  known address.

Verified by sending **the exact payload the PHP builds** to the live API: UPS
Standard, $53.08, 4 days — the cheapest of all 15 rates — which is what
`pps_quote_pick_intl_rate()` selects when fed that real response.

One note worth keeping: UPS returned `110971 RatedShipmentAlert: Your invoice
may vary from the displayed reference rates`. The shop absorbs that drift by
the no-margin rule, which is the exposure the 3-day cache bounds.

