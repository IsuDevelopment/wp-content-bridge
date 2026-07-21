<?php
/**
 * Block-pattern listing criteria.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Pattern;

use InvalidArgumentException;

/**
 * Immutable and bounded pattern query.
 */
final readonly class PatternQuery {

	/**
	 * Creates validated listing criteria.
	 *
	 * @param string      $query           Text query.
	 * @param string|null $pattern_namespace Exact pattern namespace.
	 * @param string|null $category        Exact category slug.
	 * @param string|null $post_type       Exact post-type slug.
	 * @param bool        $include_content Whether complete block markup is requested.
	 * @param int         $page            One-based page.
	 * @param int         $per_page        Page size.
	 */
	public function __construct(
		public string $query,
		public ?string $pattern_namespace,
		public ?string $category,
		public ?string $post_type,
		public bool $include_content,
		public int $page,
		public int $per_page,
	) {
	}

	/**
	 * Builds criteria from untrusted adapter input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return self
	 * @throws InvalidArgumentException When a filter or bound is invalid.
	 */
	public static function from_input( array $input ): self {
		$query             = $input['query'] ?? '';
		$pattern_namespace = $input['namespace'] ?? null;
		$category          = $input['category'] ?? null;
		$post_type         = $input['post_type'] ?? null;
		$include_content   = $input['include_content'] ?? false;
		$page              = $input['page'] ?? 1;
		$per_page          = $input['per_page'] ?? 20;

		if ( ! is_string( $query ) || 200 < strlen( $query ) ) {
			throw new InvalidArgumentException( 'query must be a string of at most 200 bytes.' );
		}
		self::assert_slug( $pattern_namespace, 'namespace', 100 );
		self::assert_slug( $category, 'category', 100 );
		self::assert_slug( $post_type, 'post_type', 20 );
		if ( ! is_bool( $include_content ) ) {
			throw new InvalidArgumentException( 'include_content must be a boolean.' );
		}
		if ( ! is_int( $page ) || 1 > $page || ! is_int( $per_page ) || 1 > $per_page || 50 < $per_page ) {
			throw new InvalidArgumentException( 'Pattern pagination is invalid.' );
		}

		return new self(
			trim( $query ),
			is_string( $pattern_namespace ) ? trim( $pattern_namespace ) : null,
			is_string( $category ) ? trim( $category ) : null,
			is_string( $post_type ) ? trim( $post_type ) : null,
			$include_content,
			$page,
			$per_page,
		);
	}

	/**
	 * Validates one bounded WordPress-style identifier.
	 *
	 * @param mixed  $value  Identifier.
	 * @param string $field  Public field name.
	 * @param int    $length Maximum byte length.
	 * @return void
	 * @throws InvalidArgumentException When invalid.
	 */
	private static function assert_slug( mixed $value, string $field, int $length ): void {
		if ( null === $value ) {
			return;
		}

		if (
			! is_string( $value )
			|| '' === trim( $value )
			|| $length < strlen( $value )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value )
		) {
			if ( 'namespace' === $field ) {
				throw new InvalidArgumentException( 'namespace must be one bounded identifier.' );
			}
			if ( 'category' === $field ) {
				throw new InvalidArgumentException( 'category must be one bounded identifier.' );
			}
			if ( 'post_type' === $field ) {
				throw new InvalidArgumentException( 'post_type must be one bounded identifier.' );
			}

			throw new InvalidArgumentException( 'Pattern identifier is invalid.' );
		}
	}
}
