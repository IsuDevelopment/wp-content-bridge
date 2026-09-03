<?php
/**
 * Validated permalink (slug) update.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * One post's prospective slug.
 *
 * The slug is the only thing this can change. Permalink *structure* is a
 * site-wide setting, and a rewrite-rule change would alter every URL at once,
 * which is not a per-object edit and does not belong on this contract.
 */
final readonly class PermalinkUpdate {

	private const MAX_SLUG_LENGTH = 200;

	/**
	 * Creates the validated update.
	 *
	 * @param int          $post_id          Target post ID.
	 * @param string       $requested_slug   Caller's slug, bounded but not yet normalized.
	 * @param VersionToken $expected_version Optimistic-concurrency token.
	 */
	private function __construct(
		public int $post_id,
		public string $requested_slug,
		public VersionToken $expected_version,
	) {}

	/**
	 * Builds the update from normalized Ability input.
	 *
	 * Normalization is deliberately **not** done here. Turning "Hello World!"
	 * into a slug is WordPress's own `sanitize_title()` behaviour, and the
	 * domain layer does not call WordPress. The application layer normalizes
	 * through `SlugNormalizer` and refuses a slug that normalizes away.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return self
	 * @throws InvalidArgumentException When the request is malformed.
	 */
	public static function from_input( array $input ): self {
		$expected = array( 'post_id', 'version_token', 'slug' );
		$keys     = array_keys( $input );
		sort( $keys );
		sort( $expected );
		if ( $keys !== $expected ) {
			throw new InvalidArgumentException( 'A permalink update requires exactly post_id, version_token, and slug.' );
		}

		$post_id = $input['post_id'];
		if ( ! is_int( $post_id ) || 0 >= $post_id ) {
			throw new InvalidArgumentException( 'A post ID must be a positive integer.' );
		}

		$token = $input['version_token'];
		if ( ! is_string( $token ) ) {
			throw new InvalidArgumentException( 'A version token must be a string.' );
		}

		$raw = $input['slug'];
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			throw new InvalidArgumentException( 'A slug is required.' );
		}
		if ( self::MAX_SLUG_LENGTH < strlen( $raw ) ) {
			throw new InvalidArgumentException( 'The slug exceeds the accepted length.' );
		}

		return new self( $post_id, trim( $raw ), VersionToken::from_string( $token ) );
	}

	/**
	 * Returns the audit field names. Never the slug itself.
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		return array( 'slug' );
	}
}
