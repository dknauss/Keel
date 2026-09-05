#!/usr/bin/env bash
#
# Do the listing screenshots still show the screens this plugin renders?
#
# The three PNGs in .wordpress-org/ are the wordpress.org listing images and the
# ones README.md shows. They went stale once already: twenty-one commits touched
# the settings screen and the Site Health surface between capture and the day
# somebody looked, and nothing said so.
#
# What is recorded is a *review*: a person looked at the pictures, given these
# screens, and was satisfied. That is deliberately not "when did the pictures last
# change" — a UI change that does not move the images produces byte-identical PNGs,
# so there is nothing to commit, and a check keyed on the pictures' own commit
# becomes unsatisfiable in exactly the case it most needs to handle.
#
# The review is recorded as a hash of the files rather than a commit id, and that
# distinction is the whole history of this script. A SHA is a fact about history,
# and this repository squash-merges: a SHA recorded on a branch is replaced by the
# squash and pruned with the branch, so it survived for whoever merged and nobody
# else. That produced a check reporting "current" to the one person guaranteed not
# to need telling, then, once corrected, a hard failure after every screenshot
# change that only a follow-up commit could clear. A hash of the content is not a
# fact about history, so history cannot invalidate it — and it can be recorded on
# the branch, in the same commit as the pictures.
#
# It also means a shallow clone can answer the question, so this is now safe in CI.
#
#   composer verify:screenshots            # check
#   composer verify:screenshots -- --record  # record, having looked
set -u

cd "$( dirname "${BASH_SOURCE[0]}" )/.."

STAMP=".wordpress-org/.screenshots-reviewed"
SHOT=".wordpress-org/screenshot-1.png"

# Every file that renders admin UI these pictures could depict. A file missing here
# is a screen that can change without anyone being asked whether the screenshots
# still match — which is how they went stale before (#144, and again with
# updates-screen.php, added in #168 and unwatched until #170).
UI="includes/settings-page.php includes/site-health.php includes/strings.php includes/admin-ux.php includes/backports.php includes/backport-install.php includes/updates-screen.php"

RECORD=0

for arg in "$@"; do
	case "$arg" in
		--record) RECORD=1 ;;
		*) echo "verify-screenshots: unknown argument '$arg'." >&2; exit 2 ;;
	esac
done

if [ ! -f "$SHOT" ]; then
	echo "verify-screenshots: $SHOT is missing." >&2
	exit 1
fi

# macOS ships shasum, most Linux images ship sha256sum, and CI is Linux.
if command -v shasum >/dev/null 2>&1; then
	sha() { shasum -a 256 | cut -d' ' -f1; }
elif command -v sha256sum >/dev/null 2>&1; then
	sha() { sha256sum | cut -d' ' -f1; }
else
	echo "verify-screenshots: no sha256 tool (shasum or sha256sum) available." >&2
	exit 1
fi

# Contents, with an explicit marker for a file that is not there — so deleting a
# watched source registers as a change rather than silently shrinking what is
# covered. The paths go in too, which costs nothing and makes the stream legible if
# it is ever dumped, but it is the marker and the fixed ordering that do the work:
# with an ordered list, a deletion or a swap already moves the digest without them.
digest_of() {
	for path in $1; do
		printf '%s\n' "$path"
		if [ -f "$path" ]; then
			cat "$path"
		else
			printf '(absent)\n'
		fi
	done | sha
}

UI_NOW="$( digest_of "$UI" )"
PICTURES_NOW="$( digest_of "$( ls .wordpress-org/screenshot-*.png 2>/dev/null | sort )" )"

write_stamp() {
	cat > "$STAMP" <<EOF
# Written by bin/verify-screenshots.sh --record. Not a commit id: see that file.
# ui      — the admin sources these pictures depict
# pictures — the pictures themselves
ui=${UI_NOW}
pictures=${PICTURES_NOW}
EOF
}

if [ "$RECORD" -eq 1 ]; then
	write_stamp
	echo "screenshots: recorded as reviewed."
	echo "  screens  ${UI_NOW:0:12}"
	echo "  pictures ${PICTURES_NOW:0:12}"
	exit 0
fi

if [ ! -f "$STAMP" ]; then
	echo "verify-screenshots: no review on record ($STAMP is missing)." >&2
	echo "Look at the pictures, then: composer verify:screenshots -- --record" >&2
	exit 1
fi

UI_WAS="$( sed -n 's/^ui=//p' "$STAMP" | tr -d '[:space:]' )"
PICTURES_WAS="$( sed -n 's/^pictures=//p' "$STAMP" | tr -d '[:space:]' )"

# Every checkout that predates this change holds a bare commit id. Comparing it to a
# digest would fail with a true but useless message about the pictures having moved,
# so it is named for what it is and cleared by one re-record.
if [ -z "$UI_WAS" ] || [ -z "$PICTURES_WAS" ]; then
	LEGACY="$( tr -d '[:space:]' < "$STAMP" )"

	if printf '%s' "$LEGACY" | grep -Eq '^[0-9a-f]{7,40}$'; then
		echo "verify-screenshots: $STAMP holds a commit id (${LEGACY:0:8}), which this check no longer uses." >&2
		echo "A commit id does not survive a squash merge; a hash of the files does." >&2
	else
		echo "verify-screenshots: $STAMP is not in the expected format." >&2
	fi

	echo "Look at the pictures once, then: composer verify:screenshots -- --record" >&2
	exit 1
fi

STALE=0

if [ "$UI_WAS" != "$UI_NOW" ]; then
	echo "The screens have changed since the screenshots were reviewed."
	STALE=1
fi

if [ "$PICTURES_WAS" != "$PICTURES_NOW" ]; then
	echo "The pictures have changed since they were reviewed."
	STALE=1
fi

if [ "$STALE" -eq 1 ]; then
	echo
	echo "Retake them if they no longer match:"
	echo "  node bin/screenshots.mjs --url http://localhost:8881 --wp @keel"
	echo
	echo "If they still match — an ARIA-only change, or a screen these three do not"
	echo "show — that is a review, and recording it is the point:"
	echo "  composer verify:screenshots -- --record"
	echo
	echo "Record in the same commit as any retaken pictures. Nothing here depends on"
	echo "which commit you are on, so a squash merge cannot invalidate it."
	exit 1
fi

echo "screenshots: current (reviewed against screens ${UI_NOW:0:12})"
