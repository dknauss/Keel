#!/usr/bin/env bash
#
# Integration behaviour tests for Keel against a REAL WordPress install.
#
# Unit tests (tests/*.php) check functions in isolation with stubs. They cannot
# tell whether a setting actually changes WordPress's behaviour — the way the
# admin-menu-width bug passed its unit test but did nothing on a real site. This
# harness sets each option in the database and asserts the real effect through a
# fresh WordPress load (so the schema-driven bootstrap re-wires with that value).
#
# Default target is the Studio site at ~/Studio/keel-test. Override with:
#   KEEL_SITE=/path/to/wp  bash tests/integration/verify-behaviors.sh
# Uses `studio wp` when the path is a Studio site, else plain `wp --path`.

set -u
SITE="${KEEL_SITE:-$HOME/Studio/keel-test}"

# A db.php dropin is not a Studio marker — SQLite installs, object caches and
# Query Monitor all ship one. Only route through `studio wp` for a path Studio
# actually manages, or KEEL_SITE pointing anywhere else fails with "The
# specified directory is not added to Studio".
case "$SITE" in
	"$HOME/Studio/"*) STUDIO_SITE=1 ;;
	*)                STUDIO_SITE=0 ;;
esac

if [ "$STUDIO_SITE" = "1" ] && command -v studio >/dev/null 2>&1; then
	WPBIN() { studio wp --path="$SITE" "$@"; }
else
	WPBIN() { wp --path="$SITE" "$@"; }
fi
# Strip Studio's spinner frames, ANSI, and PHP deprecation noise from output.
#
# The second filter drops any line containing a box-drawing character. Studio
# prints an "Update available" banner in a Unicode box on every invocation, and
# it survives the control-character strip above — every probe then read as the
# banner text with the answer glued on the end, and the run stopped at "keel not
# loaded on this site". WP-CLI draws its own tables with ASCII +-|, so nothing
# the harness actually reads is caught by this.
WP() { WPBIN "$@" 2>&1 | LC_ALL=C sed -E 's/\x1b\[[0-9;]*m//g; s/[[:cntrl:]]//g' | grep -avE "Deprecated: Case|react/promise|Loading sites" | LC_ALL=C grep -av -e $'\342\224' -e $'\342\225'; }

pass=0; fail=0
setopt() { WPBIN option patch update keel_settings "$1" "$2" >/dev/null 2>&1; }
# check <description> <php-that-echoes-OK-on-success>
check() {
	local out
	out=$(WP eval "$2" | tr -d '[:space:]')
	if [ "$out" = "OK" ]; then
		printf '  \033[32mPASS\033[0m  %s\n' "$1"; pass=$((pass+1))
	else
		printf '  \033[31mFAIL\033[0m  %s  (got: %s)\n' "$1" "$out"; fail=$((fail+1))
	fi
}

echo "Target: $SITE"
probe=$(WP eval 'echo function_exists("keel_defaults_schema") ? "READY" : "NO";' | tr -d '[:space:]')
[ "$probe" = "READY" ] || { echo "keel not loaded on this site (probe: $probe)"; exit 2; }

echo; echo "== WordPress filter semantics =="
semantics=$(WP eval-file "$(cd "$(dirname "$0")" && pwd)/filter-semantics.php" | tr -d '\r')
if printf '%s' "$semantics" | grep -q 'filter semantics: OK'; then
	printf '  \033[32mPASS\033[0m  all callbacks execute on final-value filters\n'; pass=$((pass+1))
else
	printf '  \033[31mFAIL\033[0m  real WP_Hook semantics  (got: %s)\n' "$semantics"; fail=$((fail+1))
fi

echo; echo "== Security headers =="
setopt security_headers yes; setopt frame_options SAMEORIGIN
check "X-Content-Type-Options: nosniff is sent"        '$h=apply_filters("wp_headers",array());echo (($h["X-Content-Type-Options"]??"")==="nosniff")?"OK":"no";'
check "Referrer-Policy is sent"                        '$h=apply_filters("wp_headers",array());echo (($h["Referrer-Policy"]??"")==="strict-origin-when-cross-origin")?"OK":"no";'
check "X-Frame-Options: SAMEORIGIN is sent"            '$h=apply_filters("wp_headers",array());echo (($h["X-Frame-Options"]??"")==="SAMEORIGIN")?"OK":"no";'
check "a stronger existing DENY is not downgraded"     '$h=apply_filters("wp_headers",array("X-Frame-Options"=>"DENY"));echo ($h["X-Frame-Options"]==="DENY")?"OK":"no";'
setopt security_headers no
check "headers are absent when the toggle is off"      '$h=apply_filters("wp_headers",array());echo (!isset($h["X-Content-Type-Options"])&&!isset($h["Referrer-Policy"]))?"OK":"present";'
setopt security_headers yes

