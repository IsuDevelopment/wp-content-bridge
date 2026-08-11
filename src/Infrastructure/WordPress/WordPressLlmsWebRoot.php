<?php
/**
 * WordPress llms.txt web-root resolver.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

/**
 * Resolves the filesystem directory that serves `home_url()`.
 */
final class WordPressLlmsWebRoot {

	/**
	 * Returns a normalized, trailing-slashed web root.
	 *
	 * @return string
	 */
	public static function resolve(): string {
		if ( ! defined( 'ABSPATH' ) ) {
			return '';
		}

		$root = rtrim( constant( 'ABSPATH' ), '/\\' );

		$home = rtrim( (string) home_url(), '/' );
		$site = rtrim( (string) site_url(), '/' );

		if ( $home === $site || ! str_starts_with( $site, $home . '/' ) ) {
			return $root . '/';
		}

		$segments = array_filter( explode( '/', trim( substr( $site, strlen( $home ) ), '/' ) ) );
		foreach ( $segments as $ignored ) {
			$parent = dirname( $root );
			if ( $parent === $root ) {
				break;
			}
			$root = $parent;
		}

		return $root . '/';
	}
}
