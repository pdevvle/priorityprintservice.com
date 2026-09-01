# Rollout brief — Imposition v1.24 → staging → production

**Written 2026-09-01. Hand this to a fresh session that has the PPS connectors
enabled in-chat.** Everything is committed, pushed and verified; nothing is
deployed. This is a deploy-only task.

---

## Why this is a new session

The work was done in a session where **PPS STAGING AI ENGINE** and **PPS
PRODUCTION AI ENGINE** showed `connected: true` but `enabledInChat: false` —
authenticated at account level, tools not loaded into that conversation, so
neither site was reachable. Confirmed empirically: no `mcp__PPS*` tool existed
in the registry.

**Before starting, confirm the tools are actually callable** — don't trust the
flag. If `ListConnectors` shows `enabledInChat: false`, stop and tell the owner
to enable both connectors in that chat's connector settings.

---

## What ships

Pinned commit **`a9d8544164e11ba31a15143a2a78404d42a80abc`**
on branch `claude/imposition-tool-google-drive-185wyl`.

| File | Size at this SHA | Why it must go |
|---|---|---|
| `imposition-tool.html` | 235,692 b | Production is on **v1.8** — 16 versions behind |
| `pps-imposition.php` | 27,854 b | New queue endpoints; the queue work is **inert without it** |

Raw URLs (verified reachable, HTTP 200):

```
https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/a9d8544164e11ba31a15143a2a78404d42a80abc/imposition-tool.html
https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/a9d8544164e11ba31a15143a2a78404d42a80abc/pps-imposition.php
```

Deploy pull-based (`pps_plugin_download_url` against these raw URLs) so the
deployed bytes are pinned to a reviewable commit and rollback is the same call
with an older SHA. Per `CLAUDE.md`, **do not** hand-write files onto the server.

---

## Pre-overwrite safety check (do this first, both sites)

`CLAUDE.md` — "Before overwriting any file on a server". A whole-file deploy is
the mechanism that destroys surgical patches, and this repo has been bitten
before (artwork-upload hardening, 2026-08-01).

1. **Read the server copy of each file and compare its size against the history
   below.** A size matching **no** commit means the server copy was edited in
   place — stop, read it, find out what is in it before overwriting.
2. **Look for `.bak` / `.orig` / `.prehardening` beside either file.** That is
   the fingerprint of a surgical patch. The `.bak` is the *pre*-patch state, so
   it cannot restore the patch — it only proves one happened.

`imposition-tool.html`

| SHA | Size | |
|---|---|---|
| a9d8544 | 235,692 | this deploy |
| 0ab6577 | 223,510 | queue status/hide |
| e291519 | 217,831 | creep warning fix |
| 844e560 | 217,322 | saddle orientation guard |
| a734e25 | 213,948 | signature mode in label |
| 7e9bbf0 | 213,465 | explicit signature order |
| ed246c4 | 211,337 | ganging |
| db06005 | 205,929 | step-and-repeat toggle |

Expect production to be around **163,000–169,000 b** (v1.8). Anything else,
investigate before writing.

`pps-imposition.php`

| SHA | Size | |
|---|---|---|
| 0ab6577 | 27,854 | this deploy |
| a4e7cc8 | 20,575 | accept raster artwork |
| 60d4022 | 19,885 | adopt four files from the other line |
| e83466a | 19,482 | audit fixes |
| 619d4f3 | 15,009 | saddle imposition |
| f061fa4 | 13,217 | original |

---

## Known landmine — raster support

Earlier in the previous session a v1.8-based branch **silently dropped raster
(JPG/PNG) artwork support that production already had**, and it was only caught
because a deploy helper reported the previous file size. It has since been
merged back.

Confirmed present at `a9d8544`: `embedJpg` / `embedPng` and `PPS_RASTER_DPI`
around line 1944 of `imposition-tool.html`.

**After deploying, grep the server copy for `embedJpg` and confirm it is there.**
Order **87045** is the regression case — a customer's two JPGs that the queue
once reported as "no artwork". It should show artwork, not NO ART.

