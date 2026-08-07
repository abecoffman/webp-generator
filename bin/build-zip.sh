#!/usr/bin/env bash
set -euo pipefail

# Builds a clean, WordPress.org-ready plugin zip from the current working
# tree: honors .distignore the same way `wp plugin check` was validated
# against a clean dist export earlier in this project's history (see
# project memory / commit history for that), so this is exactly what a
# real end user -- or the WordPress.org review team -- would get, not a
# dump of this dev repo.
#
# Usage: bin/build-zip.sh [output-dir]
#   VERSION env var overrides the version used in the zip filename
#   (CI sets this from the git tag); otherwise it's read from
#   readme.txt's "Stable tag" line.
#
# Writes <output-dir>/webp-generator-<version>.zip (default output-dir:
# ./dist, gitignored).

SLUG="webp-generator"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$ROOT_DIR/dist}"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

VERSION="${VERSION:-$(grep -m1 '^Stable tag:' "$ROOT_DIR/readme.txt" | sed -E 's/^Stable tag:[[:space:]]*//')}"
if [ -z "$VERSION" ]; then
	echo "Could not determine version (no VERSION env var, no 'Stable tag:' in readme.txt)" >&2
	exit 1
fi

mkdir -p "$OUT_DIR" "$WORK_DIR/$SLUG"
rsync -a --exclude-from="$ROOT_DIR/.distignore" "$ROOT_DIR/" "$WORK_DIR/$SLUG/"

ZIP_PATH="$OUT_DIR/${SLUG}-${VERSION}.zip"
rm -f "$ZIP_PATH"
( cd "$WORK_DIR" && zip -rq "$ZIP_PATH" "$SLUG" )

echo "Built: $ZIP_PATH"
