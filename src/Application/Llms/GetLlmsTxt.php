<?php
/**
 * Get-llms-txt use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Llms\LlmsReadResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Reads the current llms.txt configuration, artifact summary, and ownership
 * state without requiring the publication flag, so an administrator can
 * inspect state and ownership conflicts before enabling anything.
 *
 * Never writes. The public verification leg of ownership detection performs
 * a same-site network request, so it is opt-in per {@see LlmsOwnershipInspector}'s
 * own contract rather than folded into every read: a caller must explicitly
 * set `verify_public_endpoint` to request it, and it is skipped entirely when
 * no configuration exists yet, since there is no site URL to probe.
 */
final readonly class GetLlmsTxt {

	public const ABILITY = 'wp-content-bridge/get-llms-txt';

	private const ALLOWED_KEYS = array( 'verify_public_endpoint' );

	/**
	 * Creates the use case.
	 *
	 * @param LlmsArtifactStore      $store     Configuration and snapshot read port.
	 * @param LlmsOwnershipInspector $ownership Ownership-conflict detection port.
	 * @param string                 $site_url  Canonical site origin supplied by the WordPress adapter.
	 */
	public function __construct(
		private LlmsArtifactStore $store,
		private LlmsOwnershipInspector $ownership,
		private string $site_url,
	) {
	}

	/**
	 * Reads the current state.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return LlmsReadResult
	 * @throws InvalidArgumentException When the request is malformed.
	 */
	public function execute( array $input ): LlmsReadResult {
		$verify = self::verify_flag( $input );

		$config   = $this->store->config();
		$artifact = $this->store->artifact();

		$state = $verify
			? $this->ownership->inspect_with_verification( $config->site_url ?? $this->site_url, $artifact?->content_hash )
			: $this->ownership->inspect();

		$version = VersionToken::for_llms( $config?->to_array(), $artifact?->content_hash );

		return new LlmsReadResult( $config, $artifact, $state, $version );
	}

	/**
	 * Validates the optional public-verification opt-in flag.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return bool
	 * @throws InvalidArgumentException When the request is malformed.
	 */
	private static function verify_flag( array $input ): bool {
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				throw new InvalidArgumentException( 'Get llms.txt input contains an unsupported field.' );
			}
		}

		$value = $input['verify_public_endpoint'] ?? false;
		if ( ! is_bool( $value ) ) {
			throw new InvalidArgumentException( 'verify_public_endpoint must be boolean.' );
		}

		return $value;
	}
}
