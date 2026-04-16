# Priority Print Service — Product Calculators

WordPress/WooCommerce plugin suite for Priority Print Service. Includes interactive pricing calculators with artwork proofing, a centralized admin config panel, and Google Drive integration for artwork management.

## Repository Layout

```
calculators/          HTML calculators served via GitHub Pages (standalone React + inline Babel)
wp-plugins/           WordPress plugin PHP files (deployed to WP server)
data/                 Reference data (UPS zone map)
docs/                 Pricing strategy, rollback, and philosophy documentation
```

## Calculators

| File | Product Type |
|------|-------------|
| `calculators/calc-preview.html` | Saddle-stitch booklets — most mature, full proof/preview/approval system |
| `calculators/calc-perfect-bound.html` | Perfect-bound booklets — mixed color per-set, outfold, perforation |
| `calculators/calc-brochure.html` | Brochures & flat printing — 9 fold types, 3D fold preview |
| `calculators/brochure-fold-previewer.html` | Standalone 3D fold preview reference tool |

Open any calculator HTML directly in a browser — no server needed. All dependencies load from CDN (React, pdf.js, jsPDF, Babel). The zone map is embedded for ZIP-based transit lookups. Submit triggers local file downloads instead of WooCommerce cart.

## WordPress Plugins

| File | Purpose |
|------|---------|
| `wp-plugins/pps-calculators.php` | Cart, orders, REST API, SEO schemas, shipping, tooltips injection |
| `wp-plugins/pps-config-admin.php` | Admin config page: pricing constants, papers, finishing, shipping |
| `wp-plugins/pps-gdrive.php` | Google Drive OAuth for artwork upload and thumbnail generation |

Upload the PHP files to `wp-content/plugins/pps-calculators/` on your WordPress server. Upload calculator HTML via the plugin admin (PPS Calculators → Upload). Assign to a WooCommerce product.

## Data

| File | Purpose |
|------|---------|
| `data/ups-zone-map-seed.json` | UPS Ground transit days by 3-digit ZIP prefix (1,000 entries) |

## Branch Strategy

- **`pps-pricing-config`** — primary development branch. GitHub Pages serves from here.
- **`website`** — deployment branch for the live site. Merge from `pps-pricing-config` when ready to deploy.
- `.nojekyll` is mandatory — without it, Jekyll breaks inline JSX/Babel `{{ }}` syntax.
