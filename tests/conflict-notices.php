<?php
/**
 * The admin notice that says another plugin is contesting the same settings.
 *
 * Site Health is where the detail lives, and nobody opens Site Health until
 * something has already gone wrong. Activating a competing plugin is the moment
 * the problem is created and the moment somebody can act on it, so the notice
 * has to be where they land — the plugins screen — and where they would go to
 * do something about it, which is Keel's own settings screen.
 *
 * Those two are not dismissible. They are the screens where the notice is the
 * point rather than an interruption, and a dismissal there would only hide the
 * thing the visit was about.
 *
 * The dashboard is different: it is daily-driver space and an undismissable
 * banner there is an obstruction. So it is dismissible, and the dismissal
 * records a fingerprint of the conflicts rather than a boolean — dismissing
 * says "I have seen these", not "never tell me again". Activate another
 * competing plugin and the fingerprint no longer matches, so it returns.
 *
 * Run: php tests/conflict-notices.php
 *
 * @package keel
 */

$GLOBALS['keel_filters']    = array();
$GLOBALS['keel_options']    = array();
$GLOBALS['wp_filter']       = array();
$GLOBALS['keel_transients'] = array();
$GLOBALS['keel_user_meta']  = array();
$GLOBALS['keel_screen']     = '';

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function _n( $single, $plural, $number, $d = null ) {
	return 1 === (int) $number ? $single : $plural; }
function __( $s, $d = null ) {
	return $s; }
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html_e( $s, $d = null ) {
	echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) {
	return (string) $s; }
function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['wp_filter'][ $hook ] ) && method_exists( $GLOBALS['wp_filter'][ $hook ], 'apply_filters' ) ) {
		return $GLOBALS['wp_filter'][ $hook ]->apply_filters( $value, array_merge( array( $value ), $args ) );
	}
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value; }
// This fixture mirrors WordPress's core helper exactly.
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore
function __return_false() { return false; }
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $k ] : $d; }
function is_multisite() {
	return false; }
function current_user_can( $cap ) {
	return true; }
function get_current_user_id() {
	return 1; }
function get_user_meta( $user_id, $key, $single = false ) {
	$k = $user_id . ':' . $key;
	return isset( $GLOBALS['keel_user_meta'][ $k ] ) ? $GLOBALS['keel_user_meta'][ $k ] : ''; }
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['keel_user_meta'][ $user_id . ':' . $key ] = $value;
	return true; }
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['keel_user_meta'][ $user_id . ':' . $key ] );
	return true; }
function admin_url( $p = '' ) {
	return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $name . '=testnonce'; }
function wp_unslash( $v ) {
	return $v; }
function sanitize_key( $v ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function get_transient( $k ) {
	return isset( $GLOBALS['keel_transients'][ $k ] ) ? $GLOBALS['keel_transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) {
	$GLOBALS['keel_transients'][ $k ] = $v;
	return true; }
function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path ); }
function trailingslashit( $path ) {
	return rtrim( (string) $path, '/\\' ) . '/'; }
function is_customize_preview() {
	return false; }
