# Production rollout — Imposition v1.25

## ✅ EXECUTED 2026-09-02 20:51 UTC — production is live on v1.25

Owner authorised the rollout. Both files deployed pull-based at the pinned SHA and
verified on the server copy:

| File | Bytes written | Expected | Verified |
|---|---|---|---|
| `pps-calculators/pps-imposition.php` | 28,732 | 28,732 | ✅ 20:50:59 UTC |
| `pps-calculators/imposition-tool.html` | 241,964 | 241,964 | ✅ 20:51:47 UTC |

- Build chip on the server now reads **`BUILD 2026-09-02 05:20 UTC · IMPOSE-V1.25`**
  (was `BUILD 2026-08-25 04:38 UTC · IMPOSE-V1.12`).
- Server copy of the tool is **byte-identical to the pinned commit** —
  sha256 `d72fda25f768c286660008b5` on both sides.
- Marker counts on the server match the repo exactly: `sha256Hex` ×3,
  `approvalState` ×5, `approvalNote` ×3, the "NOT THE APPROVED FILE" banner ×1,
  `_UNAPPROVED` ×2, and the raster trio `embedJpg`/`embedPng`/`PPS_RASTER_DPI`
  ×1/×1/×3. `allowHashMismatch` is gone (0), as intended.
- PHP carries `proof_hash` in the `pps_impose_list` payload, and
  `set_status()` + order note + `save()` — no `update_status()`, so status
  changes from the queue are silent.

**Deploy order note:** the PHP went first, then the tool — deliberately the reverse
of §5 below. New-PHP + old-tool keeps `proof_hash` flowing so the old gate stays
fully functional during the changeover; tool-first would have given new-tool +
old-PHP, where the missing `proof_hash` makes every order look unbound and approval
checking lapses entirely for those seconds.

Pre-flight passed immediately before writing: both server files still 196,017 /
20,974 b (untouched since the 2026-08-24/25 audit), no `.bak`/`.orig`/`.prehardening`
anywhere in the plugin directory, and both raw URLs returning HTTP 200 at exactly
241,964 / 28,732 bytes.

### Still to check with eyes on production

- **Order 87045** — the raster regression case. It should show artwork, not NO ART.
- A **portrait saddle order** must read `2 × 1`, never `1 × 2`.
- Queue renders with a status dropdown and Hide per row; completed orders drop off
  by default and "show completed & hidden" brings them back with a HIDDEN badge;
  the "Order metadata reference" panel expands.
- A hash-bound order should show the green "approval hash verified" line.

**Do not test a status change on a real customer order** until someone has confirmed
the silent path behaves as wanted — it is silent, so a mistake there is invisible
from the shop side rather than loud.

---

**Prepared 2026-09-02.** Target: `priorityprintservice.com` (production).
Staging is already on this build and verified.

Ships pinned commit **`03bca5852d418ca24ffa492705338a2284fe013f`**
on branch `claude/rollout-imposition-v1-24-gyn4li`.

Pinned to `03bca58` deliberately, not to the branch tip: these are the exact bytes
staging was deployed with and verified on. Later commits on the branch are docs only
and leave both artifacts byte-identical, so deploying from a newer SHA is equivalent —
but `03bca58` is the one with a verification record behind it.

| File | Bytes at this SHA | Production now |
|---|---|---|
| `imposition-tool.html` | 241,964 | 196,017 (`IMPOSE-V1.12`) |
| `pps-imposition.php` | 28,732 | 20,974 |

Raw URLs:

```
https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/03bca5852d418ca24ffa492705338a2284fe013f/imposition-tool.html
https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/03bca5852d418ca24ffa492705338a2284fe013f/pps-imposition.php
```

---

## 1. Why this SHA and not `a9d8544`

The original brief pinned `a9d8544` (v1.24) and assumed production was v1.8.
**It is not.** Production runs `IMPOSE-V1.12` from a *divergent* line — commit
`55815bd` for the tool, `5a81968` for the PHP, neither an ancestor of `a9d8544`.
Deploying `a9d8544` would have silently deleted production's **approval-hash gate**.

