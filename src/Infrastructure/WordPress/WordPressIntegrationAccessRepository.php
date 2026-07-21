<?php
/**
 * WordPress integration access repository.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Access\IntegrationAccessProblem;
use IsuDev\WPContentBridge\Application\Access\IntegrationAccessRepository;
use IsuDev\WPContentBridge\Domain\Access\IntegrationCapability;
use IsuDev\WPContentBridge\Domain\Access\IntegrationPrincipal;
use WP_User;

/**
 * Persists one plugin-managed principal through native WordPress user APIs.
 */
final class WordPressIntegrationAccessRepository implements IntegrationAccessRepository {

	/**
	 * Returns the currently managed principal.
	 *
	 * @return IntegrationPrincipal|null
	 */
	public function managed(): ?IntegrationPrincipal {
		$stored_user_id = get_option( Installer::INTEGRATION_USER_OPTION, 0 );
		$user_id        = is_numeric( $stored_user_id ) ? (int) $stored_user_id : 0;

		return 0 < $user_id ? $this->find_by_id( $user_id ) : null;
	}

	/**
	 * Resolves a principal by login or email address.
	 *
	 * @param string $identifier User login or email.
	 * @return IntegrationPrincipal|null
	 */
	public function find_by_identifier( string $identifier ): ?IntegrationPrincipal {
		$user = get_user_by( 'login', $identifier );
		if ( ! $user instanceof WP_User && is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		}

		return $user instanceof WP_User ? $this->to_principal( $user ) : null;
	}

	/**
	 * Resolves a principal by WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return IntegrationPrincipal|null
	 */
	public function find_by_id( int $user_id ): ?IntegrationPrincipal {
		$user = get_user_by( 'id', $user_id );

		return $user instanceof WP_User ? $this->to_principal( $user ) : null;
	}

	/**
	 * Replaces the managed principal and its exact WPCB capability set.
	 *
	 * @param int   $user_id       Target WordPress user ID.
	 * @param array $capabilities Exact capability set.
	 * @phpstan-param list<IntegrationCapability> $capabilities
	 * @return IntegrationPrincipal
	 * @throws IntegrationAccessProblem When the target disappears during the update.
	 */
	public function replace( int $user_id, array $capabilities ): IntegrationPrincipal {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			throw new IntegrationAccessProblem( 'user_not_found' );
		}

		$stored_user_id = get_option( Installer::INTEGRATION_USER_OPTION, 0 );
		$old_user_id    = is_numeric( $stored_user_id ) ? (int) $stored_user_id : 0;

		if ( 0 < $old_user_id && $old_user_id !== $user_id ) {
			$old_user = get_user_by( 'id', $old_user_id );
			if ( $old_user instanceof WP_User ) {
				$this->remove_managed_capabilities( $old_user );
			}
		}

		$this->remove_managed_capabilities( $user );
		foreach ( $capabilities as $capability ) {
			$user->add_cap( $capability->value );
		}

		update_option( Installer::INTEGRATION_USER_OPTION, $user_id, false );

		$updated = $this->find_by_id( $user_id );
		if ( null === $updated ) {
			throw new IntegrationAccessProblem( 'update_failed' );
		}

		return $updated;
	}

	/**
	 * Removes only capabilities explicitly owned by this settings surface.
	 *
	 * @param WP_User $user User to update.
	 * @return void
	 */
	private function remove_managed_capabilities( WP_User $user ): void {
		foreach ( IntegrationCapability::cases() as $capability ) {
			$user->remove_cap( $capability->value );
		}
	}

	/**
	 * Maps a WordPress user into the bounded application descriptor.
	 *
	 * @param WP_User $user WordPress user.
	 * @return IntegrationPrincipal
	 */
	private function to_principal( WP_User $user ): IntegrationPrincipal {
		$capabilities = array_values(
			array_filter(
				IntegrationCapability::cases(),
				static fn ( IntegrationCapability $capability ): bool => user_can( $user, $capability->value )
			)
		);

		return new IntegrationPrincipal(
			(int) $user->ID,
			$user->user_login,
			$user->display_name,
			user_can( $user, 'read' ),
			in_array( 'administrator', $user->roles, true ),
			$capabilities
		);
	}
}
