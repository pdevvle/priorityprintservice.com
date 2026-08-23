# Handoff brief — deploy the shop restyle + reorder category pages

**This brief is for a fresh, _interactive_ Claude Code session that has the
WordPress/staging connectors authorized** (the servers named `PPS-Production`,
`WordPress_com`, `priority-print`). The session that wrote this brief was a
non-interactive remote session and could not complete the MCP OAuth flow, so it could
neither deploy nor touch the WordPress database. Read it in full (or paste it into that
session). Everything below is code-verified against the repo as of the commit named in §1.

---

## 0. Context you need

- **Project:** `priorityprintservice.com` — a WordPress/WooCommerce site. The pricing
  calculators are self-contained React HTML files; the PHP plugin (`pps-calculators.php`
  + siblings) handles cart/orders/SEO/etc. Read `CLAUDE.md` at the repo root in full
  before touching anything — its rules are binding and override defaults.
- **Operator environment:** the owner does **not** use a local terminal and never SSHes
  into the server. All deploys happen through Claude via the WordPress connector /
  pull-based plugin deploy. Never tell the owner to "run this locally."
- **Working branch:** `claude/optimistic-wozniak-11ql3y`. Develop here, commit, push.
  The open draft PR is **#41**.
- **Two Pages branches exist** (`pps-pricing-config`, `pages-public`) but they only serve
  the static calculator HTML. **The shop and category pages are server-rendered
  WooCommerce** — they cannot be previewed on GitHub Pages. You verify them on the live
  WordPress site via the connector, not on Pages.

---

## 1. What is already done — do NOT redo

