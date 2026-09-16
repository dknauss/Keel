#!/usr/bin/env bash
#
# Measure what an "update policy" plugin actually decides, and what it tells you.
#
# probe-teardown.sh asks what a disable-style plugin still serves. This asks a
# different question of a different field: with the plugin active and configured,
# which core release would this site install, and does any screen the plugin owns
# say anything true about the release it is running?
#
# The two halves matter separately. A plugin can get the policy exactly right and
# still leave an administrator with no way to learn that the version underneath
# it has known vulnerabilities — those are different features, and conflating
# them is the single most common way this category is described.
#
# Like probe-teardown.sh this is deliberately plugin-agnostic: it reports what the
# site does and what the site renders, not what any plugin claims. Point it at a
# site with Easy Updates Manager active, or Keel, or nothing at all.
#
# Usage:
#   PROBE_URL=http://127.0.0.1:9315 PROBE_PATH=/path/to/wp \
#     bash tests/integration/probe-updates.sh "label for this run"
#
# Both variables are required; there is no default, because a wrong default here
# means probing somebody's real site — and this harness renders wp-admin as a
# logged-in administrator.
#
# The install must be on a release that is neither the latest nor the tip of its
# own line, or most rows have nothing to measure. See README.md in this directory
# for the lab this was built against (6.9.5: insecure, same-line tip 6.9.7,
# latest 7.1).

set -u

URL="${PROBE_URL:-}"
WP="${PROBE_PATH:-}"
LABEL="${1:-unlabelled run}"

if [ -z "$URL" ] || [ -z "$WP" ]; then
	echo "probe-updates: set PROBE_URL and PROBE_PATH. See tests/integration/README.md." >&2
	exit 2
fi

if ! wp core is-installed --path="$WP" >/dev/null 2>&1; then
	echo "probe-updates: no WordPress install at $WP" >&2
	exit 2
fi

# Where probe-http-log.php (an mu-plugin on the lab install) appends every
# outbound URL. Whether a plugin asks api.wordpress.org for stable-check is the
# only objective way to tell "reports vulnerability status" from "reports that an
# update exists": that endpoint is the only public source for the first, and
# rendered text can use the word "insecure" about anything.
HTTP_LOG="${PROBE_HTTP_LOG:-$WP/wp-content/probe-http.log}"

# The stock admin menu, captured with no plugins active. Every href not in here
# is a screen this plugin added, which is how the harness finds a plugin's own
# settings screens without being told where they are. Guessing them per plugin
# would make the reporting rows unfalsifiable: a plugin whose screen the harness
# never found would score identically to one that renders nothing.
MENU_BASELINE="${PROBE_MENU_BASELINE:-}"

# --- Authenticated identity -------------------------------------------------
# Generated per run and never written to disk: this is a live login cookie.
# Everything this harness reads is behind wp-admin, so unlike the teardown probe
# there is no anonymous half.
#
# BOTH cookies, and that is not belt and braces. probe-teardown.sh sends only the
# logged_in cookie because REST is happy with it; wp-admin is not. auth_redirect()
# filters the scheme through `auth_redirect_scheme` with an empty default, and
# wp_parse_auth_cookie() resolves an empty scheme to AUTH_COOKIE over plain HTTP.
# A logged_in-only cookie therefore bounces every wp-admin screen to wp-login.php
# with reauth=1 — a 302 that reads exactly like a plugin locking an administrator
# out of the update screens, which is a finding this harness is meant to be able
# to report truthfully.
AUTH="$( wp eval '
$u   = get_users( array( "role" => "administrator", "number" => 1 ) );
$uid = $u ? $u[0]->ID : 1;
$exp = time() + 3600;
$tok = WP_Session_Tokens::get_instance( $uid )->create( $exp );
echo AUTH_COOKIE . "=" . wp_generate_auth_cookie( $uid, $exp, "auth", $tok )
	. "; " . LOGGED_IN_COOKIE . "=" . wp_generate_auth_cookie( $uid, $exp, "logged_in", $tok );
