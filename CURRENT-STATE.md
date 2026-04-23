# Priority Print — Current Theme State

**Last updated:** 2026-04-23
**Last changed by:** Claude Code — theme bootstrap session
**Repo / branch:** `pdevvle/priorityprintservice.com` @ `theme-v1`
**Deploy state:** Pushed to GitHub. Not yet pulled by Cloudways. Not yet activated in WordPress.

---

## Architecture reminder

The theme lives on the `theme-v1` branch of the existing plugin repo. The plugin lives on the `production` branch of the same repo. These two branches never share files and never merge. Two independent Cloudways deploy slots pull them into two separate server paths.

See `DEPLOYMENT.md` for the deploy-slot setup.

## Active files

| Path | Purpose | Lines (approx.) |
|------|---------|-----------------|
| `style.css` | Theme header + brand CSS variables + base styles | ~500 |
| `functions.php` | Enqueues, menus, theme supports, WooCommerce wrappers, SEO include | ~175 |
| `index.php` | WordPress fallback template | ~65 |
| `header.php` | Site header (top bar, logo, primary nav, hamburger) | ~70 |
| `footer.php` | 3-column footer + copyright bar | ~70 |
| `front-page.php` | Homepage (hero, services, values, CTA banner, Gutenberg passthrough) | ~150 |
| `archive-product.php` | WooCommerce category / shop grid | ~70 |
| `inc/seo-class.php` | `PP_SEO` — title, meta, OG, canonical, site-wide JSON-LD | ~240 |
| `assets/css/woocommerce.css` | Layered WooCommerce visual overrides | ~215 |
| `assets/js/main.js` | Mobile nav toggle | ~60 |

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
- **Yoast SEO** is scheduled to be uninstalled at cutover. Until then, both Yoast and `PP_SEO` will emit head tags — **do not activate this theme on a site with Yoast still active for more than a few minutes** without then deactivating Yoast, or search engines will see duplicate meta descriptions / canonicals.

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

- **Cutover plugin edit.** Remove Yoast-suppression code from `pps-calculators.php` once Yoast is uninstalled; move LocalBusiness JSON-LD out of the plugin (theme already emits it). This ships as a separate commit to the `production` branch of this same repo, **after** the theme is activated and confirmed working.
- **AI Engine / MCP install.** Not yet installed on production WordPress. Once installed, Preston runs `claude mcp add priority-print <url> --transport http` to connect Claude Code.
- **Second Cloudways deploy slot.** The plugin's deploy-via-Git slot exists; the theme needs its own pointing at branch `theme-v1`. See `DEPLOYMENT.md` for the one-time setup (4 Cloudways fields + one GitHub deploy-key paste).

## Session rituals for Claude Code

At the start of every session on this theme branch:

1. `git checkout theme-v1` before making edits.
2. Read `CURRENT-STATE.md` (this file) for a live snapshot.
3. Read the specific file(s) about to change via MCP on the live server — never work from memory.
4. Make the change. Write via MCP **and** commit to Git (branch `theme-v1`) in the same session.
5. Append the change to `CHANGELOG.md` with the previous version inline.
6. Overwrite `CURRENT-STATE.md` to reflect the new live state.
7. `git push origin theme-v1`.

Never merge `theme-v1` into any other branch. Never merge other branches into `theme-v1`.