echo; echo "== Comments =="
setopt disable_comments yes
check "comments_open forced false"                     'echo apply_filters("comments_open",true,1)?"open":"OK";'
check "new content defaults to closed"                 'echo (apply_filters("get_default_comment_status","open")==="closed")?"OK":"open";'
check "comment feed link removed"                      'echo apply_filters("feed_links_show_comments_feed",true)?"shown":"OK";'
check "comment count reports zero"                     'echo ((int)apply_filters("get_comments_number",7,1)===0)?"OK":(string)apply_filters("get_comments_number",7,1);'
check "comment feeds themselves are blocked"           'echo (false!==has_action("template_redirect","keel_defaults_block_comment_feeds"))?"OK":"unhooked";'
# Called directly rather than through apply_filters("render_block"): core hooks
# WP_Duotone::render_duotone_support() there, which requires a third $instance
# argument and fatals when a test fires the filter with two.
check "comment blocks render as nothing"               'echo (""===keel_defaults_suppress_comment_blocks("<div>comments</div>",array("blockName"=>"core/comments")))?"OK":"rendered";'
check "the render filter is hooked"                    'echo (false!==has_filter("render_block","keel_defaults_suppress_comment_blocks"))?"OK":"unhooked";'
check "non-comment blocks render untouched"            'echo ("<p>hi</p>"===keel_defaults_suppress_comment_blocks("<p>hi</p>",array("blockName"=>"core/paragraph")))?"OK":"mangled";'
check "a block with no blockName is untouched"         'echo ("<p>hi</p>"===keel_defaults_suppress_comment_blocks("<p>hi</p>",array()))?"OK":"mangled";'
setopt disable_comments no
check "comments_open untouched when off"                'echo apply_filters("comments_open",true,1)?"OK":"closed";'
check "comment count untouched when off"                'echo ((int)apply_filters("get_comments_number",7,1)===7)?"OK":"zeroed";'
check "the render filter is unhooked when off"          'echo (false===has_filter("render_block","keel_defaults_suppress_comment_blocks"))?"OK":"still-hooked";'
check "comment feeds served when off"                   'echo (false===has_action("template_redirect","keel_defaults_block_comment_feeds"))?"OK":"still-blocked";'
setopt disable_comments yes

echo; echo "== REST authentication =="
setopt disable_rest yes
check "anonymous REST is refused"                      '$e=apply_filters("rest_authentication_errors",null);echo (is_wp_error($e)&&$e->get_error_code()==="rest_not_logged_in")?"OK":"allowed";'
check "an earlier error is passed through"             '$p=new WP_Error("other","x");$e=apply_filters("rest_authentication_errors",$p);echo ($e===$p)?"OK":"clobbered";'
check "head discovery link removed"                    'echo (false===has_action("wp_head","rest_output_link_wp_head"))?"OK":"advertised";'
check "Link: header removed"                           'echo (false===has_action("template_redirect","rest_output_link_header"))?"OK":"advertised";'
check "RSD discovery entry removed"                    'echo (false===has_action("xmlrpc_rsd_apis","rest_output_rsd"))?"OK":"advertised";'
setopt disable_rest no
# Core's own rest_cookie_check_errors() answers true, not null, when there is
# nothing wrong — so the assertion is "no error", not "untouched".
check "anonymous REST allowed when off"                'echo (!is_wp_error(apply_filters("rest_authentication_errors",null)))?"OK":"refused";'
check "discovery link restored when off"               'echo (false!==has_action("wp_head","rest_output_link_wp_head"))?"OK":"still-removed";'
setopt disable_rest yes

echo; echo "== Editor / classic =="
setopt force_classic_editor yes
check "block editor disabled for posts"                'echo apply_filters("use_block_editor_for_post",true,null)?"block":"OK";'
setopt force_classic_editor no
check "block editor restored when off"                 'echo apply_filters("use_block_editor_for_post",true,null)?"OK":"still-off";'

echo; echo "== Uploads =="
setopt lowercase_upload_filenames yes
check "upload filename lowercased"                      'echo (apply_filters("sanitize_file_name","MixedCase.PNG")==="mixedcase.png")?"OK":apply_filters("sanitize_file_name","MixedCase.PNG");'
setopt lowercase_upload_filenames no
check "filename case preserved when off"                'echo (apply_filters("sanitize_file_name","MixedCase.PNG")==="MixedCase.PNG")?"OK":"changed";'

