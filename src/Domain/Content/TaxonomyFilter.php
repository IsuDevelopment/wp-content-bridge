<?php
/**
 * Taxonomy search filter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Content;

use InvalidArgumentException;

/**
 * Immutable filter matching any selected term in one taxonomy.
 */
final readonly class TaxonomyFilter {

	/**
	 * Creates a taxonomy filter.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $term_ids Positive term IDs.
	 * @phpstan-param non-empty-list<int> $term_ids
	 */
	public function __construct(
		public string $taxonomy,
		public array $term_ids,
	) {
	}

	/**
	 * Builds a filter from untrusted adapter input.
	 *
	 * @param mixed $input Raw filter input.
	 * @return self
	 * @throws InvalidArgumentException When the shape or bounds are invalid.
	 */
	public static function from_input( mixed $input ): self {
		if ( ! is_array( $input ) ) {
			throw new InvalidArgumentException( 'A taxonomy filter must be an object.' );
		}

		$allowed_keys = array( 'taxonomy', 'term_ids' );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed_keys, true ) ) {
				throw new InvalidArgumentException( 'A taxonomy filter contains an unsupported field.' );
			}
		}

		$taxonomy = $input['taxonomy'] ?? null;
		$term_ids = $input['term_ids'] ?? null;

		if ( ! is_string( $taxonomy ) || 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/', $taxonomy ) ) {
			throw new InvalidArgumentException( 'A taxonomy filter contains an invalid taxonomy name.' );
		}

		if ( ! is_array( $term_ids ) || array() === $term_ids || count( $term_ids ) > 100 ) {
			throw new InvalidArgumentException( 'A taxonomy filter must contain between 1 and 100 term IDs.' );
		}

		$normalized_ids = array();
		foreach ( $term_ids as $term_id ) {
			if ( ! is_int( $term_id ) || $term_id < 1 ) {
				throw new InvalidArgumentException( 'A taxonomy filter contains an invalid term ID.' );
			}
			$normalized_ids[] = $term_id;
		}

		$unique_ids = array_values( array_unique( $normalized_ids ) );

		return new self( $taxonomy, $unique_ids );
	}
}
