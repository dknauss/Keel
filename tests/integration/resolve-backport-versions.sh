#!/usr/bin/env bash
#
# Resolve a live backport matrix row's source and target from the release list.
#
# A row names a branch (KEEL_BRANCH, e.g. "6.9"). The target is that branch's
# newest release, and the source is the release before it, which must be one the
# stable-check API calls insecure. Otherwise the row would install over a version
# Keel is right to leave alone, and prove nothing.
#
# Pinning both per row meant every WordPress security release failed the weekly
# run with "expected tip X, got Y". That was the check working, against numbers
# that had gone stale, and it made a real regression indistinguishable from a
# release day.
#
# Writes KEEL_SOURCE and KEEL_TARGET to $GITHUB_ENV for the steps that follow,
# and prints them so the log names what the row tested. Refuses, writing
# nothing, whenever the branch cannot give a meaningful row.
#
# KEEL_STABLE_CHECK_FILE substitutes a saved stable-check map for the live API.
# tests/backport-matrix-versions.php uses it.

set -euo pipefail

branch="${KEEL_BRANCH:?Set KEEL_BRANCH to the x.y branch this row tests.}"
: "${GITHUB_ENV:?GITHUB_ENV must name the file the resolved versions are written to.}"

fail() {
	echo "resolve backport versions: $*" >&2
	exit 1
}

[[ "$branch" =~ ^[0-9]+\.[0-9]+$ ]] || fail "branch '$branch' is not an x.y version"

if [[ -n "${KEEL_STABLE_CHECK_FILE:-}" ]]; then
	map="$( cat "$KEEL_STABLE_CHECK_FILE" )"
else
	map="$( curl --fail --silent --show-error --location --retry 5 --retry-all-errors \
		https://api.wordpress.org/core/stable-check/1.0/ )"
fi

# Releases on the branch are "x.y" and "x.y.N". sort -V orders 6.9.10 after 6.9.9,
# which a string sort would not.
tip="$( jq -r --arg b "$branch" 'keys[] | select(. == $b or startswith($b + "."))' <<<"$map" | sort -V | tail -n 1 )"

[[ -n "$tip" ]] || fail "the release list has no $branch releases"
[[ "$tip" != "$branch" ]] || fail "the $branch branch has no patch release yet, so there is no same-line patch to test"

patch="${tip##*.}"
if (( patch == 1 )); then
	# WordPress names a branch's first release "x.y", never "x.y.0".
	source="$branch"
else
	source="$branch.$(( patch - 1 ))"
fi

status="$( jq -r --arg v "$source" '.[$v] // "missing"' <<<"$map" )"
[[ "$status" == "insecure" ]] || fail "source $source is '$status', not insecure, so a $source -> $tip row would prove nothing"

echo "resolve backport versions: $branch branch: $source (insecure) -> $tip"
{
	echo "KEEL_SOURCE=$source"
	echo "KEEL_TARGET=$tip"
} >>"$GITHUB_ENV"
