# Public assets — GitHub Pages publish surface

This branch exists to be served. It is **not** the working branch.

Everything here is intentionally public: the customer-facing calculator pages and
the theme header preview. Nothing else belongs on it.

## Why this branch exists

GitHub Pages serves the *entire tree* of whatever branch it publishes from, as raw
files, at guessable URLs. When Pages published from `pps-pricing-config` — which is
also the default branch, and carries the whole project — that meant the following
were fetchable by anyone, no repository access required:

- `docs/MASTER_PRICING_LOGIC.md`, the pricing strategy source of truth
- `CLAUDE.md`, describing the architecture and internal conventions
- 19 `.php` files — Pages does not execute PHP, it serves it as plain text
- the SEO analysis, design briefs, test fixtures and tooling docs

Splitting the publish surface from the working branch fixes that class of leak
permanently: a file can only be served if someone deliberately adds it here.

## Adding a file

Don't, unless it is genuinely meant for the public web. Ask what happens if a
competitor reads it in full, because they can.

## Deploying

Cherry-pick calculator changes here from the working branch. This branch is an
orphan — it has no shared history with `pps-pricing-config`, so nothing that was
ever committed there can be recovered from this branch's log either.
