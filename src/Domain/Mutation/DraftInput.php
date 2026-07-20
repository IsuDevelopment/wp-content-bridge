<?php
/**
 * Validated input for creating a new draft.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable, validated new-post input. Status is always draft; there is no
 * status field on this DTO by design.
 */
final readonly class DraftInput {

	private const POST_TYPE_PATTERN = '/^[a-z0-9_-]{1,20}$/';
	private const KEY_PATTERN       = '/^[A-Za-z0-9_.\-]{1,128}$/';
	private const MAX_TITLE         = 500;
	private const MAX_EXCERPT       = 2000;
	private const MAX_MARKUP_BYTES  = 500000;

	/**
	 * Creates a draft input.
	 *
	 * @param string  $post_type Post type name.
	 * @param string  $title Post title.
	 * @param string  $block_markup Block HTML markup.
	 * @param ?string $excerpt Post excerpt.
	 * @param array   $taxonomies Taxonomy assignments.
	 * @phpstan-param array<int, TaxonomyAssignment> $taxonomies Bounded taxonomy assignments.
	 * @param ?string $idempotency_key Idempotency key for deduplication.
	 */
	public function __construct(
		public string $post_type,
		public string $title,
		public string $block_markup,
		public ?string $excerpt,
		public array $taxonomies,
		public ?string $idempotency_key,
	) {}

	/**
	 * Build from untrusted input.
	 *
	 * @param array<string, mixed> $input Raw create-draft input.
	 * @throws InvalidArgumentException When input is malformed.
	 */
	public static function from_input( array $input ): self {
		$allowed = array( 'post_type', 'title', 'block_markup', 'excerpt', 'taxonomies', 'idempotency_key' );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Create-draft input contains an unsupported field.' );
			}
		}

		$post_type = $input['post_type'] ?? null;
		if ( ! is_string( $post_type ) || 1 !== preg_match( self::POST_TYPE_PATTERN, $post_type ) ) {
			throw new InvalidArgumentException( 'A post type is invalid.' );
		}

		$title = $input['title'] ?? null;
		if ( ! is_string( $title ) ) {
			throw new InvalidArgumentException( 'A title is required.' );
		}
		$title = trim( $title );
		if ( '' === $title ) {
			throw new InvalidArgumentException( 'A title must not be empty.' );
		}
		if ( mb_strlen( $title ) > self::MAX_TITLE ) {
			throw new InvalidArgumentException( 'A title must be at most 500 characters.' );
		}

		$block_markup = $input['block_markup'] ?? '';
		if ( ! is_string( $block_markup ) ) {
			throw new InvalidArgumentException( 'Block markup must be a string.' );
		}
		if ( strlen( $block_markup ) > self::MAX_MARKUP_BYTES ) {
			throw new InvalidArgumentException( 'Block markup exceeds the size limit.' );
		}

		$excerpt = null;
		if ( array_key_exists( 'excerpt', $input ) && null !== $input['excerpt'] ) {
			$candidate = $input['excerpt'];
			if ( ! is_string( $candidate ) || mb_strlen( $candidate ) > self::MAX_EXCERPT ) {
				throw new InvalidArgumentException( 'An excerpt is invalid.' );
			}
			$excerpt = $candidate;
		}

		$taxonomies = array();
		if ( array_key_exists( 'taxonomies', $input ) && null !== $input['taxonomies'] ) {
			if ( ! is_array( $input['taxonomies'] ) ) {
				throw new InvalidArgumentException( 'Taxonomies must be an array.' );
			}
			foreach ( $input['taxonomies'] as $assignment ) {
				$taxonomies[] = TaxonomyAssignment::from_input( $assignment );
			}
		}

		$idempotency_key = null;
		if ( array_key_exists( 'idempotency_key', $input ) && null !== $input['idempotency_key'] ) {
			$candidate = $input['idempotency_key'];
			if ( ! is_string( $candidate ) || 1 !== preg_match( self::KEY_PATTERN, $candidate ) ) {
				throw new InvalidArgumentException( 'An idempotency key is invalid.' );
			}
			$idempotency_key = $candidate;
		}

		return new self( $post_type, $title, $block_markup, $excerpt, $taxonomies, $idempotency_key );
	}
}