---

## Sequence

### 1. Staging

Deploy both files at the pinned SHA. Then verify **by looking, not assuming**:

- Build chip bottom-right reads **`IMPOSE-V1.24`**.
- Server copy contains `embedJpg` (raster landmine above).
- **PPS Calculators → Imposition** loads; the queue renders.
- Each row has an **order-status dropdown** and a **Hide** button.
- Completed orders are **absent** by default; **"show completed & hidden"**
  reveals them with a HIDDEN badge.
- Open the **"Order metadata reference"** panel in the header — it expands.
- Load a **portrait saddle order**: the layout panel must read **`2 × 1`**,
  never `1 × 2`. (`1 × 2` means the page size came through landscape — see
  `docs/PPS_METADATA_REFERENCE.md` §2.)
- Order 87045 shows artwork.

Do **not** exercise a status change on staging against a real customer order
unless the owner is fine with the email — see the open decision below.

### 2. Production

Same SHA, same two files, same verification list. Nothing new is authored for
production; it gets exactly what staging was verified on.

### Rollback

Same `pps_plugin_download_url` call with the previous SHA. For the tool alone,
`db06005` is the last pre-ganging build; for the PHP, `a4e7cc8` is the last
pre-queue version.

---

## Behaviour changes the owner should expect

1. **The queue's default view changes.** It is now a *work list*: only open
   statuses show. Completed / cancelled / refunded / failed drop off, as do
   orders parked with Hide. "Show completed & hidden" widens it — nothing is
   unreachable. This is the requested behaviour but it looks different the
   first time.
2. **Status changes send customer emails.** `update_status()` runs the real
   WooCommerce transition, so marking an order Completed from the queue fires
   the completed-order email exactly as the order screen does. A confirmation
   dialog says so before it fires, and the order note records who did it.

### Open decision — carry this to the owner

They were asked whether the queue should change status **silently**
(`set_status()` + `save()`, no transition emails) instead. **No answer yet.**
If they want silent, make that change *before* production, not after — a
prepress queue quietly emailing customers is the kind of thing that is only
noticed from the customer's side.

---

## What is already done (do not redo)

- All code committed and pushed to `claude/imposition-tool-google-drive-185wyl`.
- `imposition-tool.html` v1.24 published to `pps-pricing-config` (GitHub Pages
  preview) — that branch is the Pages source; `.nojekyll` intact.
- Ten-case imposition regression byte-identical across every change in the
  series; duplex registration verified in every signature mode on both flip
  edges; queue behaviour driven end-to-end in a browser against a mock
  WooCommerce backend.
- Docs updated: `docs/IMPOSITION_TOOL.md`, `docs/PPS_METADATA_REFERENCE.md`,
  `CLAUDE.md`.

## What is in this release (v1.8 → v1.24)

- Saddle **signature order** made explicit — step and repeat (default, unchanged
  from pre-1.18), cut and stack, gang in order.
- Saddle **page orientation is part of the spec**; the engine refuses a job
  whose artwork orientation contradicts it, and the trim re-orients when the
  calc changes. This fixed a real "every page sideways" bug.
- **Creep** confirmation reported the opposite of the truth (Babel rewrites
  `const` to `var`, so the warning read the value before assignment). Geometry
  was always right; only the message lied.
- Free **art scale**, **pull off trim**, **parent sheet override**, independent
  **marks / fold guides / slug** switches, **manual grid** and per-gap gutters.
- **Optional content (hidden PDF layers)** preserved rather than silently
  printed.
- Queue: **order status control**, **auto-drop completed**, **manual hide**.
- **Order metadata reference** panel folded into the tool.

---

## Sandbox constraint worth knowing

The previous session's sandbox could reach **github.com only** — not the live
sites, not `pdevvle.github.io`. So Pages publication was verified through git
(reading the file back off the branch), never by fetching the URL. A new
session will likely have the same limit: verify the live sites through the PPS
connector tools, not `curl`.
