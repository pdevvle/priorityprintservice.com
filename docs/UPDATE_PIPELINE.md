# The update pipeline — how changes reach production, post-go-live

**Short answer: neither of the two options.** Don't work on staging and push
to production, and don't treat production as the only place you touch. Those
are the same mistake in opposite directions — both assume there is *one*
pipeline. There are four, because you make four different kinds of change,
and they have different risk profiles and different promotion mechanisms.

---

## Why "edit staging, push to production" is now the wrong model

The Cloudways staging→production push **replaces production's database with
staging's**. From `docs/GO_LIVE_RUNBOOK.md` Gate 3:

> Any order, customer, or form submission that lands on live between your
> pull and your push is silently destroyed by the push.

That is why the 3.0 go-live needed a freeze window, a live→staging order-table
pull, an auto-increment realignment, and a 600-line runbook. It was the right
tool **once**, for a big-bang cutover.

It is the wrong tool for "I changed some copy and a preset." Using it for
routine work means every content edit costs you a freeze window, or costs you
orders. **Treat the full push as a rebuild tool you may never use again.**

## The model that replaces it

> **Git is the source of truth for code. Production is the source of truth
> for content, orders and money. Staging is a disposable test bed that
> refreshes *downward* from production.**

Nothing is ever promoted staging→production wholesale again. Code flows
through git to both sites independently. Content is authored where it lives.
Staging gets periodically overwritten *from* production — the safe direction,
because staging holds nothing precious.

---

## The four lanes

### Lane A — Code (plugin PHP, calculator HTML)

**Source of truth: git.** This lane already works and needs no change.

1. Edit source in the repo, commit on the working branch.
2. Calculators only: `node tools-compile-calcs.mjs`, cherry-pick the compiled
   output to `pps-pricing-config` (Pages preview updates in ~60s).
3. Deploy to **staging**, pinned to a commit SHA:
   `pps_plugin_download_url` → `https://raw.githubusercontent.com/…/<SHA>/<file>`
   - PHP → `pps-calculators/<file>`
   - Calculators → `pps-calculators/_pending_html/<file>`, then `mcp_ping`
     to fire the deploy hook.
4. Verify on staging (clear WP Rocket first — this is the step most often
   skipped, and it makes a good deploy look broken).
5. Deploy the **same SHA** to production. Verify. Clear cache.

**Rollback:** same call, older SHA. Nothing else to undo.

**Never hand-edit a file on a server.** See CLAUDE.md, "Server-side patches
must come home the same session" — that rule exists because it has already
cost this project a set of security fixes.

### Lane B — Content (pages, posts, term descriptions, product copy)

**Source of truth: production.** Author it there.

Content is reversible, has no build step, and cannot break the cart. The cost
of an error is "fix the typo"; the cost of routing it through staging is a
destructive push. So: **edit production directly.**

The exception is content that depends on new code — see *Sequencing* below.

Term descriptions deserve a specific note: they are the category-page layout
(masthead, wizard, prose, `[pps_cat_attributes]`), they are un-versioned, and
they exist **only** in the database. Before editing one, read the live value
(`pps_woo_get_category`). A repo checkout tells you nothing about what a
category page currently renders.

### Lane C — DB-resident config (presets, tooltips, FAQs, calc config)

These are the awkward ones: they behave like code (structured, breakable,
worth reviewing) but live like content (in `wp_options`, un-versioned, edited
through admin forms).

Inventory: `pps_presets`, `pps_tooltips`, `pps_faqs`, `pps_calc_config`,
`pps_addons_visibility`, and the registry.

**Two valid routes. Pick per change:**

**B-style (default) — author on production.** Presets, tooltips and FAQs are
*additive*: a bad preset is one bad URL you delete; a bad tooltip is one wrong
sentence. They touch no orders and no money. Just make them on production and
check the result.

**Promote-by-option — for anything you want to rehearse first.** You can copy
a single option between sites without any destructive push:

```
staging:    wp_get_option('pps_presets', raw)
production: wp_update_option('pps_presets', <that value>)
```

That is a surgical, one-key DB sync. It is how you build a preset set on
staging, look at it, then move it — with no freeze window and no risk to
orders.

> ⚠ **Never copy `pps_calc_config` wholesale between sites.** It carries live
> credentials (Shippo token, Drive, recipient emails). Overwriting production's
> copy with staging's can swap live keys for stale ones. Pricing changes go
> **direct to production** through the Central Config admin, which is
> reviewable anyway. This is the one option that must never be bulk-promoted.

**Because these are un-versioned, record what you changed.** A DB config
change that exists only in `wp_options` is invisible to every future session
reading the repo. Note it in the relevant doc in the same session — the same
discipline as "server-side patches come home the same session."

### Lane D — Plugin & core updates (WooCommerce, Astra, Rank Math, WP)

**This is what staging is actually for.**

1. Update on **staging** first.
2. Exercise the risky paths: add to cart, checkout to the payment step,
   a calculator page, a category page, the Drive artwork upload, the
   imposition tool.
3. If clean, apply the same update on **production**.

Each site updates independently — plugin updates are files on disk, not
database rows, so there is nothing to push. Note the standing rule in
`docs/PPS_3.1_WC11_PLAN.md`: WooCommerce 11 lands on both sites together, as
its own release, not folded into other work.

---

## Sequencing when a change spans lanes

