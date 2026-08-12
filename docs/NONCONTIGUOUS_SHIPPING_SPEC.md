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

## Destination classes

| Class | Members | Behaviour |
|---|---|---|
| **Contiguous** | 48 states + DC | Unchanged: free ground, static map + live transit |
| **Rated** | AK, HI, PR, VI, GU, MP | UPS live rate charged; UPS transit days used |
| **Quote-only** | AS, UM, AA/AE/AP (APO/FPO/DPO), FM, MH, PW, all non-US | No self-serve checkout — "Contact us for a shipping quote" |

**Why quote-only is a real class, not a cop-out:** UPS cannot deliver to
APO/FPO/DPO at all (military mail is USPS-only), and American Samoa, the Minor
Outlying Islands and the Freely Associated States (Micronesia, Marshall Islands,
Palau) are not UPS domestic destinations. Since the owner rule forbids USPS,
these lanes have no rate to quote. Returning "contact us" is the honest answer;
inventing a surcharge would be a promise we cannot keep.

### Exhaustive US non-contiguous list (for the classifier)

- **Non-contiguous states:** AK (Alaska), HI (Hawaii)
- **Inhabited territories:** PR (Puerto Rico), VI (US Virgin Islands),
  GU (Guam), AS (American Samoa), MP (Northern Mariana Islands)
- **Minor Outlying Islands (UM):** Baker, Howland, Jarvis, Johnston Atoll,
  Kingman Reef, Midway, Navassa, Palmyra, Wake — effectively unshippable
- **Military ZIPs:** AA (Americas), AE (Europe/Mideast/Africa), AP (Pacific) —
  USPS-only, so UPS-only means quote-only
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
exists, the destination is quote-only and no date is promised at all — the 7-day
AK/HI map entries should be raised to a conservative stand-in (AK 7, HI 8) purely
as a pre-address placeholder, never as a committed date.

### 3. Cost enters the Grand Total, not checkout

The price card now reads *"Includes all applicable taxes, fees, and shipping."*
Any AK/HI/territory surcharge must therefore be **inside** the quoted total. A
checkout-added surcharge would make that line false. The rated amount flows
through the same `shipping` object the engine already returns, with a config
margin applied.

### 4. Config knobs (Central Config → Shipping)

| Key | Default | Meaning |
|---|---|---|
| `noncontig_enabled` | `false` | Master switch for rated destinations |
| `noncontig_margin_pct` | `15` | Margin on the UPS rate (handling, packaging, rate drift) |
| `noncontig_min_charge` | `25` | Floor, so a tiny parcel still covers handling |
| `noncontig_classes` | `AK,HI,PR,VI,GU,MP` | Which destinations are rated vs quote-only |

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
5. Quote-only path for unservable lanes.
6. Matrix destination slice.
