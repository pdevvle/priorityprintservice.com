# Brief — review the `pps_tooltips` staging sync (2026-08-09)

For a fresh session or a human reviewer. **The change is already applied to
staging.** This asks for feedback, not execution — nothing here should be
re-applied. PR #46 on branch `claude/file-contents-review-7742p9`.

The executing session concluded the originating brief
(`docs/TOOLTIP_SYNC_BRIEF.md`, on `claude/optimistic-wozniak-11ql3y`) had the
diagnosis backwards, then made the change anyway on different reasoning. That is
exactly the kind of call worth a second opinion: if the re-diagnosis is wrong, the
write-up is now the misleading record future sessions will trust.

## Access you need

- Repo read. Relevant files: `docs/TOOLTIP_SYNC_OUTCOME.md`,
  `docs/backups/pps_tooltips-staging-2026-08-09-pre-sync.json`, and the original
  `docs/TOOLTIP_SYNC_BRIEF.md` on `claude/optimistic-wozniak-11ql3y`.
- **PPS_STAGING_AI_ENGINE** connector, to read `wp_options['pps_tooltips']`.
- Ideally a browser or a network path to the staging site. The executing session's
  environment denied `woocommerce-70867-4915293.cloudwaysapps.com`, so the
  rendered check is the one thing still unverified.

## What was done

- `wp_options['pps_tooltips']` on staging: 30 keys → 6, written back as a **native
  PHP array**. Deleted the 6 add-on and 18 per-stock paper keys named in the
  original brief; kept `perfect_binding`, `saddle_stitch`, `bleed`,
  `paper_text_weight`, `paper_cardstock`, `page_count` byte-for-byte.
- Archived the full 30-key pre-change value to `docs/backups/`.
- No code changed. Two follow-ups were written up but deliberately not applied.

## Attack this claim first

Everything rests on one finding: the option was stored as a **JSON string**, not a
PHP array, and both read sites do `(array) get_option( 'pps_tooltips', array() )`.
Casting a string yields `array( 0 => $string )`, so:

- the code-shipped defaults were **already winning** for all 33 keys;
- `page_count` — the only key with no entry in `pps_default_tooltips()` — was
  **never delivered at all**, so its tooltip and animated `.webp` rendered nothing;
- 15,325 bytes of stale JSON rode into `PPS_CONFIG.tips` under numeric key `0` on
  every calculator and preset page;
- the masking the original brief described was **latent, not active** — the admin
  Tooltips save writes a native array, so the next save from that tab would have
  triggered it.

Three independent ways to check it:

1. The cast semantics, locally: `php -r '$s="{\"a\":1}"; var_dump( (array) $s );'`
   Confirm you get `[0 => string]` and not a keyed map.
2. Corroborating precedent in the same file: `pps_get_registry()` carries a comment
   documenting this exact failure mode — *"The MCP `wp_update_option` endpoint
   serialises array payloads as JSON strings"* — and guards against it. Tooltips
   never got that guard. Does that read to you as the same bug, or a different one?
3. `git grep` the DB's old paper copy (e.g. `Our lightest in-stock text paper`)
   across `--all`. It appears at no commit — which is the basis for the claim that
   the 18 paper entries were authored outside version control.

**If the re-diagnosis is wrong** and the 24 keys really were masking the new copy,
the deletion is still the right outcome, but `docs/TOOLTIP_SYNC_OUTCOME.md` and the
PR body explain it incorrectly and should be corrected rather than left to mislead.

## Verify the current state — cheap and decisive

`wp_get_option` `pps_tooltips` with `raw: true`. Expect:

- a **JSON object**, not a quoted JSON string (if it comes back as a string, the
  write did not land as a native array and the fix is incomplete);
- exactly 6 keys, the ones listed above;
- `page_count` still carrying its `image` block with the `.webp` src intact.

Compare any key against `docs/backups/…-pre-sync.json` to confirm the kept content
survived unchanged.

## The check nobody has run

The originating brief's step 6. On a booklet calculator page:

- the page-count `?` shows "How to Count Booklet Pages" **with its animated image** —
  this is the one thing the change should have visibly altered, since it was dead
  before;
- a paper card `?` ends with "quick turnaround, small quantities, and hardcopy
  proofs" (the repo copy), **not** "In stock • Not UV coatable" (the deleted DB copy);
- in the console, `Object.keys( PPS_CONFIG.tips ).includes( '0' )` is `false`, and
  `PPS_CONFIG.tips.page_count` is an object.

Purge WP Rocket if the page looks stale.

## Weakest part of the change

The backup's fidelity. It was transcribed from a single read of the live option.
11 of the 30 keys were programmatically checked against git (6 add-ons vs
`1185e1c`, 5 kept keys vs `472bf3d`) and matched byte-for-byte — but **the 18 paper
entries have no version-controlled counterpart to diff against**, and the live
option has since been overwritten, so that check can no longer be repeated.

Is a single-read archive of unversioned production copy good enough, or should the
paper copy have been ported into `pps_default_tooltips()` before deleting it?

## Decisions that need a human

1. **Branch topology — probably the biggest finding.** The canonical 33-key
   `pps_default_tooltips()` exists **only** on `claude/optimistic-wozniak-11ql3y`.
   It is deployed to staging (verified: deployed file is 296,420 bytes, matching
   `472bf3d` and no other commit) but is **not** on `pps-pricing-config`, whose copy
   still has 13 keys. Per CLAUDE.md's own rule, that is one deploy from being
   undone. Should that branch merge first, ahead of everything else here?
2. **The two unapplied follow-ups** (full patch in `docs/TOOLTIP_SYNC_OUTCOME.md`):
   give the tooltips read the same JSON-string tolerance `pps_get_registry()` has,
   and port `page_count` into the defaults. They were held back because this branch
   carries the 13-key defaults and editing it would revert the deployed set on the
   next deploy. Right call, or over-cautious?
   Ordering constraint either way: the tolerance must land **after** the stale keys
   are cleared, or it starts honouring the copies this task removed.
3. **Editorial.** The deleted paper copy carried UV-coatability and lead-time lines
   (`In stock • UV coatable`, `Factory order (5–7 business day lead time)`) that the
   current repo defaults do not. Deliberate, or an accidental omission worth
   restoring into the defaults?
4. **The 5 redundant kept keys.** `perfect_binding`, `saddle_stitch`, `bleed`,
   `paper_text_weight`, `paper_cardstock` are byte-identical duplicates of the
   defaults, so they override nothing. The original brief said keep them
   byte-for-byte and that was honoured. Drop them too, so only genuine overrides
   live in the DB?
5. **Autoload, unverified.** The plugin writes this option with
   `update_option( …, false )`; the MCP endpoint may not preserve `autoload = no`.
   Worth checking with DB access — the option is now ~4 KB (down from ~15 KB), so
   even autoloaded it is better than before, but it should match intent.

## Do NOT

- Re-run the deletion — it is done. Re-applying against the current 6-key value
  would be a no-op at best.
- Add the JSON-string tolerance without first confirming the 24 keys are gone.
- Edit `pps-calculators.php` on `claude/file-contents-review-7742p9` — that branch's
  copy of `pps_default_tooltips()` is the old 13-key version.
- Touch any key other than the six listed. `score_fold` and `envelopes` exist in the
  code defaults but never existed in this option; that is expected, not drift.
