# Priority Print — Changelog

One entry per changed file per session. Most recent first. Each entry includes the previous version inline (in a `<details>` block) so rollback never requires `git blame` archaeology.

Entries before the first deploy to Cloudways are marked `(pre-deploy)`.

---

## [2026-04-23] — theme bootstrap (pre-deploy)

**Change:** Initial commit of the Priority Print standalone WordPress theme to branch `theme-v1` of `pdevvle/priorityprintservice.com`. Replaces Astra Pro + Yoast SEO. Files: `style.css`, `functions.php`, `index.php`, `header.php`, `footer.php`, `front-page.php`, `archive-product.php`, `inc/seo-class.php`, `assets/css/woocommerce.css`, `assets/js/main.js`, plus repo docs (`README.md`, `DEPLOYMENT.md`, `CURRENT-STATE.md`, `.gitignore`).

**Scope:** All files are new. There is no previous version to restore. The `theme-v1` branch is an independent parallel history in the plugin repo — it does not share commits with `production`, `main`, or any other branch, and must never be merged with them.

**Rollback:** Deactivate the theme in WordPress admin → Appearance → Themes, switch back to the previous active theme (Astra). Do not delete the files until the previous theme is confirmed active. `pps-calculators` plugin is not modified as part of this commit — SEO-schema split with the plugin happens at cutover, in a separate commit to the `production` branch of this same repo.

<details>
<summary>Previous versions (pre-deploy — none exist)</summary>

This is the initial commit. No previous versions exist.

</details>
