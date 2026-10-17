# Outcome — `pps_tooltips` staging sync (2026-08-09)

Executes `docs/TOOLTIP_SYNC_BRIEF.md` (on branch `claude/optimistic-wozniak-11ql3y`).
The job is **done on staging**, but the diagnosis in the brief was wrong in a way
worth recording, because the real fault was a storage-format bug that had two
side effects nobody had noticed.

## What the brief assumed

> staging's `wp_options['pps_tooltips']` still holds OLD copies of 24 keys, and
> `array_merge( pps_default_tooltips(), saved )` — saved wins per key — so the new
> copy is being masked for exactly those keys.

## What was actually true

`pps_tooltips` was stored as a **JSON string**, not a PHP array. Both read sites
do `(array) get_option( 'pps_tooltips', array() )`, and casting a string to an
array in PHP yields `array( 0 => $string )` — not a keyed map. So:

| | Before | After |
|---|---|---|
| New copy for the 24 keys | already winning (defaults never overridden) | still winning |
| `page_count` tooltip | **not delivered at all** | delivered |
| Junk `0` key in `PPS_CONFIG.tips` | **15,325 bytes of stale JSON per page load** | gone |
| Masking risk | latent — one admin save away | removed |

Two consequences the brief did not anticipate:

1. **`page_count` was silently dead.** It is the only key that exists *solely* in
   the database — `pps_default_tooltips()` has no `page_count` entry — so the
   string-cast meant the "How to Count Booklet Pages" tooltip, including its
   animated `.webp`, rendered nothing on the booklet calculators. It works again.
2. **Every calculator and preset page shipped ~15 KB of dead JSON**, merged in
   under numeric key `0` and serialised straight into `PPS_CONFIG.tips`.

The masking the brief describes was real but **latent, not active**: the admin
Tooltips save path (`update_option( 'pps_tooltips', $clean, false )`) writes a
native array, so the first save from that tab would have flipped the option to a
real map and the 24 stale keys would have started masking the new copy that same
request. Cleaning the keys defused it.

## Verified before touching anything

- Deployed `pps-calculators/pps-calculators.php` is **296,420 bytes**, which
  matches `472bf3d` exactly and no other commit in history — so the deployed
  defaults really are the 33-key canonical set, and dropping the 24 DB keys
  cannot strand a tooltip. No `.bak` / `.orig` / `.prehardening` siblings.
- All 24 target keys were present; none carried image/video/youtube blocks, so
  step 2 of the brief needed no media port. The only media block in the option is
  on `page_count`, which was kept.

## What changed on staging

`wp_options['pps_tooltips']`: 30 keys → 6, written back as a **native array** so
the merge semantics work as designed.

Kept byte-for-byte: `perfect_binding`, `saddle_stitch`, `bleed`,
`paper_text_weight`, `paper_cardstock`, `page_count`.
Deleted: the 6 add-ons and 18 per-stock papers listed in the brief.

Of the kept keys, five are byte-identical duplicates of `pps_default_tooltips()`
and could be dropped later with no visible effect. `page_count` is the only one
carrying unique content.

## Backup

`docs/backups/pps_tooltips-staging-2026-08-09-pre-sync.json` — the full 30-key
pre-change value.

This is not a redundant copy. The **18 per-stock paper tooltips in the database
never existed in the repo** at any commit; they were authored outside version
control and are the older `Best for: … / In stock • Not UV coatable` copy, not the
`In our standing inventory — … hardcopy proofs` copy that ships in
`pps_default_tooltips()`. Deleting them from the DB was the only copy's last
stop, so it is archived here.

Fidelity check: the 11 keys git can corroborate (6 add-ons against `1185e1c`, 5
kept keys against `472bf3d`) match the archive byte-for-byte. The 18 paper
entries have no version-controlled counterpart to diff against — they come from a
single read of the live option.

Note for whoever owns the copy: the deleted paper text carried UV-coatability
and lead-time lines (`In stock • UV coatable`, `Factory order (5–7 business day
lead time)`) that the current repo defaults do not. That is an editorial call,
not a bug — flagging it in case the omission was accidental.

## Not done — two follow-ups that need the tooltip branch

Both belong in `pps-calculators.php`, and **this branch is the wrong place for
them**: it carries the 13-key version of `pps_default_tooltips()`, so editing it
here and deploying would revert the deployed 33-key set. They need to land on
`claude/optimistic-wozniak-11ql3y` (or after it merges).

### 1. Give the tooltips read the same tolerance the registry already has

This whole bug is one `pps_get_registry()` already documents and defends against:

```php
// The MCP wp_update_option endpoint serialises array payloads as JSON
// strings; decode so callers always receive an array …
if ( is_string( $reg ) ) { … }
```

`pps_tooltips` never got that guard, which is exactly why it drifted. Both read
sites (in the product render and the preset render) should go through one helper:

```php
function pps_get_saved_tooltips() {
    $saved = get_option( 'pps_tooltips', array() );
    if ( is_string( $saved ) ) {
        $decoded = json_decode( $saved, true );
        $saved = is_array( $decoded ) ? $decoded : array();
    }
    return is_array( $saved ) ? $saved : array();
}
```

then `$tips = array_merge( pps_default_tooltips(), pps_get_saved_tooltips() );`

Order matters: apply this **only** with the stale keys already cleared, or the
decode starts honouring exactly the stale copies this task removed.

### 2. Port `page_count` into `pps_default_tooltips()`

It is the sole tooltip with no code-shipped default, so it depends on a database
row that nothing in the repo can rebuild. Moving it into the defaults makes the
repo authoritative for every key, matching the paper-catalog philosophy the brief
invokes.

## Verification still owed

Brief step 6 (rendered check) could not be run from this session: the environment's
network policy denies `woocommerce-70867-4915293.cloudwaysapps.com`, so the page
HTML was unreachable. The DB round-trip was verified by re-reading the option.

Reasoning about what should be visible: because defaults were already winning, the
paper cards, Cover Coating and Print Quality copy were **already correct before
this change** — the brief's step-6 expectations were being met for the wrong
reason. The one visible difference is the `page_count` tooltip coming back on the
booklet calculators. Worth a hard refresh, and purge WP Rocket if stale.

(For reference: the brief's "Print Quality" wording lives on the `vivid` key —
`Every job receives high quality printing, but enhanced vivid mode…` — there is no
`print_quality` key.)

## Carry to production

Per the brief, staging's DB ships to production in the go-live push, so this
carries automatically. If production is instead synced by hand, apply the same
6-key native-array value there — and check its stored type, since a production
copy written the same way will have the same string-cast bug.