Most real work touches two lanes at once. The featured-cards change was
exactly this: a new PHP file (Lane A) plus a homepage edit swapping hand-written
markup for `[pps_featured_cards]` (Lane B).

**Rule: code before the content that depends on it. Content before the code
it depends on is removed.**

- Adding a shortcode → deploy the PHP first. An unregistered shortcode renders
  as literal `[pps_featured_cards]` text on your homepage.
- Removing a shortcode → edit the content first, then remove the code.

Within one lane-spanning change, the order is: **staging code → staging
content → verify → production code → production content → verify.** Never
production content before production code.

---

## "Can we push only the plugin files, not the database?"

Reasonable instinct, and the half of it about your edits is correct: a
files-only push does **not** touch `wp_options`, posts, terms or orders, so
your content and config edits on production are safe from it.

But it is still the wrong tool here, for two reasons.

### 1. You already have something strictly better

`pps_plugin_download_url` pulls **one named file, from one pinned commit,
onto one named site**, and touches nothing else. That is more surgical than
any push Cloudways offers: file-level rather than tree-level, reviewable
(the bytes are pinned to a reviewable SHA), and the rollback is the same call
with an older SHA.

A files push is the blunt version of a thing you can already do precisely.
For PPS's own plugins, there is no scenario where the push is the better
choice.

### 2. The landmine: `wp-content/uploads/` is production-critical data

A files push copies the file tree. On this site that tree contains, under
uploads:

| Path | What it holds |
|---|---|
| `uploads/pps-artwork/` | **every customer's uploaded artwork** |
| `uploads/pps-calculators/` | the deployed calculator HTML for that site |
| `uploads/pps-calculators/js/` | compiled app bundles, hashed per deploy |
| `uploads/…` (rest) | the WordPress media library |

Staging's copy of those is stale by definition. A files push that includes
uploads would overwrite production's artwork directory with staging's —
**destroying the artwork for every order placed since the last refresh.**
That is the same class of damage as the database push, just to a different
asset. It would surface as orders whose Drive/press files are simply gone.

If Cloudways' push lets you **scope to `wp-content/plugins/` only**, the
hazard goes away — but verify that in the panel before relying on it, and
treat "files push" as unsafe until you have confirmed the scope. Do not
assume it is limited to plugins because that is what you intended.

### Third-party plugins: don't file-push those either

WooCommerce, Astra, Rank Math and friends should be updated with **each
site's own updater**, not by copying files between sites. Plugin updates run
activation hooks and database migrations; copying the new files alone leaves
new code running against an old schema. WooCommerce in particular runs a
database update step after a major version bump — let it run, per site.

So the answer to "test on staging, then move it to production" for a plugin
update is: **update on staging, exercise it, then run the same update on
production through wp-admin.** Same version, same migrations, no file sync.

### Summary

| You want to move | Use | Not |
|---|---|---|
| A PPS plugin file / calculator | `pps_plugin_download_url` @ pinned SHA | Cloudways files push |
| A third-party plugin update | Each site's own wp-admin updater | Any file copy |
| A preset / tooltip / FAQ set | `wp_get_option` → `wp_update_option`, one key | Any push |
| Content | Author on production | Any push |

Nothing in that table needs a Cloudways push, files-only or otherwise.

## Keeping staging useful

Staging drifts. Once it diverges from production it stops predicting anything,
and a "clean on staging" result stops meaning much.

**Refresh staging *from* production** (Cloudways "pull from live", the safe
direction) roughly monthly, and always before a risky piece of work —
a WooCommerce major update, a theme change, anything touching checkout.
Staging holds nothing you need to keep, so this costs nothing.

After each refresh, staging is behind on code again. Re-deploy the current
pinned SHA to it (Lane A step 3) so it matches the repo.

---

## What each environment is *for*, in one line each

| Environment | Role | Never |
|---|---|---|
| **Git** | Source of truth for all code | Never deploy an unpinned/unreviewed file |
| **Staging** | Test bed for code and updates; disposable | Never the source of a promotion to production |
| **Production** | Source of truth for content, config, orders, money | Never hand-edit plugin files here |

---

## The one case where you *would* full-push again

A deliberate rebuild — you have made so many structural changes on staging
that re-authoring them on production is worse than a freeze window. If that
day comes, it is `docs/GO_LIVE_RUNBOOK.md` again in full: freeze, backup both,
pull live order tables into staging, realign auto-increment, push, verify,
unfreeze. Plus `docs/POST_PUSH_PATCHWORK_BRIEF.md` afterwards, because
Cloudways' search-replace rewrites URLs but not email addresses and will
re-break every mailbox.

If you find yourself reaching for that more than about once a year, the real
problem is that something belongs in Lane A (git) and isn't there yet.

---

## Worked example — the current queue, mapped

| Change | Lane | Route |
|---|---|---|
| Grand Total, fine print, coupon modal, ErrorBoundary, gallery fix, reorder fix | A | git `35d97c6`/`e5b4c5f` → staging (done) → production |
| Presets admin patch `ff542a1` | A | fold into the next deploy, don't ship alone |
| Homepage `[pps_featured_cards]` swap | B, depends on A | staging done; production **after** its PHP lands |
| The 10 SEO presets | C | build on production once `ff542a1` is live — or build on staging and promote `pps_presets` by option |
| Seven missing FAQ sets | C | `pps_faqs` — author on production, additive |
| WooCommerce 11 | D | staging first, both sites together, own release |

Note what is **not** in that table: any full push. Every item above reaches
production without one.
