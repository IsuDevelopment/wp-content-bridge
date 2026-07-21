<?php
/**
 * Trash-content use-case tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\ContentTrashRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationInvalidState;
use IsuDev\WPContentBridge\Application\Mutation\TrashContent;
use IsuDev\WPContentBridge\Application\Mutation\TrashUnavailable;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\MutationTarget;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;

/**
 * Verifies policy, state, concurrency, availability, write, and audit ordering.
 */
final class TrashContentTest extends TestCase {

	private const CURRENT = 'abcdef0123456789:2026-07-21 12:00:00';
	private const STALE   = '0000000000000000:2026-07-20 12:00:00';

	/**
	 * A permitted current target is trashed and audited once.
	 *
	 * @return void
	 */
	public function test_trashes_current_target_and_records_success(): void {
		$repository = $this->repository( 'draft', true );
		$audit      = $this->audit();
		$result     = ( new TrashContent( $this->manager( true ), $repository, $audit ) )->execute( $this->input(), 7 );

		self::assertSame( 'trash', $result->status );
		self::assertSame( array( 'status' ), $result->changed_fields );
		self::assertSame( 1, $repository->trash_calls );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
		self::assertSame( array( 'status' ), $audit->events[0]->changed_fields );
	}

	/**
	 * Policy denial prevents the write.
	 *
	 * @return void
	 */
	public function test_policy_denial_prevents_write(): void {
		$repository = $this->repository( 'draft', true );
		$audit      = $this->audit();
		$this->expectException( MutationForbidden::class );

		try {
			( new TrashContent( $this->manager( false ), $repository, $audit ) )->execute( $this->input(), 7 );
		} finally {
			self::assertSame( 0, $repository->trash_calls );
			self::assertSame( 'denied', $audit->events[0]->outcome );
		}
	}

	/**
	 * A stale token prevents the write.
	 *
	 * @return void
	 */
	public function test_stale_token_conflicts(): void {
		$repository = $this->repository( 'draft', true );
		$audit      = $this->audit();
		$this->expectException( MutationConflict::class );

		try {
			( new TrashContent( $this->manager( true ), $repository, $audit ) )->execute( $this->input( self::STALE ), 7 );
		} finally {
			self::assertSame( 0, $repository->trash_calls );
			self::assertSame( 'conflict', $audit->events[0]->outcome );
		}
	}

	/**
	 * Already-trashed and internal states are rejected.
	 *
	 * @return void
	 */
	public function test_rejects_invalid_source_state(): void {
		$repository = $this->repository( 'trash', true );
		$this->expectException( MutationInvalidState::class );

		( new TrashContent( $this->manager( true ), $repository, $this->audit() ) )->execute( $this->input(), 7 );
	}

	/**
	 * A site without reversible trash fails closed.
	 *
	 * @return void
	 */
	public function test_disabled_wordpress_trash_fails_closed(): void {
		$repository = $this->repository( 'draft', false );
		$audit      = $this->audit();
		$this->expectException( TrashUnavailable::class );

		try {
			( new TrashContent( $this->manager( true ), $repository, $audit ) )->execute( $this->input(), 7 );
		} finally {
			self::assertSame( 0, $repository->trash_calls );
			self::assertSame( 'wpcb_trash_unavailable', $audit->events[0]->error_code );
		}
	}

	/**
	 * Builds the standard valid request.
	 *
	 * @param string $token Version token.
	 * @return array<string, mixed>
	 */
	private function input( string $token = self::CURRENT ): array {
		return array(
			'post_id'       => 42,
			'version_token' => $token,
		);
	}

	/**
	 * Creates a content access manager with a fixed trash policy.
	 *
	 * @param bool $allowed Whether trash policy is enabled.
	 * @return ContentAccessManager
	 */
	private function manager( bool $allowed ): ContentAccessManager {
		$settings = new class( $allowed ) implements ContentAccessSettingsRepository {
			/**
			 * Creates the fixed settings repository.
			 *
			 * @param bool $allowed Whether trash is allowed.
			 */
			public function __construct( private bool $allowed ) {}

			/**
			 * Returns the fixed settings map.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return array(
					'post' => array(
						'get_content'   => true,
						'trash_content' => $this->allowed,
					),
				);
			}
		};
		$catalog  = new class() implements ContentTypeCatalog {
			/**
			 * Returns one eligible post type.
			 *
			 * @return list<ContentTypeDefinition>
			 */
			public function list_eligible(): array {
				return array( new ContentTypeDefinition( 'post', 'Posts', true, true, true ) );
			}
		};

		return new ContentAccessManager( $settings, $catalog );
	}

	/**
	 * Creates a deterministic trash repository fake.
	 *
	 * @param string $status    Current status.
	 * @param bool   $supported Whether reversible trash is enabled.
	 * @return ContentTrashRepository&object{trash_calls: int}
	 */
	private function repository( string $status, bool $supported ): ContentTrashRepository {
		return new class( $status, $supported, self::CURRENT ) implements ContentTrashRepository {
			/**
			 * Number of trash writes.
			 *
			 * @var int
			 */
			public int $trash_calls = 0;

			/**
			 * Creates the deterministic repository fake.
			 *
			 * @param string $status        Current status.
			 * @param bool   $supported     Whether trash is available.
			 * @param string $current_token Current version token.
			 */
			public function __construct( private string $status, private bool $supported, private string $current_token ) {}

			/**
			 * Reports the configured trash availability.
			 *
			 * @return bool
			 */
			public function trash_supported(): bool {
				return $this->supported;
			}

			/**
			 * Resolves the fixed mutation target.
			 *
			 * @param int $post_id Target post ID.
			 * @return MutationTarget
			 */
			public function target( int $post_id ): ?MutationTarget {
				return new MutationTarget( $post_id, 'post', $this->status, VersionToken::from_string( $this->current_token ) );
			}

			/**
			 * Records one trash call and returns its result.
			 *
			 * @param int $post_id Target post ID.
			 * @return MutationResult
			 */
			public function trash( int $post_id ): MutationResult {
				++$this->trash_calls;

				return new MutationResult( $post_id, 'post', 'trash', new VersionToken( 'fedcba9876543210', '2026-07-21 12:01:00' ), array( 'status' ), false );
			}
		};
	}

	/**
	 * Creates an in-memory audit spy.
	 *
	 * @return AuditLog&object{events: array<int, AuditEvent>}
	 */
	private function audit(): AuditLog {
		return new class() implements AuditLog {
			/**
			 * Recorded events.
			 *
			 * @var array<int, AuditEvent>
			 */
			public array $events = array();

			/**
			 * Records one audit event.
			 *
			 * @param AuditEvent $event Event to record.
			 * @return void
			 */
			public function record( AuditEvent $event ): void {
				$this->events[] = $event;
			}
		};
	}
}
