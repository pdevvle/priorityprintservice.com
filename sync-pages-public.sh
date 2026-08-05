#!/usr/bin/env bash
# Sync the Pages publish branch from the working branch.
#
# `pages-public` exists so GitHub Pages serves a whitelist instead of the whole
# project (Pages serves every file in its publish branch, including .php as plain
# text). That split is only safe if the branch is also kept CURRENT — it silently
# went stale within hours of being created, and a stale publish branch serves the
# wrong prices, which is worse than the leak it was built to close.
#
# Run after any calculator change. Verifies rather than assumes: it fails if the
# whitelist would publish anything it shouldn't, and prints what changed.
set -euo pipefail

SRC="${1:-pps-pricing-config}"
DST="pages-public"
WHITELIST=(calc-*.html pps-theme/preview.html .nojekyll)

git fetch -q origin "$SRC" "$DST"
START="$(git rev-parse --abbrev-ref HEAD)"
cleanup() { git checkout -q "$START" 2>/dev/null || true; }
trap cleanup EXIT

git checkout -q "$DST"
git reset -q --hard "origin/$DST"

for f in $(git ls-tree -r --name-only "origin/$SRC" | grep -E '^calc-.*\.html$') pps-theme/preview.html .nojekyll; do
  git checkout "origin/$SRC" -- "$f"
done

# Refuse to publish anything outside the whitelist — the whole point of the branch.
BAD="$(git ls-files | grep -vE '^(calc-.*\.html|pps-theme/preview\.html|\.nojekyll|README\.md)$' || true)"
if [ -n "$BAD" ]; then
  echo "REFUSING TO SYNC — non-whitelisted files on $DST:" >&2
  echo "$BAD" >&2
  exit 1
fi

if git diff --cached --quiet && git diff --quiet; then
  echo "$DST already matches $SRC — nothing to do."
  exit 0
fi

# Stage the whitelist and nothing else. This used to be `git add -A`, which swept in
# whatever untracked files happened to sit in the shared worktree — plugin zips from a
# packaging script ended up published by Pages. add -A on a publish branch is a leak
# waiting for a stray file; naming the paths cannot be.
git add -- "${WHITELIST[@]}"

echo "Publishing:"
git diff --cached --stat
git commit -q -m "Sync calculators from $SRC"
git push -q origin "$DST"
echo "Pushed $DST."
