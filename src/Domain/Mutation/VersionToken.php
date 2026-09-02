<?php
/**
 * Optimistic-concurrency version token.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable content version token used to reject stale writes.
 */
final readonly class VersionToken {

	/**
	 * Creates a token.
	 *
	 * @param string $content_hash 16-char lowercase hex digest.
	 * @param string $modified_gmt WordPress `post_modified_gmt` value.
	 */
	public function __construct(
		public string $content_hash,
		public string $modified_gmt,
	) {
	}

	/**
	 * Derives a token from the fields that must not change under a stale write.
	 *
	 * `$meta_fingerprint` is what makes the token cover a meta-only write.
	 * Without it the token is blind to `update-seo` and to Custom/Service
	 * Schema writes, all of which store post meta and touch none of the four
	 * fields above — so the token came back unchanged after a successful write
	 * and could not detect a concurrent one. It defaults to empty so the
	 * domain stays constructible without a meta source, but every adapter in
	 * this plugin supplies it through `PostVersionTokenFactory`.
	 *
	 * @param string $modified_gmt     WordPress `post_modified_gmt` value.
	 * @param string $title            Post title.
	 * @param string $content          Raw post content.
	 * @param string $status           Post status.
	 * @param string $meta_fingerprint Digest of the post's meta.
	 * @return self
	 */
	public static function for_content(
		string $modified_gmt,
		string $title,
		string $content,
		string $status,
		string $meta_fingerprint = ''
	): self {
		$hash = substr(
			hash( 'sha256', $content . '|' . $title . '|' . $status . '|' . $meta_fingerprint ),
			0,
			16
		);

		return new self( $hash, $modified_gmt );
	}

	/**
	 * Derives a token from an effective llms.txt configuration snapshot plus
	 * its generated artifact's content hash, so a regeneration that changes
	 * the document — or an administrator changing the configuration —
	 * invalidates a caller's previously read token.
	 *
	 * Unlike {@see self::for_content()}, both inputs may be absent: nothing
	 * may have been configured or generated yet. Deterministic sentinels keep
	 * the token stable and well-formed at that state instead of requiring a
	 * special-cased "unconfigured" token shape.
	 *
	 * @param array<string, mixed>|null $config_snapshot       Effective `LlmsConfig::to_array()`, or null when unconfigured.
	 * @param string|null               $artifact_content_hash Stored artifact's content hash, or null when none has been generated.
	 * @return self
	 */
	public static function for_llms( ?array $config_snapshot, ?string $artifact_content_hash ): self {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- domain code must not depend on WordPress being loaded.
		$encoded_config = null === $config_snapshot ? '' : (string) json_encode( $config_snapshot );
		$hash           = substr( hash( 'sha256', $encoded_config . '|' . ( $artifact_content_hash ?? '' ) ), 0, 16 );

		return new self( $hash, $artifact_content_hash ?? 'none' );
	}

	/**
	 * Parses the wire form `{content_hash}:{modified_gmt}`.
	 *
	 * @param string $value Serialized token.
	 * @return self
	 * @throws InvalidArgumentException When the shape is invalid.
	 */
	public static function from_string( string $value ): self {
		if ( strlen( $value ) < 18 || ':' !== $value[16] ) {
			throw new InvalidArgumentException( 'A version token is malformed.' );
		}

		$hash         = substr( $value, 0, 16 );
		$modified_gmt = substr( $value, 17 );

		if ( 1 !== preg_match( '/^[0-9a-f]{16}$/', $hash ) || '' === $modified_gmt ) {
			throw new InvalidArgumentException( 'A version token is malformed.' );
		}

		return new self( $hash, $modified_gmt );
	}

	/**
	 * Serializes to the wire form.
	 *
	 * @return string
	 */
	public function to_string(): string {
		return $this->content_hash . ':' . $this->modified_gmt;
	}

	/**
	 * Compares two tokens.
	 *
	 * @param VersionToken $other Token to compare against.
	 * @return bool
	 */
	public function equals( VersionToken $other ): bool {
		return $this->content_hash === $other->content_hash
			&& $this->modified_gmt === $other->modified_gmt;
	}
}
