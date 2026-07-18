<?php
/**
 * WordPress rendered schema reader.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use Closure;
use IsuDev\WPContentBridge\Application\Seo\RenderedSchemaReader;

/**
 * Fetches a same-origin page and returns its public JSON-LD graph.
 *
 * The request is strictly same-origin, time- and size-bounded, and cached. The
 * captured graph is only ever consumed by the allowlist projector, so arbitrary
 * page markup can never enter normalized output.
 */
final readonly class WordPressRenderedSchemaReader implements RenderedSchemaReader {

	private const MAX_BODY_BYTES = 3145728;
	private const MAX_NODES      = 200;
	private const TIMEOUT        = 5;
	private const REDIRECTS      = 3;
	private const CACHE_PREFIX   = 'wpcb_seo_ld_';
	private const CACHE_TTL      = 600;

	/**
	 * Creates the reader.
	 *
	 * @param string       $site_origin Site origin used for the same-origin guard.
	 * @param Closure|null $fetcher     Optional fetcher returning {code:int, body:string} or null.
	 * @phpstan-param (Closure(string): (array{code: int, body: string}|null))|null $fetcher
	 */
	public function __construct(
		private string $site_origin,
		private ?Closure $fetcher = null,
	) {
	}

	/**
	 * Returns bounded public JSON-LD nodes for a same-origin URL.
	 *
	 * @param string $url Same-origin, already-authorized public URL.
	 * @return list<array<string, mixed>>
	 */
	public function graph_for_url( string $url ): array {
		if ( '' === $url || ! $this->is_same_origin( $url ) ) {
			return array();
		}

		$cache_key = self::CACHE_PREFIX . md5( $url );
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				/**
				 * Cached graph nodes previously produced by this reader.
				 *
				 * @var list<array<string, mixed>> $cached
				 */
				return $cached;
			}
		}

		$response = $this->fetch( $url );
		if ( null === $response || 200 !== $response['code'] ) {
			return array();
		}
		$body = $response['body'];
		if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return array();
		}

		$nodes = $this->extract_graph( $body );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, $nodes, self::CACHE_TTL );
		}

		return $nodes;
	}

	/**
	 * Confirms the URL host matches the configured site origin.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_same_origin( string $url ): bool {
		$target = $this->origin_parts( $url );
		$site   = $this->origin_parts( $this->site_origin );

		return null !== $target
			&& null !== $site
			&& $target['host'] === $site['host']
			&& $target['scheme'] === $site['scheme']
			&& $target['port'] === $site['port'];
	}

	/**
	 * Extracts the normalized scheme, host, and port for an origin comparison.
	 *
	 * @param string $url Candidate URL.
	 * @return array{scheme: string, host: string, port: int|null}|null
	 */
	private function origin_parts( string $url ): ?array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url is unavailable outside the WordPress runtime.
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || ! is_string( $parts['host'] ) || '' === $parts['host'] ) {
			return null;
		}
		$scheme = isset( $parts['scheme'] ) && is_string( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$port   = isset( $parts['port'] ) && is_int( $parts['port'] ) ? $parts['port'] : null;

		return array(
			'scheme' => $scheme,
			'host'   => strtolower( $parts['host'] ),
			'port'   => $port,
		);
	}

	/**
	 * Performs the bounded HTTP request through WordPress or an injected fetcher.
	 *
	 * @param string $url Same-origin URL.
	 * @return array{code: int, body: string}|null
	 */
	private function fetch( string $url ): ?array {
		if ( null !== $this->fetcher ) {
			$result = ( $this->fetcher )( $url );

			return is_array( $result ) ? $result : null;
		}

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return null;
		}

		$sslverify = function_exists( 'apply_filters' )
			? (bool) apply_filters( 'wpcb_seo_rendered_schema_sslverify', true, $url )
			: true;

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => self::REDIRECTS,
				'sslverify'   => $sslverify,
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return null;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Collects JSON-LD graph nodes from rendered HTML.
	 *
	 * @param string $html Rendered page markup.
	 * @return list<array<string, mixed>>
	 */
	private function extract_graph( string $html ): array {
		if ( ! preg_match_all( '#<script\b[^>]*type=(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is', $html, $matches ) ) {
			return array();
		}

		$nodes = array();
		foreach ( $matches[2] as $block ) {
			$decoded = json_decode( trim( (string) $block ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$candidates = isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] )
				? $decoded['@graph']
				: ( array_is_list( $decoded ) ? $decoded : array( $decoded ) );
			foreach ( $candidates as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$normalized = array();
				foreach ( $node as $key => $value ) {
					if ( is_string( $key ) ) {
						$normalized[ $key ] = $value;
					}
				}
				if ( array() !== $normalized ) {
					$nodes[] = $normalized;
				}
				if ( count( $nodes ) >= self::MAX_NODES ) {
					return $nodes;
				}
			}
		}

		return $nodes;
	}
}
