<?php
/**
 * Runtime stand-in for Yoast's global integration surface, used only by unit tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '28.0' );
}

if ( ! function_exists( 'YoastSEO' ) ) {
	/**
	 * Returns the currently configured fake Yoast main surface for the active test.
	 *
	 * @return object
	 */
	function YoastSEO(): object { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Must match Yoast's real global function name.
		return \IsuDev\WPContentBridge\Tests\Unit\Infrastructure\Yoast\YoastSeoProviderTest::$main;
	}
}
