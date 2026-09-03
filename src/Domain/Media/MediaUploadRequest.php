<?php
/**
 * Validated media-upload input.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Media;

use InvalidArgumentException;

/**
 * One remote image to import, with the caller's retry key.
 *
 * Nothing here decides the file type. The URL, its extension, and the response
 * content type are untrusted hints; the stored type is sniffed from the bytes
 * by the infrastructure adapter (ADR 0031 decision 3).
 */
final readonly class MediaUploadRequest {

	public const MAX_URL_LENGTH   = 2048;
	private const MAX_TEXT_LENGTH = 500;
	private const MIN_KEY_LENGTH  = 8;
	private const MAX_KEY_LENGTH  = 191;

	/**
	 * Creates the validated request.
	 *
	 * @param string      $source_url      Remote image URL to fetch.
	 * @param string      $idempotency_key Caller-supplied retry key.
	 * @param string|null $title           Optional attachment title.
	 * @param string|null $alt_text        Optional alternative text.
	 * @param string|null $caption         Optional caption.
	 */
	private function __construct(
		public string $source_url,
		public string $idempotency_key,
		public ?string $title,
		public ?string $alt_text,
		public ?string $caption,
	) {}

	/**
	 * Builds the request from normalized Ability input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return self
	 * @throws InvalidArgumentException When the request is malformed.
	 */
	public static function from_input( array $input ): self {
		$known = array( 'source_url', 'idempotency_key', 'title', 'alt_text', 'caption' );
		$extra = array_diff( array_keys( $input ), $known );
		if ( array() !== $extra ) {
			throw new InvalidArgumentException( 'The request contains unsupported fields.' );
		}

		$source_url = $input['source_url'] ?? null;
		if ( ! is_string( $source_url ) || '' === trim( $source_url ) ) {
			throw new InvalidArgumentException( 'A source URL is required.' );
		}
		$source_url = trim( $source_url );
		if ( self::MAX_URL_LENGTH < strlen( $source_url ) ) {
			throw new InvalidArgumentException( 'The source URL exceeds the accepted length.' );
		}

		$key = $input['idempotency_key'] ?? null;
		if ( ! is_string( $key ) ) {
			throw new InvalidArgumentException( 'An idempotency key is required.' );
		}
		$key = trim( $key );
		if ( self::MIN_KEY_LENGTH > strlen( $key ) || self::MAX_KEY_LENGTH < strlen( $key ) ) {
			throw new InvalidArgumentException( 'An idempotency key must be between 8 and 191 characters.' );
		}

		return new self(
			$source_url,
			$key,
			self::optional_text( $input, 'title' ),
			self::optional_text( $input, 'alt_text' ),
			self::optional_text( $input, 'caption' )
		);
	}

	/**
	 * Returns the field names this request would set. Never their values.
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		$fields = array( 'source_url' );
		foreach ( array( 'title', 'alt_text', 'caption' ) as $field ) {
			if ( null !== $this->{$field} ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Validates one optional bounded text field.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param string               $field Field name.
	 * @return string|null
	 * @throws InvalidArgumentException When present but not bounded text.
	 */
	private static function optional_text( array $input, string $field ): ?string {
		if ( ! array_key_exists( $field, $input ) || null === $input[ $field ] ) {
			return null;
		}

		$value = $input[ $field ];
		if ( ! is_string( $value ) || self::MAX_TEXT_LENGTH < mb_strlen( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is one of three literal names from the loop above, not caller input.
			throw new InvalidArgumentException( 'The ' . $field . ' must be text within the accepted length.' );
		}

		return $value;
	}
}
