# PPS Tooltips — New Claude Instance Brief

This document briefs a new Claude instance on the PPS tooltip system, how to expand it, and how to deploy changes to staging.

---

## Environment Overview

- **Repository:** `pdevvle/priorityprintservice.com` on GitHub
- **Staging site:** Cloudways-hosted WordPress/WooCommerce at `woocommerce-70867-4915293.cloudwaysapps.com`
- **MCP servers:** `priority-print` = STAGING, `PPS-Production` = PRODUCTION. Use staging (`priority-print`) for all development and testing. Never use `PPS-Production` for dev work.
- **The repo owner does NOT use Claude Code locally.** All work happens through Claude on the web. Never tell the user to run commands locally.

---

## Tooltip Architecture

The PPS plugin has a two-tier tooltip system:

### RichTip (primary — server-driven, media-rich)

A React component defined in each calculator HTML file. It renders a cyan "?" icon next to its children. On click, it opens a modal (bottom-sheet on mobile, centered dialog on desktop) with rich content blocks.

**Props:**
| Prop | Type | Purpose |
|------|------|---------|
| `children` | JSX | Content rendered next to the "?" icon |
| `tipKey` | string | Lookup key in `PPS_CONFIG.tips` |
| `title` | string | Fallback title if tipKey not found |
| `content` | string or array | Fallback content if tipKey not found |
| `dark` | boolean | Dark-mode color scheme |

**Data flow:**
1. PHP reads `wp_options['pps_tooltips']` on page load
2. Injects into `window.PPS_CONFIG.tips` as a JavaScript object
3. RichTip component looks up `PPS_CONFIG.tips[tipKey]` at render time
4. Falls back to inline `title`/`content` props if tipKey is missing or `PPS_CONFIG` isn't loaded (standalone/Pages testing)

**Component location in each calculator:**
- `calc-preview-test.html` — saddle stitch (most mature)
- `calc-perfect-bound.html` — perfect bound
- `calc-brochure.html` — brochure/flat printing
- `calc-coupon-book.html` — coupon books

### InfoTip (legacy — inline, text-only)

A simpler React component used only in proof/preview modals for legend guides (bleed/trim/safety). It shows a small floating tooltip near the trigger, not a full modal. Takes a `text` prop (plain JSX), no `tipKey` support.

**Do not expand InfoTip.** New tooltip work should use RichTip with tipKeys. InfoTip is frozen in place for the proof modal legends.

---

## Tooltip Content Schema

Each tooltip in `wp_options['pps_tooltips']` is keyed by a slug (lowercase alphanumeric + underscore) and contains:

```json
{
  "tipkey_slug": {
    "title": "Human-readable title",
    "content": [
      { "type": "text", "value": "Paragraph of text." },
      { "type": "image", "src": "https://...", "alt": "Alt text" },
      { "type": "video", "src": "https://...mp4", "poster": "https://..." },
      { "type": "youtube", "src": "https://www.youtube.com/embed/VIDEO_ID", "alt": "Video title" }
    ]
  }
}
```

**Block types:**
| Type | Required fields | Optional | Rendered as |
|------|----------------|----------|-------------|
| `text` | `value` (string) | — | `<p>` tag |
| `image` | `src` (URL) | `alt` | `<img loading="lazy">` |
| `video` | `src` (URL) | `poster` | `<video controls playsInline>` |
| `youtube` | `src` (embed URL) | `alt` | `<iframe>` with 16:9 aspect ratio |

**Sanitization (server-side):**
- Keys: `sanitize_key()` — lowercase a-z, 0-9, underscore only
- Titles: `sanitize_text_field()`
- Text values: `sanitize_textarea_field()`
- URLs: `esc_url_raw()`

---

## Existing Tooltips (30+ as of June 2025)

