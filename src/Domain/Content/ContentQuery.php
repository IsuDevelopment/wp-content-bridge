<?php
/**
 * Normalized content search query.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Content;

use InvalidArgumentException;

/**
 * Immutable, transport-neutral search criteria.
 */
final readonly class ContentQuery {

	/**
	 * Creates normalized criteria.
	 *
	 * @param string      $query        Free-text query.
	 * @param array       $post_types   Requested post types.
	 * @param array       $statuses     Requested statuses.
	 * @param array       $author_ids   Requested author IDs.
	 * @param array       $taxonomy_filters Taxonomy filters.
	 * @param int         $page         One-based page.
	 * @param int         $per_page     Results per page.
	 * @param string      $order_by     Sort field.
	 * @param string      $order        Sort direction.
	 * @param string|null $published_after  Inclusive lower publication bound.
	 * @param string|null $published_before Inclusive upper publication bound.
	 * @param string|null $modified_after   Inclusive lower modification bound.
	 * @param string|null $modified_before  Inclusive upper modification bound.
	 * @phpstan-param list<string> $post_types
	 * @phpstan-param list<string> $statuses
	 * @phpstan-param list<int> $author_ids
	 * @phpstan-param list<TaxonomyFilter> $taxonomy_filters
	 */
	public function __construct(
		public string $query,
		public array $post_types,
		public array $statuses,
		public array $author_ids,
		public array $taxonomy_filters,
		public int $page,
		public int $per_page,
		public string $order_by,
		public string $order,
		public ?string $published_after,
		public ?string $published_before,
		public ?string $modified_after,
		public ?string $modified_before,
	) {
	}

	/**
	 * Builds a query from untrusted adapter input.
	 *
	 * JSON Schema defaults are intentionally repeated here because the Abilities
	 * API validates defaults but does not inject them into callback input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return self
	 */
	public static function from_input( array $input ): self {
		$page        = self::integer( $input['page'] ?? 1, 1, PHP_INT_MAX );
		$per_page    = self::integer( $input['per_page'] ?? 20, 1, 100 );
		$order_by    = self::enum( $input['order_by'] ?? 'relevance', array( 'relevance', 'date', 'modified', 'title', 'id' ) );
		$order_input = $input['order'] ?? 'desc';
		$order       = self::enum( is_string( $order_input ) ? strtolower( $order_input ) : $order_input, array( 'asc', 'desc' ) );

		return new self(
			self::optional_string( $input['query'] ?? '' ),
			self::string_list( $input['post_types'] ?? array() ),
			self::string_list( $input['statuses'] ?? array( 'publish' ) ),
			self::integer_list( $input['author_ids'] ?? array() ),
			self::taxonomy_filters( $input['taxonomy'] ?? array() ),
			$page,
			$per_page,
			$order_by,
			$order,
			self::date_time( $input['published_after'] ?? null ),
			self::date_time( $input['published_before'] ?? null ),
			self::date_time( $input['modified_after'] ?? null ),
			self::date_time( $input['modified_before'] ?? null ),
		);
	}

	/**
	 * Validates an optional string.
	 *
	 * @param mixed $value Candidate value.
	 * @return string
	 * @throws InvalidArgumentException When invalid.
	 */
	private static function optional_string( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'A text field must be a string.' );
		}

		return trim( $value );
	}

	/**
	 * Returns a copy constrained to effective post types.
	 *
	 * @param array $post_types Effective types.
	 * @return self
	 * @phpstan-param list<string> $post_types
	 */
	public function with_post_types( array $post_types ): self {
		return new self(
			$this->query,
			$post_types,
			$this->statuses,
			$this->author_ids,
			$this->taxonomy_filters,
			$this->page,
			$this->per_page,
			$this->order_by,
			$this->order,
			$this->published_after,
			$this->published_before,
			$this->modified_after,
			$this->modified_before,
		);
	}

	/**
	 * Validates bounded taxonomy filters.
	 *
	 * @param mixed $value Candidate filter list.
	 * @return list<TaxonomyFilter>
	 * @throws InvalidArgumentException When invalid or duplicated.
	 */
	private static function taxonomy_filters( mixed $value ): array {
		if ( ! is_array( $value ) || count( $value ) > 10 ) {
			throw new InvalidArgumentException( 'Taxonomy filters must be an array with at most 10 items.' );
		}

		$filters = array();
		$seen    = array();
		foreach ( $value as $item ) {
			$filter = TaxonomyFilter::from_input( $item );
			if ( isset( $seen[ $filter->taxonomy ] ) ) {
				throw new InvalidArgumentException( 'Each taxonomy may appear only once.' );
			}

			$seen[ $filter->taxonomy ] = true;
			$filters[]                 = $filter;
		}

		return $filters;
	}

	/**
	 * Validates a bounded integer.
	 *
	 * @param mixed $value   Candidate value.
	 * @param int   $minimum Lower bound.
	 * @param int   $maximum Upper bound.
	 * @return int
	 * @throws InvalidArgumentException When invalid.
	 */
	private static function integer( mixed $value, int $minimum, int $maximum ): int {
		if ( ! is_int( $value ) || $value < $minimum || $value > $maximum ) {
			throw new InvalidArgumentException( 'An integer is outside the allowed range.' );
		}

		return $value;
	}

	/**
	 * Validates an enum value.
	 *
	 * @param mixed $value   Candidate value.
	 * @param array $allowed Allowed values.
	 * @return string
	 * @phpstan-param list<string> $allowed
	 * @throws InvalidArgumentException When invalid.
	 */
	private static function enum( mixed $value, array $allowed ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( 'A field contains an unsupported value.' );
		}

		return $value;
	}

	/**
	 * Validates a string list.
	 *
	 * @param mixed $value Candidate list.
	 * @return list<string>
	 * @throws InvalidArgumentException When invalid.
	 */
	private static function string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'A selection must be an array.' );
		}

		$result = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item ) {
				throw new InvalidArgumentException( 'A selection contains an invalid value.' );
			}
			$result[] = $item;
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Validates an integer list.
	 *
	 * @param mixed $value Candidate list.
	 * @return list<int>
	 * @throws InvalidArgumentException When invalid.
	 */
	private static function integer_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'A selection must be an array.' );
		}

		$result = array();
		foreach ( $value as $item ) {
			if ( ! is_int( $item ) || $item < 1 ) {
				throw new InvalidArgumentException( 'A selection contains an invalid value.' );
			}
			$result[] = $item;
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Validates an optional date-time.
	 *
	 * @param mixed $value Candidate date-time.
	 * @return string|null
	 * @throws InvalidArgumentException When invalid.
	 */
	private static function date_time( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_string( $value ) || false === strtotime( $value ) ) {
			throw new InvalidArgumentException( 'A date field must be an ISO 8601 date-time.' );
		}

		return $value;
	}
}
