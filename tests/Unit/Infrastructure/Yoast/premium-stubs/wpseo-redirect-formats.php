<?php
/**
 * Runtime stand-in for Yoast Premium's redirect format tokens.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WPSEO_Redirect_Formats' ) ) {
	/**
	 * Stand-in for Premium's redirect format tokens.
	 */
	class WPSEO_Redirect_Formats {

		public const PLAIN = 'plain';
		public const REGEX = 'regex';
	}
}
