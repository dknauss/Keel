#!/usr/bin/env bash
#
# Which editor does the post-edit screen actually load?
#
# probe-teardown.sh answers "what does the site still serve" for the REST,
# comment and XML-RPC surfaces. It says nothing about the editor, so the classic-
# editor column of the matrix was code review only — and code review can tell you
# a plugin filters `use_block_editor_for_post_type` without telling you whether
# the screen that results is the classic editor, the block editor, or a broken
# hybrid.
#
# This is deliberately separate rather than folded into probe-teardown.sh: every
# probe here needs an authenticated admin session and a rendered admin screen,
# which is a different and much slower shape than an anonymous HTTP GET.
#
# Usage:
#   PROBE_URL=http://127.0.0.1:9327 PROBE_PATH=/path/to/wp \
#     bash tests/integration/probe-editor.sh "label"
set -u

URL="${PROBE_URL:-}"
WP="${PROBE_PATH:-}"
LABEL="${1:-editor}"

if [ -z "$URL" ] || [ -z "$WP" ]; then
	echo "probe-editor: usage: PROBE_URL=… PROBE_PATH=… $0 [label]" >&2
	exit 2
fi

# A live session, generated per run and never written where it could be committed.
#
# Two cookies, not one, and that is the difference between this and the REST
# probes in probe-teardown.sh. A REST request authenticates on the `logged_in`
# cookie alone, but `auth_redirect()` — which guards every wp-admin screen —
# resolves its scheme through `apply_filters( 'auth_redirect_scheme', '' )`, and
# an empty scheme makes `wp_validate_auth_cookie()` check the **auth** cookie.
# Sending only `logged_in` gets a 302 to wp-login.php with `reauth=1`, and the
# probe then reports every editor marker as 0 — a plugin appearing to strip the
# whole editor when the request never got past the login redirect.
AUTH="$( wp eval '
$u   = get_users( array( "role" => "administrator", "number" => 1 ) );
$uid = $u ? $u[0]->ID : 1;
$exp = time() + 3600;
$tok = WP_Session_Tokens::get_instance( $uid )->create( $exp );
printf(
	"%s=%s; %s=%s",
	AUTH_COOKIE,
	wp_generate_auth_cookie( $uid, $exp, "auth", $tok ),
	LOGGED_IN_COOKIE,
	wp_generate_auth_cookie( $uid, $exp, "logged_in", $tok )
);
' --path="$WP" 2>/dev/null | tr -d '\r' | grep '=' | tail -1 )"

G() { curl -s -H "Cookie: $AUTH" "$URL/wp-admin/$1"; }

# A probe that silently measures the login page is worse than no probe. Fail loudly.
probe_guard=$( curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $AUTH" "$URL/wp-admin/post.php?post=1&action=edit" )
if [ "$probe_guard" != "200" ]; then
	echo "probe-editor: the edit screen returned $probe_guard, not 200 — the session is not authenticating." >&2
	exit 1
fi

edit_screen=$( G "post.php?post=1&action=edit" )
list_screen=$( G "edit.php" )
new_screen=$( G "post-new.php" )

# Block editor markers: the container div and the body class the block editor
# page sets. Classic markers: the TinyMCE textarea and its wrapper.
count() { echo "$1" | grep -co "$2" || true; }

e_block=$( count "$edit_screen" "block-editor-page" )
e_editorjs=$( count "$edit_screen" "wp-block-editor" )
e_classic=$( count "$edit_screen" "wp-editor-area" )
e_tinymce=$( count "$edit_screen" "tinymce" )

n_block=$( count "$new_screen" "block-editor-page" )
n_classic=$( count "$new_screen" "wp-editor-area" )

# Row actions on the posts list. Classic Editor adds a second edit link when it
# offers both editors; nothing else in this field does.
l_editlinks=$( count "$list_screen" "action=edit" )
l_classicact=$( count "$list_screen" "classic-editor" )

# What the filters say *in a CLI context*, kept beside the rendered result
# precisely so the two can disagree visibly — and they do.
#
# Disable Gutenberg registers its filter from an admin-only hook, so this reads
# `block` for a plugin that demonstrably serves the classic editor over HTTP.
# That is not a defect in the plugin; it is the limit of asking a filter what it
# would do instead of asking the screen what it did, which is the whole reason
# the classic-editor column of the matrix could not be settled by code review.
FILTERS=$( wp eval '
$pt = function_exists( "use_block_editor_for_post_type" ) ? use_block_editor_for_post_type( "post" ) : null;
$pp = function_exists( "use_block_editor_for_post" ) ? use_block_editor_for_post( 1 ) : null;
printf(
	"for_post_type=%s for_post=%s",
	null === $pt ? "n/a" : ( $pt ? "block" : "classic" ),
	null === $pp ? "n/a" : ( $pp ? "block" : "classic" )
);
' --path="$WP" 2>/dev/null | tr -d '\r' | grep 'for_post_type' | tail -1 )

cat <<OUT
=== $LABEL
edit.block_editor_page    $e_block
edit.wp_block_editor_ref  $e_editorjs
edit.classic_textarea     $e_classic
edit.tinymce_ref          $e_tinymce
new.block_editor_page     $n_block
new.classic_textarea      $n_classic
list.edit_links           $l_editlinks
list.classic_editor_refs  $l_classicact
cli_filters.$FILTERS
OUT