### Add-on / Feature tooltips:
| Key | Title |
|-----|-------|
| `vivid` | Enhanced Vivid Printing |
| `coating` | UV Cover Coating |
| `bundling` | Bundling |
| `round_cornering` | Round Cornering |
| `perforation` | Perforation |
| `outfold` | Fold-Out Page (Outfold) |
| `perfect_binding` | Perfect Binding |
| `saddle_stitch` | Saddle Stitch Binding |
| `bleed` | Artwork Bleeds |

### Paper tooltips (generic):
| Key | Title |
|-----|-------|
| `paper_text_weight` | Text Weight Paper |
| `paper_cardstock` | Cardstock |

### Paper tooltips (per-stock):
| Key | Title |
|-----|-------|
| `paper_text_70lb_uncoated_opaque_text` | 70lb Uncoated Opaque Text |
| `paper_text_80lb_matte_text` | 80lb Matte Text |
| `paper_text_100lb_gloss_text` | 100lb Gloss Text |
| `paper_text_50lb_offset_smooth_opaque` | 50lb Offset Smooth Opaque |
| `paper_text_60lb_offset_smooth_opaque` | 60lb Offset Smooth Opaque |
| `paper_text_80lb_offset_smooth_opaque` | 80lb Offset Smooth Opaque |
| `paper_text_80lb_gloss_factory_coated` | 80lb Gloss Factory Coated (Text) |
| `paper_text_100lb_matte_factory_coated` | 100lb Matte Factory Coated (Text) |
| `paper_cs_80lb_opaque_uncoated` | 80lb Opaque Uncoated Cardstock |
| `paper_cs_80lb_matte_cardstock` | 80lb Matte Cardstock |
| `paper_cs_100lb_gloss_cardstock` | 100lb Gloss Cardstock |
| `paper_cs_14pt_gloss_c1s` | 14pt Gloss C1S Cardstock |
| `paper_cs_16pt_coated_c2s` | 16pt Coated C2S Cardstock |
| `paper_cs_80lb_gloss_factory_coated` | 80lb Gloss Factory Coated (Cardstock) |
| `paper_cs_100lb_matte_factory_coated` | 100lb Matte Factory Coated (Cardstock) |
| `paper_cs_12pt_c2s_factory_coated` | 12pt C2S Factory Coated Cardstock |
| `paper_cs_14pt_c2s_factory_coated` | 14pt C2S Factory Coated Cardstock |
| `paper_cs_18pt_c1s_factory_gloss` | 18pt C1S Factory Gloss Cardstock |

---

## Where RichTip Is Used Today

**Current status: the RichTip component is defined but NOT YET wired to any form fields in the calculators.** The component exists in all 4 calculator HTML files but has zero `<RichTip tipKey="...">` instances.

Tooltips ARE used on **category pages** via `pps-term-shortcodes.php` (a separate vanilla-JS modal system that reads the same `pps_tooltips` option). The category page tooltip triggers use CSS class `pps-cat-tip` with a `data-tip="keyname"` attribute.

### Expansion opportunity

Every calculator form field that has an add-on, paper selection, or finishing option is a candidate for a RichTip. The tipKeys already exist; they just need to be wired into the JSX.

**Example of wiring a tooltip to a field:**
```jsx
{/* Before — no tooltip */}
<Sel label="Coating" ...>

{/* After — with RichTip wrapping the label */}
<RichTip tipKey="coating">
  <Sel label="Coating" ...>
</RichTip>
```

Or, if the label is a separate element:
```jsx
<RichTip tipKey="coating">
  <span>Coating</span>
</RichTip>
```

The RichTip wraps its children and appends the "?" icon inline.

---

## Category Page Tooltip System (pps-term-shortcodes.php)

Category pages use a **separate vanilla-JS tooltip modal** that reads the same `pps_tooltips` wp_option. This is NOT the React RichTip component — it's a standalone modal injected via `wp_footer`.

**How it works:**
1. `pps-term-shortcodes.php` registers shortcodes: `[pps_cat_papers]`, `[pps_cat_turnaround]`, `[pps_cat_coatings]`, `[pps_cat_addons]`
2. These shortcodes render HTML with `class="pps-cat-tip" data-tip="tipkey"` on elements that should show tooltips
3. A `wp_footer` action injects the overlay modal + JS that reads `pps_tooltips` and renders content on click

