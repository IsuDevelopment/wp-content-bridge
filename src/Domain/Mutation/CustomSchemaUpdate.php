<?php
/**
 * Validated input for a Custom Schema update.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Provider-neutral Custom Schema write intent.
 *
 * The JSON source is an intentional, bounded Schema.org document. It is never
 * interpreted as WordPress metadata or as a provider method dispatch request.
 */
final readonly class CustomSchemaUpdate {

	public const MAX_SOURCE_LENGTH = 100000;

	/**
	 * Complete public wire-key allowlist.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_KEYS = array(
		'post_id',
		'version_token',
		'enabled',
		'source',
	);

	/**
	 * Creates a validated update.
	 *
	 * @param int          $post_id          Target post ID.
	 * @param VersionToken $expected_version Optimistic-concurrency token.
	 * @param bool|null    $enabled          Whether Custom Schema is enabled.
	 * @param string|null  $source           JSON source; empty clears.
	 */
	public function __construct(
		public int $post_id,
		public VersionToken $expected_version,
		public ?bool $enabled,
		public ?string $source,
	) {}

	/**
	 * Builds an update from untrusted Ability input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @throws InvalidArgumentException When the request is malformed or empty.
	 */
	public static function from_input( array $input ): self {
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				throw new InvalidArgumentException( 'Custom Schema input contains an unsupported field.' );
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

		$enabled = self::optional_bool( $input );
		$source  = self::optional_source( $input );
		if ( null === $enabled && null === $source ) {
			throw new InvalidArgumentException( 'A Custom Schema update must change at least one field.' );
		}

		return new self( $post_id, VersionToken::from_string( $token ), $enabled, $source );
	}

	/**
	 * Names of fields present in this update.
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		return array_keys( $this->writable_fields() );
	}

	/**
	 * Returns the fixed provider-neutral write document.
	 *
	 * @return array<string, bool|string>
	 */
	public function writable_fields(): array {
		$fields = array();
		if ( null !== $this->enabled ) {
			$fields['enabled'] = $this->enabled;
		}
		if ( null !== $this->source ) {
			$fields['source'] = $this->source;
		}

		return $fields;
	}

	/**
	 * Validates the optional enabled flag.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @throws InvalidArgumentException When present but not boolean.
	 */
	private static function optional_bool( array $input ): ?bool {
		if ( ! array_key_exists( 'enabled', $input ) ) {
			return null;
		}
		if ( ! is_bool( $input['enabled'] ) ) {
			throw new InvalidArgumentException( 'The Custom Schema enabled field must be boolean.' );
		}

		return $input['enabled'];
	}

	/**
	 * Validates and canonicalizes editable JSON text without decoding it.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @throws InvalidArgumentException When the source is invalid or too large.
	 */
	private static function optional_source( array $input ): ?string {
		if ( ! array_key_exists( 'source', $input ) ) {
			return null;
		}

		$source = $input['source'];
		if ( ! is_string( $source ) ) {
			throw new InvalidArgumentException( 'Custom Schema source must be a string.' );
		}
		if ( ! mb_check_encoding( $source, 'UTF-8' ) || str_contains( $source, "\0" ) ) {
			throw new InvalidArgumentException( 'Custom Schema source must be valid UTF-8 without null bytes.' );
		}

		$source = str_replace( array( "\r\n", "\r" ), "\n", $source );
		if ( self::MAX_SOURCE_LENGTH < strlen( $source ) ) {
			throw new InvalidArgumentException( 'Custom Schema source exceeds the 100000-byte limit.' );
		}

		return $source;
	}
}