function wp_json_encode( $v, $f = 0 ) {
	return json_encode( $v ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this stub is what stands in for wp_json_encode().

/** Stand-in for get_current_screen(), which decides which placement is in play. */
function get_current_screen() {
	return (object) array( 'id' => $GLOBALS['keel_screen'] );
}

/** Minimal stand-in for WP_Hook. */
class Keel_Notice_Test_Hook {
	public $callbacks = array();
	public function __construct( array $callbacks ) {
		$this->callbacks = $callbacks; }
	public function remove_filter( $hook, $callback, $priority ) {
		unset( $hook );
		foreach ( isset( $this->callbacks[ $priority ] ) ? $this->callbacks[ $priority ] : array() as $id => $registered ) {
			if ( $registered['function'] === $callback ) {
				unset( $this->callbacks[ $priority ][ $id ] );
			}
		}
		if ( empty( $this->callbacks[ $priority ] ) ) {
			unset( $this->callbacks[ $priority ] );
		}
	}
	public function apply_filters( $value, $args ) {
		foreach ( $this->callbacks as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$args[0] = $value;
				$value   = call_user_func_array( $registered['function'], array_slice( $args, 0, isset( $registered['accepted_args'] ) ? $registered['accepted_args'] : 1 ) );
			}
		}
		return $value;
	}
}

define( 'ABSPATH', __DIR__ . '/' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

/*
 * The same two-file fixture tests/policy-conflicts.php builds, and for the same
 * reason: a callback is attributed to a plugin by the file its code lives in, so
 * a conflict cannot be staged without files in a plugins directory to stage it
 * with. realpath() because the prefix comparison is literal.
 */
$keel_fixture_root = sys_get_temp_dir() . '/keel-conflict-notices-' . getmypid();
$keel_self_dir     = basename( dirname( dirname( __DIR__ ) . '/keel.php' ) );

foreach ( array(
	'rival-plugin' => 'keel_notice_rival',
	'second-rival' => 'keel_notice_second_rival',
	$keel_self_dir => 'keel_notice_self',
) as $keel_dir => $keel_fn ) {
	@mkdir( $keel_fixture_root . '/' . $keel_dir, 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a temp fixture in a CLI test.
	file_put_contents( $keel_fixture_root . '/' . $keel_dir . '/plugin.php', "<?php\nfunction {$keel_fn}( \$value = null ) { return true; }\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- as above.
	require $keel_fixture_root . '/' . $keel_dir . '/plugin.php';
}

register_shutdown_function(
	function () use ( $keel_fixture_root ) {
		foreach ( (array) glob( $keel_fixture_root . '/*/plugin.php' ) as $keel_file ) {
			unlink( $keel_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this test's own fixture.
			rmdir( dirname( $keel_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- as above.
		}
		@rmdir( $keel_fixture_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- as above.
	}
);

define( 'WP_PLUGIN_DIR', realpath( $keel_fixture_root ) );

$GLOBALS['keel_options']['active_plugins'] = array( 'rival-plugin/plugin.php', 'second-rival/plugin.php' );

require dirname( __DIR__ ) . '/keel.php';

$fail = 0;

/**
 * Assert helper. Counts rather than exits, so one broken placement does not
 * hide the other two.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Description.
 */
function keel_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

/**
 * Render the notice on a given screen.
 *
 * @param string $screen Screen id.
 * @return string
 */
function keel_notice_on( $screen ) {
	$GLOBALS['keel_screen'] = $screen;
	ob_start();
	keel_defaults_render_conflicts_notice();
	return (string) ob_get_clean();
}

$screens = array(
	'plugins'            => 'the plugins screen',
	'settings_page_keel' => 'the Keel settings screen',
	'dashboard'          => 'the dashboard',
);

// --- silent when nothing is contested -------------------------------------

$GLOBALS['wp_filter'] = array();

foreach ( $screens as $screen => $label ) {
	keel_assert( '' === trim( keel_notice_on( $screen ) ), "Nothing is shown on {$label} when no plugin is competing." );
}

// --- a real conflict ------------------------------------------------------

/*
 * Keel declares its own side through the registration wrapper — it registers
 * `__return_false` here on a real site, which resolves to core and is
 * indistinguishable from anybody else's. The rival goes in the filter registry,
 * which is the only place a rival can be found.
 */
keel_defaults_add_policy_filter( 'comments_open', '__return_false', 20 );

$GLOBALS['wp_filter'] = array(
	'comments_open' => new Keel_Notice_Test_Hook(
		array(
			20 => array(
				array(
					'function'      => '__return_false',
					'accepted_args' => 1,
				),
			),
			30 => array(
				array(
					'function'      => 'keel_notice_rival',
					'accepted_args' => 1,
				),
			),
		)
	),
);

keel_assert( array() !== keel_defaults_competing_plugins( true ), 'The fixture stages an overlap the detector sees.' );

foreach ( $screens as $screen => $label ) {
	$html = keel_notice_on( $screen );

	keel_assert( '' !== trim( $html ), "The notice is shown on {$label}." );
	keel_assert( false !== strpos( $html, 'rival-plugin' ), "The notice on {$label} names the competing plugin." );

	/*
	 * The list, not the prose. "Keel" appears in the sentence by design, so a
	 * bare search for the directory name would fail on the plugin's own name;
	 * what must not appear is Keel among the plugins it is complaining about.
	 */
	preg_match( '/as Keel: ([^<]+)/', $html, $named );
	keel_assert(
		isset( $named[1] ) && 'rival-plugin' === trim( $named[1] ),
		"The notice on {$label} lists the competing plugin and nothing else (got: " . ( isset( $named[1] ) ? trim( $named[1] ) : 'nothing' ) . ')."'
	);
	keel_assert( false !== strpos( $html, 'site-health' ), "The notice on {$label} links to Site Health for the detail." );
}

// --- dismissal is a dashboard affordance only -----------------------------

keel_assert(
	false === strpos( keel_notice_on( 'plugins' ), 'keel-dismiss-conflicts' ),
	'The plugins screen offers no dismissal — hiding it there would hide the reason for the visit.'
);
keel_assert(
	false === strpos( keel_notice_on( 'settings_page_keel' ), 'keel-dismiss-conflicts' ),
	'The Keel settings screen offers no dismissal either.'
);
keel_assert(
	false !== strpos( keel_notice_on( 'dashboard' ), 'keel-dismiss-conflicts' ),
	'The dashboard offers a dismissal, because a permanent banner there is an obstruction.'
);

/*
 * --- the copy has to survive counting ---
 *
 * The notice named three plugins under a heading reading "Another plugin is
 * setting the same defaults", and explained the mechanism as what happens
 * "when two plugins set the same one". Both sentences were written for the case
 * in front of us at the time. Neither is a fault in the detection and both are
 * wrong on the screen, which is the only place they are read.
 *
 * The count that governs the heading is how many plugins were named, not how
 * many settings are contested: three plugins fighting over one setting is still
 * plural.
 */
$one = keel_notice_on( 'plugins' );

keel_assert(
	false !== strpos( $one, 'Another plugin may influence' ),
	'With a single rival the heading stays singular.'
);
keel_assert(
	false === stripos( $one, 'when two plugins' ),
	'The mechanism is never described as something that happens between exactly two plugins.'
);

$GLOBALS['wp_filter']['comments_open'] = new Keel_Notice_Test_Hook(
	array(
		20 => array(
			array(
				'function'      => '__return_false',
				'accepted_args' => 1,
			),
		),
		30 => array(
			array(
				'function'      => 'keel_notice_rival',
				'accepted_args' => 1,
			),
			array(
				'function'      => 'keel_notice_second_rival',
				'accepted_args' => 1,
			),
		),
	)
);

keel_defaults_competing_plugins( true );
$many = keel_notice_on( 'plugins' );

keel_assert( false !== strpos( $many, 'second-rival' ), 'The fixture stages more than one rival.' );
keel_assert(
	false === strpos( $many, 'Another plugin may influence' ),
	'With several rivals the heading stops saying "Another plugin".'
);
keel_assert(
	false !== strpos( $many, 'Other plugins may influence' ),
	'And reads as a plural instead.'
);

// Back to one, so nothing downstream inherits the larger fixture.
$GLOBALS['wp_filter']['comments_open'] = new Keel_Notice_Test_Hook(
	array(
		20 => array(
			array(
				'function'      => '__return_false',
				'accepted_args' => 1,
			),
		),
		30 => array(
			array(
				'function'      => 'keel_notice_rival',
				'accepted_args' => 1,
			),
		),
	)
);
keel_defaults_competing_plugins( true );

// --- dismissing records what was dismissed --------------------------------

$fingerprint = keel_defaults_conflicts_fingerprint( keel_defaults_competing_plugins() );

keel_assert( 40 === strlen( $fingerprint ), 'The fingerprint is a sha1 of the conflict set.' );

$GLOBALS['keel_user_meta']['1:keel_conflicts_dismissed'] = $fingerprint;

keel_assert(
	'' === trim( keel_notice_on( 'dashboard' ) ),
	'Once dismissed, the dashboard stays quiet for that set of conflicts.'
);
keel_assert(
	'' !== trim( keel_notice_on( 'plugins' ) ),
	'Dismissing on the dashboard does not silence the plugins screen — different screens, different jobs.'
);
keel_assert(
	'' !== trim( keel_notice_on( 'settings_page_keel' ) ),
	'Nor the Keel settings screen.'
);

/*
 * The reason a fingerprint and not a boolean. A second competing plugin is new
 * information, and somebody who dismissed the first notice has not seen it.
 */
keel_defaults_add_policy_filter( 'use_block_editor_for_post', '__return_false' );

$GLOBALS['wp_filter']['use_block_editor_for_post'] = new Keel_Notice_Test_Hook(
	array(
		10 => array(
			array(
				'function'      => '__return_false',
				'accepted_args' => 1,
			),
		),
		20 => array(
			array(
				'function'      => 'keel_notice_rival',
				'accepted_args' => 1,
			),
		),
	)
);

keel_assert(
	keel_defaults_conflicts_fingerprint( keel_defaults_competing_plugins( true ) ) !== $fingerprint,
	'A new conflict changes the fingerprint.'
);
keel_assert(
	'' !== trim( keel_notice_on( 'dashboard' ) ),
	'So the dashboard notice returns: the dismissal covered the conflicts that existed then, not every conflict forever.'
);

// And the fingerprint does not depend on the order things were registered in.
$reordered = array(
	'use_block_editor_for_post' => array( 'z-plugin', 'rival-plugin' ),
	'comments_open'             => array( 'rival-plugin' ),
);
$ordered   = array(
	'comments_open'             => array( 'rival-plugin' ),
	'use_block_editor_for_post' => array( 'rival-plugin', 'z-plugin' ),
);

keel_assert(
	keel_defaults_conflicts_fingerprint( $reordered ) === keel_defaults_conflicts_fingerprint( $ordered ),
	'The fingerprint is stable under ordering, so a notice does not reappear because a hook fired in a different order.'
);

/*
 * --- a setting not taking effect must reach the notice, not only Site Health ---
 *
 * The overlap half of this report names plugins. The divergence half says a
 * governed setting is not producing the value it asks for, and it exists
 * precisely for the case the overlap half cannot see: a plugin that overrides
 * Keel through one of WordPress's own helper functions leaves nothing to
 * attribute it by.
 *
 * That is the case where the administrator has no other way to find out, and it
 * was the one case with no notice at all. Measured on a real install: Site
 * Health said "A setting is not taking effect" while the dashboard and plugins
 * screens showed nothing, because the notice read only the structural overlaps
 * and returned early when there were none.
 */
$GLOBALS['wp_filter']                      = array();
$GLOBALS['keel_user_meta']                 = array();
$GLOBALS['keel_options']['keel_settings']  = array( 'xmlrpc_allow_remote_publishing' => 'yes' );
$GLOBALS['keel_options']['active_plugins'] = array( 'rival/rival.php' );

$GLOBALS['keel_transients']['keel_policy_divergence'] = array(
	'plugins' => keel_defaults_active_plugin_fingerprint(),
	'hooks'   => array( 'xmlrpc_enabled' => true ),
);

keel_assert(
	array( 'xmlrpc_enabled' => true ) === keel_defaults_policy_divergences(),
	'The fixture records a divergence with no structural overlap beside it.'
);
keel_assert(
	array() === keel_defaults_competing_plugins( true ),
	'And there is no attributable overlap, so only the divergence can trigger a notice.'
);

foreach ( array( 'plugins', 'dashboard', 'settings_page_keel' ) as $screen ) {
	$html = keel_notice_on( $screen );
	keel_assert(
		'' !== trim( $html ),
		"A divergence alone shows a notice on {$screen}; it was silent there while Site Health reported it."
	);
	keel_assert(
		false !== strpos( $html, 'xmlrpc_enabled' ),
		"The {$screen} notice names the setting that is not taking effect."
	);
}

// Dismissing has to cover the divergence too, or the dashboard notice either
// never goes away or goes away and never comes back when a new one appears.
$fingerprint = keel_defaults_notice_fingerprint();
keel_assert( '' !== $fingerprint, 'A divergence alone produces a dismissal fingerprint.' );

$GLOBALS['keel_user_meta']['1:keel_conflicts_dismissed'] = $fingerprint;
keel_assert( '' === trim( keel_notice_on( 'dashboard' ) ), 'Dismissing the divergence notice hides it on the dashboard.' );

$GLOBALS['keel_transients']['keel_policy_divergence']['hooks']['comments_open'] = false;
$GLOBALS['keel_options']['keel_settings']['disable_comments']                   = 'yes';
keel_assert(
	'' !== trim( keel_notice_on( 'dashboard' ) ),
	'A second setting failing to take effect brings the notice back: the dismissal covered what was there then.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, sprintf( "conflict notices: %d assertion%s failed\n", $fail, 1 === $fail ? '' : 's' ) );
	exit( 1 );
}

fwrite( STDOUT, "conflict notices: OK\n" );