`03bca58` (v1.25) is v1.24 **plus** that gate, reconciled per the owner's decision
that a mismatch should advise rather than refuse.

---

## 2. Reconciliation audit — evidence v1.25 loses nothing

Run 2026-09-02 against production's **live bytes**, pulled through the connector,
not against the git commits inferred from file size.

**Live production is byte-identical to the commits it was built from:**

| File | Live size | SHA-256 (first 24) | Matches |
|---|---|---|---|
| `imposition-tool.html` | 196,017 | `18a4a6c76cec5e5f5876d0bb` | `55815bd` exactly |
| `pps-imposition.php` | 20,974 | — | `5a81968` (size + content) |

So nothing on production was hand-edited in place, and the reconciliation source
was the right one.

**Tool — identifiers present in production but not in v1.25: 29.** All accounted for:

| Identifier(s) | Verdict |
|---|---|
| `allowHashMismatch`, `setAllowHashMismatch`, `hashState`, `manualHashState`, `orderHash`, `expectedHash` | Gate internals, renamed to `approvalState`/`approvalNote`. Behaviour preserved and widened. |
| `sheetsPerSig` | **Not a loss** — v1.24 renamed it `sheetsPerForm` and generalized it for the signature-order modes. `totalSheets` still computes and still feeds `sheets: totalSheets` into `drawSlug`; the slug still carries the run count. |
| `synthesis`, `White` | Prose inside one bleed-synthesis comment, reworded in v1.13–v1.24. The mechanism is intact: `SYNTH_MAXFIX`, `SYNTH_MINGAP`, `streakEps`, `STREAK_EPS`, `synthB`, `hasBleed` all present at equal or higher occurrence counts. |
| `Recent` | UI label "Recent orders with artwork on Drive", replaced by the work-list redesign. |
| `BEFORE`, `NOTHING`, `Pre`, `Load`, `Signature`, `Status`, `conscious`, `despite`, `included`, `individually`, `inspect`, `picked`, `proceed`, `refusal`, `skip`, `skipped`, `stage`, `unavailable`, `unchecked` | Prose from the v1.12 blocking-gate wording, deliberately rewritten. |

**Tool — double-quoted string literals in production but not in v1.25: 4.** All deliberate:

- `${row.art_file_name}` — the v1.12 blocking error's interpolation; v1.25 passes the
  same value through `approvalNote`.
- `edges are white by design (no ink near trim) — bleed synthesis skipped` — reworded
  in v1.24 to "edges are white for more than 3/16″ on all four sides, so the fill is
  white too — that is the file, not the setting".
- `impose despite approval mismatch` and `override` — the override checkbox, removed
  because with mismatches no longer blocking it gated nothing.

**PHP — identifiers absent: `refuses`, `those`** (prose only).
**PHP — string literals absent: `wc-completed`** — no longer a hardcoded literal; it is
constructed at runtime by `pps_impose_done_statuses()` and re-added to the query under
"show completed & hidden". Completed orders stay reachable; they are just off the
default work list.

**Every meta key and AJAX hook survives at identical counts:**
`_pps_proof_hash`, `_pps_gdrive_folder_id`, `_pps_metadata`, `_pps_artwork_files`,
`_pps_gdrive_file_id`, `pps_impose_app`, `pps_impose_list`, `pps_impose_download`,
`pps_impose_upload`.

---

## 3. Behaviour changes production will see

1. **Approval mismatch advises instead of refusing.** v1.12 refused to impose a file
   whose bytes differ from what the customer approved unless the operator ticked an
   override. v1.25 always imposes, and instead: pins a red **"NOT THE APPROVED FILE"**
   banner with both hash prefixes for as long as the file is staged, stamps
   `_UNAPPROVED` into the output filename so the flag reaches Drive and the press room,
   and lists every flagged row in a bulk run's summary. Verified files get a green
   confirmation; a hash that cannot be checked (no `crypto.subtle` — non-HTTPS admin)
   reports as *unverifiable*, never as a false mismatch.
