<?php
/**
 * Validated attachment-metadata update.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Media;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * One attachment's editable descriptive fields.
 *
 * The four fields are text about the file, never the file itself: nothing here
 * can change the stored bytes, the MIME type, or the filename. Editing those
 * would be a replace, not an edit, and would need its own decision.
 */
final readonly class MediaMetadataUpdate {

	public const FIELDS = array( 'title', 'alt_text', 'caption', 'description' );

	private const MAX_TITLE_LENGTH = 500;
	private const MAX_TEXT_LENGTH  = 5000;

	/**
	 * Creates the validated update.
	 *
	 * @param int                   $attachment_id    Target attachment ID.
	 * @param array<string, string> $fields          Present fields only, already bounded.
	 * @param VersionToken          $expected_version Optimistic-concurrency token.
	 */
	private function __construct(
		public int $attachment_id,
		public array $fields,
		public VersionToken $expected_version,
	) {}

	/**
	 * Builds the update from normalized Ability input.
	 *
	 * At least one field must be present. An update naming none would take a
	 * token, pass every check, write nothing, and report success - which reads
	 * to a caller as "the edit was applied".
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return self
	 * @throws InvalidArgumentException When the request is malformed.
	 */
	public static function from_input( array $input ): self {
		$known = array_merge( array( 'attachment_id', 'version_token' ), self::FIELDS );
		if ( array() !== array_diff( array_keys( $input ), $known ) ) {
			throw new InvalidArgumentException( 'The request contains unsupported fields.' );
		}

		$attachment_id = $input['attachment_id'] ?? null;
		if ( ! is_int( $attachment_id ) || 0 >= $attachment_id ) {
			throw new InvalidArgumentException( 'An attachment ID must be a positive integer.' );
		}

		$token = $input['version_token'] ?? null;
		if ( ! is_string( $token ) ) {
			throw new InvalidArgumentException( 'A version token must be a string.' );
		}

		$fields = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$value = $input[ $field ];
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Attachment metadata fields must be strings.' );
			}
			$limit = 'title' === $field ? self::MAX_TITLE_LENGTH : self::MAX_TEXT_LENGTH;
			if ( $limit < mb_strlen( $value ) ) {
				throw new InvalidArgumentException( 'An attachment metadata field exceeds the accepted length.' );
			}
			$fields[ $field ] = $value;
		}

		if ( array() === $fields ) {
			throw new InvalidArgumentException( 'At least one of title, alt_text, caption, or description is required.' );
		}

		return new self( $attachment_id, $fields, VersionToken::from_string( $token ) );
	}

	/**
	 * Returns the field names this update sets. Never their values.
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		return array_keys( $this->fields );
	}
}
