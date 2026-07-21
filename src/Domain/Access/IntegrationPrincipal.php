<?php
/**
 * Integration principal descriptor.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Access;

use InvalidArgumentException;

/**
 * Immutable, bounded view of one WordPress user used for integration access.
 */
final readonly class IntegrationPrincipal {

	/**
	 * Creates a principal descriptor.
	 *
	 * @param int    $user_id          WordPress user ID.
	 * @param string $login            WordPress user login.
	 * @param string $display_name     Safe admin-facing display name.
	 * @param bool   $has_native_read  Whether the user has WordPress's native read capability.
	 * @param bool   $is_administrator Whether the user has the administrator role.
	 * @param array  $capabilities     Assigned WPCB integration capabilities.
	 * @phpstan-param list<IntegrationCapability> $capabilities
	 * @throws InvalidArgumentException When the identity is invalid.
	 */
	public function __construct(
		public int $user_id,
		public string $login,
		public string $display_name,
		public bool $has_native_read,
		public bool $is_administrator,
		public array $capabilities
	) {
		if ( 1 > $user_id || '' === trim( $login ) ) {
			throw new InvalidArgumentException( 'Integration principal identity is invalid.' );
		}
	}

	/**
	 * Checks whether a plugin capability is assigned.
	 *
	 * @param IntegrationCapability $capability Capability to inspect.
	 * @return bool
	 */
	public function has( IntegrationCapability $capability ): bool {
		return in_array( $capability, $this->capabilities, true );
	}
}
