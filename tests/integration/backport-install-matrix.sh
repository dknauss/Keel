#!/usr/bin/env bash
# Exercise the real admin-post installer across one rollback/forward matrix row.

set -euo pipefail

: "${KEEL_SITE:?Set KEEL_SITE to the disposable WordPress path.}"
: "${KEEL_URL:?Set KEEL_URL to the disposable WordPress URL.}"
: "${KEEL_SOURCE:?Set KEEL_SOURCE to the vulnerable source version.}"
: "${KEEL_TARGET:?Set KEEL_TARGET to the patched same-line version.}"
: "${KEEL_FORWARD:?Set KEEL_FORWARD to the final current version.}"

KEEL_LOCALE="${KEEL_LOCALE:-en_US}"
KEEL_MULTISITE="${KEEL_MULTISITE:-false}"
KEEL_PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
read -r -a KEEL_WP_BASE <<<"${KEEL_WP_COMMAND:-wp}"
KEEL_WP=("${KEEL_WP_BASE[@]}" --path="$KEEL_SITE" --allow-root)

fail() {
	echo "backport matrix: $*" >&2
	exit 1
}

assert_version() {
	local expected="$1"
	local actual
	actual="$("${KEEL_WP[@]}" core version)"
	[[ "$actual" == "$expected" ]] || fail "expected WordPress $expected, got $actual"
}

refresh_offers() {
	"${KEEL_WP[@]}" transient delete keel_stable_check >/dev/null 2>&1 || true
	"${KEEL_WP[@]}" transient delete keel_stable_check_failed >/dev/null 2>&1 || true
	"${KEEL_WP[@]}" site transient delete update_core >/dev/null 2>&1 || true
	"${KEEL_WP[@]}" core check-update >/dev/null || true
}

auth_json() {
	"${KEEL_WP[@]}" eval-file "$KEEL_PLUGIN_ROOT/tests/integration/backport-install-auth.php"
}

post_install() {
	local auth="$1"
	local target="$2"
	local cookie_name cookie_value nonce status
	cookie_name="$(jq -r '.cookie_name' <<<"$auth")"
	cookie_value="$(jq -r '.cookie_value' <<<"$auth")"
	nonce="$(jq -r '.nonce' <<<"$auth")"
	status="$(curl --silent --show-error --output /tmp/keel-backport-body \
		--write-out '%{http_code}' \
		--cookie "$cookie_name=$cookie_value" \
		--data-urlencode 'action=keel_defaults_install_backport' \
		--data-urlencode "version=$target" \
		--data-urlencode "_wpnonce=$nonce" \
		"$KEEL_URL/wp-admin/admin-post.php")"
	[[ "$status" == "302" ]] || fail "admin-post returned HTTP $status: $(head -c 500 /tmp/keel-backport-body)"
}

assert_result_code() {
	local expected="$1"
	local result code
	result="$("${KEEL_WP[@]}" transient get keel_backport_install_result_1 --format=json)"
	code="$(jq -r '.code' <<<"$result")"
	[[ "$code" == "$expected" ]] || fail "expected result $expected, got $code -- $result"
	"${KEEL_WP[@]}" transient delete keel_backport_install_result_1 >/dev/null
}

# What the installer said, whether or not it worked. Ordering matters here: the
# version assertion fires first on a failed install and reports only that the
# version did not change, discarding the one value that says why. The first run
# of this matrix failed on fr_FR with exactly that — "expected 6.8.8, got
# 6.8.7", and nothing about the cause.
report_result() {
	local result
	result="$("${KEEL_WP[@]}" transient get keel_backport_install_result_1 --format=json 2>/dev/null || echo '{}')"
	echo "backport matrix: installer result -- $result"
}

assert_healthy() {
	"${KEEL_WP[@]}" core verify-checksums --version="$("${KEEL_WP[@]}" core version)" --locale="$KEEL_LOCALE" >/dev/null
	curl --fail --silent --show-error "$KEEL_URL/" >/dev/null
}


