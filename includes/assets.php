<?php
/**
 * Asset registration: every stylesheet and script Keel adds to a page.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/*
 * =====================================================================
 * ASSETS — the one place a <style> or <script> reaches a page
 * =====================================================================
 *
 * Everything Keel adds to the head goes through wp_enqueue. Nothing echoes a
 * tag. That is a WordPress.org requirement, and it buys three things the old
 * `echo '<style>…'` shape could not: a site can dequeue any of it by handle, a
 * page-caching or asset-concatenating plugin can see it, and `defer`/`async`
 * and the script-loader filters apply.
 *
 * Two kinds of asset, and the distinction is what decides where a rule lives:
 *
 *   1. Static — the CSS and JS are the same on every install. These are real
 *      files under assets/, registered with a src and a version, and the
 *      browser caches them.
 *   2. Computed — the rule depends on stored settings (a logo URL, a menu
 *      width in pixels, the environment colours). These cannot be a file, so
 *      they are added with wp_add_inline_style() against one of the three
 *      no-src handles registered below.
 *
 * The no-src handles exist so inline CSS has something to attach to. A handle
 * registered with `false` as its src prints no <link> of its own and prints
 * nothing at all when no inline data was added to it, so enqueuing all three
 * unconditionally costs an empty array entry and no markup.
 *
 * Registration runs at priority 5 on each of the three enqueue hooks, and each
 * of those callbacks registers its handle and then immediately runs that
 * context's providers, in that order — wp_add_inline_style() against a handle
 * that is not yet registered is silently dropped, so the two cannot be split
 * across hooks or reordered.
 */

/** Shared handle for computed CSS on admin screens. */
const KEEL_DEFAULTS_ADMIN_HANDLE = 'keel-defaults-admin';

/** Shared handle for computed CSS on wp-login.php. */
const KEEL_DEFAULTS_LOGIN_HANDLE = 'keel-defaults-login';

/** Shared handle for computed CSS on the front end. */
const KEEL_DEFAULTS_FRONT_HANDLE = 'keel-defaults-front';

add_action( 'admin_enqueue_scripts', 'keel_defaults_register_admin_style', 5 );
add_action( 'login_enqueue_scripts', 'keel_defaults_register_login_style', 5 );
add_action( 'wp_enqueue_scripts', 'keel_defaults_register_front_style', 5 );

/**
 * Register and enqueue the no-src handle that carries computed admin CSS.
 *
 * @return void
 */
function keel_defaults_register_admin_style() {
	keel_defaults_register_inline_style( KEEL_DEFAULTS_ADMIN_HANDLE );
	keel_defaults_attach_styles( 'admin', KEEL_DEFAULTS_ADMIN_HANDLE );
}

/**
 * Register and enqueue the no-src handle that carries computed login CSS.
 *
 * @return void
 */
function keel_defaults_register_login_style() {
	keel_defaults_register_inline_style( KEEL_DEFAULTS_LOGIN_HANDLE );
	keel_defaults_attach_styles( 'login', KEEL_DEFAULTS_LOGIN_HANDLE );
}

/**
 * Register and enqueue the no-src handle that carries computed front-end CSS.
 *
 * @return void
 */
function keel_defaults_register_front_style() {
	keel_defaults_register_inline_style( KEEL_DEFAULTS_FRONT_HANDLE );
	keel_defaults_attach_styles( 'front', KEEL_DEFAULTS_FRONT_HANDLE );
}

/**
 * Register and enqueue one no-src style handle.
 *
 * @param string $handle Handle to register.
 * @return void
 */
