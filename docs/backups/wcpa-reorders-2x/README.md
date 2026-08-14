# Reorders WCPA 2x pricing — clone-and-repoint, 2026-08-14

The **Reorders** product (ID 86782) was charging less than intended, and the
owner asked for the prices charged on that product to be approximately
doubled "using the fields". This directory is the canonical record of the
change applied to production `wp_options`/post-meta on 2026-08-14, plus the
byte-exact material needed to roll it back.

## Root cause of the under-charging (found during this work, NOT changed)

Reorders (86782) has `_regular_price = 0`. Every category form's main
materials + markup charge is a formula scaled by `{product_price}` (via the
`Discount_Formula` / `Perfect Bound Regular` / `saddle1`-style fee fields),
so on this product that entire component evaluates to **$0**. Customers were
paying only the labor fee formulas (press / cutting / bindery / binding) plus
the Universal Options flat fees. The doubling below doubles what is actually
charged; it does **not** resurrect the zeroed materials component (0×2 = 0).
If the owner wants the materials component back, the lever is the product's
base price (the legacy formulas were tuned against the source products'
prices, e.g. Budget Flyers 22247 = $50), which is a separate decision.

## What was changed

The Reorders product attaches only router form **86780**, whose formselector
fields embedded five shared legacy "IH" forms. Those shared forms were NOT
touched. Instead, five clones were created with doubled charges, and the
router's `form_id`s were repointed at the clones:

| Product type          | Original form (untouched)            | 2x clone |
|-----------------------|--------------------------------------|----------|
| Brochure/Flyer/Postcard | 31533 "IH - Flyer Category Form"   | **87013** |
| Saddlestitch Booklet  | 30683 "IH - Booklet"                 | **87012** |
| Perfect Bound Booklet | 86779 "IH – Perfect Bound Legacy"    | **87010** |
| Coupon Booklet/Notepad| 33459 "IH - Coupon Booklets & Notepads" | **87011** |
| Universal Options     | 30665 "IH - Universal Options"       | **87009** |

### Doubling mechanism (why not just double every "price")

The literal option "prices" in these forms are **not dollars** — the size
options carry imposition counts (8/6/4/2/0.5) and the quantity options carry
piece counts (25…5000); both are divisors/multipliers inside the fee
formulas. Doubling them would corrupt the math (doubling an imposition
*halves* the charge). The rule actually applied, computed with a cross-form
reference scan over all five forms:

1. **Terminal fee fields** (`type: content`, `enablePrice: true`) whose
   `.price` is NOT referenced by any other formula got their expression
   wrapped `( … )*2`. 26 fee fields across the four category forms.
2. **Protected (left at 1x)** because other formulas multiply by them
   (wrapping would compound): `Discount_Formula` (31533), `Stitching`
   (30683), the Turnaround multiplier select `wcpa-select-1641677383742`
   (30665, ×1.07), and the Binding Style image-group (33459, referenced by
   the main coupon fee).
3. **Universal Options flat dollar fees doubled numerically**: Edit Design
   44.99→89.98, New Design 74.99→149.98, Digital Proof 9.99→19.98, Hardcopy
   Proof 34.99→69.98, Bleeds Not-Sure 10→20, Bleeds No 30→60.

Every clone's stored `_wcpa_fb_json_data` was read back from production and
byte-compared against the intended payload in this directory — all five are
byte-exact, as is the repointed router JSON.

### Editor-data caveat

Only clone 87009 carries `_wcpa_fb-editor-data` (the WCPA form-builder's
working copy). 87010–87013 have `_wcpa_fb_json_data` only — same as the
pre-existing production form 86779, which runs fine without it (the frontend
renders from json_data). **Do not open clones 87010–87013 in the WCPA
form-builder and save** — the builder may load an empty canvas and wipe the
form. To change a clone, edit the corresponding `clone_*_json_data.txt`
here, re-apply via the AI-engine `wp_update_post_meta`, and re-verify, or
ask Claude. (The transformed editor-data payloads for 30683/31533/33459 are
kept in this directory should the owner ever want them installed.)

## Files

- `form_<id>_meta.json` — original production meta of each source form and
  the router, captured before any change (byte-exact).
- `clone_<id>_json_data.txt` — the doubled `_wcpa_fb_json_data` now live on
  the corresponding clone (file is named by the SOURCE form id).
- `clone_<id>_editor_data.txt` — transformed editor-data (installed only for
  30665→87009).
- `router_86780_ORIGINAL_json_data.txt` — router before the repoint.
- `router_86780_new_json_data.txt` — router as deployed (clone form_ids).

## Rollback

Restore the router's `_wcpa_fb_json_data` on post **86780** to the contents
of `router_86780_ORIGINAL_json_data.txt` (via the AI-engine
`wp_update_post_meta`; this JSON contains no backslashes so no escaping
gymnastics are needed). That single write reverts the Reorders product to
the original 1x forms. The clones can be left in place (they attach to
nothing on their own) or trashed.

## Cache note

WCPA embeds the form JSON in the rendered product page, so a cached
`/product/reorders/` page keeps quoting 1x prices until page cache is
purged (WP Rocket clear + Cloudflare purge).
