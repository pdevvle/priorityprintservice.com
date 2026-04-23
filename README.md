# Priority Print — WordPress theme

Custom standalone WordPress theme for [priorityprintservice.com](https://priorityprintservice.com/). Replaces Astra Pro + Yoast SEO with a lightweight, Claude-Code-maintainable codebase.

This theme lives on branch **`theme-v1`** of the existing `pdevvle/priorityprintservice.com` repository (not in its own repo). The `pps-calculators` plugin lives on the `production` branch of the same repo. Two Cloudways deploy slots pull the two branches independently. See `DEPLOYMENT.md`.

## Stack this assumes

- WordPress + WooCommerce
- [ACOWEBS WCPA](https://acowebs.com/woocommerce-product-addons/) for dynamic product configuration (untouched by this theme)
- `pps-calculators` plugin for calculator pages (owns Product/WebApp/FAQ schema on those pages)
- [AI Engine](https://wordpress.org/plugins/ai-engine/) free plugin for MCP access from Claude Code

## Files

| File | Owns |
|------|------|
| `style.css` | Theme header (activates theme in WP), brand CSS variables, base styles |
| `functions.php` | Enqueues, menus, theme supports, WooCommerce wrapper overrides, SEO include |
| `index.php` | WordPress fallback template (required for theme to activate) |
| `header.php` | Top bar + logo + primary nav (sticky, mobile hamburger) |
| `footer.php` | 3-column footer + copyright bar |
| `front-page.php` | Homepage: hero, services, values, CTA banner, Gutenberg passthrough |
| `archive-product.php` | WooCommerce category / shop grid |
| `inc/seo-class.php` | `PP_SEO` — title, meta, OG, canonical, site-wide JSON-LD |
| `assets/css/woocommerce.css` | Layered WooCommerce visual overrides |
| `assets/js/main.js` | Mobile nav toggle (vanilla JS) |
| `DEPLOYMENT.md` | GitHub ↔ Cloudways pipeline setup + day-to-day flow |
| `CHANGELOG.md` | Per-session change log with full-file rollback snapshots |
| `CURRENT-STATE.md` | Living snapshot of what is deployed right now |

## Brand values (update in `style.css` `:root`)

| Token | Value |
|-------|-------|
| Primary | `#007EFF` |
| Accent | `#E05C1A` (placeholder until confirmed) |
| Ink | `#101114` |
| Light bg | `#F5F5F3` |
| Body font | `Source Serif 4` |
| Heading font | `Barlow Condensed` |
| Logo | https://priorityprintservice.com/wp-content/uploads/2023/06/2021-Logo-full-16.png |

## SEO ownership split

This theme's `PP_SEO` class owns site-wide head tags (title/meta description/OG/canonical) and the homepage's Organization + LocalBusiness + WebSite JSON-LD.

The `pps-calculators` plugin continues to own calculator-page schemas (Product / WebApp / FAQ) because those depend on calculator configuration state the theme cannot see. `PP_SEO::is_calculator_page()` detects those pages via the `_pps_calculator` post meta and skips the theme's site-wide schema on them to avoid duplicates.

## Not in v1 (intentional)

- `single.php` / `page.php` / `single-product.php` — WordPress falls back to `index.php` and WC's default templates
- Cart / checkout styling — default WooCommerce
- Admin dashboard or Customizer panels for brand colors
- Contact form plugin integration

## Conventions for future edits

- **Vanilla only.** No jQuery, no CSS framework, no build step, no npm.
- **Escape on output.** Use `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` as appropriate. Never `echo $user_input;` directly.
- **One font request.** Google Fonts is one combined CSS2 URL — adding a new face means editing that URL, not adding a second stylesheet.
- **WooCommerce template overrides go through hooks + CSS**, never by copying WC template files into the theme. That keeps WC plugin updates safe.
- **Never merge `theme-v1` into any other branch.** It's a parallel history in the same repo, not a feature branch.
