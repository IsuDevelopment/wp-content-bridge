<?php
/**
 * Editorial-context query.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Editorial;

use InvalidArgumentException;

/**
 * Immutable, bounded selection of editorial-context sections.
 */
final readonly class EditorialContextQuery {

	public const SECTIONS = array( 'post_types', 'taxonomies', 'terms', 'authors', 'recent_content', 'local_businesses' );

	/**
	 * Creates normalized editorial-context criteria.
	 *
	 * @param array $sections           Requested sections.
	 * @param array $post_types         Requested content types.
	 * @param array $taxonomies         Requested taxonomy slugs.
	 * @param int   $recent_limit       Maximum recent summaries.
	 * @param int   $terms_per_taxonomy Maximum terms per taxonomy.
	 * @phpstan-param non-empty-list<string> $sections
	 * @phpstan-param list<string> $post_types
	 * @phpstan-param list<string> $taxonomies
	 */
	public function __construct(
		public array $sections,
		public array $post_types,
		public array $taxonomies,
		public int $recent_limit,
		public int $terms_per_taxonomy,
	) {
	}

	/**
	 * Builds a query from untrusted adapter input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return self
	 * @throws InvalidArgumentException When input violates the domain bounds.
	 */
	public static function from_input( array $input ): self {
		$sections = self::string_list( $input['sections'] ?? self::SECTIONS, self::SECTIONS, 6, false );
		if ( array() === $sections ) {
			throw new InvalidArgumentException( 'At least one editorial section is required.' );
		}

		return new self(
			$sections,
			self::string_list( $input['post_types'] ?? array(), null, 20, true ),
			self::string_list( $input['taxonomies'] ?? array(), null, 20, true ),
			self::bounded_integer( $input['recent_limit'] ?? 20, 1, 50 ),
			self::bounded_integer( $input['terms_per_taxonomy'] ?? 50, 1, 100 ),
		);
	}

	/**
	 * Checks whether one section was requested.
	 *
	 * @param string $section Section key.
	 * @return bool
	 */
	public function includes( string $section ): bool {
		return in_array( $section, $this->sections, true );
	}

	/**
	 * Validates a bounded unique slug/enum list.
	 *
	 * @param mixed      $value       Candidate list.
	 * @param array|null $allowed     Optional enum values.
	 * @param int        $maximum     Maximum members.
	 * @param bool       $allow_empty Whether an empty list is valid.
	 * @return list<string>
	 * @phpstan-param list<string>|null $allowed
	 * @throws InvalidArgumentException When the selection is invalid.
	 */
	private static function string_list( mixed $value, ?array $allowed, int $maximum, bool $allow_empty ): array {
		if ( ! is_array( $value ) || count( $value ) > $maximum ) {
			throw new InvalidArgumentException( 'An editorial selection exceeds its allowed bounds.' );
		}

		$result = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/', $item ) ) {
				throw new InvalidArgumentException( 'An editorial selection contains an invalid value.' );
			}
			if ( null !== $allowed && ! in_array( $item, $allowed, true ) ) {
				throw new InvalidArgumentException( 'An editorial section is unsupported.' );
			}
			$result[] = $item;
		}

		$result = array_values( array_unique( $result ) );
		if ( ! $allow_empty && array() === $result ) {
			throw new InvalidArgumentException( 'At least one editorial section is required.' );
		}

		return $result;
	}

	/**
	 * Validates one integer bound.
	 *
	 * @param mixed $value   Candidate value.
	 * @param int   $minimum Minimum.
	 * @param int   $maximum Maximum.
	 * @return int
	 * @throws InvalidArgumentException When the integer is outside its bound.
	 */
	private static function bounded_integer( mixed $value, int $minimum, int $maximum ): int {
		if ( ! is_int( $value ) || $value < $minimum || $value > $maximum ) {
			throw new InvalidArgumentException( 'An editorial integer is outside the allowed range.' );
		}

		return $value;
	}
}
