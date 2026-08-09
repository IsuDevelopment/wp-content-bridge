<?php
/**
 * Validated input for transitioning an existing post's status.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Status\ContentStatus;

/**
 * Immutable, structurally-validated transition-content-status request.
 *
 * Only shape is validated here: field set, types, and the presence/absence
 * relationship between `target_status` and `publish_at`. Whether the
 * `publish_at` string actually names an existing, future instant is a
 * timezone-aware question this class cannot answer without WordPress
 * (`wp_timezone()`, the current time) and is deliberately left to
 * {@see \IsuDev\WPContentBridge\Domain\Status\PublishAt::from_local_string()},
 * invoked by the use case once the site clock is available.
 */
final readonly class TransitionStatusInput {

	/**
	 * Creates a transition-content-status request.
	 *
	 * @param int           $post_id          Target post ID.
	 * @param VersionToken  $expected_version Expected object version.
	 * @param ContentStatus $target_status    Requested target status.
	 * @param string|null   $publish_at       Raw wire-format local date-time, present only when `$target_status` is `future`.
	 */
	public function __construct(
		public int $post_id,
		public VersionToken $expected_version,
		public ContentStatus $target_status,
		public ?string $publish_at,
	) {
	}

	/**
	 * Builds a request from untrusted input.
	 *
	 * @param array<string, mixed> $input Raw ability input.
	 * @return self
	 * @throws InvalidArgumentException When input is malformed, or `publish_at` is present/absent inconsistently with `target_status`.
	 */
	public static function from_input( array $input ): self {
		$allowed = array( 'post_id', 'version_token', 'target_status', 'publish_at' );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Transition-content-status input contains an unsupported field.' );
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

		$target_status_raw = $input['target_status'] ?? null;
		if ( ! is_string( $target_status_raw ) ) {
			throw new InvalidArgumentException( 'A target_status is required.' );
		}
		$target_status = ContentStatus::tryFrom( $target_status_raw );
		if ( null === $target_status ) {
			throw new InvalidArgumentException( 'target_status must be one of the five permitted statuses.' );
		}

		$publish_at_present = array_key_exists( 'publish_at', $input ) && null !== $input['publish_at'];

		if ( ContentStatus::FUTURE === $target_status ) {
			if ( ! $publish_at_present || ! is_string( $input['publish_at'] ) || '' === $input['publish_at'] ) {
				throw new InvalidArgumentException( 'publish_at is required when target_status is future.' );
			}
		} elseif ( $publish_at_present ) {
			throw new InvalidArgumentException( 'publish_at is only accepted when target_status is future.' );
		}

		return new self(
			$post_id,
			$expected_version,
			$target_status,
			ContentStatus::FUTURE === $target_status ? $input['publish_at'] : null
		);
	}
}
