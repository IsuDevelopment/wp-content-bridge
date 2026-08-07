<?php
/**
 * Unit tests for the UpdateContent use case.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\BlockMarkupValidator;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\InvalidBlockMarkup;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\UpdateContent;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the update-content write flow: policy, optimistic concurrency,
 * block validation, write, and the exactly-one-audit-row-per-attempt guarantee.
 */
final class UpdateContentTest extends TestCase {

	private const CURRENT = 'abcdef0123456789:2026-07-20 00:00:00';
	private const STALE   = '0000000000000000:2026-07-19 00:00:00';

	/**
	 * A permitted, valid request with a matching token updates and records one success row.
	 */
	public function test_updates_and_records_success(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT );
		$use_case   = new UpdateContent(
			$this->manager_allowing_update( true ),
			$this->passing_validator(),
			$repository,
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::CURRENT,
				'title'         => 'New',
			),
			5
		);

		self::assertFalse( $result->created );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
		self::assertSame( self::CURRENT, $audit->events[0]->expected_version );
	}

	/**
	 * A stale version token throws MutationConflict, skips the write, and records one conflict row.
	 */
	public function test_stale_token_conflicts_and_does_not_write(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT );
		$use_case   = new UpdateContent(
			$this->manager_allowing_update( true ),
			$this->passing_validator(),
			$repository,
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::STALE,
					'title'         => 'New',
				),
				5
			);
			self::fail( 'Expected MutationConflict.' );
		} catch ( MutationConflict $conflict ) {
			self::assertSame( 'wpcb_conflict', $conflict->error_code() );
		}

		self::assertFalse( $repository->updated, 'update() must not run on conflict.' );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'conflict', $audit->events[0]->outcome );
	}

	/**
	 * A missing post throws ContentUnavailable and records one invalid row.
	 */
	public function test_missing_post_is_unavailable(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateContent(
			$this->manager_allowing_update( true ),
			$this->passing_validator(),
			$this->repository( null ),
			$audit
		);

		$this->expectException( ContentUnavailable::class );

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::CURRENT,
					'title'         => 'New',
				),
				5
			);
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_content_unavailable', $audit->events[0]->error_code );
		}
	}

	/**
	 * Policy denial throws MutationForbidden and records exactly one denied row.
	 */
	public function test_policy_denial_is_forbidden(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateContent(
			$this->manager_allowing_update( false ),
			$this->passing_validator(),
			$this->repository( self::CURRENT ),
			$audit
		);

		$this->expectException( MutationForbidden::class );

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::CURRENT,
					'title'         => 'New',
				),
				5
			);
		} finally {
			self::assertSame( 'denied', $audit->events[0]->outcome );
		}
	}

	/**
	 * Malformed input throws before policy checks and records one invalid row.
	 */
	public function test_invalid_input_records_invalid(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateContent(
			$this->manager_allowing_update( true ),
			$this->passing_validator(),
			$this->repository( self::CURRENT ),
			$audit
		);

		$this->expectException( InvalidArgumentException::class );

		try {
			$use_case->execute(
				array(
					'post_id' => 42,
					'title'   => 'New',
				),
				5
			);
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
		$use_case = new UpdateContent(
			$this->manager_allowing_update( true ),
			$this->failing_validator( array( 'block 0: unregistered' ) ),
			$this->repository( self::CURRENT ),
			$audit
		);

		$this->expectException( InvalidBlockMarkup::class );

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::CURRENT,
					'block_markup'  => '<!-- wp:acme/nope /-->',
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
	 * A repository write failure throws MutationWriteFailed and records exactly
	 * one failure row with the stable write-failed error code.
	 */
	public function test_write_failure_records_failure(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateContent(
			$this->manager_allowing_update( true ),
			$this->passing_validator(),
			$this->failing_repository( self::CURRENT ),
			$audit
		);

		$this->expectException( MutationWriteFailed::class );

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::CURRENT,
					'title'         => 'New',
				),
				5
			);
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'failure', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_write_failed', $audit->events[0]->error_code );
		}
	}

	/**
	 * If the audit sink itself throws while recording a success row, that throw
	 * must propagate directly to the caller and must NOT be caught and
	 * misrecorded as a second (failure) audit row for the same attempt.
	 */
	public function test_audit_sink_throw_on_success_propagates_without_double_recording(): void {
		$audit    = $this->throwing_audit_spy();
		$use_case = new UpdateContent(
			$this->manager_allowing_update( true ),
			$this->passing_validator(),
			$this->repository( self::CURRENT ),
			$audit
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'audit sink unavailable' );

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::CURRENT,
					'title'         => 'New',
				),
				5
			);
		} finally {
			self::assertSame( 1, $audit->calls, 'The audit sink must be called exactly once per attempt.' );
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
	 * Creates an audit log whose record() always throws, simulating a broken
	 * audit sink (e.g. a `wpcb_mutation` action listener that throws). Used to
	 * prove that such a throw propagates directly to the caller and is recorded
	 * exactly once, never caught and misrecorded as a second failure row.
	 *
	 * @return AuditLog&object{calls: int}
	 */
	private function throwing_audit_spy(): AuditLog {
		return new class() implements AuditLog {
			/**
			 * Number of times record() has been invoked.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Counts the call and always throws.
			 *
			 * @param AuditEvent $event Pre-redacted event.
			 * @throws \RuntimeException Always; simulates a broken audit sink.
			 */
			public function record( AuditEvent $event ): void {
				++$this->calls;

				throw new \RuntimeException( 'audit sink unavailable' );
			}
		};
	}

	/**
	 * Creates a content access manager that allows or denies update-content.
	 *
	 * @param bool $allow Whether update_content is permitted for the post type.
	 * @return ContentAccessManager
	 */
	private function manager_allowing_update( bool $allow ): ContentAccessManager {
		$stored = array(
			'post' => array(
				'get_content'    => true,
				'update_content' => $allow,
			),
		);

		$repository = new class( $stored ) implements ContentAccessSettingsRepository {

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

		$catalog = new class() implements ContentTypeCatalog {

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

			/**
			 * Not used by these tests.
			 *
			 * @param string $markup Unused markup.
			 * @throws \LogicException Always; this fake is not exercised by these tests.
			 */
			public function normalize( string $markup ): string {
				throw new \LogicException( 'not used' );
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

			/**
			 * Not used by these tests.
			 *
			 * @param string $markup Unused markup.
			 * @throws \LogicException Always; this fake is not exercised by these tests.
			 */
			public function normalize( string $markup ): string {
				throw new \LogicException( 'not used' );
			}
		};
	}

	/**
	 * Creates a mutation repository whose current version matches the given token
	 * (or reports the post as absent when null), tracking whether update() ran.
	 *
	 * @param string|null $current_token Serialized current token, or null for a missing post.
	 * @return ContentMutationRepository
	 */
	private function repository( ?string $current_token ): ContentMutationRepository {
		return new class( $current_token ) implements ContentMutationRepository {
			/**
			 * Whether update() has been invoked.
			 *
			 * @var bool
			 */
			public bool $updated = false;

			/**
			 * Creates the fake repository.
			 *
			 * @param string|null $current_token Serialized current token, or null for a missing post.
			 */
			public function __construct( private ?string $current_token ) {
			}

			/**
			 * Reports "post", or null when the post is absent.
			 *
			 * @param int $post_id Post ID.
			 * @return string|null
			 */
			public function post_type( int $post_id ): ?string {
				return null === $this->current_token ? null : 'post';
			}

			/**
			 * Reports the configured current token, or null when the post is absent.
			 *
			 * @param int $post_id Post ID.
			 * @return VersionToken|null
			 */
			public function current_version( int $post_id ): ?VersionToken {
				return null === $this->current_token ? null : VersionToken::from_string( $this->current_token );
			}

			/**
			 * Not used by these tests.
			 *
			 * @param DraftInput $input Validated draft input.
			 * @throws \LogicException Always; this fake is not exercised by these tests.
			 */
			public function create( DraftInput $input ): MutationResult {
				throw new \LogicException( 'not used' );
			}

			/**
			 * Records that a write occurred and returns a fixed successful result.
			 *
			 * @param int           $post_id Post ID to update.
			 * @param ContentUpdate $update  Validated update input.
			 * @return MutationResult
			 */
			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				$this->updated = true;

				return new MutationResult(
					$post_id,
					'post',
					'draft',
					new VersionToken( 'fedcba9876543210', '2026-07-20 01:00:00' ),
					$update->changed_fields(),
					false
				);
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
	 * Creates a mutation repository whose current version matches the given token
	 * but whose update() always throws MutationWriteFailed.
	 *
	 * @param string $current_token Serialized current token that satisfies concurrency control.
	 * @return ContentMutationRepository
	 */
	private function failing_repository( string $current_token ): ContentMutationRepository {
		return new class( $current_token ) implements ContentMutationRepository {

			/**
			 * Creates the fake repository.
			 *
			 * @param string $current_token Serialized current token that satisfies concurrency control.
			 */
			public function __construct( private string $current_token ) {
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
			 * Reports the configured current token.
			 *
			 * @param int $post_id Post ID.
			 * @return VersionToken|null
			 */
			public function current_version( int $post_id ): ?VersionToken {
				return VersionToken::from_string( $this->current_token );
			}

			/**
			 * Not used by these tests.
			 *
			 * @param DraftInput $input Validated draft input.
			 * @throws \LogicException Always; this fake is not exercised by these tests.
			 */
			public function create( DraftInput $input ): MutationResult {
				throw new \LogicException( 'not used' );
			}

			/**
			 * Always throws MutationWriteFailed.
			 *
			 * @param int           $post_id Post ID to update.
			 * @param ContentUpdate $update  Validated update input.
			 * @throws MutationWriteFailed Always; simulates a rejected write.
			 */
			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new MutationWriteFailed( 'boom' );
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
}
