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
# Every file that can change one of the three pictures. backports.php and
# backport-install.php render the patch-status panel and its install button,
# which is the largest admin surface added since this list was written — and
# the guard was blind to all of it.
# Every file that renders admin UI these pictures could depict. A file missing here
# is a screen that can change without anyone being asked whether the screenshots
# still match — which is how they went stale before (#144, and again with
# updates-screen.php, added in #168 and unwatched until #170).
UI="includes/settings-page.php includes/site-health.php includes/strings.php includes/admin-ux.php includes/backports.php includes/backport-install.php includes/updates-screen.php"

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

STAMP=".wordpress-org/.screenshots-reviewed"

# The commit the screenshots were last *reviewed* at, not the commit that last
# changed the files. Those differ whenever a UI change does not alter the
# picture — ARIA attributes, a new screen that is not one of the three — and
# comparing against the file's own commit made the check unsatisfiable in
# exactly that case: retaking produced byte-identical images, so there was
# nothing to commit and the guard failed forever.
#
# Recording a reviewed-at commit says what is actually being asserted: somebody
# looked since the screens changed.
#
# Record it from main, after the merge. This repository squash-merges, so a SHA
# taken on a feature branch never exists once that branch lands — which is how
# the stamp came to name a commit git could not resolve, and why the fallback
# below has to be a real fallback rather than a pass.
if [ -f "$STAMP" ]; then
	CAPTURED="$( tr -d '[:space:]' < "$STAMP" )"
else
	CAPTURED="$( git log --format='%H' -1 -- "$SHOT" )"
fi

if [ -z "$CAPTURED" ]; then
	echo "verify-screenshots: $SHOT has never been committed." >&2
	exit 1
fi

# A stamp git cannot resolve makes the range query below return nothing, which
# reads exactly like "no changes since" and passes. Same shape as the shallow
# clone above: when the check cannot be performed it must say so, not agree.
if ! git cat-file -e "${CAPTURED}^{commit}" 2>/dev/null; then
	echo "verify-screenshots: '${CAPTURED}' is not a commit in this repository." >&2
	echo "Fix ${STAMP}, or delete it to fall back to the screenshots' own commit." >&2
	exit 1
fi

# shellcheck disable=SC2086
STALE="$( git log --oneline "${CAPTURED}..HEAD" -- $UI )"

if [ -n "$STALE" ]; then
	echo "The screenshots were captured at ${CAPTURED:0:8}, and the screens have changed since:"
	echo "$STALE" | sed 's/^/  /'
	echo
	echo "Retake them:  node bin/screenshots.mjs --url http://localhost:8881 --wp @keel"
	echo
	echo "If the pictures are unchanged — an ARIA-only change, or a screen these three"
	echo "do not show — confirm you looked, and record it:"
	echo "  git rev-parse HEAD > $STAMP"
	echo
	echo "Run that AFTER the commit carrying the new pictures has landed. HEAD is"
	echo "still the branch point while the change is uncommitted, so stamping early"
	echo "records a commit older than the images and this check fails again."
	exit 1
fi

echo "screenshots: current (nothing has touched the admin screens since ${CAPTURED:0:8})"
