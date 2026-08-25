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
# The folder inside the zip is `keel-defaults`, which is the slug wordpress.org
# will assign and therefore the directory the plugin installs into. WordPress
# identifies a plugin by folder/file.php, so this has to be the name it will
# have on a real install rather than a shorter one that only ever existed here.
#
# Usage: bash bin/build-zip.sh [output-dir]   (default: build)
set -euo pipefail

OUT="${1:-build}"
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"

cd "$ROOT"
rm -rf "$OUT"
mkdir -p "$OUT/keel-defaults"

# Copy the whole tree, then drop everything .distignore excludes, so the zip
# stays in sync with a single source of truth.
rsync -a --exclude='.git' --exclude="$OUT" ./ "$OUT/keel-defaults/"

# Patterns may contain wildcards — /languages/*.po has to drop every catalog,
# not just one named file. The prefix stays quoted so a path with a space still
# works; only the pattern itself is left unquoted, which is what lets it glob.
# nullglob so a pattern matching nothing expands to nothing rather than to
# itself, which would have rm chasing a literal '*'.
shopt -s nullglob

while IFS= read -r pattern; do
	pattern="${pattern%%$'\r'}"
	[ -z "$pattern" ] && continue
	case "$pattern" in \#*) continue ;; esac
	matches=( "$OUT/keel-defaults/"${pattern#/} )
	for match in "${matches[@]}"; do
		rm -rf "$match"
	done
done < .distignore

( cd "$OUT" && zip -rq keel.zip keel-defaults )

echo "== zip contents =="
unzip -l "$OUT/keel.zip"
