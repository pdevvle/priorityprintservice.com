# Deployment: GitHub → Cloudways (theme branch)

The Priority Print theme lives on branch `theme-v1` of the existing `pdevvle/priorityprintservice.com` repository — not in its own repo. The existing `pps-calculators` plugin lives on the `production` branch of the same repository. Two separate Cloudways deploy slots pull the two branches into two separate paths on the live server. The branches never merge and never share files — they're entirely independent histories that happen to live in the same GitHub repo for operational simplicity.

## Branch map

| Branch | What it holds | Cloudways deploys to |
|--------|---------------|----------------------|
| `production` | `pps-calculators` plugin source | `public_html/wp-content/plugins/pps-calculators/` |
| `theme-v1` | Priority Print theme source | `public_html/wp-content/themes/priority-print/` |
| `main`, `claude/*`, `pps-pricing-config` | plugin development branches | — (not deployed) |

Do **not** merge `theme-v1` into any other branch, and do not merge other branches into `theme-v1`. It is a standalone, parallel history.

## One-time Cloudways setup for the theme

1. Cloudways dashboard → your existing application (the one serving priorityprintservice.com).
2. **Application Management → Deployment via Git** → click **Add another remote** (or equivalent — the slot for the plugin already exists; this creates a second).
3. **Git Remote Address**:
   ```
   git@github.com:pdevvle/priorityprintservice.com.git
   ```
4. **Branch**: `theme-v1`
5. **Deployment Path**: `public_html/wp-content/themes/priority-print`
   - This directory must not exist yet (Cloudways will create it on first deploy).
6. **SSH key**: Cloudways generates a new public key for this slot. Copy it.
7. Back on GitHub: https://github.com/pdevvle/priorityprintservice.com/settings/keys → **Add deploy key** → title "Cloudways — theme slot" → paste → leave "Allow write access" unchecked → save. Adding a second deploy key to the same repo is supported and does not interfere with the first.
8. In Cloudways, click **Start Deployment**.
9. After deploy completes, WordPress admin → Appearance → Themes → the **Priority Print** theme should appear. **Do not activate yet** — activation is the cutover step.

## Going live (cutover)

Deliberate and manual. Do in this order, ideally during a low-traffic window:

1. Cloudways → **take a full application backup** (one button; restores later if needed).
2. WordPress admin → Plugins → confirm `pps-calculators` is still active and Yoast is still active. Do not change yet.
3. WordPress admin → Appearance → Themes → hover over **Priority Print** → **Activate**.
4. Click through the live site in a private/incognito browser window:
   - Homepage renders hero + services + values + CTA banner.
   - `/shop/` renders the product grid (three cards per row on desktop).
   - A calculator product page loads; pricing still updates via WCPA.
   - Cart → Checkout completes end-to-end with a test card.
5. View-source the homepage. Confirm exactly one `<meta name="description">`, one canonical, one Organization JSON-LD. If you see duplicates, Yoast is still emitting — proceed to step 6 to clean it up.
6. WordPress admin → Plugins → Yoast SEO → **Deactivate** → **Delete**. The theme's `PP_SEO` class already emits the head tags Yoast was emitting.
7. WordPress admin → Appearance → Themes → hover over Astra / Astra Pro if present → **Delete** (they're inactive at this point; Delete removes them from disk).
8. View-source the homepage one more time. Should still show clean single-source head tags.

If anything in step 4 looks broken, immediately: Appearance → Themes → activate Astra (or whatever was active before), and send a screenshot of the broken behavior.

## Day-to-day deploys

For theme edits Claude Code makes in future sessions:

```
# in a session scoped to this repo
git checkout theme-v1
# make edits
git add -A
git commit -m "Theme: ..."
git push origin theme-v1
```

Then in Cloudways → Deployment via Git → **Pull Latest Code** on the theme deploy slot.

For plugin edits, that's `production` branch as before — unchanged by this setup.

### MCP write + Git mirror model

When the AI Engine MCP connection is active, theme-editing sessions should:

1. Read the live file on the Cloudways server via MCP before editing.
2. Write the new version via MCP **and** commit the same change to Git (branch `theme-v1`) in the same session.
3. Update `CHANGELOG.md` with the full previous version inline.
4. Update `CURRENT-STATE.md` to reflect the new live state.

If MCP write succeeds but Git commit fails, the repo and server diverge. Catch it via occasional `git diff` live-server vs repo.

## Rollback

**Inline** (fastest for a single-file bad change): `CHANGELOG.md` on the theme branch stores the previous version of every edited file in a `<details>` block. Copy that block's content, paste it over the live file via MCP or SFTP.

**Git revert** (cleaner for multi-file change): on `theme-v1`, `git revert <bad-sha>`, push, pull in Cloudways.

**Full previous deploy**: Cloudways → Deployment via Git → point the theme slot's Branch field at a known-good commit SHA, pull, fix forward when ready. Plugin deployment is unaffected because it runs from a different branch / path.
