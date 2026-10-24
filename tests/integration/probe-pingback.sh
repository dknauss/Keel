#!/usr/bin/env bash
#
# Send one real pingback and report whether it became a comment.
#
# probe-teardown.sh shows whether pingback.ping is listed and what fault it
# answers with. That leaves one question open, and it is the one people install
# an XML-RPC plugin to answer: does a pingback from an outside page still land?
# A plugin can change the fault code of an authenticated method and leave
# pingback.ping executing; only a pingback with a real source page shows it.
#
# The source page must link to post 1 and be reachable from the site. For a
# local lab, serve fixtures/pingback-source.html as index.html with
# `php -S 127.0.0.1:9401`, and install fixtures/lab-pingback-source.php as an
# mu-plugin, because wp_safe_remote_get() refuses loopback hosts by design.
#
# Usage:
#   PROBE_URL=http://127.0.0.1:9400 PROBE_PATH=/path/to/wp \
#     bash tests/integration/probe-pingback.sh "label"
#
# It can be passed to probe-plugin.sh as PROBE_SCRIPT=probe-pingback.sh. Any
# pingback it creates is deleted again.

set -u

URL="${PROBE_URL:-}"
WP="${PROBE_PATH:-}"
LABEL="${1:-unlabelled run}"
SOURCE="${PROBE_PINGBACK_SOURCE:-http://127.0.0.1:9401/}"

if [ -z "$URL" ] || [ -z "$WP" ]; then
	echo "probe-pingback: set PROBE_URL and PROBE_PATH. See tests/integration/README.md." >&2
	exit 2
fi

PERMA="$( wp eval 'echo get_permalink( 1 );' --path="$WP" 2>/dev/null | tr -d '\r' | tail -1 )"
[ -n "$PERMA" ] || { echo "probe-pingback: post 1 has no permalink" >&2; exit 2; }

# Counted and cleaned with plugins skipped, so no teardown plugin can hide a
# row that landed. Same reasoning as write.comment_landed_indb.
count() {
	wp eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = \"pingback\"" );' \
		--path="$WP" --skip-plugins --skip-themes 2>/dev/null | tail -1
}
clean() {
	wp eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_type = \"pingback\"" );' \
		--path="$WP" --skip-plugins --skip-themes >/dev/null 2>&1
}

clean
REPLY="$( curl -s -X POST -H 'Content-Type: text/xml' --data "<?xml version=\"1.0\"?><methodCall><methodName>pingback.ping</methodName><params><param><value><string>$SOURCE</string></value></param><param><value><string>$PERMA</string></value></param></params></methodCall>" "$URL/xmlrpc.php" )"
FAULT="$( printf '%s' "$REPLY" | grep -o '<int>-\{0,1\}[0-9]*</int>' | head -1 | sed 's/[^0-9-]//g' )"
LANDED="$( count )"
clean

cat <<EOF
=== $LABEL
pingback.fault            ${FAULT:-none}
pingback.registered       $( printf '%s' "$REPLY" | grep -c 'registered' )
pingback.comment_landed   $LANDED
EOF
