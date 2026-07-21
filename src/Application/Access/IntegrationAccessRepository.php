<?php
/**
 * Integration access persistence port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Access;

use IsuDev\WPContentBridge\Domain\Access\IntegrationCapability;
use IsuDev\WPContentBridge\Domain\Access\IntegrationPrincipal;

/**
 * Resolves principals and persists the exact plugin-managed capability set.
 */
interface IntegrationAccessRepository {

	/**
	 * Returns the currently managed principal, if it still exists.
	 *
	 * @return IntegrationPrincipal|null
	 */
	public function managed(): ?IntegrationPrincipal;

	/**
	 * Resolves a principal by login or email address.
	 *
	 * @param string $identifier User login or email.
	 * @return IntegrationPrincipal|null
	 */
	public function find_by_identifier( string $identifier ): ?IntegrationPrincipal;

	/**
	 * Resolves a principal by WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return IntegrationPrincipal|null
	 */
	public function find_by_id( int $user_id ): ?IntegrationPrincipal;

	/**
	 * Replaces the managed principal and its exact WPCB integration capability set.
	 *
	 * @param int   $user_id       Target WordPress user ID.
	 * @param array $capabilities Exact capability set.
	 * @phpstan-param list<IntegrationCapability> $capabilities
	 * @return IntegrationPrincipal
	 */
	public function replace( int $user_id, array $capabilities ): IntegrationPrincipal;
}
