<?php
/**
 * Minimal WP_Error stand-in, shared by the tests that need one.
 *
 * Each test file runs in its own PHP process, so two files declaring this class
 * never collide at runtime — but phpcs reads the tree as a whole and reports the
 * duplicate, which fails `composer lint` and therefore CI. Sharing one
 * declaration removes the duplication rather than silencing the report, and
 * means a test that needs a richer stand-in changes it once.
 *
 * @package Keel
 */

if ( ! class_exists( 'WP_Error' ) ) {

	/**
	 * Stand-in for WordPress's WP_Error.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		public $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		public $message;

		/**
		 * Errors recorded through add(), keyed by code.
		 *
		 * @var array<string, string>
		 */
		public $errors = array();

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data (unused).
		 */
		public function __construct( $code = '', $message = '', $data = array() ) {
			unset( $data );
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Error code accessor.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Error message accessor.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * Record an error, the way WordPress's own $errors object does.
		 *
		 * WordPress hands the validate_password_reset callback a WP_Error to add
		 * to rather than a value to return, so a test of that entry point needs
		 * somewhere for the failure to land.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data (unused).
		 * @return void
		 */
		public function add( $code, $message = '', $data = array() ) {
			unset( $data );
			$this->errors[ $code ] = $message;

			if ( '' === (string) $this->code ) {
				$this->code    = $code;
				$this->message = $message;
			}
		}

		/**
		 * Codes recorded through add().
		 *
		 * @return string[]
		 */
		public function get_error_codes() {
			return array_keys( $this->errors );
		}
	}
}
