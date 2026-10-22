<?php
/**
 * Which other active plugins are contesting the same settings.
 *
 * Two plugins registered on the same policy hook do not error or log. Their
 * callbacks may agree, may apply only in different contexts, or may produce a
 * different final value. The registry proves shared participation, not which
 * configured outcome WordPress will use. Nothing here invokes those callbacks;
 * this only makes the structural overlap visible.
 *
 * Detection lives in its own file because it has two consumers now: the Site
 * Health report in site-health.php, and the admin notices in admin-ux.php. It
 * belongs to neither.
 *
 * The hook map is the only part needing human judgement, and it stays short on
 * purpose. A check that fires on every well-behaved plugin is one people learn
 * to ignore, so a hook earns an entry only when contention on it has a
 * consequence somebody can act on. A hook Keel touches but nobody can lose on
 * is deliberately absent rather than marked harmless.
 *
 * @package Keel
 */

// Bail if called directly.
defined( 'ABSPATH' ) || exit;

/**
 * Marker for a callback no plugin can be attributed to.
 *
 * A token, not a sentence. This used to be the translated string "Unattributed
 * callback", pushed into the same array that otherwise holds plugin directory
 * names — so Site Health rendered "comments_open — callbacks from Unattributed
 * callback", which reads as the name of a plugin, and several such rows read as
 * several plugins. Reported as exactly that: the notice named one plugin while
 * Site Health appeared to name five others.
 *
 * Keeping the marker out of the display layer is what fixes it. The list stays a
 * list of plugin names, this says "and something here has no name", and the
 * wording is decided once, where the rendering is.
 *
 * Prefixed so it cannot collide with a real plugin directory.
 */
const KEEL_DEFAULTS_UNATTRIBUTED = '__keel_unattributed__';

/**
 * Prefix marking a report entry as a theme. What follows is its directory name.
 *
 * A theme is reported exactly as a plugin is, notice included: somebody chose
 * it, and its settings or a child theme can change what it does.
 */
const KEEL_DEFAULTS_THEME_PREFIX = 'theme:';

/**
 * Prefix marking a report entry as a must-use plugin rather than a plugin
 * directory. What follows it is the file name in the must-use directory.
 *
 * Must-use code is kept apart from ordinary plugins because nobody can switch it
 * off from the Plugins screen: a host or a developer put it there on purpose.
 * It is named in Site Health and never raises the notice.
 */
const KEEL_DEFAULTS_MU_PLUGIN_PREFIX = 'mu-plugin:';

/*
 * Compatibility entry point. The modules only declare callbacks: attribution
 * identifies code without invoking it; overlap classifies shared hooks;
 * divergence observes WordPress's real filter runs. Notices consume both reports.
 * Keep modules in this directory: callback provenance includes their real paths.
 */
require_once __DIR__ . '/conflict-attribution.php';
require_once __DIR__ . '/conflict-overlap.php';
require_once __DIR__ . '/conflict-divergence.php';
require_once __DIR__ . '/conflict-notices.php';

require_once __DIR__ . '/registration-audit.php';
require_once __DIR__ . '/abilities-authorization.php';
