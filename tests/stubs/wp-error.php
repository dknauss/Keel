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
	}
}