**Add-on to tipKey mapping (in pps-term-shortcodes.php):**
```php
$addon_tip_map = array(
    'vivid'       => 'vivid',
    'coating'     => 'coating',
    'bundling'    => 'bundling',
    'rc'          => 'round_cornering',
    'two_staple'  => 'saddle_stitch',
    'perforation' => 'perforation',
    'outfold'     => 'outfold',
);
```

Papers can also have a `_tip` field in their config that maps to a tipKey.

---

## Admin UI for Tooltips

**Location:** WordPress Admin > Settings > PPS Config > "Tooltips" tab

**File:** `pps-config-admin.php` — function `pps_config_tab_tooltips()` (around line 1780)

**Features:**
- Collapsible cards for each tooltip (shows key, title, block count)
- Add/remove tooltips
- Add/remove content blocks within each tooltip
- Block type dropdown (text, image, video, youtube) with dynamic field switching
- On save, JS serializes the UI to JSON → hidden textarea → POST to PHP
- PHP AJAX handler at `wp_ajax_pps_save_tooltips` sanitizes and writes to `wp_options['pps_tooltips']`

**Limits enforced in admin UI:**
- Max 100 tooltips
- Max 20 blocks per tooltip
- Keys auto-cleaned to lowercase alphanumeric + underscore

---

## PHP: Tooltip Injection Points

### Calculator pages (`pps-calculators.php`)

**Product pages (line ~692):**
```php
$tips = get_option( 'pps_tooltips', array() );
if ( ! empty( $tips ) ) {
    $config['tips'] = $tips;
}
```

**Preset URLs (line ~2686):**
```php
$tips = get_option( 'pps_tooltips', array() );
if ( ! empty( $tips ) ) $config['tips'] = $tips;
```

Both inject tooltips into `window.PPS_CONFIG` which the React calculators read.

### AJAX save handler (`pps-calculators.php`, line ~2121):
```php
add_action( 'wp_ajax_pps_save_tooltips', function() {
    check_ajax_referer( 'pps_tooltip_save', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    // ... sanitize and save to wp_options['pps_tooltips']
});
```

### Default tooltips seeded on activation (`pps-calculators.php`, line ~2167):
```php
register_activation_hook( __FILE__, function() {
    if ( ! get_option( 'pps_tooltips' ) ) {
        update_option( 'pps_tooltips', pps_default_tooltips(), false );
    }
});
```

Default tooltip definitions live in `pps_default_tooltips()` (line ~2033 of pps-calculators.php).

---

## How to Deploy Changes to Staging

### Updating tooltip DATA (wp_options — no deploy needed)

Tooltip content lives in `wp_options['pps_tooltips']`. To update it:

**Option A — Admin UI:** Settings > PPS Config > Tooltips tab. Edit in the browser.

**Option B — MCP tool (from Claude):**
```
mcp__priority-print__wp_update_option
  key: "pps_tooltips"
  value: { ... full tooltips object ... }
```

**Option C — AJAX (from code):**
POST to `admin-ajax.php` with action `pps_save_tooltips`, nonce, and JSON body.

Changes take effect immediately on the next page load — no deploy step needed.

### Updating PHP files (pps-calculators.php, pps-term-shortcodes.php, etc.)

**Via MCP tool:**
```
mcp__priority-print__pps_plugin_write_file
```
This writes a file directly to the plugin directory on the staging server. The file is live immediately.

**Workflow:**
1. Edit the PHP file in the git repo
2. Use `pps_plugin_write_file` to push the updated file to staging
3. Verify on staging
4. Commit and push to git

### Updating calculator HTML files

Calculator HTML files are served from `wp-content/uploads/pps-calculators/` on the staging site, NOT directly from the plugin directory.

