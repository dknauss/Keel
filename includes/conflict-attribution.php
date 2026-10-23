<?php
/**
 * Callback reflection, plugin/theme/must-use identity, and display names.
 *
 * Loaded by conflicts.php. Bootstrap retains registration and timing.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve a registered callback to the plugin directory it lives in.
 *
 * Reflection is what makes this general: it answers "which file is this code
 * in" for any callable, so a plugin nobody has heard of is attributed exactly
 * like a known one. No list of rival plugins to maintain — such a list only
 * ever knows yesterday's plugins.
 *
 * @param mixed $callback Registered callback.
 * @return string Plugin directory name, or '' when it cannot be attributed.
 */
function keel_defaults_callback_plugin_dir( $callback ) {
	$file = keel_defaults_callback_file( $callback );

	if ( '' === $file || ! defined( 'WP_PLUGIN_DIR' ) ) {
		return '';
	}

	$real_file = realpath( $file );
	$file      = false !== $real_file ? $real_file : $file;
	$file      = wp_normalize_path( $file );

	foreach ( keel_defaults_active_plugin_roots() as $slug => $root ) {
		if ( $file === $root || 0 === strpos( $file, trailingslashit( $root ) ) ) {
			return $slug;
		}
	}

	// Runtime attribution must still work during activation and in test
	// harnesses whose active-plugin option is intentionally incomplete.
	$real_plugin_dir = realpath( WP_PLUGIN_DIR );
	$base            = trailingslashit( wp_normalize_path( false !== $real_plugin_dir ? $real_plugin_dir : WP_PLUGIN_DIR ) );
	if ( 0 !== strpos( $file, $base ) ) {
		return '';
	}

	$parts = explode( '/', substr( $file, strlen( $base ) ) );

	return $parts[0];
}

/**
 * Resolve a registered callback to the active theme, or its parent.
 *
 * Only those two can have loaded code on this request, so there is no need to
 * know where every installed theme lives.
 *
 * @param mixed $callback Registered callback.
 * @return string Theme directory name, or '' when the callback is not a theme's.
 */
function keel_defaults_callback_theme( $callback ) {
	$file = keel_defaults_callback_file( $callback );

	if ( '' === $file || ! function_exists( 'get_stylesheet_directory' ) || ! function_exists( 'get_template_directory' ) ) {
		return '';
	}

	$real = realpath( $file );
	$file = wp_normalize_path( false !== $real ? $real : $file );

	// The child first: it is the more specific owner of a file under both.
	foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $dir ) {
		$real_dir = realpath( $dir );
		$root     = trailingslashit( wp_normalize_path( false !== $real_dir ? $real_dir : $dir ) );

		if ( '/' !== $root && 0 === strpos( $file, $root ) ) {
			return basename( $dir );
		}
	}

	return '';
}

/**
 * A theme's name as the Themes screen shows it, from its directory.
 *
 * @param string $slug Theme directory name.
 * @return string
 */
function keel_defaults_theme_name( $slug ) {
	$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme( $slug ) : null;
	$name  = is_object( $theme ) && $theme->exists() ? (string) $theme->get( 'Name' ) : '';

	return '' !== $name ? $name : (string) $slug;
}

/**
 * Where WordPress itself lives, by both the configured and the real path.
 *
 * Reflection reports the real path of a file. Where ABSPATH is a symlink, as it
 * is on some managed hosts, comparing against ABSPATH alone finds no core
 * callback at all, and every one of them reads as code nobody can trace.
 *
 * @return string[] Normalized directories, each with a trailing slash.
 */
function keel_defaults_core_roots() {
	static $roots = null;

	if ( null !== $roots ) {
		return $roots;
	}

	$roots = array();
	$real  = realpath( ABSPATH );

	foreach ( array_unique( array_filter( array( ABSPATH, $real ) ) ) as $core ) {
		$core = trailingslashit( wp_normalize_path( $core ) );

		if ( defined( 'WPINC' ) ) {
			$roots[] = trailingslashit( $core . WPINC );
		}
		$roots[] = $core . 'wp-admin/';
	}

	return $roots;
}

