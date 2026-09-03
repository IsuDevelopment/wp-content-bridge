<?php
/**
 * Runtime stand-in for the Yoast SEO Premium redirect surface, used only by
 * unit tests.
 *
 * Mirrors Premium 28.0 as read from source (ADR 0026 amendment), including the
 * two behaviours the adapter exists to accommodate: a plain origin is stored
 * with both slashes trimmed, and `get_redirect()` answers `false` -- not null
 * -- when nothing matches.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

if ( ! defined( 'WPSEO_PREMIUM_VERSION' ) ) {
	define( 'WPSEO_PREMIUM_VERSION', '28.0' );
}

require_once __DIR__ . '/premium-stubs/wpseo-redirect-formats.php';
require_once __DIR__ . '/premium-stubs/wpseo-redirect.php';
require_once __DIR__ . '/premium-stubs/wpseo-redirect-manager.php';

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Answers from the capability set the active test granted.
	 *
	 * @param string $capability Capability name.
	 * @param mixed  ...$args    Unused extra arguments.
	 * @return bool
	 */
	function current_user_can( string $capability, ...$args ): bool {
		unset( $args );

		return in_array( $capability, $GLOBALS['wpcb_test_capabilities'] ?? array(), true );
	}
}
