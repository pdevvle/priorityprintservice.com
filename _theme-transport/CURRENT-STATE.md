# Priority Print — Current Theme State

**Last updated:** 2026-04-23
**Last changed by:** Claude Code — theme bootstrap session
**Deploy state:** Not yet deployed. Local repo only. See `DEPLOYMENT.md` for cutover plan.

---

## Active files

| Path | Purpose | Lines (approx.) |
|------|---------|-----------------|
| `style.css` | Theme header + brand CSS variables + base styles | ~400 |
| `functions.php` | Enqueues, menus, theme supports, WooCommerce wrappers, SEO include | ~140 |
| `index.php` | WordPress fallback template | ~65 |
| `header.php` | Site header (top bar, logo, primary nav, hamburger) | ~70 |
| `footer.php` | 3-column footer + copyright bar | ~60 |
| `front-page.php` | Homepage (hero, services, values, CTA banner, Gutenberg passthrough) | ~125 |
| `archive-product.php` | WooCommerce category / shop grid | ~60 |
| `inc/seo-class.php` | `PP_SEO` — title, meta, OG, canonical, site-wide JSON-LD | ~200 |
| `assets/css/woocommerce.css` | Layered WooCommerce visual overrides | ~170 |
| `assets/js/main.js` | Mobile nav toggle | ~55 |

## Brand values (currently wired)

| Token | Value | Source |
|-------|-------|--------|
| Primary | `#007EFF` | `style.css` → `:root` → `--pp-blue` |
| Accent | `#E05C1A` *(placeholder)* | `style.css` → `:root` → `--pp-orange` |
| Ink | `#101114` | `style.css` → `:root` → `--pp-ink` |
| Light bg | `#F5F5F3` | `style.css` → `:root` → `--pp-bg-light` |
| Body font | `Source Serif 4` | `style.css` → `:root` → `--pp-font-body`; loaded in `functions.php` |
| Heading font | `Barlow Condensed` | `style.css` → `:root` → `--pp-font-head`; loaded in `functions.php` |
| Logo fallback | `https://priorityprintservice.com/wp-content/uploads/2023/06/2021-Logo-full-16.png` | `functions.php` → `pp_logo_url()` |
| Phone (footer + schema) | `+1-623-977-8888` | `inc/seo-class.php` → `PP_SEO::PHONE`; `footer.php` (hardcoded) |

## SEO schema ownership

- **Theme owns** (via `PP_SEO` in `inc/seo-class.php`): title, meta description, OG, Twitter, canonical, homepage Organization + LocalBusiness + WebSite JSON-LD.
- **Plugin owns** (in `pps-calculators.php`): Product, WebApp, FAQ JSON-LD on calculator pages. Detected via `_pps_calculator` post meta; theme suppresses its own homepage schema on calc pages.
- **Yoast SEO** is scheduled to be uninstalled at cutover. Until then, both Yoast and `PP_SEO` will emit head tags — **do not activate this theme on a site with Yoast still active** without first disabling Yoast, or you will get duplicate meta descriptions / canonicals.

## Menus (WP Appearance → Menus)

| Theme location | Slug | First-run fallback |
|----------------|------|--------------------|
| Primary Navigation | `primary` | Shop / About / Quote / Contact (hardcoded in `header.php` when no menu is assigned) |
| Footer Navigation | `footer` | Shop / About / Get a Quote / Contact (hardcoded in `footer.php` when no menu is assigned) |

## Known issues

- **`#E05C1A` orange is a placeholder.** Confirm with Preston, then update `style.css` → `--pp-orange` and re-run MCP write + Git mirror.
- **Footer address / email is hardcoded.** `hello@priorityprintservice.com` and the phone number in `footer.php` are not options yet. Move to a Customizer setting or an options page when Preston asks — not before.
- **No single-product / single / page templates.** WordPress falls back to `index.php` (or WC's `templates/single-product.php`). If Preston wants a branded single-product page, that's a new template file.
- **WCPA styling not audited.** ACOWEBS WCPA adds form markup on product pages. `assets/css/woocommerce.css` doesn't touch WCPA classes yet — styling may feel inconsistent on product detail pages until reviewed on staging.

## Pending changes (discussed, not shipped)

- **Cutover plugin edit.** Remove Yoast-suppression code from `pps-calculators.php` once Yoast is uninstalled; move LocalBusiness JSON-LD out of the plugin (theme already emits it). Must ship to the plugin repo (`pdevvle/priorityprintservice.com`) in a separate commit, after the theme is activated on staging and confirmed working.
- **AI Engine / MCP install.** Not yet installed on production WordPress. Once installed, Preston runs `claude mcp add priority-print https://priorityprintservice.com/wp-json/mcp/v1/<token> --transport http` to connect Claude Code.
- **Second Cloudways deploy slot.** The plugin's deploy-via-Git slot exists; the theme needs its own. See `DEPLOYMENT.md` for the one-time setup.

## Session rituals for Claude Code

At the start of every session on this repo:

1. Read `CURRENT-STATE.md` (this file) for a live snapshot.
2. Read the specific file(s) about to change via MCP on the live server — never work from memory.
3. Make the change. Write via MCP **and** commit to Git in the same session.
4. Append the change to `CHANGELOG.md` with the previous version inline.
5. Overwrite `CURRENT-STATE.md` to reflect the new live state.
