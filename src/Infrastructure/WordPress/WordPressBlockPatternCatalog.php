<?php
/**
 * WordPress block-pattern catalog adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Pattern\BlockPatternCatalog;
use IsuDev\WPContentBridge\Application\Pattern\PatternPayloadTooLarge;
use IsuDev\WPContentBridge\Domain\Pattern\BlockPatternItem;
use IsuDev\WPContentBridge\Domain\Pattern\PatternQuery;
use IsuDev\WPContentBridge\Domain\Pattern\PatternSearchResult;
use WP_Block_Patterns_Registry;

/**
 * Reads and normalizes the current in-memory pattern registry without remote loading.
 */
final class WordPressBlockPatternCatalog implements BlockPatternCatalog {

	private const CANDIDATE_SCAN_LIMIT = 1000;
	private const CONTENT_LIMIT_BYTES  = 2 * 1024 * 1024;
	private const LIST_LIMIT           = 20;

	/**
	 * Lists registered patterns with deterministic filtering and pagination.
	 *
	 * @param PatternQuery $query Listing criteria.
	 * @return PatternSearchResult
	 * @throws PatternPayloadTooLarge When selected complete content exceeds 2 MiB.
	 */
	public function list( PatternQuery $query ): PatternSearchResult {
		$registered = WP_Block_Patterns_Registry::get_instance()->get_all_registered();
		usort(
			$registered,
			static fn ( array $left, array $right ): int => strcmp(
				is_string( $left['name'] ?? null ) ? $left['name'] : '',
				is_string( $right['name'] ?? null ) ? $right['name'] : ''
			)
		);

		$total_is_exact = count( $registered ) <= self::CANDIDATE_SCAN_LIMIT;
		$registered     = array_slice( $registered, 0, self::CANDIDATE_SCAN_LIMIT );
		$matching       = array_values(
			array_filter(
				$registered,
				fn ( array $pattern ): bool => $this->matches( $pattern, $query )
			)
		);

		$total_items  = count( $matching );
		$total_pages  = (int) ceil( $total_items / $query->per_page );
		$offset       = ( $query->page - 1 ) * $query->per_page;
		$selected     = array_slice( $matching, $offset, $query->per_page );
		$items        = array();
		$content_size = 0;

		foreach ( $selected as $pattern ) {
			$item          = $this->normalize( $pattern, $query->include_content );
			$content_size += $item->content_bytes();
			if ( self::CONTENT_LIMIT_BYTES < $content_size ) {
				throw new PatternPayloadTooLarge( 'Requested pattern content exceeds the response limit.' );
			}
			$items[] = $item;
		}

		return new PatternSearchResult(
			$items,
			$query->page,
			$query->per_page,
			$total_items,
			$total_pages,
			$total_is_exact,
			! $total_is_exact || ( $offset + count( $items ) ) < $total_items,
			self::CANDIDATE_SCAN_LIMIT,
			self::CONTENT_LIMIT_BYTES,
		);
	}

	/**
	 * Checks all requested filters against an allowlisted raw projection.
	 *
	 * @param array        $pattern Registered pattern.
	 * @param PatternQuery $query   Listing criteria.
	 * @phpstan-param array<array-key, mixed> $pattern
	 * @return bool
	 */
	private function matches( array $pattern, PatternQuery $query ): bool {
		$name = $pattern['name'] ?? null;
		if ( ! is_string( $name ) || 200 < strlen( $name ) || ! str_contains( $name, '/' ) ) {
			return false;
		}

		$namespace = explode( '/', $name, 2 )[0];
		if ( '' === $namespace || 100 < strlen( $namespace ) ) {
			return false;
		}
		if ( null !== $query->pattern_namespace && $query->pattern_namespace !== $namespace ) {
			return false;
		}
		if ( null !== $query->category && ! in_array( $query->category, self::strings( $pattern['categories'] ?? array() ), true ) ) {
			return false;
		}
		if ( null !== $query->post_type ) {
			$post_types = self::strings( $pattern['postTypes'] ?? array() );
			if ( array() !== $post_types && ! in_array( $query->post_type, $post_types, true ) ) {
				return false;
			}
		}
		if ( '' !== $query->query ) {
			$title       = self::plain_text( $pattern['title'] ?? '' );
			$description = self::plain_text( $pattern['description'] ?? '' );
			$haystack    = implode(
				' ',
				array_merge(
					array(
						$name,
						$title,
						$description,
					),
					self::strings( $pattern['keywords'] ?? array() )
				)
			);
			if ( ! str_contains( self::lowercase( $haystack ), self::lowercase( $query->query ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalizes one raw registry entry without exposing unknown keys.
	 *
	 * @param array $pattern Registered pattern.
	 * @param bool  $include_content Whether markup is requested.
	 * @phpstan-param array<array-key, mixed> $pattern
	 * @return BlockPatternItem
	 */
	private function normalize( array $pattern, bool $include_content ): BlockPatternItem {
		$name        = is_string( $pattern['name'] ?? null ) ? $pattern['name'] : '';
		$namespace   = explode( '/', $name, 2 )[0];
		$source      = is_string( $pattern['source'] ?? null ) ? self::bounded_text( $pattern['source'], 100 ) : null;
		$viewport    = $pattern['viewportWidth'] ?? null;
		$viewport    = is_numeric( $viewport ) && 0 <= (int) $viewport ? (int) $viewport : null;
		$raw_content = $pattern['content'] ?? '';
		$content     = $include_content && is_string( $raw_content ) ? $raw_content : null;

		return new BlockPatternItem(
			$name,
			$namespace,
			self::plain_text( $pattern['title'] ?? '' ),
			self::plain_text( $pattern['description'] ?? '' ),
			$source,
			$viewport,
			! isset( $pattern['inserter'] ) || true === $pattern['inserter'],
			array_slice( self::strings( $pattern['categories'] ?? array() ), 0, self::LIST_LIMIT ),
			array_slice( self::strings( $pattern['keywords'] ?? array() ), 0, self::LIST_LIMIT ),
			array_slice( self::strings( $pattern['blockTypes'] ?? array() ), 0, self::LIST_LIMIT ),
			array_slice( self::strings( $pattern['postTypes'] ?? array() ), 0, self::LIST_LIMIT ),
			array_slice( self::strings( $pattern['templateTypes'] ?? array() ), 0, self::LIST_LIMIT ),
			$content,
		);
	}

	/**
	 * Keeps unique non-empty string values only.
	 *
	 * @param mixed $value Raw registry value.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();
		foreach ( $value as $item ) {
			$normalized = is_string( $item ) ? self::bounded_text( $item, 200 ) : '';
			if ( '' !== $normalized && ! in_array( $normalized, $strings, true ) ) {
				$strings[] = $normalized;
			}
		}

		return $strings;
	}

	/**
	 * Normalizes registry labels to plain untrusted text.
	 *
	 * @param mixed $value Raw text.
	 * @return string
	 */
	private static function plain_text( mixed $value ): string {
		return is_string( $value ) ? self::bounded_text( wp_strip_all_tags( $value ), 1000 ) : '';
	}

	/**
	 * Limits valid UTF-8 text without splitting a multibyte character.
	 *
	 * @param string $value  Text value.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function bounded_text( string $value, int $length ): string {
		$value = wp_check_invalid_utf8( $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length, 'UTF-8' );
		}

		$bounded = substr( $value, 0, $length );

		return wp_check_invalid_utf8( $bounded );
	}

	/**
	 * Performs best-available case folding without adding an extension dependency.
	 *
	 * @param string $value Search text.
	 * @return string
	 */
	private static function lowercase( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
