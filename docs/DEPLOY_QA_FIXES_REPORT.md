# Report back — `DEPLOY_QA_FIXES_BRIEF.md`, executed 2026-08-10

For the session that wrote that brief. Answers its "Report back" section, plus
findings that change two of its premises. Staging only; nothing touched
production.

---

## Task 1 — 3 calculator files: ✅ DEPLOYED

Downloaded from pinned `4012e8f4f7ac1ffe66968d6afbafc931035d3af9` into
`pps-calculators/_pending_html/`, then `mcp_ping` to fire the deploy hook.

| File | Bytes written | Archive |
|---|---|---|
| `calc-preview-test.html` | 610,471 | `_archive/2026-08-10-020053/` |
| `calc-perfect-bound.html` | 618,556 | `_archive/2026-08-10-020056/` |
| `calc-coupon-book.html` | 426,191 | `_archive/2026-08-10-020106/` |

Engine accepted all three — pending root retains nothing, every path is under
`_archive/`. Byte counts match the fetches, so the pinned bytes are live.

**Not verified visually.** The sandbox cannot reach staging (egress policy 403s
the host at CONNECT), so the W/H-swap and gradient checks still need a browser.

## Task 2 — WP Rocket: ⚠️ NOT APPLIED, handed to the owner

Your write-hazard protocol was followed, and it produced a different answer than
expected.

**The hazard you warned about is gone.** I probed the array round-trip on a
throwaway option key first — nested objects, lists, ints and bools all returned
as structured data, not a quoted JSON string. Current `wp_update_option` decodes
JSON to a PHP array before storing. So the `pps_get_registry()` /
`pps_get_tooltips()` guard comments describe a fixed problem.

**But there are two other blockers, and they're hard:**

1. **`wp_rocket_settings.consumer_key` reads back as `"********"`.** No way to
   tell a transit mask from the stored value. `wp_update_option` writes the whole
   option — there is no single-key patch — so a read-modify-write risks storing
   the literal asterisks over Rocket's license key.
2. **There is no lazy-render key in the option at all** on 3.18.3. Even with a
   safe write, Lazy Render could not be disabled this way; that toggle exists
   only in the UI.

So this went to wp-admin, which your brief blesses as always-safe: **File
Optimization → uncheck Delay JavaScript execution; Media → uncheck Automatic
lazy rendering; then Clear and preload cache.**

**Evidence supporting your diagnosis**, from the config I read: `delay_js` is
`1`, and `delay_js_exclusions` holds only three patterns —
`(?:/wp-content|/wp-includes/)(.*)`, a jQuery pattern, and `js-(before|after)`.
None of those covers Babel if it loads from a CDN, which is exactly the
"scripts held until first interaction, then pay the ~600KB compile" mechanism.

## Task 3 — checkout disclaimer: ✅ FIXED

**It lived in page `15372`, slug `checkout`** — inside an `<h6>` in a Spectra
(`wp-block-uagb-container`) block, in `post_content`. Not a Woo setting.

Changed via targeted search-and-replace (`wp_alter_post`) rather than
re-uploading the page. Now reads:

> "The shipping costs and time estimates on this page reflect both the time and
> cost of production and transit."

Verified: a follow-up search for the old wording returns "No occurrences found."

The heading's auto-generated anchor still contains the old slug text
(`…the-cost-for-both-production-and-transit`). Left deliberately — changing it
would break any inbound link to that anchor. Say if you want it regenerated.

## Task 4 — 50lb row: ⚠️ CONFIRMED PRESENT, handed to the owner

Saved config does still carry it, exactly as you predicted:

```
pps_calc_config.papers_nc[3] =
  { label: "50lb Offset Smooth Opaque", val: 2.001, price: 0.039,
    factory: true, coatable: false }
```

Not removed via connector. Rewriting a ~300-line pricing structure to delete one
row is disproportionate when **PPS Config → Papers → delete → Save** is one
click, and it would round-trip a live API token through the JSON layer for no
benefit (see the Shippo note below).

## Freeze-cluster retest — ⛔ NOT RUN

Blocked: needs the Rocket change first, and needs a browser. See the next section
for what changed about its prerequisites.

---

## Two premises in your brief are now wrong

### 1. New Relic was never part of the freeze cluster

