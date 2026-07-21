<?php
/**
 * Integration access manager tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Access;

use IsuDev\WPContentBridge\Application\Access\IntegrationAccessManager;
use IsuDev\WPContentBridge\Application\Access\IntegrationAccessProblem;
use IsuDev\WPContentBridge\Application\Access\IntegrationAccessRepository;
use IsuDev\WPContentBridge\Domain\Access\IntegrationCapability;
use IsuDev\WPContentBridge\Domain\Access\IntegrationPrincipal;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the closed capability vocabulary and native-read prerequisite.
 */
final class IntegrationAccessManagerTest extends TestCase {

	/**
	 * An unknown capability rejects the entire request before persistence.
	 */
	public function test_rejects_unknown_capability(): void {
		$repository = $this->repository( true );
		$manager    = new IntegrationAccessManager( $repository );

		try {
			$manager->update( 7, array( 'wpcb_read_content', 'administrator' ) );
			self::fail( 'Unknown capability was accepted.' );
		} catch ( IntegrationAccessProblem $error ) {
			self::assertSame( 'invalid_capability', $error->error_code );
		}

		self::assertSame( 0, $repository->replace_calls );
	}

	/**
	 * Bridge grants require the native read capability used by the MCP transport.
	 */
	public function test_requires_native_read_before_granting_bridge_capabilities(): void {
		$repository = $this->repository( false );
		$manager    = new IntegrationAccessManager( $repository );

		try {
			$manager->update( 7, array( 'wpcb_read_content' ) );
			self::fail( 'Capabilities were assigned without native read access.' );
		} catch ( IntegrationAccessProblem $error ) {
			self::assertSame( 'native_read_required', $error->error_code );
		}

		self::assertSame( 0, $repository->replace_calls );
	}

	/**
	 * Administrator accounts cannot be selected as external integration principals.
	 */
	public function test_rejects_administrator_principal(): void {
		$repository = $this->repository( true, true );
		$manager    = new IntegrationAccessManager( $repository );

		try {
			$manager->update( 7, array( 'wpcb_read_content' ) );
			self::fail( 'Administrator principal was accepted.' );
		} catch ( IntegrationAccessProblem $error ) {
			self::assertSame( 'administrator_not_allowed', $error->error_code );
		}

		self::assertSame( 0, $repository->replace_calls );
	}

	/**
	 * Valid tokens are deduplicated and persisted in deterministic order.
	 */
	public function test_replaces_exact_allowlisted_capability_set(): void {
		$repository = $this->repository( true );
		$manager    = new IntegrationAccessManager( $repository );
		$result     = $manager->update(
			7,
			array( 'wpcb_manage_seo', 'wpcb_read_content', 'wpcb_manage_seo' )
		);

		self::assertSame( 1, $repository->replace_calls );
		self::assertSame(
			array( IntegrationCapability::MANAGE_SEO, IntegrationCapability::READ_CONTENT ),
			$result->capabilities
		);
	}

	/**
	 * Empty capability sets remain valid so an administrator can revoke access.
	 */
	public function test_allows_complete_revocation_without_native_read(): void {
		$repository = $this->repository( false );
		$manager    = new IntegrationAccessManager( $repository );
		$result     = $manager->update( 7, array() );

		self::assertSame( array(), $result->capabilities );
		self::assertSame( 1, $repository->replace_calls );
	}

	/**
	 * Media reads are part of the closed operational capability vocabulary.
	 */
	public function test_allows_dedicated_media_read_capability(): void {
		$manager = new IntegrationAccessManager( $this->repository( true ) );
		$result  = $manager->update( 7, array( IntegrationCapability::READ_MEDIA->value ) );

		self::assertSame( array( IntegrationCapability::READ_MEDIA ), $result->capabilities );
	}

	/**
	 * Pattern reads are part of the closed operational capability vocabulary.
	 */
	public function test_allows_dedicated_pattern_read_capability(): void {
		$manager = new IntegrationAccessManager( $this->repository( true ) );
		$result  = $manager->update( 7, array( IntegrationCapability::READ_PATTERNS->value ) );

		self::assertSame( array( IntegrationCapability::READ_PATTERNS ), $result->capabilities );
	}

	/**
	 * Creates an in-memory repository around one principal.
	 *
	 * @param bool $has_native_read Native WordPress read state.
	 * @param bool $is_administrator Whether the principal is an administrator.
	 * @return IntegrationAccessRepository&object{replace_calls: int}
	 */
	private function repository( bool $has_native_read, bool $is_administrator = false ): IntegrationAccessRepository {
		return new class( $has_native_read, $is_administrator ) implements IntegrationAccessRepository {

			/**
			 * Number of persistence calls made by the service.
			 *
			 * @var int
			 */
			public int $replace_calls = 0;

			/**
			 * Principal stored by the in-memory repository.
			 *
			 * @var IntegrationPrincipal
			 */
			private IntegrationPrincipal $principal;

			/**
			 * Creates the in-memory repository.
			 *
			 * @param bool $has_native_read Native WordPress read state.
			 * @param bool $is_administrator Whether the principal is an administrator.
			 */
			public function __construct( bool $has_native_read, bool $is_administrator ) {
				$this->principal = new IntegrationPrincipal( 7, 'bridge', 'Bridge', $has_native_read, $is_administrator, array() );
			}

			/**
			 * Returns the managed principal.
			 *
			 * @return IntegrationPrincipal
			 */
			public function managed(): ?IntegrationPrincipal {
				return $this->principal;
			}

			/**
			 * Resolves by identifier.
			 *
			 * @param string $identifier User identifier.
			 * @return IntegrationPrincipal|null
			 */
			public function find_by_identifier( string $identifier ): ?IntegrationPrincipal {
				return 'bridge' === $identifier ? $this->principal : null;
			}

			/**
			 * Resolves by ID.
			 *
			 * @param int $user_id User ID.
			 * @return IntegrationPrincipal|null
			 */
			public function find_by_id( int $user_id ): ?IntegrationPrincipal {
				return 7 === $user_id ? $this->principal : null;
			}

			/**
			 * Replaces the exact capability set.
			 *
			 * @param int   $user_id       User ID.
			 * @param array $capabilities Capability set.
			 * @phpstan-param list<IntegrationCapability> $capabilities
			 * @return IntegrationPrincipal
			 */
			public function replace( int $user_id, array $capabilities ): IntegrationPrincipal {
				++$this->replace_calls;
				$this->principal = new IntegrationPrincipal(
					$user_id,
					$this->principal->login,
					$this->principal->display_name,
					$this->principal->has_native_read,
					$this->principal->is_administrator,
					$capabilities
				);

				return $this->principal;
			}
		};
	}
}
