#!/usr/bin/env bash
#
# Multisite behaviour, against a real network.
#
# Everything Keel does on multisite has until now been proven only against stubs
# the plugin's own tests define: tests/multisite-seeding.php declares its own
# switch_to_blog(), get_sites() and restore_current_blog(), and
# tests/network-policy.php declares its own get_site_option(). Those tests are
# worth having — they pin the logic — but a stub that is wrong about WordPress
# lets every one of them pass while the plugin does the wrong thing on a real
# network. Nothing in the repository could tell the difference.
#
# This runs the same paths against a live install with a real network, real
# subsites and real site options.
#
#   PROBE_PATH=/path/to/multisite-wp bash tests/integration/verify-network.sh
#
# It creates one throwaway subsite and removes it again. It never writes to a
# site it did not create, and it restores the network policy it found.
set -u

WP="${PROBE_PATH:-}"
FAIL=0
SLUG="keel-verify-$$"

if [ -z "$WP" ]; then
	echo "verify-network: PROBE_PATH is required and has no default." >&2
	exit 2
fi

w() { wp --path="$WP" "$@" 2>/dev/null | grep -v '^Deprecated' | grep -v '^$'; }

ok() {
	if [ "$2" = "$3" ]; then
		printf '  ok    %s\n' "$1"
	else
		printf '  FAIL  %s\n        expected %s, got %s\n' "$1" "$3" "$2"
		FAIL=$(( FAIL + 1 ))
	fi
}

if [ "$( w eval 'echo is_multisite() ? "yes" : "no";' )" != "yes" ]; then
	echo "verify-network: $WP is not a multisite install. Convert one with 'wp core multisite-convert'." >&2
	exit 2
fi

echo "=== multisite behaviour, on a real network ==="

# Keep whatever policy is already set and put it back at the end. This script
# should be runnable against a network somebody is using without silently
# rewriting their governance.
SAVED_POLICY="$( w eval 'echo wp_json_encode( get_site_option( "keel_network_settings", array() ) );' )"

cleanup() {
	w eval "update_site_option( 'keel_network_settings', json_decode( '${SAVED_POLICY}', true ) ?: array() );" >/dev/null
	if [ -n "${NEW_ID:-}" ]; then
		w site delete "$NEW_ID" --yes >/dev/null
	fi
}
trap cleanup EXIT

# --- a site created after activation is seeded -------------------------------
# The path here is wp_initialize_site, which fires only on a real network. The
# unit test drives it by calling the handler directly with a stub site object;
# this proves WordPress actually calls it.
NEW_URL="$( w site create --slug="$SLUG" --porcelain 2>/dev/null | tail -1 )"
NEW_ID="$( w site list --field=blog_id --slug="$SLUG" | tail -1 )"

if [ -z "$NEW_ID" ]; then
	echo "  FAIL  could not create a throwaway subsite to test seeding" >&2
	exit 1
fi

SEEDED="$( w eval "switch_to_blog( $NEW_ID ); \$o = get_option( 'keel_settings' ); restore_current_blog(); echo is_array( \$o ) && ! empty( \$o ) ? 'yes' : 'no';" )"
ok "a subsite created after activation is seeded" "$SEEDED" "yes"

# --- network policy reaches a site that disagrees ----------------------------
# The subsite deliberately stores the opposite of the policy. That is every
# existing subsite on a network where somebody set a value before the Super
# Admin decided anything.
w eval "switch_to_blog( $NEW_ID ); \$s = (array) get_option( 'keel_settings', array() ); \$s['require_strong_passwords'] = 'no'; update_option( 'keel_settings', \$s ); restore_current_blog();" >/dev/null

# Clear policy explicitly before the baseline. The first draft of this script
# read the subsite while whatever policy the network already had was still in
# force, and reported "the subsite keeps its own value" as a failure — a test
# that depended on ambient state to be correct.
w eval "update_site_option( 'keel_network_settings', array() );" >/dev/null

BEFORE="$( w eval "switch_to_blog( $NEW_ID ); echo keel_defaults_get( 'require_strong_passwords' ); restore_current_blog();" )"
ok "without policy, the subsite keeps its own value" "$BEFORE" "no"

w eval "update_site_option( 'keel_network_settings', array( 'require_strong_passwords' => 'yes' ) );" >/dev/null

DURING="$( w eval "switch_to_blog( $NEW_ID ); echo keel_defaults_get( 'require_strong_passwords' ); restore_current_blog();" )"
ok "network policy overrides the subsite" "$DURING" "yes"

LOCKED="$( w eval "switch_to_blog( $NEW_ID ); echo keel_defaults_network_manages( 'require_strong_passwords' ) ? 'yes' : 'no'; restore_current_blog();" )"
ok "the subsite reports the setting as network-managed" "$LOCKED" "yes"

# The site's own stored value must be untouched — this is the whole argument for
# resolving at read rather than writing into subsites.
STORED="$( w eval "switch_to_blog( $NEW_ID ); \$s = (array) get_option( 'keel_settings', array() ); echo \$s['require_strong_passwords']; restore_current_blog();" )"
ok "the subsite's own stored value is untouched by policy" "$STORED" "no"

w eval "update_site_option( 'keel_network_settings', array() );" >/dev/null

AFTER="$( w eval "switch_to_blog( $NEW_ID ); echo keel_defaults_get( 'require_strong_passwords' ); restore_current_blog();" )"
ok "lifting policy returns the subsite to its own value" "$AFTER" "no"

# --- an unmanaged key is never locked ----------------------------------------
w eval "update_site_option( 'keel_network_settings', array( 'require_strong_passwords' => 'yes' ) );" >/dev/null
UNMANAGED="$( w eval "switch_to_blog( $NEW_ID ); echo keel_defaults_network_manages( 'disable_emojis' ) ? 'yes' : 'no'; restore_current_blog();" )"
ok "a key the network does not manage is not reported as managed" "$UNMANAGED" "no"

# --- policy is one value for the network, not per site -----------------------
# Reading it from a different site must give the same answer, or "network" policy
# is really per-site policy with extra steps.
MAIN="$( w eval "switch_to_blog( 1 ); echo keel_defaults_get( 'require_strong_passwords' ); restore_current_blog();" )"
ok "the same policy is read from another site" "$MAIN" "yes"

# --- capability gating -------------------------------------------------------
# A site administrator holds manage_options on their own site. That must not be
# enough to set policy for every site.
SUPER="$( w eval "\$u = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); wp_set_current_user( \$u ? \$u[0]->ID : 1 ); echo keel_defaults_can_manage_network() ? 'yes' : 'no';" )"
ok "a Super Admin can manage network policy" "$SUPER" "yes"

PLAIN="$( w eval "\$id = wp_create_user( 'keel-plain-$$', wp_generate_password( 24 ), 'keel-plain-$$@example.invalid' ); switch_to_blog( $NEW_ID ); \$u = new WP_User( \$id ); \$u->add_role( 'administrator' ); wp_set_current_user( \$id ); echo keel_defaults_can_manage_network() ? 'yes' : 'no'; restore_current_blog(); wpmu_delete_user( \$id );" )"
ok "a site administrator cannot" "$PLAIN" "no"

echo
if [ "$FAIL" -gt 0 ]; then
	echo "verify-network: ${FAIL} failed"
	exit 1
fi

echo "verify-network: OK"
