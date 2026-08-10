#!/usr/bin/env bash
#
# Build the distributable plugin zip.
#
# Extracted because two workflows need it and had a copy each. The copies were
# identical when written, which is the only time copies ever are: release.yml
# builds the tagged zip, and ci.yml builds the rolling one the Playground demo
# installs, so a change to what ships would have had to be made twice or the
# demo would quietly serve a differently-built plugin from the release.
#
# The file list comes from .distignore rather than from an allowlist here, so
# there is one answer to "what ships" and it lives beside the plugin.
#
# Usage: bash bin/build-zip.sh [output-dir]   (default: build)
set -euo pipefail

OUT="${1:-build}"
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"

cd "$ROOT"
rm -rf "$OUT"
mkdir -p "$OUT/keel"

# Copy the whole tree, then drop everything .distignore excludes, so the zip
# stays in sync with a single source of truth.
rsync -a --exclude='.git' --exclude="$OUT" ./ "$OUT/keel/"

while IFS= read -r pattern; do
	pattern="${pattern%%$'\r'}"
	[ -z "$pattern" ] && continue
	case "$pattern" in \#*) continue ;; esac
	rm -rf "$OUT/keel/${pattern#/}"
done < .distignore

( cd "$OUT" && zip -rq keel.zip keel )

echo "== zip contents =="
unzip -l "$OUT/keel.zip"
