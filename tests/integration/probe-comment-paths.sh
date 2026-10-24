#!/usr/bin/env bash
#
# Try every way core creates a comment, and count which ones landed.
#
# probe-teardown.sh measures one write path (the comment form) against the
# plugin's own configuration, which for Keel also closes REST and most of
# XML-RPC, so most write paths are refused before a comment guard is reached.
# This asks the narrower question a comment guard has to answer: with every
# other door open, does a comment still get in by any route?
#
#   form      anonymous POST to wp-comments-post.php
#   rest      authenticated POST /wp/v2/comments (anonymous REST comments are
#             off in core, so a signed-in user is the realistic writer)
#   note      authenticated POST /wp/v2/comments with type=note: block-editor
#             Notes, which a comment guard must leave working
#   xmlrpc    wp.newComment with an administrator's credentials
#   pingback  pingback.ping from a real source page (see probe-pingback.sh)
#
# Each row is "<HTTP status or XML-RPC fault> landed=<0|1>", counted with
# plugins skipped so nothing can hide a row that was stored.
#
# Usage:
#   PROBE_URL=http://127.0.0.1:9400 PROBE_PATH=/path/to/wp \
#   PROBE_XMLRPC_USER=admin PROBE_XMLRPC_PASS=… \
#     bash tests/integration/probe-comment-paths.sh "label"
#
# On multisite, point PROBE_URL at the site under test and set PROBE_WP_URL to
# the same URL so WP-CLI addresses that site. Every comment it creates is
# deleted again. The pingback row needs the source page and mu-plugin that
# probe-pingback.sh describes; without them it reports what the site answered.

set -u

URL="${PROBE_URL:-}"
WP="${PROBE_PATH:-}"
LABEL="${1:-unlabelled run}"
SOURCE="${PROBE_PINGBACK_SOURCE:-http://127.0.0.1:9401/}"
XUSER="${PROBE_XMLRPC_USER:-admin}"
XPASS="${PROBE_XMLRPC_PASS:-}"
WPURL=()
[ -n "${PROBE_WP_URL:-}" ] && WPURL=( --url="$PROBE_WP_URL" )

if [ -z "$URL" ] || [ -z "$WP" ]; then
	echo "probe-comment-paths: set PROBE_URL and PROBE_PATH. See tests/integration/README.md." >&2
	exit 2
fi

w() { wp "$@" --path="$WP" "${WPURL[@]}" 2>/dev/null | tr -d '\r'; }

PERMA="$( w eval 'echo get_permalink( 1 );' | tail -1 )"
[ -n "$PERMA" ] || { echo "probe-comment-paths: post 1 has no permalink" >&2; exit 2; }

# Comments carrying this marker are the probe's own; counted and removed with
# plugins skipped. Removed after every path, not only at the end: core's flood
# check reads the last comment from the same address, so a comment the form
# row stored makes the pingback row answer 429 and measure flood control.
MARK="comment-paths-probe-$$"
landed() {
	w eval "global \$wpdb; echo (int) \$wpdb->get_var( \"SELECT COUNT(*) FROM {\$wpdb->comments} WHERE comment_content LIKE '%$1%' OR comment_author_url LIKE '%$1%'\" );" --skip-plugins --skip-themes | tail -1
}
clean() {
	w eval "global \$wpdb; \$wpdb->query( \"DELETE FROM {\$wpdb->comments} WHERE comment_content LIKE '%$MARK%' OR comment_type = 'pingback'\" );" --skip-plugins --skip-themes >/dev/null
}
clean

# --- form ---------------------------------------------------------------------
form_status=$( curl -s -o /dev/null -w '%{http_code}' -X POST \
	--data "comment_post_ID=1&author=PathsProbe&email=paths@example.invalid&comment=$MARK-form" \
	"$URL/wp-comments-post.php" )
form_landed=$( landed "$MARK-form" )
clean

# --- REST, as a signed-in administrator -------------------------------------
AUTH="$( w eval '
$u   = get_users( array( "role" => "administrator", "number" => 1 ) );
$uid = $u ? $u[0]->ID : 1;
if ( is_multisite() && ! is_user_member_of_blog( $uid ) ) { $uid = get_super_admins() ? get_user_by( "login", get_super_admins()[0] )->ID : $uid; }
$exp = time() + 3600;
$tok = WP_Session_Tokens::get_instance( $uid )->create( $exp );
$ck  = wp_generate_auth_cookie( $uid, $exp, "logged_in", $tok );
$_COOKIE[ LOGGED_IN_COOKIE ] = $ck;
wp_set_current_user( $uid );
echo LOGGED_IN_COOKIE . "=" . $ck . "|" . wp_create_nonce( "wp_rest" );
' | grep '|' | tail -1 )"
COOKIE="${AUTH%|*}"
NONCE="${AUTH##*|}"
rest_post() {
	curl -s -o /dev/null -w '%{http_code}' -X POST -H "Cookie: $COOKIE" -H "X-WP-Nonce: $NONCE" \
		--data "post=1&content=$1$2" "$URL/?rest_route=/wp/v2/comments"
}
rest_status=$( rest_post "$MARK-rest" '' )
rest_landed=$( landed "$MARK-rest" )
clean
note_status=$( rest_post "$MARK-note" '&type=note' )
note_landed=$( landed "$MARK-note" )
clean

# --- XML-RPC wp.newComment ----------------------------------------------------
X="$( curl -s -X POST -H 'Content-Type: text/xml' --data "<?xml version=\"1.0\"?><methodCall><methodName>wp.newComment</methodName><params><param><value><int>1</int></value></param><param><value><string>$XUSER</string></value></param><param><value><string>$XPASS</string></value></param><param><value><int>1</int></value></param><param><value><struct><member><name>content</name><value><string>$MARK-xmlrpc</string></value></member></struct></value></param></params></methodCall>" "$URL/xmlrpc.php" )"
xmlrpc_fault=$( printf '%s' "$X" | grep -A1 faultCode | grep -o '<int>-\{0,1\}[0-9]*</int>' | head -1 | sed 's/[^0-9-]//g' )
xmlrpc_landed=$( landed "$MARK-xmlrpc" )
clean

# --- pingback -----------------------------------------------------------------
P="$( curl -s -X POST -H 'Content-Type: text/xml' --data "<?xml version=\"1.0\"?><methodCall><methodName>pingback.ping</methodName><params><param><value><string>$SOURCE</string></value></param><param><value><string>$PERMA</string></value></param></params></methodCall>" "$URL/xmlrpc.php" )"
ping_fault=$( printf '%s' "$P" | grep -A1 faultCode | grep -o '<int>-\{0,1\}[0-9]*</int>' | head -1 | sed 's/[^0-9-]//g' )
ping_landed=$( w eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = \"pingback\"" );' --skip-plugins --skip-themes | tail -1 )

clean

cat <<EOF
=== $LABEL
paths.form                $form_status landed=$form_landed
paths.rest                $rest_status landed=$rest_landed
paths.note                $note_status landed=$note_landed
paths.xmlrpc              fault=${xmlrpc_fault:-none} landed=$xmlrpc_landed
paths.pingback            fault=${ping_fault:-none} landed=$ping_landed
EOF
