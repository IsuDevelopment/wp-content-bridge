<?php
/**
 * Redirect target URL value object.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Redirect;

use InvalidArgumentException;

/**
 * A same-site redirect destination (P0, ADR 0026 s5). A bare path needs no
 * origin check; an absolute URL must match the configured site origin
 * exactly and is normalized down to a path so no host survives that could go
 * stale after a future site-URL change.
 */
final readonly class RedirectTargetUrl {

	/**
	 * The validated, site-relative target.
	 *
	 * @var string
	 */
	private string $target;

	/**
	 * Validates and normalizes one redirect target against the site origin.
	 *
	 * @param string $site_url Canonical site URL.
	 * @param string $target   Candidate target: a site-relative path or an
	 *                          absolute same-site URL.
	 * @throws InvalidArgumentException When the target is empty, unsafe, or
	 *                                   not on the configured site origin.
	 */
	public function __construct( string $site_url, string $target ) {
		if ( '' === $target || 1 === preg_match( '/[\x00-\x1f\x7f]/', $target ) ) {
			throw new InvalidArgumentException( 'Redirect target is invalid.' );
		}

		$path = str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' )
			? $target
			: self::normalize_absolute_target( $site_url, $target );

		if ( str_contains( $path, '#' ) ) {
			throw new InvalidArgumentException( 'Redirect target must not contain a fragment.' );
		}
		foreach ( explode( '/', explode( '?', $path )[0] ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				throw new InvalidArgumentException( 'Redirect target must not contain traversal segments.' );
			}
		}

		$this->target = $path;
	}

	/**
	 * Returns the validated, site-relative target.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->target;
	}

	/**
	 * Validates an absolute URL against the site origin and strips it to a
	 * site-relative path plus query string.
	 *
	 * @param string $site_url Canonical site URL.
	 * @param string $target   Absolute candidate URL.
	 * @return string
	 * @throws InvalidArgumentException When the origin does not match or the
	 *                                   site URL itself is not configured.
	 */
	private static function normalize_absolute_target( string $site_url, string $target ): string {
		$site = self::parse_http_url( $site_url );
		if ( null === $site ) {
			throw new InvalidArgumentException( 'The WordPress site URL is not configured as an absolute HTTP URL.' );
		}
		$candidate = self::parse_http_url( $target );
		if ( null === $candidate ) {
			throw new InvalidArgumentException( 'Redirect target must be a site-relative path or an absolute HTTP URL.' );
		}
		if ( isset( $candidate['user'] ) || isset( $candidate['pass'] ) ) {
			throw new InvalidArgumentException( 'Redirect target must not contain credentials.' );
		}
		if (
			strtolower( $site['scheme'] ) !== strtolower( $candidate['scheme'] )
			|| self::normalize_host( $site['host'] ) !== self::normalize_host( $candidate['host'] )
		) {
			throw new InvalidArgumentException( 'Redirect target must use the current site origin.' );
		}

		$path = $candidate['path'] ?? '/';
		if ( isset( $candidate['query'] ) && '' !== $candidate['query'] ) {
			$path .= '?' . $candidate['query'];
		}

		return $path;
	}

	/**
	 * Parses one absolute HTTP(S) URL conservatively, returning null instead
	 * of throwing so a caller can decide what an invalid site URL means.
	 *
	 * @param string $url URL candidate.
	 * @return array<string, mixed>|null
	 * @phpstan-return array{scheme: string, host: string, port?: int, path?: string, query?: string, user?: string, pass?: string}|null
	 */
	private static function parse_http_url( string $url ): ?array {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 2048 || str_contains( $url, '\\' ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- domain value object remains executable without WordPress loaded.
		$parts = parse_url( $url );
		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		return $parts;
	}

	/**
	 * Normalizes a host for exact origin comparison.
	 *
	 * @param string $host Host value.
	 * @return string
	 */
	private static function normalize_host( string $host ): string {
		return strtolower( rtrim( $host, '.' ) );
	}
}
