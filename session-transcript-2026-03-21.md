# PPS Session Transcript — March 21, 2026

## Context
Preston uploaded all 4 plugin files and a long conversation history document covering work from Feb 28 through ~Mar 20. This session picks up from the last prompt in that history.

## Changes Made This Session

### 1. Calculator (calc-preview-test.html)

**"Biz Days" → "Availability" (range display)**
- Panel sidebar and mobile compact bar now show "Availability" with a range: `{earliestBizDays}–{freeDeliveryBizDays} days`
- If both values are equal, shows just the single number
- Replaces the old static "Est. Days" / single number display

**Transit labels removed from state dropdown**
- State dropdown options now show just "AZ", "CA", etc. — no more "AZ — 1 day transit"
- Transit data still drives calculations behind the scenes

**Free Delivery info box cleaned up**
- Shows "X business days" instead of "X biz days — Xd×2 prod + Yd transit"
- Earliest possible line simplified to just "X business days" without formula breakdown
- Transit internals hidden from customer view

**Full shipping address fields**
- New `shipAddr` state: `{name, company, street1, street2, city, zip}`
- Shipping section has: Full Name, Company (optional), Street Address, Address Line 2 (optional), City, State dropdown, ZIP Code
- State dropdown still drives transit time lookup
- Address included in order metadata and summary, restored from reorder config

**UPS Zone Map integration**
- `ZONE_MAP` loaded from `PPS_CONFIG.zoneMap` — 1,000-entry JSON (3-digit ZIP prefix → transit days)
- `getTransitDays(state, zip)` — tries zip-prefix lookup first, falls back to state-level map
- `shipZip` derived from `shipAddr.zip`, passed to `calculate()` and projection table
- Transit estimates update instantly as customer types their zip — zero API calls

**Shippo groundwork (not yet active)**
- PCF config: `shippo_api_token` (empty = use static map), `shippo_origin_zip` ("85027")
- ZIP input has marked integration point for future debounced Shippo call
- Comment block in the zip onChange handler explains the future flow

### 2. Plugin (pps-calculators.php)

**Zone map injection**
- Reads `pps_ups_zone_map` from `wp_options` and passes as `PPS_CONFIG.zoneMap`
- Only included when map exists and is non-empty

**Zone map seed on activation**
- `pps_seed_zone_map()` generates initial map from state transit data expanded to all 1,000 3-digit ZIP prefixes
- Runs automatically on plugin activation if no map exists
- Marked as "(initial seed)" in the timestamp so admin knows to upload real UPS data

**4 REST API endpoints added:**
- `GET /wp-json/pps/v1/shipping/address` — returns logged-in user's WC shipping address
- `POST /wp-json/pps/v1/shipping/address` — saves address to WC user meta (splits name→first/last)
- `POST /wp-json/pps/v1/shipping/validate` — Shippo address validation proxy (501 if no token)
- `POST /wp-json/pps/v1/shipping/transit-estimate` — Shippo transit time by zip (501 if no token)

### 3. Config Admin (pps-config-admin.php)

**UPS Ground Zone Map section** (Shipping tab, top)
- Shows current map stats: prefix count, distribution by transit day, last update timestamp
- CSV upload: accepts UPS zone chart saved as CSV
- JSON paste: alternative direct input
- `pps_parse_ups_zone_csv()` parser handles UPS format (ranges like "004-005", single prefixes, extracts Ground column)
- Form has `enctype="multipart/form-data"` for file upload
- Save handler processes CSV upload first, then JSON paste as fallback

**Shippo Integration section** (Production tab)
- API Token and Origin ZIP fields
- Save handler sanitizes as text fields

**State transit table relabeled**
- Now reads "State Transit Fallback (used when zone map is missing a prefix)"

### 4. Seed Data (ups-zone-map-seed.json)
- 1,000-entry JSON map: every 3-digit ZIP prefix → transit days from Phoenix
- Built from existing state-level transit data, verified against all 50 states
- Can be pasted directly into the admin JSON field if activation seed didn't run

## File States
| File | Lines | Status |
|------|-------|--------|
| calc-preview-test.html | 3188 | Updated — zone map, address fields, availability display |
| pps-calculators.php | 1238 | Updated — zone map injection, seed, REST endpoints |
| pps-config-admin.php | 947 | Updated — zone map admin UI, CSV parser, Shippo fields |
| pps-gdrive.php | 539 | Unchanged |
| ups-zone-map-seed.json | 1 | New — initial seed data |

## How to Update the Zone Map
1. Go to https://www.ups.com/us/en/support/shipping-support/shipping-costs-rates/daily-rates
2. Download zone chart for origin ZIP 850
3. Open in Excel → Save As → CSV
4. WP Admin → PPS Calculators → ⚙ Central Config → Shipping tab
5. Upload the CSV → Save
6. Recommended: check quarterly (Jan, Apr, Jul, Oct)

## Pending / Not Yet Implemented
- Shippo live integration (endpoints built but not wired to frontend — needs API token)
- WC address recall on page load (endpoint exists, frontend fetch not wired)
- International shipping rates via Shippo
- Address validation UI (suggested address correction prompt)
