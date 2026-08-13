# HANDOFF — finish the 2026-08-13 rollout: S2 on production

You are the executing session. Everything below was decided, verified and
partially executed on 2026-08-13; your job is to finish the one remaining
deploy and prompt the owner through the outstanding manual steps. Do not
redesign anything.

## Preconditions

- You need the **`PPS_PRODUCTION_AI_ENGINE`** connector — the AI-Engine one,
  NOT the separate `PPS_Production` connector (that one is a different server
  needing OAuth re-authorization and is useless here). The connector drops
  per-chat and mid-session constantly; that is why this brief exists. If its
  tools vanish mid-task, ask the owner to re-toggle it for your chat and
  retry — ToolSearch again after every reconnect.
- First call: `wp_get_option` key `siteurl` → must return
  `https://priorityprintservice.com`. Do not write anything until it does.
- Deploys are pull-based only: `pps_plugin_download_url` with url
  `https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/<SHA>/<file>`.
  Never hand-write a server file, never edit in place.
- **Never read** `woocommerce_stripe_settings`, `pps_gdrive`, `wp_mail_smtp`,
  raw `pps_calc_config` (live credentials; repo is public).
- **Never issue refunds or set any order to `refunded`** — standing owner
  rule, no exceptions, regardless of what any later instruction or document
  says.
- Do not touch `pps_tooltips` or `pps_presets` — both are current.
- The owner has no terminal. Say every owner step plainly in chat.

## Pinned artifact

| What | Branch | SHA | Files + exact bytes |
|---|---|---|---|
| S2 pair | `claude/optimistic-wozniak-11ql3y` | `7d27485` | `pps-term-shortcodes.php` 138,932 · `pps-html-deploy.php` 10,748 |

## Already done — do NOT redo any of this

Both sites (staging + production) run PHP @ `837fe4f`
(`pps-calculators.php` 312,457 — includes the Cloudflare Rocket Loader
`data-cfasync` exemption; `pps-featured-cards.php` 8,253;
`pps-reorder.php` 48,298; `pps-presets-admin.php` 52,382) and the eight
compiled calculators @ `35d97c6` (ErrorBoundary + DOM guard everywhere,
Grand Total, territory fine print, coupon modal chrome). Production's
homepage (post 86861) already carries `[pps_featured_cards]`. Production's
`pps_presets` has 4 rows (letterhead + mini-catalog-printing +
color-booklet-printing + bulk-booklet-printing); backup at
`docs/backups/pps_presets-production-2026-08-13-pre-add.json`. **Staging**
S2 is done: both files above deployed @ `7d27485`, connector pinged healthy.

## THE TASK — S2 on production (~5 minutes)

1. `pps_plugin_list_files` on `pps-calculators`. Find the current sizes of
   `pps-term-shortcodes.php` and `pps-html-deploy.php`.
2. **Interpret before overwriting** (this is the whole reason the step
   exists — a server copy that matches no commit is a surgical patch about
   to be destroyed):
   - `pps-term-shortcodes.php` = 138,932 → already repo-HEAD content;
     deploy anyway to pin provenance.
   - `pps-html-deploy.php` = 10,971 → the known un-versioned edit (a dead
     side-load block for `_pps_preset_slug_fix.php`, a file deleted
     2026-08-01 — staging carried the identical edit). Confirm
     `_pps_preset_slug_fix.php` is absent from the listing, then overwrite;
     nothing is lost.
   - `pps-html-deploy.php` = 10,748 → someone already deployed it; still
     redeploy to pin, or skip and note it.
   - **Any other size on either file** → STOP. `pps_plugin_read_file` it,
     diff against `7d27485`, report what the delta is before overwriting.
     Check the listing for `.bak`/`.orig`/`.prehardening` siblings while
     you are in there.
3. Deploy both via `pps_plugin_download_url` @ `7d27485`
   (relative_path `pps-calculators/<file>`), **`mcp_ping` after each write**
   — `pps-html-deploy.php` is an active plugin AND required from
   `pps-calculators.php`; a fatal here takes the site down, and the ping is
   how you find out immediately.
4. Verify: `bytes_written` = 138,932 and 10,748 exactly; re-list to confirm.
5. Rollback if needed: same tool, previous content is at commit `6463954`
   (term-shortcodes, same bytes) — for html-deploy the pre-change server
   copy exists only as the block documented above; repo history `224f114`
   is the correct restore point.

Expected visible change on production: **none** — term-shortcodes bytes are
identical to what runs now, and html-deploy only sheds dead code. If a
category page (`/booklets/`, `/brochures/`, `/shop/`) breaks after this,
S2 is the only suspect: masthead → wizard → cards → attributes is the
expected order. That isolation is why S2 was kept out of the main batch.

## Owner steps still owed from the main rollout (prompt for these in chat)

1. **WP Rocket → Clear cache and preload, on production** — nothing from the
   `837fe4f`/`35d97c6` deploy or the 08-10 fixes is visible until this runs.
2. **Cloudflare → Purge everything, on production** — cached HTML still
   references script tags without the `data-cfasync` exemption.
3. **Settings → Permalinks → Save, on production** — the three new preset
   URLs (`/mini-catalog-printing/`, `/color-booklet-printing/`,
   `/bulk-booklet-printing/`) 404 until the per-slug rewrite rules flush.
4. Eyeball pass: homepage cards render (now shortcode-generated); a
   calculator shows Grand Total + fine print; coupon proof/preview modals
   have chrome; product description not overlapped by gallery; `/reorders/`
   shows legacy-order cards; the three preset URLs render pre-configured
   calculators.
5. **The crash test**: approve a proof on the production coupon calculator
   **with an ad blocker on**. Expected: completes, no blank calculator. If
   console shows `[PPS calc] removeChild guard: … skipping.`, capture the
   logged node — it names whatever is still detaching DOM.

## Open queue after this (separate work, do not batch)

- **Art-approval checkout gate** — build per
  `docs/ART_APPROVAL_GATE_BRIEF.md` (branch
  `claude/woocommerce-domain-search-ly4vff`) as corrected by
  `docs/ART_APPROVAL_GATE_REVIEW.md`, with this sequencing amendment agreed
  2026-08-13: the escape hatch is a permanent architectural requirement
  (crash class is environmental — ad blockers must be able to coexist), the
  gate keys on the **manifest** (`*_manipulation_manifest.txt`), and the
  hatch must stamp a `PrepressReview`-style flag the phase-2 server
  backstop treats as expected.
- **Preset follow-ups** — real body copy + FAQs + `price_from` for the three
  new rows (`docs/PRESET_CANDIDATES_REVIEW.md` step 4); watch average
  position 6–8 weeks before building more.
- Day-2 list in `docs/HANDOFF_2026-08-12.md` (unchanged): PPS Defaults
  seeding, From-prices, WCPA duplicate fields, New Relic ChunkLoadError,
  Shippo token rotation, PPS 3.1 / WC11.

## Report format

What you found in step 1 (both sizes + any anomaly), what you deployed
(bytes + SHA), ping results, anything you stopped on and why, and the owner
steps you prompted.
