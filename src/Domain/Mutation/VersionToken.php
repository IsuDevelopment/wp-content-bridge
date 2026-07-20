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
	 * @param string $modified_gmt WordPress `post_modified_gmt` value.
	 * @param string $title        Post title.
	 * @param string $content      Raw post content.
	 * @param string $status       Post status.
	 * @return self
	 */
	public static function for_content( string $modified_gmt, string $title, string $content, string $status ): self {
		$hash = substr( hash( 'sha256', $content . '|' . $title . '|' . $status ), 0, 16 );

		return new self( $hash, $modified_gmt );
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