**Owner, 2026-08-10: the "failing/retry-looping New Relic chunk" was his browser's
ad blocker** killing that request locally. There is nothing to disable in
Cloudways; that owner-side action is void.

This matters for the diagnosis, not just the chore list:

- **Delay JS + Lazy Render is now the whole hypothesis, not half of it.** Your
  brief had NR "amplifying the boot storm." Remove it and the Rocket change either
  fixes the cluster or the diagnosis is incomplete — there's no second factor left
  to absorb a partial result.
- **The retest must run in a clean browser profile with the blocker off.** An ad
  blocker can itself stall a page when script code awaits a request it killed. If
  QA's ~30s freezes and blank gaps were observed in that same browser, some
  symptoms may be local artifacts. Without a clean profile you can't distinguish a
  failed fix from a blocked request.
- **The retest's prerequisites drop from two owner actions to one** (Rocket only).

### 2. The array-serialisation hazard no longer applies

Probed and clean, as above. Worth knowing because it was the stated reason to
avoid connector writes to `wp_options` generally — that caution can be relaxed,
*except* where a value comes back masked (Rocket) or where the option is huge and
load-bearing (`pps_calc_config`).

---

## Findings you'll want

**A live Shippo token sits in `pps_calc_config`.** `pcf.shippo_api_token` holds a
`shippo_live_…` key. The code handles it correctly — `pps_get_public_config()`
strips it (and `question_recipient_email`) at both `window.PPS_CONFIG` emission
sites, lines 882 and 4295, and `pps-shippo-test.php` self-tests for the leak. So
nothing is publicly exposed. **But reading the raw option pulls a live
payment-adjacent credential into a transcript**, which happened here while
verifying Task 4. Rotation is pending an owner decision. Future sessions should
prefer the admin UI for paper-row checks.

**Order meta is unreadable with current tooling.** `woo_get_order` returns
id/status/total/currency/dates/billing/shipping/payment_method/line_items/notes
and **no meta of any kind**. Under HPOS the PPS data lives in
`wp_wc_orders_meta`, which `wp_get_post_meta` cannot reach either. So
`PPS-Spec`, `_pps_artwork_path`, `_pps_artwork_files` and `_pps_summary` cannot be
verified at all — which blocks confirming that the production orders pulled to
staging carry usable PPS data. Needs a small read-only accessor (a
`woo_get_order_meta` tool, or a `meta` field on `woo_get_order`; the class already
has `flatten_meta()`). Not built without sanction.

**MCP tools v1.8.0 is deployed to staging** (`922b463`, 71,633 bytes, pinned) —
the three retention fixes. Reviewed before deploying since it deletes customer
files; lock releases via `try/finally` and self-expires, the skip reason
re-checks `file_exists()`, and the prefetch normalises paths consistently. Two
sequential dry runs both proceeded, which is the observable proof the lock
releases. Repo and staging now agree on `priority-print-mcp.php`.

---

## Compliance with your DO-NOT list

- **No PHP re-deployed from an older SHA.** One PHP file *was* deployed:
  `priority-print-mcp.php` at v1.8.0 — a different plugin from the
  `pps-calculators` set your instruction protects, and a *newer* commit, requested
  by `NEXT_SESSION_BRIEF_2026-08-10.md` §E. `pps-calculators.php`,
  `pps-term-shortcodes.php`, `pps-config-admin.php` and `pps-reorder.php` were not
  touched.
- `wp_options['pps_tooltips']` — untouched.
- No `wp_option` written without the read-back gate. The only writes were the
  throwaway probe key and (separately, for go-live work) the HPOS sync flag.
- Nothing deployed to PPS-Production.
- **Residue:** option `pps_array_roundtrip_probe` exists, blanked. There is no
  delete-option tool. Inert; drop it whenever convenient.

## Still open

| Item | Owner |
|---|---|
| Rocket: Delay JS + Lazy Render off, clear cache | owner (wp-admin) |
| Delete the 50lb paper row | owner (PPS Config → Papers) |
| Visual check of the Task 1 fixes (W/H, gradient) | owner or a browser-capable session |
| Freeze-cluster retest in a clean profile — **the 3.0 gate** | after Rocket |
| Shippo token rotation decision | owner |
| Read-only order-meta accessor, to unblock PPS-data verification | needs sanction |
