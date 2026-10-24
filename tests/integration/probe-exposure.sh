#!/usr/bin/env bash
#
# Measure what a site still tells an anonymous visitor about its accounts.
#
# probe-teardown.sh asks what a "disable it" plugin closes. This asks the
# question the security suites sell: with "stop user enumeration", "hide login
# errors" and similar options switched on, can an anonymous request still learn
# that an account exists, and what it is called? It probes every route core
# offers for that answer, not only the one a plugin's setting is named after,
# because the routes are independent: blocking `/?author=1` does nothing for the
# users sitemap, and hiding the REST users route does nothing for a login form
# that says which half of a credential was wrong.
#
# Like probe-teardown.sh, it is plugin-agnostic and reports what the site does.
# Capture a stock baseline on the same install first; a cell means something
# only against it.
#
# Usage:
#   PROBE_URL=http://127.0.0.1:9400 PROBE_PATH=/path/to/wp \
#     bash tests/integration/probe-exposure.sh "label for this run"
#
# It can be passed to probe-plugin.sh as PROBE_SCRIPT=probe-exposure.sh.
# Nothing is written to the site except an expired login attempt.

set -u

URL="${PROBE_URL:-}"
WP="${PROBE_PATH:-}"
LABEL="${1:-unlabelled run}"

if [ -z "$URL" ] || [ -z "$WP" ]; then
	echo "probe-exposure: set PROBE_URL and PROBE_PATH. See tests/integration/README.md." >&2
	exit 2
fi

if ! wp core is-installed --path="$WP" >/dev/null 2>&1; then
	echo "probe-exposure: no WordPress install at $WP" >&2
	exit 2
fi

