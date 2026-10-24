#!/usr/bin/env bash
#
# Put a comment-disabling plugin next to one that reopens comments, and ask both
# the site and the plugin what happened.
#
# Every other probe here measures one plugin alone. Real sites are not like
# that: a forum, review, membership or page-builder plugin that answers
# comments_open later than the comment plugin does wins, and the site serves a
# comment form the administrator switched off. This harness measures two things
# about that situation, and they are different questions:
#
#   1. what the site does — is the form rendered, and does a submitted comment
#      land in the database (counted with plugins skipped, so nothing can hide
#      it);
#   2. what the comment plugin's own diagnostics say about it, read through the
#      plugin's own functions rather than scraped from a screen.
#
# Only the plugins below have a reader in (2), because each plugin reports in
# its own way; add one by adding a case.
#
# Usage:
#   PROBE_URL=http://127.0.0.1:9400 PROBE_PATH=/path/to/wp \
#     bash tests/integration/probe-conflict.sh <plugin-slug> <reopener-slug|none>
#
# The reopeners are fixtures/lab-comment-reopener (core's __return_true, which
# nothing can attribute) and fixtures/lab-comment-reopener-named (a callback in
# the fixture's own file). Copy them into wp-content/plugins/ first. The script
# deactivates every plugin before and after it runs, so point it at a lab only.

set -u

URL="${PROBE_URL:-}"
WP="${PROBE_PATH:-}"
SLUG="${1:-}"
REOPENER="${2:-none}"
HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
CONFIGS="${PROBE_CONFIG_DIR:-$HERE/probe-configs}"

if [ -z "$URL" ] || [ -z "$WP" ] || [ -z "$SLUG" ]; then
	echo "probe-conflict: usage: PROBE_URL=… PROBE_PATH=… $0 <plugin-slug> <reopener-slug|none>" >&2
	exit 2
fi

wp plugin deactivate --all --path="$WP" >/dev/null 2>&1
# Keel records a divergence when it sees one; start without an old record.
wp eval 'delete_transient( "keel_policy_divergence" );' --path="$WP" --skip-plugins >/dev/null 2>&1

[ -f "$CONFIGS/$SLUG.php" ] && wp eval-file "$CONFIGS/$SLUG.php" --path="$WP" --skip-plugins --skip-themes >/dev/null 2>&1
wp plugin activate "$SLUG" --path="$WP" >/dev/null 2>&1 || { echo "probe-conflict: could not activate $SLUG" >&2; exit 1; }
[ -f "$CONFIGS/$SLUG.post.php" ] && wp eval-file "$CONFIGS/$SLUG.post.php" --path="$WP" >/dev/null 2>&1
if [ "$REOPENER" != none ]; then
	wp plugin activate "$REOPENER" --path="$WP" >/dev/null 2>&1 || { echo "probe-conflict: could not activate $REOPENER" >&2; exit 1; }
fi

PERMA="$( wp eval 'echo get_permalink( 1 );' --path="$WP" 2>/dev/null | tr -d '\r' | tail -1 )"

# Ordinary traffic: a visitor reads the post and submits a comment. The page
# view is also what lets a plugin that observes its own result notice.
FORM="$( curl -s "$PERMA" | grep -c 'id="commentform"\|wp-block-post-comments-form' )"
curl -s -o /dev/null -X POST \
	--data 'comment_post_ID=1&author=ConflictProbe&email=conflict@example.invalid&comment=conflict-probe-body' \
	"$URL/wp-comments-post.php"
LANDED="$( wp eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_content = \"conflict-probe-body\"" );' --path="$WP" --skip-plugins --skip-themes 2>/dev/null | tail -1 )"
wp eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_content = \"conflict-probe-body\"" );' --path="$WP" --skip-plugins --skip-themes >/dev/null 2>&1

echo "=== $SLUG + $REOPENER"
echo "site.comment_form         $FORM"
echo "site.comment_landed       $LANDED"

case "$SLUG" in
	keel)
		# The Site Health test an administrator sees, and the lines of it that
		# name a hook or a verdict.
		wp eval '
		wp_set_current_user( 1 );
		$r = keel_defaults_site_health_conflicts();
		echo "says.status               ", $r["status"], "\n";
		echo "says.label                ", $r["label"], "\n";
		$text = wp_strip_all_tags( str_replace( array( "</li>", "</p>", "</h3>", "</h4>" ), "\n", $r["description"] ) );
		foreach ( explode( "\n", $text ) as $line ) {
			if ( preg_match( "/comments_open|pings_open|Not taking effect|last seen|cannot say/i", $line ) ) {
				echo "says.line                 ", trim( html_entity_decode( $line, ENT_QUOTES ) ), "\n";
			}
		}' --path="$WP" 2>/dev/null | grep '^says\.'
		;;
	disable-comments)
		# Its Site Health Info section (2.9.0+ also reports blocked attempts)
		# and the verdict of its own theme-conflict scanner for the same post.
		wp eval '
		wp_set_current_user( 1 );
		foreach ( apply_filters( "debug_information", array() ) as $key => $section ) {
			if ( false === stripos( $key, "disable" ) ) { continue; }
			foreach ( $section["fields"] as $field ) {
				if ( preg_match( "/Comment Status|Global Disable|XML-RPC|REST API/", $field["label"] ) ) {
					echo "says.info                 ", $field["label"], ": ", $field["value"], "\n";
				}
			}
		}
		$dc   = Disable_Comments::get_instance();
		$scan = $dc->run_theme_scan( get_permalink( 1 ) );
		if ( is_wp_error( $scan ) ) {
			echo "says.scan                 error: ", $scan->get_error_message(), "\n";
		} else {
			$v = $dc->interpret_theme_scan( $scan );
			echo "says.scan                 [", $v["verdict"], "] ", $v["message"], "\n";
		}' --path="$WP" 2>/dev/null | grep '^says\.'
		;;
esac

wp plugin deactivate --all --path="$WP" >/dev/null 2>&1
