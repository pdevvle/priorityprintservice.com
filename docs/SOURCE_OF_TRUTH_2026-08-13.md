# Source of Truth — full audit & consolidation, 2026-08-13

Result of a repo-wide + production-server sweep for changes living outside
their canonical home: uncommitted local work, diverged branches, and server
files matching no commit. Everything found is either **rescued into git**,
**confirmed repo-ahead** (server just needs a redeploy), or **documented
below as a known orphan with a rescue bar**.

## The three-place model (who is canonical for what)

| Place | Canonical for | Notes |
|---|---|---|
| `pps-pricing-config` (default branch) | **Compiled** calculator HTML (what Pages serves and production deploys), plus the shared repo root | After this consolidation it contains this session's full state |
| `claude/optimistic-wozniak-11ql3y` | Calculator **source form** (inline text/babel JSX) + `tools-compile-calcs.mjs`, the assistant/intake/MCP-server PHP, most 08-12/08-13 docs | **Owned by a concurrently ACTIVE session** (pushed `8d4887b` mid-audit). Do not force anything onto it |
| Production server | DB-resident config (`pps_calc_config`, presets, tooltips), orders, uploads | Files should always match a pinned commit; audit below verifies this |

The compiled/source split is load-bearing: the same `calc-*.html` filenames
hold **different representations** on the two branches. They can never be
naively merged — a merge annihilates one form. Work flows source → compile →
`pps-pricing-config` → deploy.

## Production file audit (2026-08-13, post-deploy of `365c7df`)

Every file in `wp-content/plugins/pps-calculators/` size-matched against
every commit in every branch:

| File | Verdict |
|---|---|
| 8 × `calc-*.html`, `imposition-tool.html`, `pps-config-admin.php`, `pps-presets-admin.php`, `pps-html-deploy.php`, `pps-term-shortcodes.php`, `pps-cart-price-floor.php`, `pps-gdrive.php`, `pps-login-brand.php`, `pps-shippo-test.php` | ✅ match current branch tips exactly |
| `pps-calculators.php` (312,457) | ✅ = commit `837fe4f`. Repo is **ahead** (`dfa4e9b`/`8d4887b` add `pps-defaults-url.php` require + share-link vocab — not yet deployed) |
| `pps-assistant*.php` ×4, `pps-intake.php`, `pps-featured-cards.php`, `pps-term-html.php`, `pps-reorder.php` | ✅ match `optimistic-wozniak` tips |
| `pps-imposition.php` (19,885) | **Was a true orphan** — server-side `CLEAN_*.pdf` patch in no branch. **Rescued 2026-08-13, byte-exact** (commit "Bring home the production CLEAN_ patch") |
| `pps-home-probe.php` (14,586) | Orphan by bytes, but repo (`14,605`, commit `e4f2301`) is the **later superset** — all server features present in repo. Deployed copy = near-final draft, 19B short. Repo ahead; next deploy supersedes. Nothing to rescue |
| `priority-print-mcp/priority-print-mcp.php` (71,614) | Same pattern: server = near-final draft of committed v1.8.0 (71,633, 19B short); repo carries **v1.9.0** (74,023) not yet deployed. Repo ahead |

## Remaining orphans (documented, not yet in git)

### 1. `priority-print-mcp-style.php` — whole active plugin, in NO branch

- Single-file plugin at **plugins root** (invisible to folder listings; found
  via `active_plugins`). "Priority Print MCP — Styling Tools" v1.1.0,
  **34,867 bytes**, mtime 2026-08-10 09:27.
- Provides all `pps_get_custom_css` / `pps_set_theme_mod` / `pps_*_astra` /
  nav-menu / reusable-block / site-identity MCP tools via `mwai_mcp_*`
  filters. Additive/cooperative; read-only widget access by design.
- **Full text was read via `pps_plugin_read_file` in the 2026-08-13 session
  transcript** (this session) — the content is preserved there verbatim.
