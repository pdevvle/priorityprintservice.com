#!/usr/bin/env bash
# Package a single-file plugin as a zip WordPress will accept.
#
# The owner installs through wp-admin, not SSH, and WordPress's uploader takes a zip
# containing a DIRECTORY whose name matches the plugin — a bare .php at the zip root is
# rejected with "No valid plugins were found", which reads as a broken file rather than
# a packaging mistake. So this builds:
#
#     pps-mcp-server.zip
#       └── pps-mcp-server/
#             └── pps-mcp-server.php
#
# Re-uploading an existing plugin is fine: WordPress notices the folder exists and
# offers "Replace current with uploaded". The plugin stays active across the swap.
#
# Usage:  ./tools-plugin-zip.sh [file.php ...]      (default: every standalone plugin)
#
# Zips are build artifacts and are not committed; dist/ is gitignored.
set -euo pipefail
cd "$(dirname "$0")"

# Standalone single-file plugins. pps-calculators.php and its siblings are deliberately
# absent: they are one multi-file plugin deployed by pinned URL, not by upload.
DEFAULT=(pps-mcp-server.php pps-mcp-diagnostics.php)
FILES=("${@:-}")
[ -z "${FILES[0]:-}" ] && FILES=("${DEFAULT[@]}")

mkdir -p dist
for f in "${FILES[@]}"; do
  [ -f "$f" ] || { echo "skip $f (not found)"; continue; }
  slug="$(basename "$f" .php)"

  # A plugin without a Plugin Name header installs but never appears in the admin list,
  # which is a confusing way to fail.
  grep -q '^ \* Plugin Name:' "$f" || { echo "SKIP $f — no 'Plugin Name:' header"; continue; }
  php -l "$f" >/dev/null || { echo "SKIP $f — does not parse"; continue; }

  rm -rf "dist/$slug" "dist/$slug.zip"
  mkdir -p "dist/$slug"
  cp "$f" "dist/$slug/"
  ( cd dist && zip -q -r "$slug.zip" "$slug" )
  rm -rf "dist/$slug"

  ver="$(sed -n 's/^ \* Version: *//p' "$f" | head -1)"
  printf "%-28s %7s bytes  v%s\n" "dist/$slug.zip" "$(wc -c < "dist/$slug.zip")" "${ver:-?}"
done