function keel_defaults_register_inline_style( $handle ) {
	wp_register_style( $handle, false, array(), KEEL_DEFAULTS_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version is supplied; the sniff misreads a false src.
	wp_enqueue_style( $handle );
}

/**
 * Register a callback that supplies computed CSS for one context.
 *
 * The counterpart to keel_defaults_add_policy_filter(): bootstrap.php stays a
 * list of one-line `if` blocks, one per default, and this file stays the only
 * place that knows about handles and enqueue hooks. A provider is any callable
 * returning a CSS string; returning '' means "nothing on this request", which
 * is how the per-screen guards inside several of them report a miss.
 *
 * @param string   $context  One of 'admin', 'login', 'front'.
 * @param callable $provider Callable returning a CSS string.
 * @return void
 */
function keel_defaults_add_style( $context, $provider ) {
	keel_defaults_style_providers( $context, $provider );
}

/**
 * The registry behind keel_defaults_add_style().
 *
 * Called with a provider it records one; called with only a context it returns
 * that context's list. One function rather than a global, so nothing outside
 * this file can reach in and rewrite the list.
 *
 * @param string        $context  Context key.
 * @param callable|null $provider Provider to record, or null to read.
 * @return array
 */
function keel_defaults_style_providers( $context, $provider = null ) {
	static $providers = array(
		'admin' => array(),
		'login' => array(),
		'front' => array(),
	);

	if ( ! isset( $providers[ $context ] ) ) {
		return array();
	}

	if ( null !== $provider ) {
		/*
		 * Keyed by name, so registering the same function twice registers it
		 * once. add_action() has always behaved this way for a named callback,
		 * and these registrations used to be add_action() calls — without the
		 * key, a second run of keel_defaults_bootstrap() would emit every rule
		 * twice, which is a difference in behaviour hidden behind the fact that
		 * nothing runs it twice today.
		 *
		 * Closures get an incrementing key instead of being deduplicated:
		 * two closures are never equal, and WordPress does not deduplicate them
		 * either — matching that is more useful than inventing a rule here.
		 */
		$key = is_string( $provider ) ? $provider : count( $providers[ $context ] );

		$providers[ $context ][ $key ] = $provider;
	}

	return $providers[ $context ];
}

/**
 * Run one context's providers and attach whatever they return.
 *
 * @param string $context Context key.
 * @param string $handle  Handle to attach the result to.
 * @return void
 */
function keel_defaults_attach_styles( $context, $handle ) {
	foreach ( keel_defaults_style_providers( $context ) as $provider ) {
		if ( ! is_callable( $provider ) ) {
			continue;
		}

		$css = trim( (string) call_user_func( $provider ) );

		if ( '' === $css ) {
			continue;
		}

		wp_add_inline_style( $handle, $css );
	}
}

/**
 * The URL of a file in assets/.
 *
 * @param string $path Path relative to assets/, e.g. 'css/settings.css'.
 * @return string
 */
function keel_defaults_asset_url( $path ) {
	return plugins_url( 'assets/' . ltrim( $path, '/' ), KEEL_DEFAULTS_FILE );
}

/*
 * ---------------------------------------------------------------------
 * The two settings screens
 * ---------------------------------------------------------------------
 *
 * These are static files rather than inline strings: the CSS and JS are
 * identical on every install, so they belong in a file the browser can cache
 * and a site can dequeue, override, or defer by handle.
 *
 * Enqueued only on the screens that use them, registered from inside
 * `load-{$hook}` so the screen match is WordPress's own rather than a string
 * comparison here that would need updating if either menu slug moved.
 */

/** Handle for the settings-screen stylesheet. */
const KEEL_DEFAULTS_SETTINGS_STYLE = 'keel-defaults-settings';

/** Handle for the settings-screen script. */
const KEEL_DEFAULTS_SETTINGS_SCRIPT = 'keel-defaults-settings';

/** Handle for the shared locked-control script. */
const KEEL_DEFAULTS_LOCKED_SCRIPT = 'keel-defaults-locked-controls';

/**
 * Arrange for a screen's assets to be enqueued when that screen loads.
 *
 * @param string $hook     Screen hook suffix from add_*_page().
 * @param string $callback Enqueue callback.
 * @return void
 */
function keel_defaults_enqueue_on_screen( $hook, $callback ) {
	if ( ! $hook ) {
		return;
	}

	add_action(
		'load-' . $hook,
		static function () use ( $callback ) {
			add_action( 'admin_enqueue_scripts', $callback );
		}
	);
}

/**
 * Enqueue the per-site settings screen's stylesheet and script.
 *
 * @return void
 */
function keel_defaults_enqueue_settings_assets() {
	wp_enqueue_style(
		KEEL_DEFAULTS_SETTINGS_STYLE,
		keel_defaults_asset_url( 'css/settings.css' ),
		array(),
		KEEL_DEFAULTS_VERSION
	);

	// In the footer, and with no dependencies: it reads the DOM it is bound to
	// and nothing else, so it needs neither jQuery nor a DOMContentLoaded wrapper.
	wp_enqueue_script(
		KEEL_DEFAULTS_SETTINGS_SCRIPT,
		keel_defaults_asset_url( 'js/settings.js' ),
		array(),
		KEEL_DEFAULTS_VERSION,
		true
	);

	keel_defaults_enqueue_locked_controls();
}

/**
 * Enqueue the network policy screen's assets.
 *
 * The locked-control script and nothing else. The network screen is
 * deliberately simpler than the per-site one — no dependent-row hiding, no
 * range slider, no setting anchors — so every rule in settings.css and every
 * line of settings.js would be inert there, and a screen that loads assets it
 * cannot use is how a stylesheet ends up being "needed" by accident later.
 *
 * @return void
 */
function keel_defaults_enqueue_network_assets() {
	keel_defaults_enqueue_locked_controls();
}

/**
 * Enqueue the script that refuses changes to a locked control.
 *
 * @return void
 */
function keel_defaults_enqueue_locked_controls() {
	wp_enqueue_script(
		KEEL_DEFAULTS_LOCKED_SCRIPT,
		keel_defaults_asset_url( 'js/locked-controls.js' ),
		array(),
		KEEL_DEFAULTS_VERSION,
		true
	);
}
