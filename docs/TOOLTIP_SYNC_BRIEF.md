# Brief — sync `pps_tooltips` on staging to the new canonical copy

For a session with the **PPS_STAGING_AI_ENGINE** connector. One well-defined job:
staging's `wp_options['pps_tooltips']` still holds OLD copies of 24 keys, and the
tooltip injection merges `array_merge( pps_default_tooltips(), saved )` — saved
wins per key — so the new copy (deployed in `pps-calculators.php` @ `472bf3d`)
is being masked for exactly those keys. New keys (`cover_coating`,
`paper_text_linen`) already flow through because the DB lacks them.

**Bonus:** staging's DB ships to production in the go-live push, so doing this
once on staging carries it to production automatically.

## The 24 stale keys

Add-ons (6): `vivid`, `coating`, `round_cornering`, `bundling`, `perforation`,
`outfold`.

Per-stock papers (18): `paper_text_70lb_uncoated_opaque_text`,
`paper_text_80lb_matte_text`, `paper_text_100lb_gloss_text`,
`paper_text_50lb_offset_smooth_opaque`, `paper_text_60lb_offset_smooth_opaque`,
`paper_text_80lb_offset_smooth_opaque`, `paper_text_80lb_gloss_factory_coated`,
`paper_text_100lb_matte_factory_coated`, `paper_cs_80lb_opaque_uncoated`,
`paper_cs_80lb_matte_cardstock`, `paper_cs_100lb_gloss_cardstock`,
`paper_cs_14pt_gloss_c1s`, `paper_cs_16pt_coated_c2s`,
`paper_cs_80lb_gloss_factory_coated`, `paper_cs_100lb_matte_factory_coated`,
`paper_cs_12pt_c2s_factory_coated`, `paper_cs_14pt_c2s_factory_coated`,
`paper_cs_18pt_c1s_factory_gloss`.

Do NOT touch any other key (`score_fold`, `envelopes`, `bleed`,
`perfect_binding`, `saddle_stitch`, `paper_text_weight`, `paper_cardstock`, and
anything else present — they must survive byte-for-byte).

## Recommended approach: DELETE the 24 keys (Option A)

Removing them makes the repo's `pps_default_tooltips()` permanently
authoritative for these keys — the same single-source philosophy as the paper
catalog. The admin Tooltips tab can still override any key later by re-adding it.

(Option B — overwrite the 24 keys with copies of the default content — only if
step 2 below finds admin-added media worth keeping on a specific key; prefer
porting such media into the repo defaults instead.)

## Procedure

1. `wp_get_option` key `pps_tooltips` (raw: true). **Immediately save the full
   value to a local backup file** before anything else.
2. Inspect the 24 target keys in the read value: if any carries non-text blocks
   (image/video/youtube) that the repo defaults lack, note it and decide
   (default: port the media block into `pps_default_tooltips()` in the repo,
   same-session, then still delete the DB key).
3. Delete the 24 keys from the object. Verify: key count after = before − 24,
   and no OTHER key changed.
4. `wp_update_option` `pps_tooltips` with the FULL modified object. Never write
   a partial/truncated object — this replaces the option wholesale, and it
   carries 30+ unrelated tooltips.
5. Re-read the option; confirm the 24 keys are gone and two untouched keys
   (e.g. `score_fold`, `bleed`) are intact.
6. Verify rendered: a category page paper card "?" shows the new stock copy
   (ends with the "quick turnaround, small quantities, and hardcopy proofs"
   availability line); a booklet calculator's Cover Coating "?" shows the
   owner's retexture copy; Print Quality shows "Every job receives high quality
   printing…". Full-page caches may lag — purge WP Rocket if stale.

## Where the canonical copy lives (for reference, not edits)

`pps_default_tooltips()` in `pps-calculators.php` (deployed), sourced from
`docs/PAPER_CATALOG.md` (papers) and the owner's 2026-08-09 add-on copy. The
update chain rule is documented in CLAUDE.md's Shared Components section.