**Deploy process:**
1. Edit the calculator HTML file in the git repo
2. Use `pps_plugin_write_file` to write the file to the plugin's `_pending_html/` directory
3. The `pps_html_deploy_run()` hook (fires on `plugins_loaded`) picks it up, validates it, copies it to uploads, and updates the registry
4. Alternatively, trigger a page load on the staging site to kick the deploy hook

**Or use the batch deployer pattern:**
Write a temporary `_pps_deploy_batch.php` file that fetches calculator HTML directly from GitHub raw URLs, verifies SHA256 checksums, and copies to the uploads directory. Side-load it from `pps-html-deploy.php` with:
```php
$_var = __DIR__ . '/_pps_deploy_batch.php';
if (file_exists($_var)) { require_once $_var; }
unset($_var);
```
The batch deployer should self-delete after running.

---

## Git Repository & Branches

| Branch | Purpose |
|--------|---------|
| `pps-pricing-config` | **Pages source branch** — GitHub Pages serves calculator previews from root of this branch. All calculator HTML changes must be pushed here for preview URLs to update. |
| `main` / `master` | Standard branch for PHP plugin code |
| Feature branches (`claude/...`) | Development branches for Claude sessions |

**Preview URLs (GitHub Pages from `pps-pricing-config`):**
- Saddle stitch: `https://pdevvle.github.io/priorityprintservice.com/calc-preview-test.html`
- Perfect bound: `https://pdevvle.github.io/priorityprintservice.com/calc-perfect-bound.html`
- Brochure: `https://pdevvle.github.io/priorityprintservice.com/calc-brochure.html`
- Coupon book: `https://pdevvle.github.io/priorityprintservice.com/calc-coupon-book.html`

**Critical rules:**
- `.nojekyll` MUST exist on `pps-pricing-config` root — without it, Jekyll breaks inline JSX `{{ }}` syntax
- Never write a literal `</script>` inside `<script type="text/babel">` — escape as `<\/script>`
- Never push to the `website` branch — it's unrelated

---

## Key Files Reference

| File | What it does |
|------|-------------|
| `calc-preview-test.html` | Saddle stitch calculator — has RichTip + InfoTip components |
| `calc-perfect-bound.html` | Perfect bound calculator — has RichTip + InfoTip components |
| `calc-brochure.html` | Brochure calculator — has RichTip component |
| `calc-coupon-book.html` | Coupon book calculator — has RichTip + InfoTip components |
| `pps-calculators.php` | Main plugin: tooltip injection, AJAX save handler, default tooltips, config injection |
| `pps-config-admin.php` | Admin settings page — Tooltips tab UI lives here |
| `pps-term-shortcodes.php` | Category page shortcodes — vanilla-JS tooltip modal, addon tip mapping |
| `pps-html-deploy.php` | Side-loader for PHP modules + HTML deploy engine |
| `docs/MASTER_PRICING_LOGIC.md` | Pricing engine reference — read before any formula changes |

---

## Practical Expansion Checklist

To add a new tooltip to the system:

1. **Choose a tipKey** — lowercase, underscores only (e.g., `folding`, `page_count`, `spine_width`)
2. **Add the tooltip content** — either via the admin Tooltips tab or by updating `wp_options['pps_tooltips']` directly through MCP
3. **Wire it into the calculator** — wrap the relevant form element with `<RichTip tipKey="your_key">...</RichTip>` in the calculator HTML
4. **Wire it into category pages (optional)** — add the tipKey to `$addon_tip_map` in `pps-term-shortcodes.php` if it corresponds to an add-on, or set the `_tip` field on a paper config entry
5. **Deploy** — push HTML changes to staging via the deploy process; PHP changes via `pps_plugin_write_file`
6. **Test** — verify the "?" icon appears, the modal opens with correct content, and mobile bottom-sheet works

To update existing tooltip content without touching code:
- Use `mcp__priority-print__wp_update_option` with key `pps_tooltips` — changes are live instantly.
