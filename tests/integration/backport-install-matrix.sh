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
	[[ "$code" == "$expected" ]] || fail "expected result $expected, got $code"
	"${KEEL_WP[@]}" transient delete keel_backport_install_result_1 >/dev/null
}

assert_healthy() {
	"${KEEL_WP[@]}" core verify-checksums --version="$("${KEEL_WP[@]}" core version)" --locale="$KEEL_LOCALE" >/dev/null
	curl --fail --silent --show-error "$KEEL_URL/" >/dev/null
}

assert_version "$KEEL_SOURCE"
refresh_offers

first_auth="$(auth_json)"
[[ "$(jq -r '.target' <<<"$first_auth")" == "$KEEL_TARGET" ]] || fail "Keel did not derive target $KEEL_TARGET"
post_install "$first_auth" "$KEEL_TARGET"
assert_version "$KEEL_TARGET"
assert_result_code updated
assert_healthy

# The same signed request cannot name an old target after the site has moved.
post_install "$first_auth" "$KEEL_TARGET"
assert_result_code stale_target

"${KEEL_WP[@]}" core update --version="$KEEL_SOURCE" --force --locale="$KEEL_LOCALE" >/dev/null
assert_version "$KEEL_SOURCE"
refresh_offers

second_auth="$(auth_json)"
post_install "$second_auth" "$KEEL_TARGET"
assert_version "$KEEL_TARGET"
assert_result_code updated
assert_healthy

"${KEEL_WP[@]}" core update --version="$KEEL_FORWARD" --force --locale="$KEEL_LOCALE" >/dev/null
assert_version "$KEEL_FORWARD"
assert_healthy

echo "backport matrix: $KEEL_SOURCE -> $KEEL_TARGET -> rollback -> $KEEL_TARGET -> $KEEL_FORWARD passed ($KEEL_LOCALE, multisite=$KEEL_MULTISITE)"
