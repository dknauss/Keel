#!/usr/bin/env bash
#
# Install, configure, probe and deactivate one plugin.
#
# probe-teardown.sh measures whatever the site currently does. This wraps it with
# the other half of a comparison: getting the plugin into the state its own
# settings screen would produce. A plugin measured with default settings is a
# plugin measured switched off, and the resulting numbers look like a damning
# finding rather than an untouched checkbox.
#
# Usage:
#   PROBE_URL=http://127.0.0.1:9314 PROBE_PATH=/path/to/wp \
#     bash tests/integration/probe-plugin.sh disable-comments "disable-comments 2.8.0 (1M)"
#
# The plugin must already be in wp-content/plugins. Configuration is optional:
# probe-configs/<slug>.php is applied when it exists, and plugins with nothing to
# configure (Disable XML-RPC, Disable WP REST API, Disable Blog) simply have no
# file.
#
# PROBE_SCRIPT selects which probe runs; the default is probe-teardown.sh. The
# update-policy comparison uses probe-updates.sh with its own config directory:
#
#   PROBE_CONFIG_DIR=tests/integration/probe-configs-updates \
#   PROBE_SCRIPT=probe-updates.sh \
#     bash tests/integration/probe-plugin.sh update-control "Update Control 1.5.1"

set -u

URL="${PROBE_URL:-}"
WP="${PROBE_PATH:-}"
SLUG="${1:-}"
LABEL="${2:-$SLUG}"
HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

if [ -z "$URL" ] || [ -z "$WP" ] || [ -z "$SLUG" ]; then
	echo "probe-plugin: usage: PROBE_URL=… PROBE_PATH=… $0 <plugin-slug> [label]" >&2
	exit 2
fi

# Configs may live outside this repository.
#
# Pixel Managed Platform is private and Keel is public, so its settings do not
# belong in probe-configs/ here. PROBE_CONFIG_DIR points at a directory holding
# <slug>.php files kept alongside that plugin instead; it takes precedence, and
# the bundled config is the fallback. That keeps the harness able to measure all
# three siblings without either repository carrying the other's internals.
CONFIG="$HERE/probe-configs/${SLUG}.php"

if [ -n "${PROBE_CONFIG_DIR:-}" ] && [ -f "${PROBE_CONFIG_DIR}/${SLUG}.php" ]; then
	CONFIG="${PROBE_CONFIG_DIR}/${SLUG}.php"
fi

# Clear the outbound-request log before anything loads the plugin.
#
# probe-updates.sh counts requests to api.wordpress.org to tell "reports the
# security status of this release" from "reports that an update exists". Those
# answers are cached in day-long transients, so only the first load that needs
# one makes the call — and for a plugin that fetches in a constructor, that load
# is the activation below, not any screen. Clearing the log after activation
# reports such a plugin as making no request at all.
if [ -n "${PROBE_HTTP_LOG:-}" ]; then
	: > "$PROBE_HTTP_LOG"
elif [ -f "$WP/wp-content/probe-http.log" ]; then
	: > "$WP/wp-content/probe-http.log"
fi
export PROBE_HTTP_LOG_PRESERVE=1

# Configuration runs BEFORE activation, with --skip-plugins.
#
# Both halves matter, and the reason is not tidiness. A teardown plugin changes
# the state its own configuration is derived from: Disable Comments RB stores a
# snapshot of every post type that supports comments, and if it is already active
# from a previous run it has removed that support before the config file can read
# it. The config then stores an empty list, the plugin does nothing, and the
# probe reports it closing nothing — confidently, and wrongly.
#
# That is not a hypothetical. It is what happened re-verifying the published
# matrix: seven differences, all in one column, all the harness's fault.
if [ -f "$CONFIG" ]; then
	if ! wp eval-file "$CONFIG" --path="$WP" --skip-plugins --skip-themes; then
		echo "probe-plugin: configuring $SLUG failed; not probing a plugin in an unknown state." >&2
		exit 1
	fi
	echo
fi

wp plugin activate "$SLUG" --path="$WP" >/dev/null 2>&1 || {
	echo "probe-plugin: could not activate $SLUG" >&2
	exit 1
}

# A second, optional config that runs AFTER activation.
#
# The pre-activation ordering above is right for everything that stores settings
# in options, and impossible for a plugin whose storage does not exist until it
# is active. Companion Auto Update keeps its settings in a table of its own and
# DROPS that table in its deactivation hook — so between runs there is nothing to
# write to, and a pre-activation config can only ever report it missing.
#
# Kept as a separate file rather than a flag on the first one, because the two
# run at times when different things are true, and a config that could be either
# invites being written for the wrong one.
POST="${CONFIG%.php}.post.php"

if [ -f "$POST" ]; then
	if ! wp eval-file "$POST" --path="$WP"; then
		echo "probe-plugin: post-activation configuration of $SLUG failed; not probing a plugin in an unknown state." >&2
		wp plugin deactivate "$SLUG" --path="$WP" >/dev/null 2>&1
		exit 1
	fi
	echo
fi

# Which question this run is asking. probe-teardown.sh is the default because it
# is what every existing caller means; the update-policy field is measured by
# probe-updates.sh instead, which asks a different set of questions of a
# different set of plugins. The activate/configure/deactivate ordering below is
# the same either way, and it is the part worth not duplicating.
PROBE_URL="$URL" PROBE_PATH="$WP" bash "$HERE/${PROBE_SCRIPT:-probe-teardown.sh}" "$LABEL"

wp plugin deactivate "$SLUG" --path="$WP" >/dev/null 2>&1