/**
 * Whether a file is inside one of several directories.
 *
 * @param string   $file  Normalized file path.
 * @param string[] $roots Normalized directories.
 * @return bool
 */
function keel_defaults_path_is_under( $file, array $roots ) {
	foreach ( $roots as $root ) {
		if ( 0 === strpos( $file, trailingslashit( $root ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Every file a must-use plugin is known to have loaded, and which one loaded it.
 *
 * A must-use plugin is often only a loader. Its entry in the must-use directory
 * can be a symlink to a file somewhere else, and that file can include any
 * number of others from directories that have nothing to do with wp-content, so
 * no path prefix identifies them.
 *
 * The order PHP included files in does. WordPress includes each must-use plugin
 * in turn and then `wp-includes/vars.php`, so a file included after one must-use
 * plugin and before the next, or before `vars.php`, was included by that plugin.
 * A run of files with no such end is discarded: where the boundary cannot be
 * seen, saying "cannot be traced" is better than naming the wrong owner. Code a
 * must-use plugin loads later, from a hook or an autoloader, is not found this
 * way; keel_defaults_mu_plugin_package_dirs() covers what it can of that. On
 * multisite a network-active plugin loads inside the last run; its own files are
 * recognized as a plugin's, but a file it includes from outside its directory
 * would be read as the last must-use plugin's.
 *
 * Only reads the list of included files. Nothing is loaded or called.
 *
 * @return array<string,string> Normalized file path => must-use file name.
 */
function keel_defaults_mu_plugin_files() {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	$map = array();

	if ( ! function_exists( 'wp_get_mu_plugins' ) ) {
		return $map;
	}

	foreach ( (array) wp_get_mu_plugins() as $mu_plugin ) {
		$real = realpath( $mu_plugin );
		$map[ wp_normalize_path( false !== $real ? $real : $mu_plugin ) ] = basename( $mu_plugin );
	}

	$mains   = $map;
	$core    = keel_defaults_core_roots();
	$plugins = array_merge( array_values( keel_defaults_active_plugin_roots() ), array( wp_normalize_path( dirname( __DIR__ ) ) ) );
	$owner   = '';
	$pending = array();

	foreach ( get_included_files() as $file ) {
		$file = wp_normalize_path( $file );

		if ( isset( $mains[ $file ] ) ) {
			$map    += $pending;
			$pending = array();
			$owner   = $mains[ $file ];
			continue;
		}

		if ( '' === $owner ) {
			continue;
		}

		if ( keel_defaults_path_is_under( $file, $core ) ) {
			if ( defined( 'WPINC' ) && 'vars.php' === basename( $file ) && WPINC === basename( dirname( $file ) ) ) {
				$map += $pending;
				break;
			}
			continue;
		}

		// A must-use loader can pull files out of an active plugin, as a shared
		// autoloader does, and can load Keel itself. Those stay the plugin's,
		// and the loader's run carries on past them.
		if ( keel_defaults_path_is_under( $file, $plugins ) || in_array( $file, $plugins, true ) ) {
			continue;
		}

		$pending[ $file ] = $owner;
	}

	return $map;
}

/**
 * The directories symlinked must-use plugins really live in.
 *
 * A loader that is a symlink points into its own package, and that package goes
 * on loading files long after the must-use phase is over: a feature loaded on a
 * later hook, a class found by an autoloader. The include order says nothing
 * about those, but they sit beside the loader.
 *
 * Only a symlink that leaves the must-use directory counts, and never one that
 * lands in a directory holding WordPress, wp-content or the plugins directory:
 * a loader linked from somewhere that broad would claim everything on the site.
 *
 * @return array<string,string> Normalized real directory => must-use file name.
 */
function keel_defaults_mu_plugin_package_dirs() {
	static $dirs = null;

	if ( null !== $dirs ) {
		return $dirs;
	}

	$dirs = array();

	if ( ! function_exists( 'wp_get_mu_plugins' ) || ! defined( 'WPMU_PLUGIN_DIR' ) ) {
		return $dirs;
	}

	$guarded = array();
	foreach ( array( 'ABSPATH', 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $constant ) {
		if ( defined( $constant ) ) {
			$real      = realpath( constant( $constant ) );
			$guarded[] = trailingslashit( wp_normalize_path( false !== $real ? $real : constant( $constant ) ) );
		}
	}

	foreach ( (array) wp_get_mu_plugins() as $mu_plugin ) {
		$real = realpath( $mu_plugin );

		if ( false === $real || ! is_link( $mu_plugin ) ) {
			continue;
		}

		$dir = trailingslashit( wp_normalize_path( dirname( $real ) ) );

		foreach ( $guarded as $guard ) {
			if ( 0 === strpos( $guard, $dir ) ) {
				continue 2;
			}
		}

		$dirs[ $dir ] = basename( $mu_plugin );
	}

	return $dirs;
}

/**
 * Resolve a registered callback to the must-use plugin that loaded it.
 *
 * @param mixed $callback Registered callback.
 * @return string File or directory name in the must-use directory, or ''.
 */
function keel_defaults_callback_mu_plugin( $callback ) {
	$file = keel_defaults_callback_file( $callback );

	if ( '' === $file ) {
		return '';
	}

	$real = realpath( $file );
	$real = wp_normalize_path( false !== $real ? $real : $file );

	// Keel can be installed as a must-use plugin. It is still not its own rival.
	if ( keel_defaults_path_is_under( $real, array( wp_normalize_path( dirname( __DIR__ ) ) ) ) ) {
		return '';
	}

	$files = keel_defaults_mu_plugin_files();

	foreach ( array( $real, wp_normalize_path( $file ) ) as $path ) {
		if ( isset( $files[ $path ] ) ) {
			return $files[ $path ];
		}
	}

	// Loaded later than the must-use phase, from a symlinked loader's own package.
	foreach ( keel_defaults_mu_plugin_package_dirs() as $dir => $mu_plugin ) {
		if ( 0 === strpos( $real, $dir ) ) {
			return $mu_plugin;
		}
	}

	if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
		return '';
	}

	// Or from the must-use directory itself.
	$real_dir = realpath( WPMU_PLUGIN_DIR );
	$base     = trailingslashit( wp_normalize_path( false !== $real_dir ? $real_dir : WPMU_PLUGIN_DIR ) );
	if ( 0 !== strpos( $real, $base ) ) {
		return '';
	}

	return (string) strtok( substr( $real, strlen( $base ) ), '/' );
}

/**
 * Resolve loaded callable forms to their source file without autoloading code.
 *
 * @param mixed $callback Callback.
 * @return string Source file, or an empty string when unavailable.
 */
function keel_defaults_callback_file( $callback ) {
	$file = '';

	try {
		if ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
			$reflection = new ReflectionFunction( $callback );
			$file       = (string) $reflection->getFileName();
		} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
			list( $class, $method ) = explode( '::', $callback, 2 );
			if ( ! class_exists( $class, false ) ) {
				return '';
			}
			$reflection = new ReflectionMethod( $class, $method );
			$file       = (string) $reflection->getFileName();
		} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			if ( ! class_exists( $class, false ) ) {
				return '';
			}
			$reflection = new ReflectionMethod( $class, (string) $callback[1] );
			$file       = (string) $reflection->getFileName();
		} elseif ( is_object( $callback ) && is_callable( $callback ) ) {
			$reflection = new ReflectionMethod( $callback, '__invoke' );
			$file       = (string) $reflection->getFileName();
		}
	} catch ( Throwable $e ) {
		return '';
	}

	return $file;
}

/**
 * Canonical roots for active, network-active, symlinked, and single-file plugins.
 *
 * @return array<string,string> Display slug => normalized real path.
 */
function keel_defaults_active_plugin_roots() {
	$plugins = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() && function_exists( 'get_site_option' ) ) {
		$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	// Read the current option values each time: activation, network policy and
	// switch_to_blog() can change them within a request. Only filesystem
	// resolution is memoized, and only for the most recently seen plugin list.
	static $resolved_plugins = null;
	static $roots            = array();
	$plugins                 = array_values( array_unique( array_map( 'strval', $plugins ) ) );
	if ( $plugins === $resolved_plugins ) {
		return $roots;
	}
	$resolved_plugins = $plugins;
	$roots            = array();
	foreach ( $plugins as $plugin ) {
		$absolute       = trailingslashit( WP_PLUGIN_DIR ) . ltrim( $plugin, '/' );
		$slug           = false !== strpos( $plugin, '/' ) ? strtok( $plugin, '/' ) : $plugin;
		$root           = false !== strpos( $plugin, '/' ) ? dirname( $absolute ) : $absolute;
		$real_root      = realpath( $root );
		$root           = false !== $real_root ? $real_root : $root;
		$roots[ $slug ] = wp_normalize_path( $root );
	}

	return $roots;
}

/**
 * A plugin's name as the Plugins screen shows it, from its directory.
 *
 * The overlap report works in directory names because that is what a callback's
 * file resolves to, and the fingerprints stay keyed on them. People reading the
 * report know "Yoast SEO", not "wordpress-seo", so names are swapped in only
 * when something is printed. Falls back to the directory when the plugin
 * header cannot be read.
 *
 * @param string $slug Plugin directory, or a single-file plugin's file name.
 * @return string
 */
function keel_defaults_plugin_name( $slug ) {
	static $installed = null;

	if ( 0 === strpos( (string) $slug, KEEL_DEFAULTS_THEME_PREFIX ) ) {
		/* translators: %s: theme name, such as Twenty Twenty-Five. */
		return sprintf( __( '%s (theme)', 'keel-defaults' ), keel_defaults_theme_name( substr( $slug, strlen( KEEL_DEFAULTS_THEME_PREFIX ) ) ) );
	}

	if ( 0 === strpos( (string) $slug, KEEL_DEFAULTS_MU_PLUGIN_PREFIX ) ) {
		/* translators: %s: file name of a must-use plugin, such as host-loader.php. */
		return sprintf( __( 'must-use plugin %s', 'keel-defaults' ), substr( $slug, strlen( KEEL_DEFAULTS_MU_PLUGIN_PREFIX ) ) );
	}

	if ( null === $installed ) {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
	}

	foreach ( $installed as $file => $data ) {
		if ( ( $file === $slug || 0 === strpos( $file, $slug . '/' ) ) && ! empty( $data['Name'] ) ) {
			return (string) $data['Name'];
		}
	}

	return (string) $slug;
}

/**
 * Keel's installed plugin identity, with a source-directory fallback.
 *
 * Resolved from this function's file rather than from KEEL_DEFAULTS_FILE. The constant is
 * defined by the bootstrap and not by anything that loads this file on its own,
 * and a guard that quietly does nothing when a constant is missing is worse than
 * no guard: it reads as protection. This file is inside the plugin by
 * construction, in every context that can call the function at all.
 *
 * @return string Installed slug, or source-directory basename when unmapped.
 */
function keel_defaults_own_plugin_dir() {
	// Reflection resolves symlinks. Use the active-plugin map to recover the
	// installed slug, which may differ from the source checkout's basename.
	$installed = keel_defaults_callback_plugin_dir( __FUNCTION__ );
	if ( '' !== $installed ) {
		return $installed;
	}

	// During activation or in partial test registries, keep the path fallback.
	return basename( dirname( dirname( wp_normalize_path( __FILE__ ) ) ) );
}

/**
 * Stable identity for a callable within one request.
 *
 * @param mixed $callback Callback.
 * @return string
 */
function keel_defaults_callable_id( $callback ) {
	if ( is_string( $callback ) ) {
		return 'string:' . strtolower( $callback );
	}
	if ( $callback instanceof Closure ) {
		return 'closure:' . spl_object_hash( $callback );
	}
	if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
		$owner = is_object( $callback[0] ) ? 'object:' . spl_object_hash( $callback[0] ) : 'class:' . strtolower( (string) $callback[0] );
		return $owner . '::' . strtolower( (string) $callback[1] );
	}
	if ( is_object( $callback ) ) {
		return 'invokable:' . spl_object_hash( $callback );
	}

	return '';
}