# The account being looked for: the author of post 1. Its login and nicename
# are what an enumeration finds, so every "leak" row below counts one of them
# in a response body or a Location header.
read -r UID_ LOGIN NICE < <( wp eval '
$p = get_post( 1 );
$u = get_userdata( $p ? $p->post_author : 1 );
echo $u->ID, " ", $u->user_login, " ", $u->user_nicename;
' --path="$WP" 2>/dev/null | tr -d '\r' | tail -1 )
[ -n "${NICE:-}" ] || { echo "probe-exposure: post 1 has no author" >&2; exit 2; }

code() { curl -s -o /dev/null -w "%{http_code}" "$@"; }

# Status plus whether the nicename appeared in the redirect target or the body.
# "200 leak" and "301 leak" are both an answer; "404" and "403" are not.
leak() {
	local out status loc body
	out="$( curl -s -D - "$@" )"
	status="$( printf '%s' "$out" | head -1 | awk '{print $2}' )"
	loc="$( printf '%s' "$out" | grep -i '^location:' | head -1 )"
	body="$( printf '%s' "$out" | sed '1,/^\r\{0,1\}$/d' )"
	if printf '%s' "$loc" | grep -qi "/author/$NICE"; then
		echo "$status leak(location)"
	elif printf '%s' "$body" | grep -qi "author-$NICE\|/author/$NICE\|\"slug\":\"$NICE\""; then
		echo "$status leak(body)"
	else
		echo "$status"
	fi
}

# How many user records with a slug came back from a REST users read.
slugs() { curl -s "$@" | grep -o '"slug":"[^"]*"' | wc -l | tr -d ' '; }

# --- Author archive routes ---------------------------------------------------
# /?author=N is the classic enumeration. The variants are what a blocklist
# keyed on the literal query string misses: the same query variable reaches
# WP::parse_request() from POST, from an array, and through index.php.
en_author_q=$( leak "$URL/?author=$UID_" )
en_author_idx=$( leak "$URL/index.php?author=$UID_" )
en_author_arr=$( leak -g "$URL/?author[]=$UID_" )
en_author_post=$( leak -X POST --data "author=$UID_" "$URL/" )
en_author_archive=$( code "$URL/author/$NICE/" )

# --- REST ----------------------------------------------------------------------
# Core matches REST routes case-insensitively, so /wp/v2/Users is the same
# route as /wp/v2/users to the server and a different string to a plugin that
# compares paths.
en_rest_users=$( slugs "$URL/wp-json/wp/v2/users" )
en_rest_users_qs=$( slugs "$URL/?rest_route=/wp/v2/users" )
en_rest_users_case=$( slugs "$URL/wp-json/wp/v2/Users" )
en_rest_user1=$( slugs "$URL/wp-json/wp/v2/users/$UID_" )
en_rest_embed=$( curl -s "$URL/wp-json/wp/v2/posts?_embed=author" | grep -c "\"slug\":\"$NICE\"" )

# --- Other routes core publishes it on ----------------------------------------
en_sitemap=$( curl -s "$URL/wp-sitemap-users-1.xml" | grep -c "/author/$NICE" )
PERMA="$( wp eval 'echo get_permalink( 1 );' --path="$WP" 2>/dev/null | tr -d '\r' | tail -1 )"
en_oembed=$( curl -s "$URL/wp-json/oembed/1.0/embed?url=$PERMA" | sed 's#\\/#/#g' | grep -c "/author/$NICE" )
en_feed=$( curl -s "$URL/feed/" | grep -c "<dc:creator><!\[CDATA\[$LOGIN\]\]></dc:creator>" )

# --- Login and password-reset forms ------------------------------------------
# A form that answers "unknown username" for one login and "wrong password" for
# another confirms which accounts exist. 1 means the two answers differ.
login_msg() {
	curl -s -X POST -b 'wordpress_test_cookie=WP%20Cookie%20check' \
		--data-urlencode "log=$1" --data-urlencode 'pwd=probe-wrong-password' \
		--data 'wp-submit=Log+In&testcookie=1' "$URL/wp-login.php" |
		grep -o '<div id="login_error"[^>]*>.*' | head -1 | sed 's/<[^>]*>//g' | sed "s/$1/USER/g" | tr -s ' '
}
lost_msg() {
	curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST \
		--data-urlencode "user_login=$1" --data 'wp-submit=Get+New+Password' \
		"$URL/wp-login.php?action=lostpassword" | sed "s/$1/USER/g"
	curl -s -X POST --data-urlencode "user_login=$1" --data 'wp-submit=Get+New+Password' \
		"$URL/wp-login.php?action=lostpassword" | grep -o '<div id="login_error"[^>]*>.*' | head -1 | sed 's/<[^>]*>//g'
}
la="$( login_msg "$LOGIN" )"
lb="$( login_msg "probe-no-such-user-$$" )"
[ "$la" = "$lb" ] && en_login=0 || en_login=1
pa="$( lost_msg "$LOGIN" )"
pb="$( lost_msg "probe-no-such-user-$$" )"
[ "$pa" = "$pb" ] && en_lost=0 || en_lost=1

# --- Response headers -----------------------------------------------------------
H="$( curl -s -D - -o /dev/null "$URL/?p=1" | tr 'A-Z' 'a-z' )"
hdr() { printf '%s' "$H" | grep -c "^$1:"; }

# --- Authenticated: the block editor's own user reads ---------------------------
# The editor's author picker reads /wp/v2/users?who=authors. A plugin that
# closes the users route for everyone breaks it, which is the trade the
# anonymous rows above should never cost.
AUTH="$( wp eval '
$u   = get_users( array( "role" => "administrator", "number" => 1 ) );
$uid = $u ? $u[0]->ID : 1;
$exp = time() + 3600;
$tok = WP_Session_Tokens::get_instance( $uid )->create( $exp );
$ck  = wp_generate_auth_cookie( $uid, $exp, "logged_in", $tok );
$_COOKIE[ LOGGED_IN_COOKIE ] = $ck;
wp_set_current_user( $uid );
echo LOGGED_IN_COOKIE . "=" . $ck . "|" . wp_create_nonce( "wp_rest" );
' --path="$WP" 2>/dev/null | tr -d '\r' | grep '|' | tail -1 )"
COOKIE="${AUTH%|*}"
NONCE="${AUTH##*|}"
A() { curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $COOKIE" -H "X-WP-Nonce: $NONCE" "$URL/wp-json/$1"; }

cat <<EOF
=== $LABEL
enum.author_query         $en_author_q
enum.author_index         $en_author_idx
enum.author_array         $en_author_arr
enum.author_post          $en_author_post
enum.author_archive       $en_author_archive
enum.rest_users           $en_rest_users
enum.rest_users_qs        $en_rest_users_qs
enum.rest_users_case      $en_rest_users_case
enum.rest_user_single     $en_rest_user1
enum.rest_embed_author    $en_rest_embed
enum.sitemap_users        $en_sitemap
enum.oembed_author_url    $en_oembed
enum.feed_login           $en_feed
enum.login_distinguishes  $en_login
enum.lostpw_distinguishes $en_lost
header.x_frame_options    $( hdr x-frame-options )
header.x_content_type     $( hdr x-content-type-options )
header.referrer_policy    $( hdr referrer-policy )
header.hsts               $( hdr strict-transport-security )
header.permissions_policy $( hdr permissions-policy )
header.csp                $( hdr content-security-policy )
auth.users_authors        $( A "wp/v2/users?who=authors&context=view" )
auth.users_me             $( A "wp/v2/users/me" )
auth.posts_edit           $( A "wp/v2/posts?context=edit" )
EOF
