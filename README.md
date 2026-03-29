# PPS Product Calculators

Custom WordPress/WooCommerce plugin for Priority Print Service — saddle-stitch booklet pricing calculator with integrated artwork proofing.

## Files

| File | Purpose |
|------|---------|
| `calc-preview-test.html` | Self-contained React calculator (works standalone or embedded in WooCommerce) |
| `pps-calculators.php` | WordPress plugin: cart, orders, REST API, SEO schemas, shipping |
| `pps-config-admin.php` | Admin config page: pricing constants, paper lists, zone map upload |
| `pps-gdrive.php` | Google Drive OAuth integration for artwork file management |
| `ups-zone-map-seed.json` | UPS Ground transit days by 3-digit ZIP prefix (1,000 entries) |

## Standalone Testing

Open `calc-preview-test.html` directly in a browser — no server needed. All dependencies load from CDN (React, pdf.js, jsPDF, Babel). The zone map is embedded for zip-based transit lookups. Submit triggers local file downloads instead of WooCommerce cart.

## WordPress Deployment

Upload the PHP files to `wp-content/plugins/pps-calculators/`. Upload `calc-preview-test.html` via the plugin admin (PPS Calculators → Upload). Assign to a WooCommerce product.
