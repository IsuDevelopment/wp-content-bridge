<?php
/**
 * Validated input for replacing one block subtree, addressed by path.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable, validated path-addressed block update. `expected_block_name`
 * asserts one exact fact about the node at `path`; a matching version token
 * alone proves only that the document did not change, not that the path
 * points where the caller believes. `block_markup` may be empty to delete
 * the addressed subtree.
 */
final readonly class BlockUpdate {

	private const MAX_MARKUP_BYTES = 500000;
	private const MAX_PATH_DEPTH   = 20;

	/**
	 * Create a block update.
	 *
	 * @param int          $post_id             The post to update.
	 * @param VersionToken $expected_version    The version token for concurrency control.
	 * @param array        $path                Zero-based indices into successive innerBlocks arrays.
	 * @param string|null  $expected_block_name Registered block name asserted at path, or null for a freeform node.
	 * @param string       $block_markup        Replacement markup for the subtree; empty deletes it.
	 * @phpstan-param list<int> $path
	 */
	public function __construct(
		public int $post_id,
		public VersionToken $expected_version,
		public array $path,
		public ?string $expected_block_name,
		public string $block_markup,
	) {}

	/**
	 * Build from untrusted input.
	 *
	 * @param array<string, mixed> $input Raw update-block/preview-update-block input.
	 * @return self
	 * @throws InvalidArgumentException When input is malformed or a required field is missing.
	 */
	public static function from_input( array $input ): self {
		$allowed = array( 'post_id', 'version_token', 'path', 'expected_block_name', 'block_markup' );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Update-block input contains an unsupported field.' );
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

		if ( ! array_key_exists( 'block_markup', $input )
			|| ! is_string( $input['block_markup'] )
			|| strlen( $input['block_markup'] ) > self::MAX_MARKUP_BYTES
		) {
			throw new InvalidArgumentException( 'Block markup is required and must be a string within the size limit.' );
		}

		return new self( $post_id, $expected_version, $path, $expected_block_name, $input['block_markup'] );
	}
}
