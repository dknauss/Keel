#!/usr/bin/env bash
#
# Measure what a "disable it" plugin actually closes, over HTTP.
#
# verify-behaviors.sh asserts that Keel's filters are registered and answer
# correctly. This asks a different question: with the plugin active on a real
# site, what does the site still serve? Those are not the same question, and the
# gap between them is where this harness earns its keep — a comment-feed fix
# passed every filter-level assertion in verify-behaviors.sh while serving a 301
# redirect loop to a URL that never existed. Only a real request caught it.
#
# It is deliberately plugin-agnostic. Point it at a site with Keel active, or
# Disable Comments, or nothing at all; it reports what the site does, not what
# any plugin claims. That is what makes it usable as a comparison tool — the
# numbers in docs/competitive-teardown-matrix.md came from this script run
# against ten different plugins on the same install.
#
# Usage:
#   PROBE_URL=http://127.0.0.1:9314 PROBE_PATH=/path/to/wp \
#     bash tests/integration/probe-teardown.sh "label for this run"
#
# Both variables are required; there is no default, because a wrong default here
# means probing somebody's real site. See README.md in this directory for how to
# build a throwaway install to point it at.

set -u

URL="${PROBE_URL:-}"
WP="${PROBE_PATH:-}"
LABEL="${1:-unlabelled run}"
HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

if [ -z "$URL" ] || [ -z "$WP" ]; then
	echo "probe-teardown: set PROBE_URL and PROBE_PATH. See tests/integration/README.md." >&2
	exit 2
fi

if ! wp core is-installed --path="$WP" >/dev/null 2>&1; then
	echo "probe-teardown: no WordPress install at $WP" >&2
	exit 2
fi

# The permalink of the seeded post, whatever the structure is set to. Hardcoding
# /hello-world/ breaks the moment someone changes permalinks.
PERMA="$( wp eval 'echo get_permalink( 1 );' --path="$WP" 2>/dev/null | tr -d '\r' | tail -1 )"
[ -n "$PERMA" ] || { echo "probe-teardown: post 1 has no permalink" >&2; exit 2; }

