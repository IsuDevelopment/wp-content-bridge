<?php
/**
 * Runtime stand-in for one stored Yoast Premium redirect.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WPSEO_Redirect' ) ) {
	/**
	 * Stand-in for one stored Premium redirect.
	 */
	class WPSEO_Redirect {

		/**
		 * Stored origin.
		 *
		 * @var string
		 */
		private string $origin;

		/**
		 * Creates a redirect value, trimming slashes exactly as Premium does
		 * for the plain format.
		 *
		 * @param string $origin Origin.
		 * @param string $target Target; empty for a Gone redirect.
		 * @param int    $type   HTTP status code.
		 * @param string $format Format token.
		 */
		public function __construct(
			string $origin,
			private string $target = '',
			private int $type = 301,
			private string $format = 'plain'
		) {
			$this->origin = ( self::PLAIN_FORMAT === $format && '/' !== $origin ) ? trim( $origin, '/' ) : $origin;
		}

		private const PLAIN_FORMAT = 'plain';

		/**
		 * Returns the stored origin.
		 *
		 * @return string
		 */
		public function get_origin(): string {
			return $this->origin;
		}

		/**
		 * Returns the stored target.
		 *
		 * @return string
		 */
		public function get_target(): string {
			return $this->target;
		}

		/**
		 * Returns the stored status code.
		 *
		 * @return int
		 */
		public function get_type(): int {
			return $this->type;
		}

		/**
		 * Returns the stored format token.
		 *
		 * @return string
		 */
		public function get_format(): string {
			return $this->format;
		}
	}
}
