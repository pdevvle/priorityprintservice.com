# Rollout — the product spawner

**Two files.** Verified against production on 2026-08-15 05:0x UTC, not assumed.

## Where production already is

Another session executed `docs/HANDOFF_2026-08-15.md` at **04:21 UTC** and landed
the five-file feed batch. Confirmed by listing:

| File | Production | mtime | |
|---|---|---|---|
| `pps-catalog.php` | 15,324 | 04:21:05 | ✓ current |
| `pps-product-feed.php` | 21,520 | 04:21:12 | ✓ current |
| `pps-gbp-sync.php` | 11,568 | 04:21:22 | ✓ current |
| `pps-calculators.php` | 330,649 | 04:21:31 | at `4ab0d05` |
| `pps-config-admin.php` | 116,490 | 04:21:40 | ✓ current |

So `docs/DEPLOY_FEED_2026-08-15.md` is **done**. This is the remainder.

## What is left

| File | Bytes | |
|---|---|---|
| `pps-spawn-product.php` | 23,407 | **new** |
| `pps-calculators.php` | 330,968 | +7 lines: the loader for it |

Commit **`5ad2b64`** on `claude/optimistic-wozniak-11ql3y`.

Pull-based, as always:
`pps_plugin_download_url` →
`https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/5ad2b64/<file>`,
relative path `pps-calculators/<file>`.

### Order and checks

1. **`pps-spawn-product.php` first.** Its loader in `pps-calculators.php` is
   `file_exists`-guarded, so deploying the satellite first means there is never a
   moment where the loader points at nothing.
2. `mcp_ping` — confirms nothing fatal.
3. **Check before overwriting `pps-calculators.php`:** production should read
   exactly **330,649**. Anything else means somebody edited in place since 04:21;
   stop and read it first (CLAUDE.md).
4. Deploy `pps-calculators.php` → expect **330,968**.
5. `mcp_ping` again. This is the main plugin file — a fatal here takes the site
   down, and the ping is how you find out in seconds rather than from a customer.

**No permalink flush needed.** The spawner is an admin screen, not a rewrite
rule. (The *feed* needed one — see below.)

### Rollback

Delete `pps-spawn-product.php` and re-deploy `pps-calculators.php` at `4ab0d05`.
The loader is `file_exists`-guarded, so removing the file alone already disables
the whole feature cleanly; the `pps-calculators.php` revert is tidiness.

## Verify

1. **PPS Calculators → New Product** exists in the admin menu.
2. Open a calculator, configure anything, **Save configuration**, copy the URL.
3. Paste it into the spawner with a throwaway name. Create.
4. You should land on a new draft product, with a notice reading roughly:
   *"Draft created. 14 calculator settings applied · price set to $284.50 ·
   marked virtual · added to the calculator registry · copied description,
   featured image, categories."*
5. On that product, confirm: **General → Virtual is ticked**, the **PPS
   Calculator** dropdown shows the right calculator, and **PPS Defaults** carries
   the settings.
6. Delete the throwaway product.

If the notice appears but the calculator dropdown reads "None", the registry
write failed — report it rather than fixing by hand.

## Still owed on the feed, from the previous rollout

The five files are on production but the feed may not be reachable yet. Both of
these are quick and neither needs code:

1. **Settings → Permalinks → Save.** The feed registers its own rewrite rule and
   `/pps-product-feed.xml` 404s until the rules flush. This was the direct cause
   of Merchant Center's "File not found". **Confirm the URL returns XML before
   telling Merchant Center to re-fetch.**
2. **`/pps-product-feed.xml?debug=1`** as an admin — reports how many products
   are in the feed, which are omitted and why, and flags per-item faults. Worth
   capturing: the product count is the first honest signal about whether the
   catalogue is ready for Shopping at all. Expect it to be low; most products
   have never had a "Price at these defaults" set, and that is exactly what the
   spawner fixes going forward.

### Two owner-only Merchant Center settings

Either will fail the entire file regardless of how correct the XML is:

- **Settings → Shipping.** With no shipping service configured, every item
  errors "Missing value: shipping".
- **Tax**, same pattern, US accounts.

Neither is something a session can do. Prompt for both.

## After this

`docs/CREATING_A_PRODUCT.md` is the operator's guide to the spawner — hand that
to the owner rather than this file.

The queue in `docs/HANDOFF_2026-08-15.md` §"The queue" is otherwise unchanged,
still led by the preset add-to-cart question, which wants two minutes of live
verification before anyone acts on it.
