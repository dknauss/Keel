#!/usr/bin/env bash
#
# Assert that a plugin does not publish author logins.
#
# probe-teardown.sh *records* what a site serves. That is the right mode for
# measuring the competitive field: you are describing plugins you do not
# control, and a low score is a finding rather than a failure.
#
# For our own three it is the wrong mode. "oEmbed returns author_name" is not an
# observation to file, it is a regression — and filing it as an observation is
# exactly what happened. A live author-identity leak sat in two of the three
# sibling plugins while a document built to measure teardown correctness
# recorded nothing, because no probe looked and nothing would have failed if one
# had.
#
# This wraps the same probes in assertions. Same measurements, different
# contract: a leak exits non-zero.
#
# Usage:
#   PROBE_URL=http://127.0.0.1:9314 PROBE_PATH=/path/to/wp \
#     bash tests/integration/assert-privacy.sh keel
#
#   PROBE_CONFIG_DIR=/path/to/pixel-experience/tests/probe-configs \
#     PROBE_URL=… PROBE_PATH=… bash tests/integration/assert-privacy.sh pixel-managed-platform
#
# A second argument selects the posture being asserted:
#
#   hidden    (default) author archives are off. Every route that publishes the
#             login must be silent — oEmbed, the users sitemap, and feeds.
#   rest-gate archives are deliberately visible, so the sitemap and feeds
#             publish the author on purpose and asserting otherwise would be
#             wrong. What still has to hold is narrower and easy to miss: oEmbed
#             comes through the `oembed/1.0` carve-out, so it must not hand
#             author identity to the anonymous caller who was just refused
#             /wp/v2/users. Two independent reasons to strip the same fields.
#
# The site needs a published post with ID 1 and an author whose login is `admin`,
# which is what probe-teardown.sh already assumes.

set -u

URL="${PROBE_URL:-}"
WP="${PROBE_PATH:-}"
SLUG="${1:-}"
POSTURE="${2:-hidden}"
HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

if [ -z "$URL" ] || [ -z "$WP" ] || [ -z "$SLUG" ]; then
	echo "assert-privacy: usage: PROBE_URL=… PROBE_PATH=… $0 <plugin-slug>" >&2
	exit 2
fi

OUTPUT="$( PROBE_URL="$URL" PROBE_PATH="$WP" bash "$HERE/probe-plugin.sh" "$SLUG" "$SLUG" 2>&1 )" || {
	echo "assert-privacy: probing $SLUG failed" >&2
	echo "$OUTPUT" >&2
	exit 1
}

value_of() {
	echo "$OUTPUT" | awk -v k="$1" '$1 == k { print $2; exit }'
}

FAILED=0

# expect <key> <wanted> <why>
expect() {
	local key="$1" want="$2" why="$3" got
	got="$( value_of "$key" )"

	if [ -z "$got" ]; then
		echo "  ?  ${key}: no such probe row — the probe group may have been renamed" >&2
		FAILED=1
		return
	fi

	if [ "$got" != "$want" ]; then
		printf '  FAIL %-26s expected %-4s got %-4s  %s\n' "$key" "$want" "$got" "$why" >&2
		FAILED=1
		return
	fi

	printf '  ok   %-26s %s\n' "$key" "$got"
}

echo "assert-privacy: $SLUG (posture: $POSTURE)"

ARCHIVE="$( value_of privacy.author_archive )"

case "$POSTURE" in
	hidden )
		# 301 is the redirect; 404 would do as well. Only a 200 fails — a
		# reachable archive means the setting is off and every assertion below
		# would be measuring nothing.
		if [ "$ARCHIVE" = "200" ]; then
			echo "  FAIL privacy.author_archive     author archives are still reachable; configure the plugin before asserting" >&2
			exit 1
		fi
		printf '  ok   %-26s %s\n' "privacy.author_archive" "$ARCHIVE"

		# Every route that publishes the login has to be silent.
		expect privacy.oembed_author    0 "oEmbed must not return author_name"
		expect privacy.oembed_authorurl 0 "author_url carries the account nicename"
		expect privacy.sitemap_listed   0 "the users sitemap must not be advertised in the index"
		expect privacy.sitemap_names    0 "the users sitemap must not enumerate author archives"
		expect privacy.feed_login       0 "feeds must not publish the login in dc:creator"
		;;

	rest-gate )
		# The site publishes author archives on purpose, so the sitemap and
		# feeds naming the author is correct and is not asserted. Asserting it
		# would be demanding the plugin override a choice the operator made.
		if [ "$ARCHIVE" != "200" ]; then
			echo "  FAIL privacy.author_archive     expected a visible archive for this posture; the config did not apply" >&2
			exit 1
		fi
		printf '  ok   %-26s %s (visible, as configured)\n' "privacy.author_archive" "$ARCHIVE"

		# oEmbed is the one obligation the gate itself carries.
		expect privacy.oembed_author    0 "oEmbed reaches anonymous callers through the carve-out; it must not name the author the gate just refused"
		expect privacy.oembed_authorurl 0 "author_url carries the account nicename"
		;;

	* )
		echo "assert-privacy: unknown posture '$POSTURE' (expected 'hidden' or 'rest-gate')" >&2
		exit 2
		;;
esac

# And the strip must not have broken the thing it was protecting — an oEmbed
# response with no title is not private, it is broken.
#
# Only checked when the route is actually open. A plugin that closes anonymous
# REST closes oEmbed with it, and for one without a carve-out that is a
# deliberate documented trade (competitive-teardown-matrix.md, finding 7), not a
# regression. Asserting it unconditionally would fail those plugins forever for
# a choice they made on purpose — and a check that cries wolf gets ignored,
# which is the whole failure mode this suite exists to avoid.
OEMBED_HTTP="$( value_of rest.oembed )"
if [ "$OEMBED_HTTP" = "200" ]; then
	expect privacy.oembed_usable 1 "the embed must still render for other sites"
else
	printf '  --   %-26s route closed (HTTP %s) — usability not applicable\n' "privacy.oembed_usable" "$OEMBED_HTTP"
fi

if [ "$FAILED" -ne 0 ]; then
	echo "assert-privacy: $SLUG publishes author identity" >&2
	exit 1
fi

echo "assert-privacy: $SLUG OK"
