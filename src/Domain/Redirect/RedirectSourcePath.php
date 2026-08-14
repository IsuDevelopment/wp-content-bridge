<?php
/**
 * Redirect source path value object.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Redirect;

use InvalidArgumentException;

/**
 * A bounded, exact, site-relative path. P0 (ADR 0026) never accepts a full
 * URL, a regex, or a query/fragment target as a redirect source.
 */
final readonly class RedirectSourcePath {

	private const MAX_LENGTH = 2048;

	/**
	 * Characters with special meaning in either provider's regex match mode.
	 * P0 sends only exact/plain matches, so a source containing one of these
	 * would be silently treated as literal by this plugin but could confuse a
	 * provider's own regex-aware tooling; rejecting it keeps the contract
	 * honest about what "exact" means.
	 */
	private const FORBIDDEN_CHARACTERS = array( '*', '(', ')', '[', ']', '{', '}', '^', '$', '\\' );

	/**
	 * The validated path, stored exactly as supplied.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Validates and stores one redirect source path.
	 *
	 * @param string $path Candidate site-relative path.
	 * @throws InvalidArgumentException When the path is not a bounded, exact,
	 *                                   site-relative path.
	 */
	public function __construct( string $path ) {
		if ( '' === $path || strlen( $path ) > self::MAX_LENGTH ) {
			throw new InvalidArgumentException( 'Redirect source path is invalid.' );
		}
		if ( ! str_starts_with( $path, '/' ) ) {
			throw new InvalidArgumentException( 'Redirect source path must start with "/".' );
		}
		if ( str_contains( $path, '//' ) ) {
			throw new InvalidArgumentException( 'Redirect source path must not contain "//".' );
		}
		if ( str_contains( $path, '?' ) || str_contains( $path, '#' ) ) {
			throw new InvalidArgumentException( 'Redirect source path must not contain a query string or fragment.' );
		}
		if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $path ) ) {
			throw new InvalidArgumentException( 'Redirect source path must not contain control characters.' );
		}
		foreach ( self::FORBIDDEN_CHARACTERS as $character ) {
			if ( str_contains( $path, $character ) ) {
				throw new InvalidArgumentException( 'Redirect source path must not contain pattern-matching characters.' );
			}
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				throw new InvalidArgumentException( 'Redirect source path must not contain traversal segments.' );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- domain value object remains executable without WordPress loaded.
		$parts = parse_url( $path );
		if ( false === $parts || isset( $parts['scheme'], $parts['host'] ) ) {
			throw new InvalidArgumentException( 'Redirect source path must not be an absolute URL.' );
		}

		$this->path = $path;
	}

	/**
	 * Returns the validated path exactly as supplied.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->path;
	}
}
