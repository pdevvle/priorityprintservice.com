# Reorders WCPA forms — dedicated clones with fixed base price, 2026-08-14

Canonical record of the WCPA form surgery on the **Reorders** product
(ID 86782), applied to production post-meta on 2026-08-14. Two phases in one
day; the second supersedes the first.

## The bug

Reorders (86782) has `_regular_price = 0`. The legacy "IH" forms' main
materials + markup charge is a formula scaled by `{product_price}` — WCPA
resolves that placeholder against the product the form renders on. The forms
were built for host products with real prices (**$50** for the flyer-family
hosts 22247/24458/25086; **$88** for the booklet-family hosts 21301/86474),
so on the $0 Reorders product the entire materials component evaluated to $0
and customers paid only labor + flat fees. Confirmed by the owner: the forms
reference `{product_price}`, and that value is no longer what the forms were
originally tuned for on this product.

## The fix (current live state)

Five dedicated clones of the embedded IH forms were created, and router
form **86780** (the only form attached to Reorders) was repointed at them.
The shared originals were never touched. In each clone, `{product_price}`
is **hard-coded to the tuned value** so the formulas behave exactly as they
did on their original host products, immune to the Reorders product's $0
price:

| Product type | Original form (untouched) | Clone | Hard-coded base |
|---|---|---|---|
| Brochure / Flyer / Postcard | 31533 | **87013** | 50 (4 spots) |
| Saddlestitch Booklet | 30683 | **87012** | 88 (17 spots) |
| Perfect Bound Booklet | 86779 | **87010** | 88 (9 spots) |
| Coupon Booklet / Notepad | 33459 | **87011** | 88 (4 spots) |
| Universal Options | 30665 | **87009** | n/a (verbatim copy) |

Everything else in the clones is the pristine original: original fee
formulas (no multipliers), original flat fees (Edit Design 44.99, New Design
74.99, Digital Proof 9.99, Hardcopy 34.99, Bleeds 10/30), original option
tables. Every clone's stored `_wcpa_fb_json_data` was read back from
production and byte-compared against the `fixed_*` payload in this
directory — all five byte-exact, as is the repointed router.

### Phase 1 (superseded, same day)

The owner first asked for charges to be approximately doubled. The clones
briefly carried the original formulas wrapped `(...)*2` with flat fees
doubled (the `clone_*` payload files). Investigation then surfaced the
`{product_price}` root cause; the owner chose to restore original 1x
pricing with the hard-coded base instead, which is what is live now. The
`clone_*` files are kept only as a record of the interim state.

## Files

- `form_<id>_meta.json` — original production meta of each source form and
  the router, captured before any change (byte-exact).
- `fixed_<id>_json_data.txt` — **the payload now live on the clone** (named
  by SOURCE form id: 31533→87013, 30683→87012, 86779→87010, 33459→87011,
  30665→87009). `fixed_30665_editor_data.txt` is 87009's builder
  working-copy (original 30665 editor-data verbatim).
- `clone_<id>_*.txt` — the superseded 2x payloads (historical only).
- `router_86780_ORIGINAL_json_data.txt` — router before the repoint.
- `router_86780_new_json_data.txt` — router as deployed (clone form_ids).

## Editor-data caveat

Only clone 87009 carries `_wcpa_fb-editor-data`. 87010–87013 have
`_wcpa_fb_json_data` only — same as pre-existing production form 86779,
which runs fine without it (the frontend renders from json_data). **Do not
open clones 87010–87013 in the WCPA form-builder and save** — the builder
may load an empty canvas and wipe the form. To change a clone, edit the
corresponding `fixed_*` file here and re-apply via the AI-engine
`wp_update_post_meta` (or ask Claude), then byte-verify.

## Rollback

Restore post **86780**'s `_wcpa_fb_json_data` to
`router_86780_ORIGINAL_json_data.txt` (no backslashes in it, so no escaping
gymnastics). That single write points Reorders back at the original shared
forms — which restores the $0-materials bug, so only do this to diagnose.

## Cache note

WCPA embeds the form JSON in the rendered product page; a cached
`/product/reorders/` keeps quoting stale prices until page cache is purged
(WP Rocket clear + Cloudflare purge).
