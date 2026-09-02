<?php
/**
 * Validated featured-image write input.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * One post's prospective featured image, or its explicit removal.
 */
final readonly class FeaturedImageUpdate {

	/**
	 * Creates the validated update.
	 *
	 * @param int          $post_id          Target post ID.
	 * @param int|null     $attachment_id    Attachment to assign, or null to remove.
	 * @param VersionToken $expected_version Optimistic-concurrency token.
	 */
	private function __construct(
		public int $post_id,
		public ?int $attachment_id,
		public VersionToken $expected_version,
	) {}

	/**
	 * Builds the update from normalized Ability input.
	 *
	 * `attachment_id` is required and explicitly nullable rather than optional:
	 * removing a featured image and leaving it alone are different intents, and
	 * an omitted key cannot express the first without silently risking the
	 * second on a malformed request.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return self
	 * @throws InvalidArgumentException When the request is malformed.
	 */
	public static function from_input( array $input ): self {
		$allowed = array( 'post_id', 'version_token', 'attachment_id' );
		$keys    = array_keys( $input );
		sort( $keys );
		$expected = $allowed;
		sort( $expected );
		if ( $keys !== $expected ) {
			throw new InvalidArgumentException( 'A featured-image update requires exactly post_id, version_token, and attachment_id.' );
		}

		$post_id = $input['post_id'];
		if ( ! is_int( $post_id ) || 0 >= $post_id ) {
			throw new InvalidArgumentException( 'A post ID must be a positive integer.' );
		}

		$attachment_id = $input['attachment_id'];
		if ( null !== $attachment_id && ( ! is_int( $attachment_id ) || 0 >= $attachment_id ) ) {
			throw new InvalidArgumentException( 'An attachment ID must be a positive integer, or null to remove the featured image.' );
		}

		$token = $input['version_token'];
		if ( ! is_string( $token ) ) {
			throw new InvalidArgumentException( 'A version token must be a string.' );
		}

		return new self( $post_id, $attachment_id, VersionToken::from_string( $token ) );
	}

	/**
	 * Whether the update removes the featured image.
	 */
	public function removes(): bool {
		return null === $this->attachment_id;
	}

	/**
	 * Returns the audit field names. Never the attachment ID itself.
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		return array( 'featured_image' );
	}
}
