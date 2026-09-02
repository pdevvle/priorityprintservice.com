# Rollout — Imposition v1.24 → staging → production

**Original brief written 2026-09-01. Executed 2026-09-02.**

| Site | State |
|---|---|
| **Staging** (`woocommerce-70867-4915293.cloudwaysapps.com`) | ✅ **Deployed at `a9d8544` and verified** |
| **Production** (`priorityprintservice.com`) | ⛔ **BLOCKED — not deployed. See §2.** |

---

## 1. Staging — done

Both files deployed pull-based via `pps_plugin_download_url` against raw URLs
pinned to `a9d8544164e11ba31a15143a2a78404d42a80abc`.

| File | Bytes written | Expected | |
|---|---|---|---|
| `pps-calculators/imposition-tool.html` | 235,692 | 235,692 | ✅ |
| `pps-calculators/pps-imposition.php` | 27,854 | 27,854 | ✅ |

Pre-overwrite safety check (per `CLAUDE.md`) passed cleanly:

- Prior staging `imposition-tool.html` was 165,788 b — **exactly** commit `4ec3258`.
- Prior staging `pps-imposition.php` was 19,885 b — **exactly** commit `60d4022`/`58851cf`.
- No `.bak` / `.orig` / `.prehardening` beside either file.
- Neither size was an orphan, so nothing had been hand-edited in place.

Verified on the **server copy after deploy** (not assumed):

- Build stamp reads `IMPOSE-V1.24`.
- Raster landmine clear: `embedJpg` ×1, `embedPng` ×1, `PPS_RASTER_DPI` ×3 — occurrence
  counts identical to the pinned blob.
- `pull-off-trim`, `OCProperties`, signature-order strings (`cut and stack`,
  `gang in order`), and the order-metadata reference panel all present.
- PHP carries both new endpoints (`pps_impose_set_status`, `pps_impose_set_hidden`),
  `pps_impose_done_statuses()` = completed/cancelled/refunded/failed/trash, and the
  raster-aware `pps_impose_pick_artwork()` (the order-87045 fix).

### Still needs a human at a browser

The sandbox cannot reach the live sites (same limit the brief predicted — only
github.com is routable), so these are **source-verified but not eyes-verified**.
Someone must open **PPS Calculators → Imposition** on staging and confirm:

- The queue renders; each row has a status dropdown and a Hide button.
- Completed orders are absent by default; "show completed & hidden" reveals them
  with a HIDDEN badge.
- The "Order metadata reference" panel expands.
- A **portrait saddle order** reads `2 × 1`, never `1 × 2`.

Order **87045** cannot be checked on staging — it does not exist there. It is a
production order.

---

## 2. Production — BLOCKED, do not deploy `a9d8544` as-is

**The brief's premise was wrong.** It says production is on v1.8, 16 versions
behind, and expects ~163,000–169,000 b. Production is actually:

| File | Size | Commit | |
|---|---|---|---|
| `imposition-tool.html` | 196,017 | `55815bd` — **`IMPOSE-V1.12`** | |
| `pps-imposition.php` | 20,974 | `5a81968` | |

Both sizes match real commits, so nothing was hand-edited — but **neither commit is
an ancestor of `a9d8544`**. Production is on a *divergent line*, not an older point
on the same one. The brief's history table only enumerated commits on
`claude/imposition-tool-google-drive-185wyl`, which is why the mismatch was invisible.

### What deploying `a9d8544` to production would destroy

The **approval-hash gate** — the enforcement half of the art-approval programme in
`docs/ART_APPROVAL_GATE_BRIEF.md`.

Orders placed since the proof-parity work carry `_pps_proof_hash`: the SHA-256 the
calculator computed over the print-ready bytes *at the moment the customer approved*.
Before imposing, the tool hashes what Drive actually returned and **refuses to impose
on mismatch** — a mismatch means the file is not what the customer approved (replaced,
re-exported, or the wrong file picked). Pre-hash orders carry no hash and impose as
before.

`a9d8544` has none of it:

| | production (`v1.12`) | `a9d8544` (`v1.24`) |
|---|---|---|
| `sha256Hex` in the tool | present | **absent** |
| `orderHash` / `expectedHash` checks | present (3 load paths) | **absent** |
| `proof_hash` in the queue payload | present | **absent** |
| `_pps_proof_hash` read in PHP | present | **absent** |

Deploying would silently remove the gate at both ends. The press could then run a
file the customer never approved, with no warning and nothing in the log — precisely
the failure mode `CLAUDE.md` was written about, and the same shape as the raster
regression the brief itself warns of.

**No commit anywhere in the repo reconciles v1.24 with the hash gate.** The highest
build carrying the gate is v1.12 — exactly what production runs.

### The reconciliation needed before production

Small and well-localized, but it touches load paths that were restructured across
16 versions, so it is a real change, not a mechanical patch:

- **PHP — 1 line:** add `'proof_hash' => (string) $item->get_meta( '_pps_proof_hash' )`
  to the `pps_impose_list` item payload.
- **Tool — 8 regions:** the `sha256Hex` helper and its `window.__ppsImpose` export,
  plus hash checks on the manual-load, queue-load and batch paths, the
  `manualHashState` / `hashState` UI states, the UNAPPROVED output flag, and the
  "impose despite approval mismatch" override.

Once merged, re-run the ten-case imposition regression and the duplex-registration
checks before deploying.

---

## 3. Open decision for the owner — carried forward, still unanswered

**Should the queue change order status silently?**

`pps_impose_set_status` calls `update_status()`, which runs the real WooCommerce
transition — so marking an order Completed from the prepress queue fires the
customer-facing completed-order email exactly as the order screen does. The UI
confirms first and the order note records who did it. The alternative is
`set_status()` + `save()`, which changes status with no email.

This was asked before and never answered. Decide it **before** production — a
prepress queue quietly emailing customers is the kind of thing that is only noticed
from the customer's side.

---

## Rollback

Same `pps_plugin_download_url` call with an older SHA.

- Staging, to undo this deploy: tool `4ec3258`, PHP `60d4022`.
- Production, if it is ever deployed and needs reverting: tool `55815bd`, PHP `5a81968`
  — **not** the SHAs in the original brief, which point at the wrong line.

---

## What shipped in v1.8 → v1.24

- Saddle **signature order** made explicit — step and repeat (default), cut and
  stack, gang in order.
- Saddle **page orientation is part of the spec**; the engine refuses a job whose
  artwork orientation contradicts it. Fixed a real "every page sideways" bug.
- **Creep** confirmation reported the opposite of the truth (Babel rewrites `const`
  to `var`, so the warning read the value before assignment). Geometry was always
  right; only the message lied.
- Free **art scale**, **pull off trim**, **parent sheet override**, independent
  **marks / fold guides / slug** switches, **manual grid** and per-gap gutters.
- **Optional content (hidden PDF layers)** preserved rather than silently printed.
- Queue: **order status control**, **auto-drop completed**, **manual hide**.
- **Order metadata reference** panel folded into the tool.
