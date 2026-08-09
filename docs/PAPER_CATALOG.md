# Paper Catalog — canonical customer-facing descriptions

Single source of truth for the copy shown wherever a paper stock is described:
the description line under each calculator's paper picker, and the per-stock
tooltips on category pages (`paper_text_*` / `paper_cs_*` keys in
`pps_tooltips`).

## How the data flows (single-source system)

`pps_paper_meta_defaults()` in `pps-config-admin.php` is the **runtime**
canonical copy of this catalog (desc + days keyed by val, nc/cs pools).
`pps_get_config()` runs every `papers_nc`/`papers_cs` row through
`pps_paper_enrich()`, which fills `desc`/`days` when the admin row lacks them
and stamps the computed `inv` flag (`pps_paper_is_inventoried()` = not factory
AND 0 days). Every surface reads those enriched rows:

- **Calculators** — rows arrive via `PPS_CONFIG.calc`; server `desc` wins,
  the embedded `PAPER_DESC` maps are the standalone/GitHub-Pages fallback
  only; `paperInv` honors the server `inv` flag when present.
- **Category wizard** — paper steps render `$p['desc']`, the shared badge
  (`pps_paper_stock_badge()`: blue-dot In Stock / Special Order +Nd / Factory
  Order +Nd), and the legend (`pps_paper_inv_legend()`).
- **Attribute cards** — `pps_cat_render_papers()` shows the same desc, badge,
  and legend, both inline and in the deferred `[pps_cat_attributes]` section.
- **Wizard flat sizes** mirror `cfg['brochure_sizes']` — the same rows the
  brochure calculator reads — so size changes propagate too. Booklet sizes
  already mirror `size_presets`.

Admin-entered desc/days (Papers tab columns) override the canonical defaults
row-by-row; blank fields fall back here.

**Editing copy:** change it here first, then mirror into (a)
`pps_paper_meta_defaults()` in `pps-config-admin.php`, (b) the `PAPER_DESC`
fallback map in each calculator HTML, and (c) `pps_default_tooltips()` in
`pps-calculators.php` — same commit. The live tooltip option
(`wp_options['pps_tooltips']`) must be synced through the staging connector —
seeds only apply on fresh activation.

## Inventory dot

Paper dropdowns mark **inventoried** stocks with a blue dot (●). A stock is
inventoried when `days === 0 && !factory`. Legend copy (verbatim, owner-supplied):

> **● In stock — Best for quick turnaround, small quantity, and hardcopy proofs**

The 14pt/16pt cards (`val` 1.0x) are special-order (+1 day) and do **not** get
the dot; factory stocks (+2–4 days) do not get the dot.

## Text weight papers (`papers_nc`)

| Stock | val | Availability | Description |
|---|---|---|---|
| 70lb Uncoated Opaque Text | 0.001 | ● in stock | Classic uncoated paper with a natural feel that's easy to write on. Crisp text and a soft, non-reflective look — letterhead, inserts, forms, and reading-heavy pages. |
| 80lb Matte Text | 0.002 | ● in stock | Smooth coated sheet with a soft, glare-free finish. Richer color than uncoated without the shine — the all-purpose choice for brochures and flyers. |
| 100lb Gloss Text | 0.003 | ● in stock | Shiny coated sheet that makes photos and color pop. The standard for marketing brochures, catalogs, and mailers. |
| 60lb Offset Smooth Opaque | 2.002 | factory, +2 days | Light uncoated sheet with good opacity for its weight. Economical for manuals, workbooks, and text-heavy booklets. |
| 80lb Offset Smooth Opaque | 2.003 | factory, +2 days | Sturdy uncoated sheet with excellent opacity. The uncoated feel with less show-through — workbooks, journals, and premium text pages. |
| 80lb Gloss Factory Coated | 2.004 | factory, +2 days | Lightweight gloss sheet with vivid color reproduction. A thinner, economical alternative to 100lb gloss for catalogs and mailers. |
| 100lb Matte Factory Coated | 2.005 | factory, +2 days | Heavy matte sheet with a refined, low-glare surface. Upscale brochures, art books, and photography that shouldn't shine. |
| Linen | 2.006 | factory, +4 days | Premium stock with a woven linen texture you can feel. Distinctive for invitations, stationery, and fine-dining menus. |

## Cardstock (`papers_cs`)

| Stock | val | Availability | Description |
|---|---|---|---|
| 80lb Opaque Uncoated | 0.01 | ● in stock | Our lightest cardstock, uncoated and easy to write on. Greeting cards, reply cards, and covers that fold cleanly. |
| 80lb Matte Cardstock | 0.02 | ● in stock | Light cardstock with a smooth matte coating. A soft, modern look for covers and cards. |
| 100lb Gloss Cardstock | 0.03 | ● in stock | Mid-weight cardstock with a glossy face that makes color punchy. Covers, postcards, and hang tags. |
| 14pt Gloss C1S | 1.01 | special order, +1 day | Thick card, gloss-coated on one side with an uncoated back that's easy to write on. The postcard standard (C1S = coated one side). |
| 16pt Coated C2S | 1.02 | special order, +1 day | Our heaviest everyday card, gloss-coated both sides (C2S). Substantial, premium feel for business cards, postcards, and covers. |
| 80lb Gloss Factory Coated | 2.21 | factory, +2 days | Light, flexible cardstock with a gloss coat on both sides. Economical covers and cards with vivid color. |
| 100lb Matte Factory Coated | 2.22 | factory, +2 days | Mid-weight matte card with an elegant, glare-free surface. Book covers and upscale cards. |
| 12pt C2S Factory Coated | 2.23 | factory, +2 days | Flexible coated card, thinner than 14pt. Tickets, tags, and lightweight postcards. |
| 14pt C2S Factory Coated | 2.24 | factory, +2 days | Thick card gloss-coated both sides for all-over shine. Postcards and covers that want gloss front and back. |
| 18pt C1S Factory Gloss | 2.25 | factory, +2 days | Our most rigid card — gloss front, uncoated writable back. Heavy-duty hang tags, counter cards, and premium postcards. |

## Notes

- Lead-time wording in the UI is rendered from each row's `days` field, never
  hard-coded in the description, so admin config changes stay truthful.
- `val` is the stable identity (`PAPER_DESC` maps key on it, namespaced
  nc/cs — note NC `0.001` and the cover pseudo-stock "Same as Inside Pages"
  share a val, so the cover-same option must never take a description).
- Tooltip keys follow the category-shortcode slug rule:
  `paper_text_<label>` / `paper_cs_<label>`, lowercased, spaces → `_`.
