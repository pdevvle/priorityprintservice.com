# Category attributes migration

Record of the term-description edits that moved the attribute blocks below the product
links. Term descriptions are **not** in version control — they live only in
`wp_term_taxonomy.description` — so this file is the only repo-side record of what was
applied. Read it before re-authoring a category description.

Applied to **staging** (`woocommerce-70867-4915293.cloudwaysapps.com`) on 2026-08-09,
against `pps-term-shortcodes.php` at commit `c75e001`.

## What changed in each description

Removed the attribute shortcodes **and the headings that wrapped them**:

```
<h2>Paper Options</h2>
<h3>Text Weight</h3>
[pps_cat_papers type="text"]
<h3>Cardstock</h3>
[pps_cat_papers type="cover"]
<h2>Coatings</h2>
[pps_cat_coatings]
<h2>Finishing Add-ons</h2>
[pps_cat_addons calc="…"]
```

…plus the standalone `[pps_cat_turnaround]` that sat directly under the wizard. Replaced
with a single `[pps_cat_attributes …]` at the end of the `.pps-cat-body` div. The headings
are now emitted by `pps_cat_render_attributes()`; do not re-add them to the description.

Hero, USP bar, `[pps_cat_wizard]` and all marketing prose were left untouched.

## Atts applied per category

| Category | Term ID | `[pps_cat_attributes …]` |
|---|---|---|
| Booklets | 86 | `papers="text,cover" cover_label="Cardstock (Covers)" coatings="yes" addons="saddle"` |
| Brochures | 81 | *(no atts — turnaround only)* |
| Coupons | 1218 | `papers="text,cover" cover_label="Cardstock (Covers)" coatings="yes" addons="coupon"` |
| Flyers | 79 | `papers="text,cover" coatings="yes" addons="brochure"` |
| Menus | 1596 | `papers="text,cover" coatings="yes" addons="brochure"` |
| Perfect Bound | 2066 | `papers="text,cover" cover_label="Cardstock (Covers)" coatings="yes" addons="perfect-bound"` |
| Postcards | 80 | `papers="cover" coatings="yes" addons="brochure"` |
| Rack Cards | 83 | `papers="text,cover" coatings="yes" addons="brochure"` |
| Square Brochures | 2059 | `papers="text,cover" coatings="yes" addons="brochure"` |

`turnaround` defaults to `yes`, so every row above renders the turnaround callout — matching
the pre-migration content, where all nine had `[pps_cat_turnaround]`.

Postcards passes `papers="cover"` only; a single paper type deliberately renders with no
`<h3>` sub-heading, which reproduces how that description was authored.

## Categories deliberately not touched

These never carried attribute shortcodes — wizard and prose only, so the reorg does not
apply and their pages are unchanged:

Business Cards (85), Business Forms (90), Door Hangers (82), Envelopes (88), Folders (111),
Greeting Cards (100), Letterhead (84), Notepads (87), Signs &amp; Banners (589),
Stationery (769), Stickers (1612).

Empty descriptions: Business Suite (107), EDDM (587), Mailers (588), Plastic (1532),
UPS (1362), legacy (2065), Uncategorized (93).

## Production

Not yet applied. Production needs both halves, in this order:

1. Deploy `pps-term-shortcodes.php` from a pinned commit (`[pps_cat_attributes]` must be a
   registered shortcode before any description references it, or the raw shortcode text
   prints on the page).
2. Apply the same term-description edits, using the atts table above.