echo; echo "== Emoji =="
setopt disable_emojis yes
check "emoji detection script unhooked"                'echo has_action("wp_head","print_emoji_detection_script")?"still":"OK";'

echo; echo "== Author archives =="
setopt disable_author_archives yes
check "author archive redirect hooked"                 'echo (false!==has_action("template_redirect","keel_defaults_redirect_author_archive"))?"OK":"no";'
check "author feed 404 handler runs first"              'echo (9===has_action("template_redirect","keel_defaults_block_author_feeds"))?"OK":"no";'

echo; echo "== Revisions =="
setopt post_revisions_limit 10
check "revision policy honours constant precedence"     '$lock=keel_defaults_config_lock("post_revisions_limit");$hook=has_filter("wp_revisions_to_keep","keel_defaults_revision_limit");if(null!==$lock){echo false===$hook?"OK":"hooked";}else{$p=get_post(1);echo ($p&&10===wp_revisions_to_keep($p))?"OK":"wrong";}'

echo; echo "== Admin menu width =="
setopt admin_menu_width 240
check "width CSS registered as an admin style provider" 'echo array_key_exists("keel_defaults_admin_menu_width_css",keel_defaults_style_providers("admin"))?"OK":"no";'
check "CSS carries the width with !important"          '$c=keel_defaults_admin_menu_width_css();echo (strpos($c,"240px !important")!==false)?"OK":"no";'
setopt admin_menu_width default
# Asserts the CSS is empty, not that a hook is absent. The hook-absence version
# of this check went vacuous when the provider moved off admin_head: it asked
# whether a hook Keel no longer uses was registered, which is false at every
# setting, so it passed without testing anything.
check "no width CSS at default"                        'echo ""===trim(keel_defaults_admin_menu_width_css())?"OK":"still";'

echo; echo "== Environment indicator =="
setopt environment_indicator yes
check "admin bar node hooked when on"                  'echo has_action("admin_bar_menu","keel_defaults_environment_toolbar_item")?"OK":"no";'
setopt environment_indicator no
check "no admin bar node when off"                     'echo has_action("admin_bar_menu","keel_defaults_environment_toolbar_item")?"still":"OK";'

echo; echo "== Post passwords =="
setopt disable_post_passwords yes
check "password UI hider registered as a style provider" 'echo array_key_exists("keel_defaults_hide_post_password_css",keel_defaults_style_providers("admin"))?"OK":"no";'
setopt disable_post_passwords no

echo; echo "== Strong passwords + role scoping =="
setopt require_strong_passwords yes
check "profile password validator hooked"              'echo has_action("user_profile_update_errors","keel_defaults_validate_profile_password")?"OK":"no";'
check "subscriber exempt from enforcement"             '$u=(object)["roles"=>["subscriber"]];echo keel_defaults_password_enforced_for_user($u)?"enforced":"OK";'
check "administrator enforced"                         '$u=(object)["roles"=>["administrator"]];echo keel_defaults_password_enforced_for_user($u)?"OK":"exempt";'

echo; echo "== XML-RPC =="
setopt xmlrpc_allow_pingbacks no
check "pingback.ping method removed"                   '$m=apply_filters("xmlrpc_methods",array("pingback.ping"=>"x","demo.sayHello"=>"y"));echo isset($m["pingback.ping"])?"present":"OK";'

echo; echo "== REST user discovery =="
setopt restrict_rest_user_discovery yes
check "users endpoint filter registered"               'echo has_filter("rest_endpoints")?"OK":"no";'

echo; echo "== Core updates =="
setopt core_update_policy minor
check "minor auto-updates allowed"                     'echo apply_filters("allow_minor_auto_core_updates",false)?"OK":"blocked";'
check "major auto-updates blocked under minor"         'echo apply_filters("allow_major_auto_core_updates",true)?"allowed":"OK";'
setopt core_update_policy all
check "major allowed under all"                        'echo apply_filters("allow_major_auto_core_updates",false)?"OK":"blocked";'
setopt core_update_policy minor

echo; echo "== Site Health =="
check "posture test registered"                        '$t=apply_filters("site_status_tests",array("direct"=>array()));echo isset($t["direct"]["keel_defaults_posture"])?"OK":"no";'

echo
printf 'Result: \033[32m%d passed\033[0m, ' "$pass"
if [ "$fail" -gt 0 ]; then printf '\033[31m%d failed\033[0m\n' "$fail"; exit 1; else printf '0 failed\n'; fi
