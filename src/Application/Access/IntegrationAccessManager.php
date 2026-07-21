<?php
/**
 * Integration access application service.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Access;

use IsuDev\WPContentBridge\Domain\Access\IntegrationCapability;
use IsuDev\WPContentBridge\Domain\Access\IntegrationPrincipal;

/**
 * Validates and applies the exact capability set for one managed principal.
 */
final readonly class IntegrationAccessManager {

	/**
	 * Creates the service.
	 *
	 * @param IntegrationAccessRepository $repository Principal access repository.
	 */
	public function __construct( private IntegrationAccessRepository $repository ) {
	}

	/**
	 * Returns the currently managed principal.
	 *
	 * @return IntegrationPrincipal|null
	 */
	public function managed(): ?IntegrationPrincipal {
		return $this->repository->managed();
	}

	/**
	 * Resolves a login or email without enumerating users.
	 *
	 * @param string $identifier User login or email.
	 * @return IntegrationPrincipal|null
	 */
	public function find( string $identifier ): ?IntegrationPrincipal {
		$identifier = trim( $identifier );
		if ( '' === $identifier || 100 < strlen( $identifier ) ) {
			return null;
		}

		return $this->repository->find_by_identifier( $identifier );
	}

	/**
	 * Replaces the managed WPCB capabilities for a previously authorized target.
	 *
	 * @param int                     $user_id          Target WordPress user ID.
	 * @param array<array-key, mixed> $requested_capabilities Requested capability tokens.
	 * @return IntegrationPrincipal
	 * @throws IntegrationAccessProblem When the principal or capability request is invalid.
	 */
	public function update( int $user_id, array $requested_capabilities ): IntegrationPrincipal {
		$principal = $this->repository->find_by_id( $user_id );
		if ( null === $principal ) {
			throw new IntegrationAccessProblem( 'user_not_found' );
		}
		if ( $principal->is_administrator ) {
			throw new IntegrationAccessProblem( 'administrator_not_allowed' );
		}

		$capabilities = $this->normalize_capabilities( $requested_capabilities );
		if ( array() !== $capabilities && ! $principal->has_native_read ) {
			throw new IntegrationAccessProblem( 'native_read_required' );
		}

		return $this->repository->replace( $user_id, $capabilities );
	}

	/**
	 * Converts untrusted tokens into the closed capability vocabulary.
	 *
	 * @param array<array-key, mixed> $requested_capabilities Requested capability tokens.
	 * @return list<IntegrationCapability>
	 * @throws IntegrationAccessProblem When any token is unknown.
	 */
	private function normalize_capabilities( array $requested_capabilities ): array {
		$normalized = array();

		foreach ( $requested_capabilities as $raw_capability ) {
			if ( ! is_string( $raw_capability ) ) {
				throw new IntegrationAccessProblem( 'invalid_capability' );
			}

			$capability = IntegrationCapability::tryFrom( $raw_capability );
			if ( null === $capability ) {
				throw new IntegrationAccessProblem( 'invalid_capability' );
			}

			$normalized[ $capability->value ] = $capability;
		}

		ksort( $normalized );

		return array_values( $normalized );
	}
}
