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
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd -P )"

cd "$ROOT"

# Resolve the output directory to a real absolute path before anything is
# deleted or excluded. Both of the steps below were wrong for a path that was
# not a plain relative name, and both failed quietly.
if [ -d "$OUT" ]; then
	OUT_ABS="$( cd "$OUT" && pwd -P )"
else
	OUT_PARENT="$( dirname "$OUT" )"
	mkdir -p "$OUT_PARENT"
	OUT_ABS="$( cd "$OUT_PARENT" && pwd -P )/$( basename "$OUT" )"
fi

# The next line is `rm -rf` on this path. `bash bin/build-zip.sh .` deleted the
# checkout it was run in — the argument is positional, so a wrapper resolving a
# path wrongly is all it takes. Refuse the source tree, any ancestor of it, and
# the root of the filesystem.
case "$OUT_ABS" in
	/ ) echo "build-zip: refusing to build into /" >&2 ; exit 1 ;;
esac
if [ "$OUT_ABS" = "$ROOT" ] || [ "${ROOT#"$OUT_ABS"/}" != "$ROOT" ]; then
	echo "build-zip: refusing to build into '$OUT_ABS' — it contains the source tree, and the next step deletes it." >&2
	exit 1
fi

# Everything below works in the resolved path, so the rm, the zip and the
# .distignore sweep all act on the same directory the exclude above names.
OUT="$OUT_ABS"

rm -rf "$OUT"
mkdir -p "$OUT/keel-defaults"

# Copy the whole tree, then drop everything .distignore excludes, so the zip
# stays in sync with a single source of truth.
#
# The output directory has to be excluded by its path RELATIVE TO THE TREE, with
# a leading slash to anchor it. `--exclude="$OUT"` matched nothing whenever $OUT
# was absolute, which is the natural way to build somewhere outside the repo —
# so the output directory copied itself into the package, and a leftover one
# from an earlier run went along with it. The zip was seven times its real size
# with a previous build nested inside, and nothing said so.
EXCLUDE_OUT=()
if [ "${OUT_ABS#"$ROOT"/}" != "$OUT_ABS" ]; then
	EXCLUDE_OUT=( --exclude="/${OUT_ABS#"$ROOT"/}" )
fi

# `${a[@]+"${a[@]}"}` rather than `"${a[@]}"`: under `set -u`, bash 3.2 — still
# what /bin/bash is on macOS — treats an empty array as unset and aborts. CI runs
# bash 5, where the plain form is fine, so this breaks only on the machine the
# maintainer builds on.
rsync -a --exclude='.git' ${EXCLUDE_OUT[@]+"${EXCLUDE_OUT[@]}"} ./ "$OUT/keel-defaults/"

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