- **Rescue bar:** commit only a copy verifying **byte count == 34,867** and
  `php -l` clean. Hand transcription was deliberately not attempted (35KB
  through a lossy channel with no server-side hash to verify against — a
  subtly-wrong "canonical" copy is worse than a documented orphan).
  Cleanest path: add a one-shot `_pps_` helper (or an md5 tool to the MCP
  plugin) so a transcript-sourced copy can be hash-verified, then commit.

### 2. `share-cart-url-for-woo` — customized third-party plugin, in NO repo

- 63 files, heavily modified from upstream v2.1.3 (v2.0 saved-carts UI,
  onboarding wizard, an entire MCP abilities class
  `class-share-cart-url-for-woo-mcp.php`), all files mtime 2026-08-10.
- The largest un-versioned surface on the site. A plugin update from
  WooCommerce.com would wipe every customization. Decide: fork into a repo,
  or accept the risk knowingly.

## Compiled-only fixes — ported to source 2026-08-13 (gap CLOSED)

> **Update 11:30 UTC:** all five edit-sets below were ported into the
> source-form calculators and pushed to `claude/optimistic-wozniak-11ql3y`
> @ `e3a57c6`, verified by compiling with `tools-compile-calcs.mjs`
> (all 8 clean; dist output carries every marker). The next
> compile-and-sync will now PRESERVE these fixes. Original porting
> list kept below for reference.

### Original porting list (now applied)

This session's five calculator edit-sets were applied to the **compiled**
files (deployed to production 2026-08-13, on `pps-pricing-config` after this
consolidation). The **source-form** files on `optimistic-wozniak` do NOT
have them. The sibling session's next compile-and-sync will silently revert
all five unless they are ported to source first:

| Edit | Files | Compiled-side anchor (source form may differ only in JSX style syntax) |
|---|---|---|
| Unrenderable-page throw (silent page-skip fix) | all 8 | `if(!srcCanvas)continue;` → throw naming page; plain JS, identical in source |
| Binder-throat `bindQty` gate | perfect-bound, coupon-book | `const bindQty=imp>=2?tQ/2:tQ;` → `bindGangs` check; PCF key `perfectbound_binder_throat_in:13`; plain JS |
| Debug relabels (Press imposition / Binder ganged 2-up / Binder passes) | perfect-bound, coupon-book | `<Row label="Binding mode" …/>` block — JSX form in source |
| FitToggle mobile wrap | perfect-bound, coupon-book | outer `style={{display:"flex",alignItems:"center",gap:6}}` + inner segmented control: add `flexWrap:"wrap"`, `maxWidth:"100%"` — JSX form |
| Mint Preset (debug-gated) | preview-test | `mintPreset` fn after `buildShareUrl`; button beside Debug Panel toggle; mixed JS/JSX |

Also PHP-side (already consolidated, listed for completeness): admin files
carry both the production QA-2 content **and** this session's features
(`prefill_*` handoff, failed-save repost preservation, binder-throat field).

## What changed in this consolidation

1. `pps-imposition.php` CLEAN_ patch rescued byte-exact (above).
2. This session's branch (`claude/woocommerce-domain-search-ly4vff`) merged
   `pps-pricing-config` and re-applied all its edits on the current compiled
   base — then **pushed to `pps-pricing-config`**, so the default branch now
   equals what production runs (plus this doc and the rescue).
3. Admin PHP aligned: production's QA-2 config-admin rebuilt as base + this
   session's edits; presets-admin carries production's two hunks + this
   session's features.

## Standing rules this audit re-confirms

- Size-match against **history**, not just tips, before overwriting server
  files (`pps-imposition.php` proved it again).
- Root-level single-file plugins don't appear in folder listings — check
  `active_plugins` when auditing.
- A 19-byte drift from the nearest commit = deployed-from-near-final-draft
  residue (two independent cases today); repo was ahead both times.
