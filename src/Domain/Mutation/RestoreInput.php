<?php
/**
 * Validated input for restoring content from trash.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable restore request with optimistic concurrency.
 */
final readonly class RestoreInput {

	/**
	 * Creates a restore request.
	 *
	 * @param int          $post_id          Target post ID.
	 * @param VersionToken $expected_version Expected object version.
	 */
	public function __construct(
		public int $post_id,
		public VersionToken $expected_version,
	) {}

	/**
	 * Builds a request from untrusted input.
	 *
	 * @param array<string, mixed> $input Raw ability input.
	 * @return self
	 * @throws InvalidArgumentException When input is malformed.
	 */
	public static function from_input( array $input ): self {
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, array( 'post_id', 'version_token' ), true ) ) {
				throw new InvalidArgumentException( 'Restore-trashed-content input contains an unsupported field.' );
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

		return new self( $post_id, VersionToken::from_string( $token ) );
	}
}