Commit **`378edb3`** on `claude/optimistic-wozniak-11ql3y` ("Shop archive: modern restyle
to match category pages") is pushed. It edits **`pps-term-shortcodes.php`** only:

1. The category shortcode CSS block (`add_action('wp_head', …)`, guard near the top of
   the file) now also fires on `is_shop()`, so `/shop/`'s `ul.products` grid, prices, and
   buttons pick up the existing modern card styling.
2. A new `woocommerce_before_main_content` action (priority 5, guarded `is_shop()`)
   injects a category-style masthead on `/shop/` — it reuses the `.pps-cat-hero` class,
   pulls the title from the WooCommerce shop page, allows an optional
   `_pps_shop_subtitle` post-meta override on the shop page, and there is a companion
   `woocommerce_show_page_title` filter that returns `false` on `is_shop()` to drop the
   now-duplicate WooCommerce `<h1>`.
3. A small CSS block styling `.woocommerce-result-count` and `.woocommerce-ordering
   select` to match the palette.

`php -l` passes. **This is committed but NOT deployed.** Your Job A deploys it.

> **Note:** PR #41 already warns that the **server copy of `pps-term-shortcodes.php` was
> edited outside version control and differs from the repo.** That makes the pre-overwrite
> diff/size check in §4.1 mandatory, not optional — a surgical patch may live only on the
> server.

---

## 2. Your two jobs

- **Job A:** Deploy the shop restyle (commit `378edb3`, plus whatever you add in Job B —
  same file) to the live/staging WordPress site and verify `/shop/` renders correctly.
- **Job B:** Reorder the category (product_cat archive) pages to:

  > **Header → masthead → wizard → product-page links / lists / modals → attributes → footer**

---

## 3. How a category page is actually composed (verified)

This is the crux of Job B. A `product_cat` archive page is assembled from **three
sources**, in this render order:

1. **The term description (stored in the database, per category).** WooCommerce prints it
   before the product loop (`woocommerce_archive_description`).
   `pps-term-shortcodes.php` runs shortcodes inside it via
   `add_filter('term_description', 'do_shortcode', 11)`. Each category's term description
   is **hand-authored** and today contains, in this order:
   - the hero/masthead HTML — a literal `<div class="pps-cat-hero">…</div>` with an
     `<h1 class="pps-cat-hero-title">` and `<p class="pps-cat-hero-sub">` (there is **no**
     PHP that emits this HTML — only the CSS for it lives in the plugin; the markup is in
     the term description);
   - optionally a USP bar `<div class="pps-cat-usps">…</div>`;
   - `[pps_cat_wizard calc="…" link="…"]` — the guided-selection wizard;
   - the **attribute** shortcodes: `[pps_cat_papers type="…" link="…"]`,
     `[pps_cat_coatings]`, `[pps_cat_turnaround]`, `[pps_cat_addons …]`;
   - prose (`<h2>`/`<p>` marketing copy).
2. **The WooCommerce product loop** (`ul.products`) — the product-page link cards. Renders
   *after* the entire term description.
3. **Plugin hooks** in `pps-term-shortcodes.php`:
   - `woocommerce_after_shop_loop` **priority 15** → the "More {Category} Options" preset
     lineup cards (more product-page links). Guarded `is_product_category()`.
   - `wp_footer` **priority 20** → the tooltip modal (`.pps-cat-tip-overlay`) used by the
     attribute lists.

**Net current order:** masthead → (USP) → wizard → **attributes** → prose → product grid
→ preset lineup. The attributes sit *before* the product links, which is what the reorg
must fix.

### Shortcode signatures (so you know what is per-category)

- `pps_cat_wizard` — atts `calc` (default `brochure`) and `link`. **Per-category.**
- `pps_cat_papers` — atts `type` (`text|cover|all`, default `all`), `factory`
  (`yes|no`), `link`. Data comes from **global** `pps_get_config()` (`papers_nc`,
  `papers_cs`); only `type`/`link` vary per category.
- `pps_cat_coatings`, `pps_cat_turnaround` — **no atts, fully global.**
- `pps_cat_addons` — takes atts; check its definition (~line 638) for which.

The important consequence: **the attribute blocks are almost entirely global.** The only
per-category variation is the paper `type` and the `link` used on the tooltip/CTA. That
makes them safe to render from a plugin hook driven by a little per-category term meta.

---

## 4. Job A — deploy the shop restyle & verify

1. **Before overwriting the live plugin file, do the CLAUDE.md safety check** (this is
   mandatory — see "Before overwriting any file on a server" in CLAUDE.md, and PR #41's
   own warning):
   - Read the current server copy of `pps-term-shortcodes.php` (connector read tool) and
     compare its byte size against the repo's history for that file. If the server size
     matches **no** commit, someone patched it in place — **stop** and diff before
     overwriting. Also treat any `.bak`/`.orig`/`.prehardening` sibling as a red flag.
   - If the server carries a surgical patch that is not in the repo, **bring it into the
     repo on this branch first** (CLAUDE.md: "server-side patches must come home the same
     session"), then deploy the merged file.
2. **Deploy repo-first, pull-based.** Prefer `pps_plugin_download_url` (or the project's
   established deploy path) pointed at the **raw GitHub URL pinned to the exact commit**
   (`378edb3`, or your later Job-B commit). Do not hand-edit the file on the server.
3. **Verify** on the live site:
   - `/shop/` shows the dark masthead with the shop title + subtitle, then the modern
     white product cards (cyan price + "Customize Order" button, hover lift), and a tidy
     sort dropdown. No duplicate `<h1>` above the grid.
   - Spot-check one `product_cat` page still looks exactly as before (this commit must not
     change category pages).
4. If the owner wants a subtitle other than the default, set `_pps_shop_subtitle` post
   meta on the WooCommerce "Shop" page.

---

## 5. Job B — reorder the category pages

**Goal order:** masthead → wizard → product links (grid + preset lineup) → **attributes**
→ footer.

The only block out of place is **attributes**, and it is out of place because it lives in
the term description, which renders before the product grid. You cannot move the grid into
the term description, so **move the attributes out of the term description into a plugin
hook that fires after the preset lineup.** Recommended, plugin-driven, versioned approach:

### 5a. Add the attributes hook (repo change, in `pps-term-shortcodes.php`)

- Add a `woocommerce_after_shop_loop` action at **priority 20** (fires after the preset
  lineup at 15), guarded `is_product_category()`.
- It renders a wrapper (e.g. `<div class="pps-cat-body pps-cat-attributes">`) containing
  the papers / coatings / turnaround / add-ons blocks for the current category. Reuse the
  existing shortcode callbacks' logic — either call `do_shortcode('[pps_cat_papers …]')`
  with the right atts, or factor the render bodies into shared helpers and call them from
  both the shortcode and the hook (cleaner; avoids double-maintenance).
- Drive per-category variation from **term meta** on the category term:
  - `_pps_attr_paper_type` → the `type` passed to papers (`text|cover|all`);
  - `_pps_attr_link` → the `link` passed to papers/add-ons;
  - optionally `_pps_attr_show` → which sections to render, if some categories omit some.
  - Sensible fallbacks when meta is absent (e.g. `type=all`, no link) so a category with
    no meta still renders a reasonable attributes block.
- The tooltip modal hook already runs on category pages, so tooltips keep working.
- `php -l`, commit to the branch, push.

### 5b. Migrate each category's term description (connector, DB)

For **every** `product_cat` term that currently has attribute shortcodes in its
description:

1. Read the term description via the connector.
2. Capture the current atts: the `type` and `link` on `[pps_cat_papers]`, any
   `[pps_cat_addons]` atts, and note which of coatings/turnaround/addons were present.
3. Write those into the term meta keys from §5a.
4. **Remove** the attribute shortcodes (`[pps_cat_papers]`, `[pps_cat_coatings]`,
   `[pps_cat_turnaround]`, `[pps_cat_addons]`) from the term description. Leave the hero,
   USP bar, wizard, and prose.
5. While you're in there, make sure the **wizard sits immediately after the masthead**
   (move `[pps_cat_wizard]` up if any prose/attributes preceded it) so the final order is
   clean.

Do this category by category and **verify each rendered page** after 5a is deployed:
masthead → wizard → prose → product grid → preset lineup → attributes.

### 5c. Alternative (only if per-category paper `type` never varies)

If, after reading the term descriptions, you find every category uses the same paper
`type` (e.g. all `all`), you can skip term meta and render a uniform attributes block in
the hook — then 5b is just "delete the attribute shortcodes from the descriptions." Check
the actual `type` values first; don't assume.

---

## 6. Constraints — non-negotiable (from CLAUDE.md)

- **Repo first, then deploy.** Never write a PHP file to the server that isn't in the repo
  at a known commit. Deploy by pulling a pinned raw commit URL, so the deployed bytes are
  reviewable and rollback is the same call with an older SHA.
- **A server-only patch is a countdown to the next deploy.** If you change server
  behavior, it must be in the repo **this session**.
- **Do the pre-overwrite size check** (§4.1) before replacing any live file.
- **Term meta you add carries behavior** — document the keys you introduce (in the commit
  message and in `CLAUDE.md`) so a future session knows the render hook depends on
  `_pps_attr_*`. (The per-category term *descriptions* were already un-versioned content;
  you are moving a slice of that content into term meta, which is the same class of thing —
  but write it down.)
- **Treat the repo as public** (it is, until the owner flips it private): no pricing
  figures, strategy, or credentials in commits.
- Don't touch the `website` branch. Don't delete `.nojekyll` on the Pages branches.
- Deploys/DB edits must come from your session — do not off-load CLI steps to the owner.

---

## 7. Acceptance criteria

- [ ] `/shop/` shows the masthead + modern product-card grid + tidy sort controls; no
      duplicate title.
- [ ] Every `product_cat` page renders in the order: masthead → wizard → product grid +
      preset lineup → attributes → footer.
- [ ] Attribute lists (papers/coatings/turnaround/add-ons) and their tooltips still work,
      now positioned after the product links.
- [ ] Category pages that had no attribute shortcodes are unaffected.
- [ ] The attributes render hook is in the repo on `claude/optimistic-wozniak-11ql3y`,
      `php -l` clean, committed, pushed; the live plugin file was deployed from a pinned
      commit; term-meta keys are documented.
- [ ] Spot-checked 3–4 different categories (different calc types: brochure, saddle,
      signs/lead-wizard, etc.) render correctly.

---

## 8. Tools / access you'll need

- **WordPress connector** (`PPS-Production` / `WordPress_com` / `priority-print` MCP): read
  & update `product_cat` term descriptions, get/update term meta, read the live plugin
  file, and trigger the pull-based plugin deploy. Authorize these first (`/mcp`).
- **GitHub** (scoped to `pdevvle/priorityprintservice.com`): commit/push to
  `claude/optimistic-wozniak-11ql3y`, update PR #41.

Open question to raise with the owner if it comes up: the site **chrome** (header/menu/
footer) is a warm-monochrome theme while calculators/category pages use the cyan-slate
"modern" look. The shop restyle deliberately matches the modern/category look per the
owner's request; unifying the two design systems is a separate, larger decision.
