<?php
/**
 * Unit tests for the CreateDraft use case.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\BlockMarkupValidator;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\CreateDraft;
use IsuDev\WPContentBridge\Application\Mutation\IdempotencyStore;
use IsuDev\WPContentBridge\Application\Mutation\InvalidBlockMarkup;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the create-draft write flow: policy, idempotency, block validation,
 * write, and the exactly-one-audit-row-per-attempt guarantee.
 */
final class CreateDraftTest extends TestCase {

	/**
	 * A permitted, valid request creates a draft and records one success row.
	 */
	public function test_creates_draft_and_records_success(): void {
		$audit    = $this->audit_spy();
		$use_case = new CreateDraft(
			$this->manager_allowing_create( true ),
			$this->passing_validator(),
			$this->creating_repository(),
			$this->empty_store(),
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Hello',
			),
			5
		);

		self::assertTrue( $result->created );
		self::assertSame( 'draft', $result->status );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
	}

	/**
	 * Policy denial throws MutationForbidden and records exactly one denied row.
	 */
	public function test_policy_denial_throws_and_records_denied(): void {
		$audit    = $this->audit_spy();
		$use_case = new CreateDraft(
			$this->manager_allowing_create( false ),
			$this->passing_validator(),
			$this->creating_repository(),
			$this->empty_store(),
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_type' => 'post',
					'title'     => 'Hi',
				),
				5
			);
			self::fail( 'Expected MutationForbidden.' );
		} catch ( MutationForbidden $forbidden ) {
			self::assertSame( 'wpcb_forbidden', $forbidden->error_code() );
		}

		self::assertCount( 1, $audit->events );
		self::assertSame( 'denied', $audit->events[0]->outcome );
		self::assertSame( 'wpcb_forbidden', $audit->events[0]->error_code );
	}

	/**
	 * Malformed input throws before policy checks and records one invalid row.
	 */
	public function test_invalid_input_records_invalid(): void {
		$audit    = $this->audit_spy();
		$use_case = new CreateDraft(
			$this->manager_allowing_create( true ),
			$this->passing_validator(),
			$this->creating_repository(),
			$this->empty_store(),
			$audit
		);

		$this->expectException( InvalidArgumentException::class );

		try {
			$use_case->execute( array( 'post_type' => 'post' ), 5 );
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_invalid_input', $audit->events[0]->error_code );
		}
	}

	/**
	 * Invalid block markup throws InvalidBlockMarkup and records one invalid row.
	 */
	public function test_invalid_blocks_records_invalid(): void {
		$audit    = $this->audit_spy();
		$use_case = new CreateDraft(
			$this->manager_allowing_create( true ),
			$this->failing_validator( array( 'block 0: unregistered' ) ),
			$this->creating_repository(),
			$this->empty_store(),
			$audit
		);

		$this->expectException( InvalidBlockMarkup::class );

		try {
			$use_case->execute(
				array(
					'post_type'    => 'post',
					'title'        => 'Hi',
					'block_markup' => '<!-- wp:acme/nope /-->',
				),
				5
			);
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_invalid_blocks', $audit->events[0]->error_code );
		}
	}

	/**
	 * A replayed idempotency key returns the existing result without creating.
	 */
	public function test_idempotent_replay_returns_existing_without_creating(): void {
		$audit      = $this->audit_spy();
		$repository = $this->replay_repository( 99 );
		$store      = $this->store_with( 5, 'key-1', 99 );
		$use_case   = new CreateDraft(
			$this->manager_allowing_create( true ),
			$this->passing_validator(),
			$repository,
			$store,
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_type'       => 'post',
				'title'           => 'Hi',
				'idempotency_key' => 'key-1',
			),
			5
		);

		self::assertFalse( $result->created );
		self::assertSame( 99, $result->post_id );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
	}

	/**
	 * A repository write failure throws MutationWriteFailed and records exactly
	 * one failure row with the stable write-failed error code.
	 */
	public function test_write_failure_records_failure(): void {
		$audit    = $this->audit_spy();
		$use_case = new CreateDraft(
			$this->manager_allowing_create( true ),
			$this->passing_validator(),
			$this->failing_repository(),
			$this->empty_store(),
			$audit
		);

		$this->expectException( MutationWriteFailed::class );

		try {
			$use_case->execute(
				array(
					'post_type' => 'post',
					'title'     => 'Hi',
				),
				5
			);
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'failure', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_write_failed', $audit->events[0]->error_code );
		}
	}

	// --- fakes -----------------------------------------------------------

	/**
	 * Creates a spy audit log that records every event it receives.
	 *
	 * @return AuditLog
	 */
	private function audit_spy(): AuditLog {
		return new class() implements AuditLog {
			/**
			 * Recorded audit events, in order.
			 *
			 * @var array<int, AuditEvent>
			 */
			public array $events = array();

			/**
			 * Records the event for later assertions.
			 *
			 * @param AuditEvent $event Pre-redacted event.
			 * @return void
			 */
			public function record( AuditEvent $event ): void {
				$this->events[] = $event;
			}
		};
	}

	/**
	 * Creates a content access manager that allows or denies create-draft.
	 *
	 * @param bool $allow Whether create_draft is permitted for the post type.
	 * @return ContentAccessManager
	 */
	private function manager_allowing_create( bool $allow ): ContentAccessManager {
		$stored = $allow
			? array(
				'post' => array(
					'get_content'  => true,
					'create_draft' => true,
				),
			)
			: array(
				'post' => array(
					'get_content'  => true,
					'create_draft' => false,
				),
			);

		$repository = new readonly class( $stored ) implements ContentAccessSettingsRepository {

			/**
			 * Creates an in-memory settings repository.
			 *
			 * @param array<string, array<string, mixed>> $settings In-memory settings.
			 */
			public function __construct( private array $settings ) {
			}

			/**
			 * Loads in-memory settings.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return $this->settings;
			}
		};

		$catalog = new readonly class() implements ContentTypeCatalog {

			/**
			 * Lists a single eligible "post" content type.
			 *
			 * @return list<ContentTypeDefinition>
			 */
			public function list_eligible(): array {
				return array( new ContentTypeDefinition( 'post', 'Posts', true, true, true ) );
			}
		};

		return new ContentAccessManager( $repository, $catalog );
	}

	/**
	 * Creates a block markup validator that always passes.
	 *
	 * @return BlockMarkupValidator
	 */
	private function passing_validator(): BlockMarkupValidator {
		return new class() implements BlockMarkupValidator {

			/**
			 * Always reports valid markup.
			 *
			 * @param string $markup Raw Gutenberg block markup.
			 * @return list<string>
			 */
			public function validate( string $markup ): array {
				return array();
			}
		};
	}

	/**
	 * Creates a block markup validator that always fails with given reasons.
	 *
	 * @param array<int, string> $reasons Failure reasons to return.
	 * @return BlockMarkupValidator
	 */
	private function failing_validator( array $reasons ): BlockMarkupValidator {
		return new class( $reasons ) implements BlockMarkupValidator {

			/**
			 * Creates a validator that always fails.
			 *
			 * @param array<int, string> $reasons Failure reasons to return.
			 */
			public function __construct( private array $reasons ) {
			}

			/**
			 * Always reports the configured failure reasons.
			 *
			 * @param string $markup Raw Gutenberg block markup.
			 * @return list<string>
			 */
			public function validate( string $markup ): array {
				return $this->reasons;
			}
		};
	}

	/**
	 * Creates a mutation repository whose create() always succeeds.
	 *
	 * @return ContentMutationRepository
	 */
	private function creating_repository(): ContentMutationRepository {
		return new class() implements ContentMutationRepository {

			/**
			 * Always reports "post".
			 *
			 * @param int $post_id Post ID.
			 * @return string|null
			 */
			public function post_type( int $post_id ): ?string {
				return 'post';
			}

			/**
			 * Always returns a fixed version token.
			 *
			 * @param int $post_id Post ID.
			 * @return VersionToken|null
			 */
			public function current_version( int $post_id ): ?VersionToken {
				return new VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' );
			}

			/**
			 * Creates a fixed successful result.
			 *
			 * @param DraftInput $input Validated draft input.
			 * @return MutationResult
			 */
			public function create( DraftInput $input ): MutationResult {
				return new MutationResult(
					10,
					$input->post_type,
					'draft',
					new VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' ),
					array( 'title' ),
					true
				);
			}

			/**
			 * Not used by these tests.
			 *
			 * @param int           $post_id Post ID to update.
			 * @param ContentUpdate $update  Validated update input.
			 * @throws \LogicException Always; this fake is not exercised by these tests.
			 */
			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new \LogicException( 'not used' );
			}

			/**
			 * Always reports no existing replay.
			 *
			 * @param int $post_id Post ID.
			 * @return MutationResult|null
			 */
			public function result_for( int $post_id ): ?MutationResult {
				return null;
			}
		};
	}

	/**
	 * Creates a mutation repository whose create() always throws
	 * MutationWriteFailed.
	 *
	 * @return ContentMutationRepository
	 */
	private function failing_repository(): ContentMutationRepository {
		return new class() implements ContentMutationRepository {

			/**
			 * Always reports "post".
			 *
			 * @param int $post_id Post ID.
			 * @return string|null
			 */
			public function post_type( int $post_id ): ?string {
				return 'post';
			}

			/**
			 * Always returns a fixed version token.
			 *
			 * @param int $post_id Post ID.
			 * @return VersionToken|null
			 */
			public function current_version( int $post_id ): ?VersionToken {
				return new VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' );
			}

			/**
			 * Always throws MutationWriteFailed.
			 *
			 * @param DraftInput $input Validated draft input.
			 * @throws MutationWriteFailed Always; simulates a rejected write.
			 */
			public function create( DraftInput $input ): MutationResult {
				throw new MutationWriteFailed( 'boom' );
			}

			/**
			 * Not used by these tests.
			 *
			 * @param int           $post_id Post ID to update.
			 * @param ContentUpdate $update  Validated update input.
			 * @throws \LogicException Always; this fake is not exercised by these tests.
			 */
			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new \LogicException( 'not used' );
			}

			/**
			 * Always reports no existing replay.
			 *
			 * @param int $post_id Post ID.
			 * @return MutationResult|null
			 */
			public function result_for( int $post_id ): ?MutationResult {
				return null;
			}
		};
	}

	/**
	 * Creates a mutation repository that only supports idempotent replay and
	 * fails the test if create() is invoked.
	 *
	 * @param int $existing_id Post ID that already exists for replay.
	 * @return ContentMutationRepository
	 */
	private function replay_repository( int $existing_id ): ContentMutationRepository {
		return new class( $existing_id ) implements ContentMutationRepository {

			/**
			 * Creates a replay-only repository.
			 *
			 * @param int $existing_id Post ID that already exists for replay.
			 */
			public function __construct( private int $existing_id ) {
			}

			/**
			 * Always reports "post".
			 *
			 * @param int $post_id Post ID.
			 * @return string|null
			 */
			public function post_type( int $post_id ): ?string {
				return 'post';
			}

			/**
			 * Always returns a fixed version token.
			 *
			 * @param int $post_id Post ID.
			 * @return VersionToken|null
			 */
			public function current_version( int $post_id ): ?VersionToken {
				return new VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' );
			}

			/**
			 * Must not be called on the replay path.
			 *
			 * @param DraftInput $input Validated draft input.
			 * @throws \LogicException Always; create() must not be reached on the replay path.
			 */
			public function create( DraftInput $input ): MutationResult {
				throw new \LogicException( 'create must not be called on replay' );
			}

			/**
			 * Not used by these tests.
			 *
			 * @param int           $post_id Post ID to update.
			 * @param ContentUpdate $update  Validated update input.
			 * @throws \LogicException Always; this fake is not exercised by these tests.
			 */
			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new \LogicException( 'not used' );
			}

			/**
			 * Replays the existing result when the ID matches.
			 *
			 * @param int $post_id Post ID.
			 * @return MutationResult|null
			 */
			public function result_for( int $post_id ): ?MutationResult {
				if ( $post_id !== $this->existing_id ) {
					return null;
				}

				return new MutationResult(
					$this->existing_id,
					'post',
					'draft',
					new VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' ),
					array(),
					false
				);
			}
		};
	}

	/**
	 * Creates an idempotency store with no stored keys.
	 *
	 * @return IdempotencyStore
	 */
	private function empty_store(): IdempotencyStore {
		return new class() implements IdempotencyStore {

			/**
			 * Always reports no existing mapping.
			 *
			 * @param int    $user_id Acting principal.
			 * @param string $key     Idempotency key.
			 * @return int|null
			 */
			public function find( int $user_id, string $key ): ?int {
				return null;
			}

			/**
			 * No-op.
			 *
			 * @param int    $user_id Acting principal.
			 * @param string $key     Idempotency key.
			 * @param int    $post_id Created post ID.
			 * @return void
			 */
			public function remember( int $user_id, string $key, int $post_id ): void {
			}
		};
	}

	/**
	 * Creates an idempotency store pre-seeded with one user/key/post mapping.
	 *
	 * @param int    $user_id Acting principal that owns the mapping.
	 * @param string $key     Idempotency key.
	 * @param int    $post_id Post ID the key maps to.
	 * @return IdempotencyStore
	 */
	private function store_with( int $user_id, string $key, int $post_id ): IdempotencyStore {
		return new class( $user_id, $key, $post_id ) implements IdempotencyStore {

			/**
			 * Creates a pre-seeded store.
			 *
			 * @param int    $user_id Acting principal that owns the mapping.
			 * @param string $key     Idempotency key.
			 * @param int    $post_id Post ID the key maps to.
			 */
			public function __construct(
				private int $user_id,
				private string $key,
				private int $post_id
			) {
			}

			/**
			 * Returns the seeded post ID when user and key match.
			 *
			 * @param int    $user_id Acting principal.
			 * @param string $key     Idempotency key.
			 * @return int|null
			 */
			public function find( int $user_id, string $key ): ?int {
				return ( $user_id === $this->user_id && $key === $this->key ) ? $this->post_id : null;
			}

			/**
			 * No-op.
			 *
			 * @param int    $user_id Acting principal.
			 * @param string $key     Idempotency key.
			 * @param int    $post_id Created post ID.
			 * @return void
			 */
			public function remember( int $user_id, string $key, int $post_id ): void {
			}
		};
	}
}
