# PPS Product Calculators

WordPress/WooCommerce plugin for Priority Print Service — pricing calculators with integrated artwork proofing.

## Architecture
- Self-contained React calculators (HTML files with inline Babel)
- PHP plugin handles cart, orders, REST API, SEO, Google Drive
- All calculators share: shipping/rush engine, zone map, tooltip system, debug panel

## Files
| File | Purpose | Size |
|------|---------|------|
| `calc-preview-test.html` | Saddle stitch booklet calculator | ~4100 lines |
| `calc-perfect-bound.html` | Perfect bound booklet calculator | ~4200 lines |
| `calc-brochure.html` | Brochure & flat printing calculator | ~1200 lines |
| `pps-calculators.php` | WP plugin: cart, orders, SEO, tooltips, reorder | ~1600 lines |
| `pps-config-admin.php` | Admin config: pricing constants, papers, zone map, tooltips | ~1100 lines |
| `pps-gdrive.php` | Google Drive OAuth + artwork upload | ~550 lines |
| `ups-zone-map-seed.json` | UPS Ground transit days by ZIP prefix | 1000 entries |

## Key Patterns
- PCF constants at top of each calculator, overridable via PPS_CONFIG.calc from PHP
- `calculate()` function is the pricing engine — different per product type
- SetPreview (booklets) / SheetPreview (brochure) handles artwork upload + proof
- RichTip component reads tooltips from PPS_CONFIG.tips (centralized in PHP)
- Shipping section with DatePicker, rush calculation, production dates is shared
- Zone map embedded in HTML for standalone testing, overridden by PHP in production

## Branches
- `pps-pricing-config` — active development branch
- GitHub Pages serves from this branch for preview
