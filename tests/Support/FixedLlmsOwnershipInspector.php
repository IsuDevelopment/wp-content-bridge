<?php
/**
 * Fixed llms.txt ownership inspector test double.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Support;

use IsuDev\WPContentBridge\Application\Llms\LlmsOwnershipInspector;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipOwner;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipState;
use IsuDev\WPContentBridge\Domain\Llms\LlmsPublicVerification;

/**
 * Returns one caller-supplied state from both inspection methods.
 */
final readonly class FixedLlmsOwnershipInspector implements LlmsOwnershipInspector {

	/**
	 * Creates the fake.
	 *
	 * @param LlmsOwnershipState|null $state Optional fixed state.
	 */
	public function __construct(
		private ?LlmsOwnershipState $state = null,
	) {
	}

	/**
	 * Returns the fixed local state.
	 *
	 * @return LlmsOwnershipState
	 */
	public function inspect(): LlmsOwnershipState {
		return $this->state ?? self::bridge_state();
	}

	/**
	 * Returns the same fixed state; this fake performs no network request.
	 *
	 * @param string      $site_url              Unused site URL.
	 * @param string|null $expected_content_hash Unused expected hash.
	 * @return LlmsOwnershipState
	 */
	public function inspect_with_verification( string $site_url, ?string $expected_content_hash ): LlmsOwnershipState {
		unset( $site_url, $expected_content_hash );

		return $this->inspect();
	}

	/**
	 * Creates the default ready bridge state.
	 *
	 * @return LlmsOwnershipState
	 */
	private static function bridge_state(): LlmsOwnershipState {
		return new LlmsOwnershipState(
			LlmsOwnershipOwner::BRIDGE,
			false,
			false,
			false,
			false,
			true,
			true,
			LlmsPublicVerification::UNKNOWN,
			null,
			'Bridge publication is ready.'
		);
	}
}
