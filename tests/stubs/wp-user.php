<?php
/**
 * Minimal WP_User stand-in shared by standalone tests.
 *
 * A single declaration keeps PHPCS duplicate-class detection independent of
 * how it assigns the test files to workers.
 *
 * @package Keel
 */

class WP_User {
	public $ID;
	public $roles;
	public $user_login;
	public $display_name;
	public $user_email    = '';
	public $user_nicename = '';
	public $user_pass     = '$wp$2y$10$old';
	public function __construct( $id, $roles = array( 'editor' ), $login = 'user', $name = 'User' ) {
		$this->ID           = $id;
		$this->roles        = $roles;
		$this->user_login   = $login;
		$this->display_name = $name;
	}
}
