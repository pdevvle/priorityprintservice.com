# Deployment: GitHub → Cloudways (theme)

This repo auto-deploys to the Cloudways WordPress app via Cloudways' native **Deploy via Git** (pull-based). No GitHub Actions, no secrets stored in GitHub. Same pipeline shape as the `priorityprintservice.com` plugin repo — just a separate Cloudways deploy config aimed at the theme directory.

## Branch roles

| Branch | Purpose |
|--------|---------|
| `main` | Integration branch. Feature work merges here, gets reviewed, then gets forwarded to `production`. |
| `production` | What Cloudways pulls. Live theme mirrors this branch. Merge here only code you've watched work on staging. |

## Deploy target on Cloudways

```
<application root>/public_html/wp-content/themes/priority-print/
```

## One-time GitHub setup

1. Go to `https://github.com/pdevvle/priority-print-theme/settings/keys`.
2. Click **Add deploy key**.
3. Title: `Cloudways — priorityprintservice.com theme`.
4. Paste the public key that Cloudways generated for this deploy slot (Application → Deployment via Git → Generate SSH Key → Copy). Do **not** reuse the plugin-repo deploy key — Cloudways generates a fresh pair per deploy slot.
5. Leave **Allow write access** unchecked.
6. Save.

## One-time Cloudways setup

1. Cloudways dashboard → the application serving priorityprintservice.com.
2. **Application Management → Deployment via Git** → add a **new** deploy slot (the one for the plugin already exists; this is the second).
3. **Git Remote Address**:
   ```
   git@github.com:pdevvle/priority-print-theme.git
   ```
4. **Branch**: `production`.
5. **Deployment Path**: `public_html/wp-content/themes/priority-print`.
   - The path must be empty the first time, or Cloudways will refuse to clone.
6. **Start Deployment**.
7. In WordPress admin → Appearance → Themes, the **Priority Print** theme should now appear. **Do not activate it until you have reviewed the live render on staging.**

## Going live (cutover)

The cutover is deliberate and manual. Do in this order:

1. Confirm `pps-calculators` plugin is already on a version that has the Yoast-suppression code. (If the cutover plan trimmed it, confirm that trim is on `production` too.)
2. Create a database backup via Cloudways.
3. WordPress admin → Appearance → Themes → activate **Priority Print**.
4. Click through:
   - Homepage renders hero + services + values + CTA banner.
   - `/shop/` renders the product grid (three cards per row desktop).
   - A calculator product page loads; pricing still updates via WCPA.
   - Cart → Checkout still complete end-to-end.
5. Plugins → deactivate → delete **Yoast SEO**. (The theme's `PP_SEO` class is already emitting its head tags.)
6. Plugins → deactivate → delete **Astra Pro** if present, and the free Astra.
7. View-source the homepage — confirm exactly one `<meta name="description">`, one canonical, one Organization JSON-LD. If you see duplicates, something isn't wired right — revert via Cloudways.

## Day-to-day deploys

```
# from a feature branch, merged into main, reviewed
git checkout production
git pull origin production
git merge --no-ff main
git push origin production
```

Then in Cloudways → Deployment via Git → **Pull Latest Code** on the theme deploy slot.

### MCP write + Git mirror model

Per the project agreement, Claude Code sessions connected via AI Engine MCP may:

1. Read the live file on the Cloudways server via MCP **before** editing (source of truth is still the server when something is live-tested).
2. Write the new version via MCP **and** commit the same change to Git in the same session.
3. Update `CHANGELOG.md` with the full previous version inline, and update `CURRENT-STATE.md`.

The risk of this model is drift: if MCP-write succeeds but Git-commit fails, the repo and server diverge. Mitigations in `CHANGELOG.md` and a periodic `git diff live-server repo` audit (see CURRENT-STATE.md → Known Issues) catch that.

## Rollback

**Inline rollback (fastest)** — `CHANGELOG.md` stores the previous version of every edited file in a `<details>` block. Copy that block's content, paste it over the live file via MCP or SFTP.

**Git revert (cleaner for multi-file changes)** — on `production`: `git revert <bad-sha>`, push, pull in Cloudways.

**Full previous deploy** — Cloudways → Deployment via Git → change the Branch field to a known-good commit SHA, pull, and fix forward when ready.