code() { curl -s -o /dev/null -w "%{http_code}" "$@"; }
jn()   { python3 -c "import sys,json
try:
    d = json.load(sys.stdin)
    print(len(d) if isinstance(d, list) else 'err')
except Exception:
    print('err')"; }

# --- Authenticated identity -------------------------------------------------
# Generated per run, never written where it could be committed: this is a live
# login cookie. The authenticated probes are the ones that catch a plugin
# blocking REST for *administrators* as well as for the public — the failure
# that takes the block editor down with it.
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

# --- REST: comment read paths -----------------------------------------------
# Both routes, because a plugin that only matches the pretty permalink leaves
# ?rest_route= wide open.
rc_pretty=$( code "$URL/wp-json/wp/v2/comments" )
rn_pretty=$( curl -s "$URL/wp-json/wp/v2/comments" | jn )
rc_qs=$( code "$URL/?rest_route=/wp/v2/comments" )
rn_qs=$( curl -s "$URL/?rest_route=/wp/v2/comments" | jn )
rn_embed=$( curl -s "$URL/wp-json/wp/v2/posts/1?_embed=replies" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    r = d.get('_embedded', {}).get('replies', [[]])
    print(len(r[0]) if r and isinstance(r[0], list) else 0)
except Exception:
    print('err')" )

# --- REST: collateral damage ------------------------------------------------
# A plugin that closes REST also closes oEmbed and the discovery link. That is
# a consequence worth measuring, not a bug — but it should be a known one.
rc_index=$( code "$URL/wp-json/" )
rc_users=$( code "$URL/wp-json/wp/v2/users" )
rc_posts=$( code "$URL/wp-json/wp/v2/posts" )
rc_oembed=$( code "$URL/wp-json/oembed/1.0/embed?url=$PERMA" )
rc_head=$( curl -s -D - -o /dev/null -L "$PERMA" | grep -ci 'rel="https://api.w.org/"' )

# --- Author identity --------------------------------------------------------
# Hiding author archives is not the same as not publishing the author's login.
# Three routes serve it and only one is called an author archive: feeds via
# <dc:creator>, oEmbed via author_name/author_url (the URL carries the
# nicename), and core's users sitemap, which is a list of author archive URLs
# by construction.
#
# This group exists because a live leak in two of the three sibling plugins
# survived a document built specifically to measure teardown correctness — the
# matrix had no row that would have looked.
pr_archive=$( code "$URL/author/admin/" )
pr_author_feed=$( code "$URL/author/admin/feed/" )
pr_sitemap_listed=$( curl -s "$URL/wp-sitemap.xml" | grep -c "wp-sitemap-users" )
pr_sitemap_names=$( curl -s "$URL/wp-sitemap-users-1.xml" | grep -c "<loc>" )
pr_oembed_author=$( curl -s "$URL/wp-json/oembed/1.0/embed?url=$PERMA" | grep -c '"author_name"' )
pr_oembed_url=$( curl -s "$URL/wp-json/oembed/1.0/embed?url=$PERMA" | grep -c '"author_url"' )
pr_oembed_works=$( curl -s "$URL/wp-json/oembed/1.0/embed?url=$PERMA" | grep -c '"title"' )
pr_feed_creator=$( curl -s "$URL/feed/" | grep -c "dc:creator" )
pr_feed_login=$( curl -s "$URL/feed/" | grep -c "admin</dc:creator>" )

# --- Feeds and headers ------------------------------------------------------
fc_site=$( code "$URL/comments/feed/" )
fc_post=$( code "${PERMA}feed/" )
xp=$( curl -s -D - -o /dev/null -L "$PERMA" | grep -ci "^x-pingback" )

# --- XML-RPC ----------------------------------------------------------------
# listMethods says what is advertised; the direct calls say what actually
# answers. A plugin can leave a method listed and still refuse it, or — far more
# commonly — drop it from no list at all and still execute it.
xml_call() {
	curl -s -X POST -H 'Content-Type: text/xml' --data "$1" "$URL/xmlrpc.php"
}
fault_of() { grep -o '<int>-\{0,1\}[0-9]*</int>' | head -1 | sed 's/[^0-9-]//g'; }

xlist=$( xml_call '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>' )
xcode=$( code -X POST -H 'Content-Type: text/xml' \
	--data '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>' "$URL/xmlrpc.php" )
xn=$( echo "$xlist" | grep -c "<string>" )
xping=$( echo "$xlist" | grep -c "pingback.ping" )
xmulti=$( echo "$xlist" | grep -c "system.multicall" )
xnew=$( echo "$xlist" | grep -c "wp.newPost" )
xcomment=$( echo "$xlist" | grep -c "wp.newComment" )

xfault_ping=$( xml_call '<?xml version="1.0"?><methodCall><methodName>pingback.ping</methodName><params><param><value><string>http://example.com/</string></value></param><param><value><string>'"$PERMA"'</string></value></param></params></methodCall>' | fault_of )
[ -n "$xfault_ping" ] || xfault_ping="none"
xfault_login=$( xml_call '<?xml version="1.0"?><methodCall><methodName>wp.getUsersBlogs</methodName><params><param><value><string>probe</string></value></param><param><value><string>wrong</string></value></param></params></methodCall>' | fault_of )
[ -n "$xfault_login" ] || xfault_login="none"

# --- Write paths ------------------------------------------------------------
# Whether a comment can be *created* is the question the read probes cannot
# answer, and the DB check below is the only honest way to ask it: a plugin can
# filter every read path and still accept the write.
w_legacy=$( code -X POST \
	--data 'comment_post_ID=1&author=KeelProbe&email=probe@example.invalid&url=&comment=probe-body' \
	"$URL/wp-comments-post.php" )
w_rest=$( code -X POST -H 'Content-Type: application/json' \
	--data '{"post":1,"author_name":"KeelProbe","author_email":"probe@example.invalid","content":"probe-body"}' \
	"$URL/wp-json/wp/v2/comments" )

# --skip-plugins so no filter can hide the row from the count or the cleanup.
w_created=$( wp eval-file "$HERE/probe-lib/count-probe-comments.php" --path="$WP" --skip-themes --skip-plugins 2>/dev/null | grep -oE '^[0-9]+$' | tail -1 )
wp eval-file "$HERE/probe-lib/clean-probe-comments.php" --path="$WP" --skip-themes --skip-plugins >/dev/null 2>&1

# --- Server-side reads ------------------------------------------------------
# The layer most teardowns forget. comments_open and the REST controller cover
# the theme template and the API; a Recent Comments widget, wp_count_comments()
# or another plugin's WP_Comment_Query goes straight to the database.
php_reads=$( wp eval '
$all   = get_comments( array( "status" => "approve" ) );
$typed = get_comments( array( "type" => "comment" ) );
$cnt   = wp_count_comments();
echo "get_comments=" . count( $all )
   . " typed=" . count( $typed )
   . " wp_count_comments=" . intval( $cnt->approved )
   . " comments_open=" . ( comments_open( 1 ) ? 1 : 0 )
   . " pings_open=" . ( pings_open( 1 ) ? 1 : 0 )
   . " number=" . intval( get_comments_number( 1 ) )
   . " supports=" . ( post_type_supports( "post", "comments" ) ? 1 : 0 )
   . " default_status=" . get_default_comment_status( "post" );
' --path="$WP" --skip-themes 2>/dev/null | grep -o 'get_comments=.*' )

# --- Rendered output --------------------------------------------------------
# Block themes keep comment blocks in their templates, so removing blocks from
# the inserter changes nothing about what a visitor sees.
front=$( curl -sL "$PERMA" )
f_form=$( echo "$front" | grep -co "comment-form" )
f_block=$( echo "$front" | grep -co "wp-block-comments" )

cat <<OUT
=== $LABEL
rest.comments.pretty      $rc_pretty (n=$rn_pretty)
rest.comments.querystring $rc_qs (n=$rn_qs)
rest.comments.embed       n=$rn_embed
rest.index                $rc_index
rest.users                $rc_users
rest.posts                $rc_posts
rest.oembed               $rc_oembed
rest.head_link            $rc_head
feed.site_comments        $fc_site
feed.post_comments        $fc_post
header.xpingback          $xp
privacy.author_archive    $pr_archive
privacy.author_feed       $pr_author_feed
privacy.sitemap_listed    $pr_sitemap_listed
privacy.sitemap_names     $pr_sitemap_names
privacy.oembed_author     $pr_oembed_author
privacy.oembed_authorurl  $pr_oembed_url
privacy.oembed_usable     $pr_oembed_works
privacy.feed_creator      $pr_feed_creator
privacy.feed_login        $pr_feed_login
xmlrpc.http               $xcode
xmlrpc.methods            $xn
xmlrpc.has_pingback       $xping
xmlrpc.has_multicall      $xmulti
xmlrpc.has_newPost        $xnew
xmlrpc.has_newComment     $xcomment
xmlrpc.direct_pingback    fault=$xfault_ping
xmlrpc.direct_login       fault=$xfault_login
write.wp-comments-post    $w_legacy
write.rest_post           $w_rest
write.comment_landed_indb ${w_created:-?}
php.$php_reads
html.comment_form         $f_form
html.comments_block       $f_block
auth.posts_edit           $( A "wp/v2/posts?context=edit" )
auth.settings             $( A "wp/v2/settings" )
auth.types                $( A "wp/v2/types/post?context=edit" )
auth.block_types          $( A "wp/v2/block-types" )
auth.comments             $( A "wp/v2/comments" )
OUT
