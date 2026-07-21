<?php
/**
 * Validated input for updating an existing post.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable, validated existing-post update. Carries no status field; updates
 * never change post status.
 */
final readonly class ContentUpdate {

	private const MAX_TITLE        = 500;
	private const MAX_EXCERPT      = 2000;
	private const MAX_MARKUP_BYTES = 500000;

	/**
	 * Create a content update.
	 *
	 * @param int                                 $post_id The post to update.
	 * @param VersionToken                        $expected_version The version token for concurrency control.
	 * @param string|null                         $title The new title, or null to leave unchanged.
	 * @param string|null                         $block_markup The new block markup content, or null to leave unchanged.
	 * @param string|null                         $excerpt The new excerpt, or null to leave unchanged.
	 * @param array<int, TaxonomyAssignment>|null $taxonomies Bounded assignments, or null when unchanged.
	 */
	public function __construct(
		public int $post_id,
		public VersionToken $expected_version,
		public ?string $title,
		public ?string $block_markup,
		public ?string $excerpt,
		public ?array $taxonomies,
	) {}

	/**
	 * Build from untrusted input.
	 *
	 * @param array<string, mixed> $input Raw update-content input.
	 * @throws InvalidArgumentException When input is malformed or empty.
	 */
	public static function from_input( array $input ): self {
		$allowed = array( 'post_id', 'version_token', 'title', 'block_markup', 'excerpt', 'taxonomies' );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Update-content input contains an unsupported field.' );
			}
		}

		$post_id = $input['post_id'] ?? null;
		if ( ! is_int( $post_id ) || 0 >= $post_id ) {
			throw new InvalidArgumentException( 'A post ID must be a positive integer.' );
		}

		$token = $input['version_token'] ?? null;
		if ( ! is_string( $token ) ) {
			throw new InvalidArgumentException( 'A version token is required.' );
		}
		$expected_version = VersionToken::from_string( $token );

		$title = null;
		if ( array_key_exists( 'title', $input ) && null !== $input['title'] ) {
			$candidate = $input['title'];
			if ( ! is_string( $candidate ) ) {
				throw new InvalidArgumentException( 'A title is invalid.' );
			}
			$candidate = trim( $candidate );
			if ( '' === $candidate || mb_strlen( $candidate ) > self::MAX_TITLE ) {
				throw new InvalidArgumentException( 'A title is invalid.' );
			}
			$title = $candidate;
		}

		$block_markup = null;
		if ( array_key_exists( 'block_markup', $input ) && null !== $input['block_markup'] ) {
			$candidate = $input['block_markup'];
			if ( ! is_string( $candidate ) || strlen( $candidate ) > self::MAX_MARKUP_BYTES ) {
				throw new InvalidArgumentException( 'Block markup is invalid.' );
			}
			$block_markup = $candidate;
		}

		$excerpt = null;
		if ( array_key_exists( 'excerpt', $input ) && null !== $input['excerpt'] ) {
			$candidate = $input['excerpt'];
			if ( ! is_string( $candidate ) || mb_strlen( $candidate ) > self::MAX_EXCERPT ) {
				throw new InvalidArgumentException( 'An excerpt is invalid.' );
			}
			$excerpt = $candidate;
		}

		$taxonomies = null;
		if ( array_key_exists( 'taxonomies', $input ) && null !== $input['taxonomies'] ) {
			if ( ! is_array( $input['taxonomies'] ) ) {
				throw new InvalidArgumentException( 'Taxonomies must be an array.' );
			}
			$taxonomies = array();
			foreach ( $input['taxonomies'] as $assignment ) {
				$taxonomies[] = TaxonomyAssignment::from_input( $assignment );
			}
		}

		if ( null === $title && null === $block_markup && null === $excerpt && null === $taxonomies ) {
			throw new InvalidArgumentException( 'An update must change at least one field.' );
		}

		return new self( $post_id, $expected_version, $title, $block_markup, $excerpt, $taxonomies );
	}

	/**
	 * Names of the fields this update changes (for audit + result).
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		$fields = array();
		if ( null !== $this->title ) {
			$fields[] = 'title';
		}
		if ( null !== $this->block_markup ) {
			$fields[] = 'content';
		}
		if ( null !== $this->excerpt ) {
			$fields[] = 'excerpt';
		}
		if ( null !== $this->taxonomies ) {
			$fields[] = 'taxonomies';
		}

		return $fields;
	}
}
