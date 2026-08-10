#!/usr/bin/env bash
#
# Are the listing screenshots older than the screens they depict?
#
# The three PNGs in .wordpress-org/ are the wordpress.org listing images and the
# ones README.md shows. They went stale once already: twenty-one commits touched
# the settings screen and the Site Health surface between capture and the day
# somebody looked, and nothing said so.
#
# Deliberately NOT part of `composer test`. It reads git history, and CI checks
# out at depth 1 — the range query would find nothing and pass vacuously, which
# is worse than not having the check. This is a local maintenance command; run it
# before a release or after touching the admin screens.
#
#   composer verify:screenshots
set -u

cd "$( dirname "${BASH_SOURCE[0]}" )/.."

SHOT=".wordpress-org/screenshot-1.png"
UI="includes/settings-page.php includes/site-health.php includes/strings.php includes/admin-ux.php"

if [ ! -f "$SHOT" ]; then
	echo "verify-screenshots: $SHOT is missing." >&2
	exit 1
fi

if ! git rev-parse --git-dir >/dev/null 2>&1; then
	echo "verify-screenshots: not a git checkout, cannot compare." >&2
	exit 1
fi

# A shallow clone cannot answer this, and must say so rather than report clean.
if [ "$( git rev-parse --is-shallow-repository 2>/dev/null )" = "true" ]; then
	echo "verify-screenshots: shallow clone — history is not deep enough to compare. Run 'git fetch --unshallow'." >&2
	exit 1
fi

CAPTURED="$( git log --format='%H' -1 -- "$SHOT" )"

if [ -z "$CAPTURED" ]; then
	echo "verify-screenshots: $SHOT has never been committed." >&2
	exit 1
fi

# shellcheck disable=SC2086
STALE="$( git log --oneline "${CAPTURED}..HEAD" -- $UI )"

if [ -n "$STALE" ]; then
	echo "The screenshots were captured at ${CAPTURED:0:8}, and the screens have changed since:"
	echo "$STALE" | sed 's/^/  /'
	echo
	echo "Retake them:  node bin/screenshots.mjs --url http://localhost:8881 --wp @keel"
	exit 1
fi

echo "screenshots: current (nothing has touched the admin screens since ${CAPTURED:0:8})"
