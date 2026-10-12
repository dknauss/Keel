<?php
/**
 * Stand-ins for Jetpack's admin menu classes, for tests/admin-menu-width-source.php.
 *
 * Jetpack's masterbar package builds its menu from a subclass of
 * Base_Admin_Menu, whose constructor hooks enqueue_scripts() onto
 * admin_enqueue_scripts at priority 11. Keel looks for that registration, so
 * the shape that matters is the class hierarchy and the method name.
 *
 * @package keel
 */

namespace Automattic\Jetpack\Masterbar;

/** The base class Keel recognises. */
abstract class Base_Admin_Menu {
	/** The method that enqueues the `jetpack-admin-menu` stylesheet. */
	public function enqueue_scripts() {
		++$GLOBALS['keel_jetpack_enqueue_calls'];
	}

	/** Another method Jetpack hooks, which enqueues nothing. */
	public function add_dashboard_switcher() {}
}

/** What WordPress.com Atomic sites get. */
class Atomic_Admin_Menu extends Base_Admin_Menu {}