' --path="$WP" 2>/dev/null | tr -d '\r' | grep '=' | tail -1 )"

[ -n "$AUTH" ] || { echo "probe-updates: could not mint an admin cookie" >&2; exit 2; }

get() { curl -s -H "Cookie: $AUTH" "$1"; }

# --- Ground truth -----------------------------------------------------------
#
# Asked of core, with the plugin active, rather than inferred from the plugin's
# settings. A policy plugin's job is to move these; reading its own stored option
# back would measure the option, not the decision.
#
# would_install is the row the category is really about. find_core_auto_update()
# is not the answer: it returns the newest auto-update offer without applying the
# policy filters at all, so on this lab it says 7.1 whatever any plugin has
# decided. The decision lives in WP_Automatic_Updater::should_update(), evaluated
# per offer — and the release that lands is the HIGHEST offer that passes, not
# the nearest one.
TRUTH="$( wp eval '
require_once ABSPATH . "wp-admin/includes/update.php";
require_once ABSPATH . "wp-admin/includes/class-wp-upgrader.php";
require_once ABSPATH . "wp-admin/includes/class-wp-automatic-updater.php";

$running = $GLOBALS["wp_version"];
$branch  = implode( ".", array_slice( explode( ".", $running ), 0, 2 ) ) . ".";

$transient = get_site_transient( "update_core" );
$offers    = ( is_object( $transient ) && ! empty( $transient->updates ) ) ? $transient->updates : array();

// The same-line patch the API is offering: the highest offer on this sites own
// x.y line. This is the release get_core_updates() discards, because core files
// every same-line offer as "autoupdate" and that screen lists manual upgrades.
$same_line = "";
foreach ( $offers as $o ) {
	if ( 0 !== strpos( $o->current, $branch ) || ! version_compare( $o->current, $running, ">" ) ) {
		continue;
	}
	if ( "" === $same_line || version_compare( $o->current, $same_line, ">" ) ) {
		$same_line = $o->current;
	}
}

$updater = new WP_Automatic_Updater();
$would   = "";
foreach ( $offers as $o ) {
	if ( "autoupdate" !== $o->response ) {
		continue;
	}
	if ( ! $updater->should_update( "core", $o, ABSPATH ) ) {
		continue;
	}
	if ( "" === $would || version_compare( $o->current, $would, ">" ) ) {
		$would = $o->current;
	}
}

// What update-core.php lists. Core stamps ->dismissed on what it returns, so the
// screen showing nothing and someone having hidden an offer are distinguishable.
$listed = array();
foreach ( (array) get_core_updates( array( "dismissed" => true, "available" => true ) ) as $o ) {
	if ( is_object( $o ) && ! empty( $o->current ) ) {
		$listed[] = $o->current . ( ! empty( $o->dismissed ) ? "(hidden)" : "" );
	}
}

// ORDER MATTERS HERE, and it is not a style choice.
//
// One plugin in this field (Easy Updates Manager) answers all four of these
// filters from a single instance property that whichever filter ran last has
// just overwritten, so auto_update_core returns the verdict for whatever branch
// was asked about most recently rather than for core. Probing the filters in a
// convenient order and then reading auto_update_core reports that plugin
// blocking an update it in fact installs.
//
// So: would_install is computed FIRST, by core own code path, above. Then
// auto_update_core is read while the state that path left behind is still the
// state core would have seen. The allow_* pairs, which are diagnostic rather
// than the answer, are read last.
$auto_core = (int) apply_filters( "auto_update_core", true, (object) array( "current" => $would ) )
	. (int) apply_filters( "auto_update_core", false, (object) array( "current" => $would ) );

