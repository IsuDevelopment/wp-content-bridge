<?php
/**
 * Validated input for an llms.txt configuration write.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Wraps the optimistic-concurrency token every write and preview must submit
 * around an otherwise-unchanged {@see LlmsConfig::from_input()} document.
 *
 * `version_token` is not part of {@see LlmsConfig::ALLOWED_KEYS} because the
 * stored configuration option never carries it; it is stripped here before
 * delegating every remaining field to the existing, already-bounded parser,
 * so validation rules live in exactly one place.
 */
final readonly class LlmsConfigUpdate {

	/**
	 * Creates a validated update.
	 *
	 * @param VersionToken $expected_version Optimistic-concurrency token.
	 * @param LlmsConfig   $config           Complete prospective configuration.
	 */
	public function __construct(
		public VersionToken $expected_version,
		public LlmsConfig $config,
	) {
	}

	/**
	 * Builds an update from untrusted Ability input.
	 *
	 * @param array<string, mixed> $input    Raw input.
	 * @param string               $site_url Canonical absolute site origin; a site fact, never caller input.
	 * @return self
	 * @throws InvalidArgumentException When the token or configuration is malformed.
	 */
	public static function from_input( array $input, string $site_url ): self {
		$token = $input['version_token'] ?? null;
		if ( ! is_string( $token ) ) {
			throw new InvalidArgumentException( 'A version token is required.' );
		}

		unset( $input['version_token'] );

		return new self( VersionToken::from_string( $token ), LlmsConfig::from_input( $input, $site_url ) );
	}
}
