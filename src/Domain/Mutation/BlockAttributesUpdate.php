<?php
/**
 * Validated input for merging a JSON object into one block's attrs,
 * addressed by path.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;
use JsonException;

/**
 * Immutable, validated path-addressed attribute merge. `expected_block_name`
 * asserts one exact fact about the node at `path`, exactly as BlockUpdate
 * requires it; a matching version token alone proves only that the document
 * did not change, not that the path points where the caller believes.
 * `attributes` is a shallow overlay onto the block's existing `attrs`: a
 * `null` value removes that key, any other value sets it, and keys absent
 * from `attributes` are left untouched.
 */
final readonly class BlockAttributesUpdate {

	private const MAX_PATH_DEPTH       = 20;
	private const MAX_ATTRIBUTES_BYTES = 100000;
	private const MAX_ATTRIBUTES_KEYS  = 50;

	/**
	 * Create a block attributes update.
	 *
	 * @param int          $post_id             The post to update.
	 * @param VersionToken $expected_version    The version token for concurrency control.
	 * @param array        $path                Zero-based indices into successive innerBlocks arrays.
	 * @param string|null  $expected_block_name Registered block name asserted at path, or null for a freeform node.
	 * @param array        $attributes          Shallow attrs overlay; a null value removes that key.
	 * @phpstan-param list<int> $path
	 * @phpstan-param array<int|string, mixed> $attributes
	 */
	public function __construct(
		public int $post_id,
		public VersionToken $expected_version,
		public array $path,
		public ?string $expected_block_name,
		public array $attributes,
	) {}

	/**
	 * Build from untrusted input.
	 *
	 * @param array<string, mixed> $input Raw update-block-attributes input.
	 * @return self
	 * @throws InvalidArgumentException When input is malformed, empty, or exceeds a bound.
	 */
	public static function from_input( array $input ): self {
		$allowed = array( 'post_id', 'version_token', 'path', 'expected_block_name', 'attributes' );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Update-block-attributes input contains an unsupported field.' );
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

		if ( ! array_key_exists( 'path', $input ) || ! is_array( $input['path'] ) || array() === $input['path'] ) {
			throw new InvalidArgumentException( 'A path is required and must be a non-empty list of non-negative integers.' );
		}
		if ( count( $input['path'] ) > self::MAX_PATH_DEPTH ) {
			throw new InvalidArgumentException( 'A path exceeds the maximum supported depth.' );
		}
		$path = array();
		foreach ( array_values( $input['path'] ) as $index ) {
			if ( ! is_int( $index ) || 0 > $index ) {
				throw new InvalidArgumentException( 'A path must contain only non-negative integers.' );
			}
			$path[] = $index;
		}

		if ( ! array_key_exists( 'expected_block_name', $input ) ) {
			throw new InvalidArgumentException( 'An expected block name is required.' );
		}
		$expected_block_name = null;
		if ( null !== $input['expected_block_name'] ) {
			if ( ! is_string( $input['expected_block_name'] ) || '' === $input['expected_block_name'] ) {
				throw new InvalidArgumentException( 'An expected block name is invalid.' );
			}
			$expected_block_name = $input['expected_block_name'];
		}

		$attributes = self::validated_attributes( $input );

		return new self( $post_id, $expected_version, $path, $expected_block_name, $attributes );
	}

	/**
	 * Validates and bounds the attribute overlay. It already arrives decoded
	 * (the wire contract is a JSON object, not a JSON string), so this
	 * checks shape and bounds rather than decoding: the top level must be an
	 * object (not a JSON array) within a key-count bound, and its canonical
	 * JSON encoding must be within a byte bound.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array
	 * @phpstan-return array<int|string, mixed>
	 * @throws InvalidArgumentException When attributes are missing, malformed, or exceed a bound.
	 */
	private static function validated_attributes( array $input ): array {
		if ( ! array_key_exists( 'attributes', $input ) || ! is_array( $input['attributes'] ) ) {
			throw new InvalidArgumentException( 'Attributes are required and must be a JSON object.' );
		}
		$attributes = $input['attributes'];

		if ( array() !== $attributes && array_is_list( $attributes ) ) {
			throw new InvalidArgumentException( 'Attributes must be a JSON object, not a list.' );
		}
		if ( count( $attributes ) > self::MAX_ATTRIBUTES_KEYS ) {
			throw new InvalidArgumentException( 'Attributes exceed the maximum supported key count.' );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- domain code cannot depend on WordPress functions.
			$encoded = json_encode( $attributes, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Attributes must be valid, encodable JSON.' );
		}
		if ( self::MAX_ATTRIBUTES_BYTES < strlen( $encoded ) ) {
			throw new InvalidArgumentException( 'Attributes exceed the 100000-byte limit.' );
		}

		return $attributes;
	}
}