// Each policy filter is asked twice, with true and with false, and the pair is
// reported as it comes back. "10" is a filter nothing has touched; "11" is
// forced on and "00" forced off, whatever core would have decided. Asking once
// with a made-up default measures the default, not the plugin.
$allow_minor = (int) apply_filters( "allow_minor_auto_core_updates", true ) . (int) apply_filters( "allow_minor_auto_core_updates", false );
$allow_major = (int) apply_filters( "allow_major_auto_core_updates", true ) . (int) apply_filters( "allow_major_auto_core_updates", false );
$allow_dev   = (int) apply_filters( "allow_dev_auto_core_updates", true ) . (int) apply_filters( "allow_dev_auto_core_updates", false );

printf(
	"%s|%s|%s|%s|%s|%s|%s|%s|%d",
	$running,
	$same_line !== "" ? $same_line : "none",
	$would !== "" ? $would : "none",
	$listed ? implode( ",", $listed ) : "none",
	$allow_minor,
	$allow_major,
	$allow_dev,
	$auto_core,
	// Not wp_is_auto_update_enabled_for_type( "core" ): that helper answers only
	// for "plugin" and "theme" and returns a hard false for every other type,
	// so a core row built on it reads "disabled" on a stock install where the
	// updater is running perfectly. is_disabled() is the switch core actually
	// consults, and it is where a plugin that kills auto-updates wholesale —
	// via AUTOMATIC_UPDATER_DISABLED or the automatic_updater_disabled filter —
	// shows up regardless of what it did to the per-branch policy filters.
	(int) $updater->is_disabled()
);
' --path="$WP" 2>/dev/null | tr -d '\r' | grep '|' | tail -1 )"

IFS='|' read -r T_RUNNING T_SAMELINE T_WOULD T_LISTED P_MINOR P_MAJOR P_DEV P_AUTO P_OFF <<< "$TRUTH"

# --- The plugin's own screens -----------------------------------------------
#
# Discovered, not configured: every #adminmenu href the stock install does not
# have. A plugin that adds no screen scores zero on every reporting row, which is
# the correct answer rather than a harness failure.
# Cleared before the first request of the run — and when this runs under
# probe-plugin.sh, not here at all.
#
# Every plugin in this field caches its API answer in a day-long transient, so
# only the first load that needs it makes the call and every later one reads the
# cache. That first load is not necessarily a screen: Core Rollback fetches
# core/version-check in a constructor that runs on the plugin's own activation,
# which happens in probe-plugin.sh before this script starts. Truncating here
# then reports a plugin that had just called the API as never calling it.
#
# So probe-plugin.sh clears the log before it activates anything and sets
# PROBE_HTTP_LOG_PRESERVE, and a standalone run clears it here.
if [ -z "${PROBE_HTTP_LOG_PRESERVE:-}" ]; then
	: > "$HTTP_LOG"
fi

DASH="$( get "$URL/wp-admin/index.php" )"
# `page=` is the marker, not the file name. A plugin screen can hang off
# index.php, options-general.php, tools.php or a top-level admin.php, and WP
# Auto Updater's hangs off index.php — matching on wp-admin paths alone finds
# core's own menu and misses the plugin entirely, which scores a plugin that
# renders a full settings screen as rendering nothing.
MENU="$( printf '%s' "$DASH" \
	| tr '<' '\n' \
	| grep -o "href='[^']*'" \
	| sed "s/href='//;s/'$//" \
	| sed 's/&amp;/\&/g;s/&#038;/\&/g' \
	| grep -E '(^|/)[a-z-]+\.php\?.*page=|(^|/)admin\.php\?' \
	| grep -v -E 'load-styles\.php|load-scripts\.php|admin-ajax\.php|logout|action=' \
	| sort -u )"

if [ -z "$MENU_BASELINE" ]; then
	# No baseline: this run IS the baseline. Write it and report nothing owned.
	OWN=""
else
	OWN="$( comm -13 "$MENU_BASELINE" <( printf '%s\n' "$MENU" ) )"
fi

