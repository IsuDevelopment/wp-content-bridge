<?php
/**
 * Same-site SEO target validation.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Seo;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;

/**
 * Creates exactly-one-selector targets and rejects cross-origin URL lookups.
 *
 * @phpstan-type ParsedUrl array{scheme: string, host: string, port?: int, path?: string, query?: string, user?: string, pass?: string}
 */
final readonly class SameSiteSeoTargetFactory {

	/**
	 * Parsed canonical site URL.
	 *
	 * @var array<string, mixed>|null
	 * @phpstan-var ParsedUrl|null
	 */
	private ?array $site;

	/**
	 * Creates a factory for one WordPress site origin.
	 *
	 * @param string $site_url Canonical site URL.
	 */
	public function __construct( string $site_url ) {
		try {
			$this->site = self::parse_http_url( $site_url );
		} catch ( InvalidArgumentException ) {
			$this->site = null;
		}
	}

	/**
	 * Creates a post or URL target from untrusted structured input.
	 *
	 * @param array $input Selector input.
	 * @return SeoTarget
	 * @phpstan-param array<string, mixed> $input
	 * @throws InvalidArgumentException When selectors are invalid or unsafe.
	 */
	public function from_input( array $input ): SeoTarget {
		$has_post_id = array_key_exists( 'post_id', $input );
		$has_url     = array_key_exists( 'url', $input );
		if ( $has_post_id === $has_url ) {
			throw new InvalidArgumentException( 'Exactly one SEO selector is required: post_id or url.' );
		}

		if ( $has_post_id ) {
			if ( ! is_int( $input['post_id'] ) ) {
				throw new InvalidArgumentException( 'SEO post_id must be a positive integer.' );
			}

			return SeoTarget::for_post( $input['post_id'] );
		}

		if ( ! is_string( $input['url'] ) ) {
			throw new InvalidArgumentException( 'SEO URL must be a string.' );
		}

		return SeoTarget::for_url( $this->normalize_same_site_url( $input['url'] ) );
	}

	/**
	 * Validates and normalizes an absolute same-origin HTTP URL.
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 * @throws InvalidArgumentException When the URL is not on the configured origin.
	 */
	private function normalize_same_site_url( string $url ): string {
		if ( null === $this->site ) {
			throw new InvalidArgumentException( 'The WordPress site URL is not configured as an absolute HTTP URL.' );
		}
		$candidate = self::parse_http_url( $url );
		if ( isset( $candidate['user'] ) || isset( $candidate['pass'] ) ) {
			throw new InvalidArgumentException( 'SEO URL credentials are not allowed.' );
		}

		$site_scheme      = strtolower( $this->site['scheme'] );
		$candidate_scheme = strtolower( $candidate['scheme'] );
		$site_host        = self::normalize_host( $this->site['host'] );
		$candidate_host   = self::normalize_host( $candidate['host'] );
		if (
			$site_scheme !== $candidate_scheme
			|| $site_host !== $candidate_host
			|| self::effective_port( $this->site ) !== self::effective_port( $candidate )
		) {
			throw new InvalidArgumentException( 'SEO URL must use the current site origin.' );
		}

		$path         = isset( $candidate['path'] ) && '' !== $candidate['path']
			? $candidate['path']
			: '/';
		$decoded_path = rawurldecode( $path );
		foreach ( explode( '/', $decoded_path ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				throw new InvalidArgumentException( 'SEO URL path traversal segments are not allowed.' );
			}
		}

		$authority = self::format_host( $candidate_host );
		$port      = self::effective_port( $candidate );
		if ( self::default_port( $candidate_scheme ) !== $port ) {
			$authority .= ':' . $port;
		}

		$normalized = $candidate_scheme . '://' . $authority . $path;
		if ( isset( $candidate['query'] ) && '' !== $candidate['query'] ) {
			$normalized .= '?' . $candidate['query'];
		}

		return $normalized;
	}

	/**
	 * Parses one absolute HTTP(S) URL conservatively.
	 *
	 * @param string $url URL input.
	 * @phpstan-return ParsedUrl
	 * @throws InvalidArgumentException When the URL is malformed or unsafe.
	 */
	private static function parse_http_url( string $url ): array {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 2048 || str_contains( $url, '\\' ) || 1 === preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			throw new InvalidArgumentException( 'URL is invalid.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- application service remains executable without WordPress loaded.
		$parts = parse_url( $url );
		if (
			false === $parts
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| false === filter_var( $url, FILTER_VALIDATE_URL )
		) {
			throw new InvalidArgumentException( 'URL must be an absolute HTTP URL.' );
		}

		$parsed = array(
			'scheme' => $parts['scheme'],
			'host'   => $parts['host'],
		);
		foreach ( array( 'path', 'query', 'user', 'pass' ) as $key ) {
			if ( isset( $parts[ $key ] ) ) {
				$parsed[ $key ] = $parts[ $key ];
			}
		}
		if ( isset( $parts['port'] ) ) {
			$parsed['port'] = $parts['port'];
		}

		return $parsed;
	}

	/**
	 * Normalizes a URL host for exact origin comparison.
	 *
	 * @param string $host Host value.
	 * @return string
	 * @throws InvalidArgumentException When the host is empty or encoded.
	 */
	private static function normalize_host( string $host ): string {
		$host = strtolower( rtrim( $host, '.' ) );
		if ( '' === $host || str_contains( $host, '%' ) ) {
			throw new InvalidArgumentException( 'SEO URL host is invalid.' );
		}

		return $host;
	}

	/**
	 * Adds brackets required when serializing an IPv6 host.
	 *
	 * @param string $host Normalized host.
	 * @return string
	 */
	private static function format_host( string $host ): string {
		return str_contains( $host, ':' ) && ! str_starts_with( $host, '[' ) ? '[' . $host . ']' : $host;
	}

	/**
	 * Resolves an explicit/default port.
	 *
	 * @param array $parts Parsed URL.
	 * @return int
	 * @phpstan-param ParsedUrl $parts
	 */
	private static function effective_port( array $parts ): int {
		if ( isset( $parts['port'] ) ) {
			return $parts['port'];
		}

		return self::default_port( strtolower( $parts['scheme'] ) );
	}

	/**
	 * Returns the standard scheme port.
	 *
	 * @param string $scheme HTTP scheme.
	 * @return int
	 */
	private static function default_port( string $scheme ): int {
		return 'https' === $scheme ? 443 : 80;
	}
}