2. **Queue status changes are silent.** `set_status()` + `save()` replaces
   `update_status()`, so moving a job through the prepress queue sends **no customer
   email**. The order note records the change, who made it, and that it was silent.
   Carries a trade-off: nothing else hooked on the transition
   (`woocommerce_order_status_*` — stock reduction, analytics, fulfilment integrations)
   runs either. Use the order screen when a customer email or those side effects are
   wanted.
3. **The queue becomes a work list.** Completed / cancelled / refunded / failed drop
   off the default view, as do orders parked with Hide. "Show completed & hidden"
   widens it — nothing is unreachable, but it looks different the first time.
4. **Twelve versions of tool features arrive at once** — explicit saddle signature
   order, saddle orientation enforcement, the creep-message fix, free art scale,
   pull-off trim, parent sheet override, independent marks/fold/slug switches, manual
   grid and per-gap gutters, hidden-layer preservation, and the order-metadata panel.

---

## 4. Pre-flight (run immediately before deploying)

Production's files were last touched 2026-08-24/25. Re-check rather than trust this
document — per `CLAUDE.md`, a whole-file deploy is what destroys surgical patches.

1. Read both server files and confirm they are still **196,017** and **20,974** bytes.
   Any other size means someone edited production since this audit — **stop**, diff it,
   and redo §2 before writing.
2. Confirm no `.bak` / `.orig` / `.prehardening` sits beside either file.
3. Confirm the raw URLs above return HTTP 200 at the pinned SHA.

## 5. Deploy

Pull-based, so the deployed bytes are pinned to a reviewable commit and rollback is the
same call with a different SHA. Do **not** hand-write files onto the server.

```
pps_plugin_download_url(
  url           = <raw imposition-tool.html at 03bca58>,
  relative_path = "pps-calculators/imposition-tool.html")      → expect 241,964

pps_plugin_download_url(
  url           = <raw pps-imposition.php at 03bca58>,
  relative_path = "pps-calculators/pps-imposition.php")        → expect 28,732
```

Both writes must report the exact byte counts above. Anything else, stop.

## 6. Verify — by looking, not assuming

On the server copy, not the repo:

- Build chip reads **`IMPOSE-V1.25`**.
- `embedJpg`, `embedPng`, `PPS_RASTER_DPI` present (the raster landmine — order
  **87045** is the regression case and lives on production, so check it shows artwork,
  not NO ART).
- `sha256Hex`, `approvalState`, `approvalNote` present; `allowHashMismatch` absent.
- PHP carries `proof_hash` in the list payload and `set_status(` (not `update_status(`).

Then in **PPS Calculators → Imposition**:

- The queue renders; each row has an order-status dropdown and a Hide button.
- Completed orders are absent by default; "show completed & hidden" reveals them with a
  HIDDEN badge.
- The "Order metadata reference" panel expands.
- A **portrait saddle order** reads `2 × 1`, never `1 × 2`.
- Load a hash-bound order: it shows the green "approval hash verified" line.

**Do not test a status change on a real customer order** until someone has confirmed the
silent path is what they want in practice — it is silent, so a mistake here is invisible
from the shop side rather than loud.

## 7. Rollback

Same call, older SHA:

| | Tool | PHP |
|---|---|---|
| Back to what production runs today (v1.12) | `55815bd` | `5a81968` |
| Back to v1.24 without the gate (**not advised**) | `a9d8544` | `a9d8544` |

The v1.12 pair is the real rollback target. The SHAs in the original brief
(`db06005` / `a4e7cc8`) point at the wrong development line — do not use them.

---

## 8. Open risk worth naming

The silent status change also suppresses every other `woocommerce_order_status_*`
listener. If anything on production hangs off those transitions — a Make.com scenario,
a fulfilment or analytics integration, stock reduction — it will stop firing for status
changes made **from the queue** (order-screen changes are unaffected). Worth checking
the active integrations once before relying on the queue for status moves.