BODY=""
SCREENS=0
for path in $OWN; do
	case "$path" in
		http*) u="$path" ;;
		/*)    u="${URL}${path}" ;;
		*)     u="${URL}/wp-admin/${path}" ;;
	esac
	BODY="$BODY
$( get "$u" )"
	SCREENS=$(( SCREENS + 1 ))
done

# The core screens this category touches, fetched for every run including the
# control. options-general.php is in the list because Update Control has no
# screen of its own — it adds a section to Settings > General — and a discovery
# pass keyed on `page=` cannot find a plugin that never adds a menu entry.
# Stock renders nothing about versions on any of these except update-core.php,
# which is exactly why the stock column has to be read alongside every other.
CORE_SCREENS=""
for path in "wp-admin/update-core.php" "wp-admin/site-health.php" "wp-admin/site-health.php?tab=debug" "wp-admin/options-general.php"; do
	CORE_SCREENS="$CORE_SCREENS
$( get "$URL/$path" )"
done

# A screen the discovery pass cannot reach — one behind a tab parameter, or one
# a plugin links to only from its own settings page. This is the single input to
# this harness that is not measured, so it is named per run rather than guessed,
# and every run that uses it says so in the label.
for path in ${PROBE_EXTRA_SCREENS:-}; do
	case "$path" in
		http*) u="$path" ;;
		/*)    u="${URL}${path}" ;;
		*)     u="${URL}/wp-admin/${path}" ;;
	esac
	BODY="$BODY
$( get "$u" )"
	SCREENS=$(( SCREENS + 1 ))
done

# grep -c exits non-zero on no match, so `|| echo 0` would print the count AND a
# zero. Count the lines instead; an absent log file reads as the zero it is.
net_stable=$(  grep -c 'core/stable-check'  "$HTTP_LOG" 2>/dev/null | head -1 )
net_version=$( grep -c 'core/version-check' "$HTTP_LOG" 2>/dev/null | head -1 )
net_stable="${net_stable:-0}"
net_version="${net_version:-0}"

# --- What those screens say -------------------------------------------------
#
# Counted twice, and kept apart, because the two halves cannot be read the same
# way. A plugin's OWN screens are its own words: everything there is attributable
# to it. The core screens are shared — update-core.php names the newest release
# on a stock install with nothing installed at all — so only the markers that
# read zero on the control mean anything there, and those are the three below.
#
# Folding them together is how "does this plugin tell you which release
# WordPress will install" gets answered yes for a plugin that renders nothing:
# core said it, on a screen the plugin happens to share.
# Two things get removed before a word is counted, and both are load-bearing.
#
# Everything outside #wpbody-content is WordPress's furniture — the admin bar,
# the menu with its "5 updates available" bubble, and a footer that reads "Get
# Version 7.1" on every screen in wp-admin. Counting it credits a plugin for
# core's chrome: five of this field's nine plugins scored hits on "the release
# WordPress would install" before this was scoped, on screens that say nothing
# of the kind.
#
# .update-nag is core's "WordPress 7.1 is available!" banner. It is INSIDE
# wpbody-content, it names the newest release, and it is on every admin screen,
# so it has to go by name. The stock column cannot catch either of these: stock
# has no plugin screens to compare against.
strip() {
	python3 -c '
import html, re, sys
s = sys.stdin.read()
s = re.sub(r"<(script|style)\b[^>]*>.*?</\1>", " ", s, flags=re.S | re.I)
s = re.sub(r"<!--.*?-->", " ", s, flags=re.S)

# Keep only the page body of each screen concatenated into this stream.
bodies = re.findall(r"<div[^>]*\bid=[\"\x27]wpbody-content[\"\x27][^>]*>(.*?)<div class=\"clear\">", s, flags=re.S | re.I)
if bodies:
    s = "\n".join(bodies)

s = re.sub(r"<div[^>]*\bclass=[\"\x27][^\"\x27]*\bupdate-nag\b[^\"\x27]*[\"\x27][^>]*>.*?</div>", " ", s, flags=re.S | re.I)
s = re.sub(r"<[^>]+>", " ", s)
sys.stdout.write(re.sub(r"[ \t]+", " ", html.unescape(s)))
'
}

# Reduce each side to what a reader sees before counting anything, with a parser
# rather than a sed expression. Stripping tags alone leaves the CONTENTS of
# <script> and <style> in the text, and wp-admin ships both inline: Twenty
# Twenty-Five's @font-face block alone carries a dozen version-stamped asset
# URLs, every one of which would score as a screen saying something about a
# WordPress release. The control run hides that, because core's own numbers move
# too — which is the kind of error that survives into a published table.
OWN_TEXT="$(  printf '%s' "$BODY"         | strip )"
CORE_TEXT="$( printf '%s' "$CORE_SCREENS" | strip )"

count() { printf '%s' "$2" | grep -o -i -E "$1" | wc -l | tr -d ' '; }

# "security release" is deliberately NOT a marker. Core's own update-core.php
# ships the phrase "maintenance and security releases only" in the auto-update
# toggle, so it scores 1 on a stock install with nothing installed — a marker
# that fires on the control cannot distinguish anything.
INSECURE='insecure|vulnerab|no longer secure|known security'
CONSTANT='WP_AUTO_UPDATE_CORE|AUTOMATIC_UPDATER_DISABLED'
RE_RUNNING="$(  printf '%s' "$T_RUNNING"  | sed 's/\./\\./g' )"
RE_SAMELINE="$( printf '%s' "$T_SAMELINE" | sed 's/\./\\./g' )"
RE_WOULD="$(    printf '%s' "$T_WOULD"    | sed 's/\./\\./g' )"

own_running=$(  count "$RE_RUNNING" "$OWN_TEXT" )
own_sameline=$( [ "$T_SAMELINE" = none ] && echo 0 || count "$RE_SAMELINE" "$OWN_TEXT" )
own_would=$(    [ "$T_WOULD" = none ]    && echo 0 || count "$RE_WOULD"    "$OWN_TEXT" )
own_insecure=$( count "$INSECURE" "$OWN_TEXT" )
own_constant=$( count "$CONSTANT" "$OWN_TEXT" )

core_sameline=$( [ "$T_SAMELINE" = none ] && echo 0 || count "$RE_SAMELINE" "$CORE_TEXT" )
core_insecure=$( count "$INSECURE" "$CORE_TEXT" )
core_constant=$( count "$CONSTANT" "$CORE_TEXT" )

cat <<OUT

=== $LABEL
truth.running_version     $T_RUNNING
truth.same_line_patch     $T_SAMELINE
truth.would_install       $T_WOULD
truth.updates_screen      $T_LISTED
policy.allow_minor        $P_MINOR
policy.allow_major        $P_MAJOR
policy.allow_dev          $P_DEV
policy.auto_update_core   $P_AUTO
policy.updater_disabled   $P_OFF
ui.own_screens            $SCREENS
own.says_running_version  $own_running
own.says_same_line_patch  $own_sameline
own.says_would_install    $own_would
own.says_insecure         $own_insecure
own.says_constant         $own_constant
core.says_same_line_patch $core_sameline
core.says_insecure        $core_insecure
core.says_constant        $core_constant
net.stable_check          $net_stable
net.version_check         $net_version
OUT

if [ -n "${PROBE_DUMP_DIR:-}" ]; then
	mkdir -p "$PROBE_DUMP_DIR"
	printf '%s\n' "$OWN"  > "$PROBE_DUMP_DIR/screens.txt"
	printf '%s'   "$BODY"         > "$PROBE_DUMP_DIR/own.html"
	printf '%s'   "$CORE_SCREENS"  > "$PROBE_DUMP_DIR/core.html"
	printf '%s'   "$OWN_TEXT"      > "$PROBE_DUMP_DIR/own.txt"
	printf '%s'   "$CORE_TEXT"     > "$PROBE_DUMP_DIR/core.txt"
	cp "$HTTP_LOG" "$PROBE_DUMP_DIR/http.log" 2>/dev/null || true
fi
