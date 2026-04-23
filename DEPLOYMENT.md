# Deployment: GitHub → Cloudways

This repo auto-deploys to the Cloudways WordPress app via Cloudways' native **Deploy via Git** (pull-based). No GitHub Actions, no secrets stored in GitHub.

## Branch roles

| Branch | Purpose |
|--------|---------|
| `production` | What Cloudways pulls. Live site mirrors this branch. Merge here only code you're willing to ship. |
| `pps-pricing-config` | GitHub Pages preview source. Must keep `.nojekyll` at its root. See `CLAUDE.md` → Branch & Deploy. |
| feature branches | Work-in-progress. Never pulled by Cloudways. |

## Deploy target on Cloudways

Cloudways clones the repo into **one** path. We deploy the whole repo into:

```
<application root>/public_html/wp-content/plugins/pps-calculators/
```

The repo root *is* the plugin (`pps-calculators.php` carries the WP plugin header at line 1). `pps-theme/` rides along inside the plugin folder and stays dormant — the live site runs Astra, and WordPress only loads themes from `wp-content/themes/`. Markdown docs and `.git/` metadata are inert on the server.

## One-time GitHub setup

1. Go to `https://github.com/pdevvle/priorityprintservice.com/settings/keys`.
2. Click **Add deploy key**.
3. Title: `Cloudways — priorityprintservice.com app`.
4. Paste the public key that Cloudways generated (Application → Deployment via Git → Generate SSH Key → Copy).
5. Leave **Allow write access** **unchecked**. Cloudways only needs to pull.
6. Save.

## One-time Cloudways setup

1. Cloudways dashboard → pick the application that serves priorityprintservice.com.
2. **Application Management → Deployment via Git → SSH Keys** — confirm the key generated in the step above is still listed. (Once the matching public key is on GitHub, the handshake works.)
3. **Deployment via Git → Git Remote Address**:
   ```
   git@github.com:pdevvle/priorityprintservice.com.git
   ```
4. **Branch**: `production`.
5. **Deployment Path**: `public_html/wp-content/plugins/pps-calculators`.
   - The path must be empty the first time, or Cloudways will refuse to clone. If the plugin dir already has a hand-uploaded copy, rename it to `pps-calculators.bak` first.
6. **Start Deployment**.
7. Watch the deployment log until it reports success.
8. In WordPress admin → Plugins, confirm **PPS Product Calculators** is active. If WordPress deactivated it during the swap, reactivate.

## Day-to-day deploys

```
# on a feature branch, merged and tested
git checkout production
git pull origin production
git merge --no-ff <your-branch>
git push origin production
```

Then in Cloudways → Deployment via Git → **Pull Latest Code**. The deploy log will show the new commit SHA; confirm it matches your push.

### Optional: auto-pull on push

Cloudways exposes a "deployment webhook URL" on the same screen. Paste it into `https://github.com/pdevvle/priorityprintservice.com/settings/hooks` as a webhook with content-type `application/json` and trigger on `push` only. Every push to `production` will then auto-pull. **Recommended only after you've watched a few manual pulls go cleanly** — an auto-pull with a bad commit ships a bad commit.

## Rollback

Fastest: in Cloudways → Deployment via Git, change the **Branch** field to a known-good commit SHA, pull, then revert back to `production` once the hotfix is on that branch.

Cleaner: `git revert <bad-sha>` on `production`, push, pull in Cloudways. Preferred when more than one commit is on the live branch since the bad deploy.

## What this pipeline does not cover

- **`pps-theme/`** — preview-only, lives in this repo for GitHub Pages. If it ever becomes the live WordPress theme, wire a second Cloudways deployment (same repo, same `production` branch, different deployment path: `public_html/wp-content/themes/pps-theme`) or split the theme into its own repo.
- **Database / wp_options** — OAuth credentials, tooltips, zone map, per-product defaults all live in `wp_options` and are not part of this pipeline. They stay on the server across deploys.
- **Uploaded calculator HTML assignments** — the WooCommerce product ↔ calculator mappings live in `wp_options` (`pps_calculators_registry`) and persist across deploys. If a calculator HTML filename changes, re-assign via PPS Calculators admin.
