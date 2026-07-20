<?php
/**
 * Validated taxonomy + term assignment for write operations.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable taxonomy assignment (taxonomy name + a bounded list of term IDs).
 *
 * Mirrors the read-side TaxonomyFilter shape but expresses intent to assign
 * terms on a write. Validity of the taxonomy against the target post type is
 * enforced later, in Infrastructure.
 */
final readonly class TaxonomyAssignment {

	private const TAXONOMY_PATTERN = '/^[a-z0-9_-]{1,32}$/';
	private const MAX_TERMS        = 100;

	/**
	 * Creates a taxonomy assignment.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $term_ids Positive term IDs.
	 * @phpstan-param array<int, int> $term_ids Non-empty list of unique positive term IDs.
	 */
	public function __construct(
		public string $taxonomy,
		public array $term_ids,
	) {}

	/**
	 * Build from untrusted input.
	 *
	 * @param mixed $input Raw assignment.
	 * @throws InvalidArgumentException When the assignment is malformed.
	 */
	public static function from_input( mixed $input ): self {
		if ( ! is_array( $input ) ) {
			throw new InvalidArgumentException( 'A taxonomy assignment must be an object.' );
		}

		$allowed = array( 'taxonomy', 'term_ids' );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'A taxonomy assignment contains an unsupported field.' );
			}
		}

		$taxonomy = $input['taxonomy'] ?? null;
		if ( ! is_string( $taxonomy ) || 1 !== preg_match( self::TAXONOMY_PATTERN, $taxonomy ) ) {
			throw new InvalidArgumentException( 'A taxonomy name is invalid.' );
		}

		$raw_terms = $input['term_ids'] ?? null;
		if ( ! is_array( $raw_terms ) || array() === $raw_terms ) {
			throw new InvalidArgumentException( 'A taxonomy assignment requires at least one term ID.' );
		}

		$term_ids = array();
		foreach ( $raw_terms as $term_id ) {
			if ( ! is_int( $term_id ) || 0 >= $term_id ) {
				throw new InvalidArgumentException( 'Term IDs must be positive integers.' );
			}
			$term_ids[] = $term_id;
		}

		$term_ids = array_values( array_unique( $term_ids ) );
		if ( count( $term_ids ) > self::MAX_TERMS ) {
			throw new InvalidArgumentException( 'A taxonomy assignment allows at most 100 term IDs.' );
		}

		return new self( $taxonomy, $term_ids );
	}
}