# Render the reporting half. This is the part the matrix never exercised: the
# verdict, the ladder and the actions had no live coverage in any locale, and
# WordPress.org describes the same release differently by locale — 6.8.8 carries
# new_files=false for en_US and true for fr_FR, which changes what relaxed file
# ownership resolves to and therefore what the panel concludes about
# operability. Asserts what must hold everywhere; prints what may differ.
assert_report() {
	local report status tip vstatus ladder actions
	report="$( "${KEEL_WP[@]}" eval-file "$KEEL_PLUGIN_ROOT/tests/integration/backport-report-probe.php" )"

	echo "backport matrix: report -- $( jq -c '{locale,version,version_status,tip,status,selection,operable,policy,relaxed,blockers}' <<<"$report" )"

	vstatus="$( jq -r '.version_status' <<<"$report" )"
	tip="$( jq -r '.tip' <<<"$report" )"
	status="$( jq -r '.status' <<<"$report" )"
	ladder="$( jq -r '.ladder_len' <<<"$report" )"
	actions="$( jq -r '.actions_len' <<<"$report" )"

	[[ "$vstatus" == "insecure" ]] || fail "reporting: expected version_status insecure, got $vstatus"
	[[ "$tip" == "$KEEL_TARGET" ]] || fail "reporting: expected tip $KEEL_TARGET, got $tip"
	[[ "$status" == "critical" ]] || fail "reporting: expected a critical verdict, got $status"
	(( ladder > 200 )) || fail "reporting: the ladder rendered $ladder bytes, which is not a ladder"
	(( actions > 100 )) || fail "reporting: the actions rendered $actions bytes"

	# The panel must name the patch for this line. Naming the newest release as
	# the thing to reach is the original defect this check exists to prevent.
	jq -e --arg t "$KEEL_TARGET" '.description | contains($t)' <<<"$report" >/dev/null \
		|| fail "reporting: the verdict never names $KEEL_TARGET"
	jq -e --arg t "$KEEL_TARGET" '.ladder | contains($t)' <<<"$report" >/dev/null \
		|| fail "reporting: the ladder never names $KEEL_TARGET"

	# An unresolved placeholder or a stringified array is how a broken sprintf
	# reaches a reader as plausible prose.
	jq -e '(.description + .ladder + .actions) | test("Array|%[0-9]+[$]s") | not' <<<"$report" >/dev/null \
		|| fail "reporting: rendered output contains an unresolved placeholder"
}

assert_version "$KEEL_SOURCE"
refresh_offers
assert_report

first_auth="$(auth_json)"
[[ "$(jq -r '.target' <<<"$first_auth")" == "$KEEL_TARGET" ]] || fail "Keel did not derive target $KEEL_TARGET"
post_install "$first_auth" "$KEEL_TARGET"
report_result
assert_version "$KEEL_TARGET"
assert_result_code updated
assert_healthy

# The same signed request cannot name an old target after the site has moved.
post_install "$first_auth" "$KEEL_TARGET"
assert_result_code stale_target

"${KEEL_WP[@]}" core update --version="$KEEL_SOURCE" --force --locale="$KEEL_LOCALE" >/dev/null
assert_version "$KEEL_SOURCE"
refresh_offers
assert_report

second_auth="$(auth_json)"
post_install "$second_auth" "$KEEL_TARGET"
report_result
assert_version "$KEEL_TARGET"
assert_result_code updated
assert_healthy

"${KEEL_WP[@]}" core update --version="$KEEL_FORWARD" --force --locale="$KEEL_LOCALE" >/dev/null
assert_version "$KEEL_FORWARD"
assert_healthy

echo "backport matrix: $KEEL_SOURCE -> $KEEL_TARGET -> rollback -> $KEEL_TARGET -> $KEEL_FORWARD passed ($KEEL_LOCALE, multisite=$KEEL_MULTISITE)"
