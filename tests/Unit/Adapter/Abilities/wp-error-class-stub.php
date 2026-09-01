<?php
/**
 * Minimal `WP_Error` stand-in for the error-status contract test.
 *
 * The unit suite boots Composer only. Just enough of the class exists here to
 * observe what `AbilityError::create()` puts into an error's code, message, and
 * data; anything beyond that is deliberately absent so a test cannot come to
 * depend on behaviour this stub invented.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Records one error code, message, and data payload.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		private string $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private string $message;

		/**
		 * Error data.
		 *
		 * @var mixed
		 */
		private mixed $data;

		/**
		 * Constructs the error.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Returns the error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Returns the error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * Returns the error data.
		 *
		 * @return mixed
		 */
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}
