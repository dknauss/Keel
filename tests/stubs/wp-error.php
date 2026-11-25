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
 * Storage follows core: messages and data are kept per code, so a callback
 * that reads, removes, or re-adds errors the way wp-login.php's filters do
 * behaves as it would in WordPress. The `code`, `message` and `data`
 * properties mirror the first error, for the older tests that read them.
 *
 * Duplicates only show up when phpcs compares the two files in one process;
 * with `parallel` in phpcs.xml that depends on how files are split, so a
 * second declaration can pass locally and fail in CI.
 *
 * @package Keel
 */

if ( ! class_exists( 'WP_Error' ) ) {

	/**
	 * Stand-in for WordPress's WP_Error.
	 */
	class WP_Error {

		/**
		 * The first error's code.
		 *
		 * @var string|int
		 */
		public $code = '';

		/**
		 * The first error's message.
		 *
		 * @var string
		 */
		public $message = '';

		/**
		 * The first error's data.
		 *
		 * @var mixed
		 */
		public $data = '';

		/**
		 * Messages by code, as core stores them.
		 *
		 * @var array<string|int, string[]>
		 */
		public $errors = array();

		/**
		 * Latest data by code.
		 *
		 * @var array<string|int, mixed>
		 */
		public $error_data = array();

		/**
		 * Earlier data by code.
		 *
		 * @var array<string|int, mixed[]>
		 */
		protected $additional_data = array();

		/**
		 * Constructor.
		 *
		 * @param string|int $code    Error code.
		 * @param string     $message Error message.
		 * @param mixed      $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		/**
		 * Codes, in the order they were added.
		 *
		 * @return array<int, string|int>
		 */
		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		/**
		 * The first code, or an empty string.
		 *
		 * @return string|int
		 */
		public function get_error_code() {
			$codes = $this->get_error_codes();
			return $codes ? $codes[0] : '';
		}

		/**
		 * Every message, or those for one code.
		 *
		 * @param string|int $code Error code; empty for all.
		 * @return string[]
		 */
		public function get_error_messages( $code = '' ) {
			if ( '' === $code ) {
				return $this->errors ? array_merge( array(), ...array_values( $this->errors ) ) : array();
			}
			return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
		}

		/**
		 * The first message for a code, the first code by default.
		 *
		 * @param string|int $code Error code.
		 * @return string
		 */
		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			$messages = $this->get_error_messages( $code );
			return $messages ? $messages[0] : '';
		}

		/**
		 * The latest data for a code, the first code by default.
		 *
		 * @param string|int $code Error code.
		 * @return mixed
		 */
		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}

		/**
		 * Every data value added for a code, oldest first.
		 *
		 * @param string|int $code Error code.
		 * @return mixed[]
		 */
		public function get_all_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			$data = isset( $this->additional_data[ $code ] ) ? $this->additional_data[ $code ] : array();
			if ( isset( $this->error_data[ $code ] ) ) {
				$data[] = $this->error_data[ $code ];
			}
			return $data;
		}

		/**
		 * Whether any error is recorded.
		 *
		 * @return bool
		 */
		public function has_errors() {
			return ! empty( $this->errors );
		}

		/**
		 * Record an error, the way WordPress's own $errors object does.
		 *
		 * @param string|int $code    Error code.
		 * @param string     $message Error message.
		 * @param mixed      $data    Error data.
		 * @return void
		 */
		public function add( $code, $message = '', $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->add_data( $data, $code );
			}

			if ( '' === $this->code ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}
		}

		/**
		 * Record data for a code, keeping the earlier value.
		 *
		 * @param mixed      $data Error data.
		 * @param string|int $code Error code; the first code by default.
		 * @return void
		 */
		public function add_data( $data, $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			if ( isset( $this->error_data[ $code ] ) ) {
				$this->additional_data[ $code ][] = $this->error_data[ $code ];
			}
			$this->error_data[ $code ] = $data;
		}

		/**
		 * Remove a code with its messages and data.
		 *
		 * @param string|int $code Error code.
		 * @return void
		 */
		public function remove( $code ) {
			unset( $this->errors[ $code ], $this->error_data[ $code ], $this->additional_data[ $code ] );
		}
	}
}
