<?php
/**
 * Unit tests for the UpdateBlock use case.
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
use IsuDev\WPContentBridge\Application\Mutation\BlockMismatch;
use IsuDev\WPContentBridge\Application\Mutation\BlockPathNotFound;
use IsuDev\WPContentBridge\Application\Mutation\BlockTreeSplicer;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\ContentSnapshotRepository;
use IsuDev\WPContentBridge\Application\Mutation\InvalidBlockMarkup;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\UpdateBlock;
use IsuDev\WPContentBridge\Domain\Content\BlockPathLookup;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies the update-block write flow: policy, optimistic concurrency, path
 * resolution, block-identity assertion, block validation, write, and the
 * exactly-one-audit-row-per-attempt guarantee. A block path must never enter
 * the audit row, which records field names only.
 */
final class UpdateBlockTest extends TestCase {

	private const CURRENT = 'abcdef0123456789:2026-07-20 00:00:00';
	private const STALE   = '0000000000000000:2026-07-19 00:00:00';
	private const CONTENT = '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->';

	/**
	 * A permitted, valid request with a matching token, valid path, and
	 * matching block name splices the replacement, writes, and records one
	 * success row whose changed_fields is exactly ["content"].
	 */
	public function test_updates_and_records_success(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'core/paragraph' ), '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->' );
		$use_case   = new UpdateBlock(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$this->passing_validator(),
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::CURRENT,
				'path'                => array( 0 ),
				'expected_block_name' => 'core/paragraph',
				'block_markup'        => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->',
			),
			5
		);

		self::assertFalse( $result->created );
		self::assertSame( array( 'content' ), $result->changed_fields );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
		self::assertSame( array( 'content' ), $audit->events[0]->changed_fields );
		self::assertSame( self::CURRENT, $audit->events[0]->expected_version );
		self::assertTrue( $repository->updated );
	}

	/**
	 * A freeform node addressed with expected_block_name: null succeeds,
	 * proving null is a legal assertion, not a missing-value default.
	 */
	public function test_freeform_node_asserted_with_null_succeeds(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( null ), 'replacement' );
		$use_case   = new UpdateBlock(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$this->passing_validator(),
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::CURRENT,
				'path'                => array( 1 ),
				'expected_block_name' => null,
				'block_markup'        => 'replacement',
			),
			5
		);

		self::assertSame( array( 'content' ), $result->changed_fields );
		self::assertTrue( $repository->updated );
	}

	/**
	 * An empty block_markup deletes the subtree and skips validation
	 * entirely (the validator must not be called for an empty replacement).
	 */
	public function test_empty_markup_deletes_and_skips_validation(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'core/paragraph' ), '' );
		$validator  = $this->passing_validator();
		$use_case   = new UpdateBlock(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$validator,
			$audit
		);

		$use_case->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::CURRENT,
				'path'                => array( 0 ),
				'expected_block_name' => 'core/paragraph',
				'block_markup'        => '',
			),
			5
		);

		self::assertSame( 0, $validator->validate_calls );
		self::assertTrue( $repository->updated );
	}

	/**
	 * A stale version token throws MutationConflict, skips path resolution
	 * and the write, and records one conflict row.
	 */
	public function test_stale_token_conflicts_and_does_not_resolve_or_write(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->unused_splicer();
		$use_case   = new UpdateBlock(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$this->passing_validator(),
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::STALE,
					'path'                => array( 0 ),
					'expected_block_name' => 'core/paragraph',
					'block_markup'        => 'anything',
				),
				5
			);
			self::fail( 'Expected MutationConflict.' );
		} catch ( MutationConflict $conflict ) {
			self::assertSame( 'wpcb_conflict', $conflict->error_code() );
		}

		self::assertFalse( $repository->updated, 'update() must not run on conflict.' );
		self::assertSame( 0, $splicer->resolve_calls );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'conflict', $audit->events[0]->outcome );
		self::assertSame( array(), $audit->events[0]->changed_fields );
	}

	/**
	 * An out-of-range path throws BlockPathNotFound and does not write.
	 */
	public function test_path_not_found_does_not_write(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( null, 'unused' );
		$use_case   = new UpdateBlock(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$this->passing_validator(),
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 99 ),
					'expected_block_name' => 'core/paragraph',
					'block_markup'        => 'anything',
				),
				5
			);
			self::fail( 'Expected BlockPathNotFound.' );
		} catch ( BlockPathNotFound $error ) {
			self::assertSame( 'wpcb_block_path_not_found', $error->error_code() );
		}

		self::assertFalse( $repository->updated );
		self::assertSame( 'invalid', $audit->events[0]->outcome );
		self::assertSame( 'wpcb_block_path_not_found', $audit->events[0]->error_code );
	}

	/**
	 * A wrong expected_block_name throws BlockMismatch and does not write,
	 * even though the version token and path both resolved successfully.
	 */
	public function test_block_mismatch_does_not_write(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'core/heading' ), 'unused' );
		$use_case   = new UpdateBlock(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$this->passing_validator(),
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'core/paragraph',
					'block_markup'        => 'anything',
				),
				5
			);
			self::fail( 'Expected BlockMismatch.' );
		} catch ( BlockMismatch $error ) {
			self::assertSame( 'wpcb_block_mismatch', $error->error_code() );
		}

		self::assertFalse( $repository->updated );
		self::assertSame( 'invalid', $audit->events[0]->outcome );
		self::assertSame( 'wpcb_block_mismatch', $audit->events[0]->error_code );
	}

	/**
	 * A missing post throws ContentUnavailable and records one invalid row.
	 */
	public function test_missing_post_is_unavailable(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateBlock(
			$this->manager_allowing_update( true ),
			$this->repository( null, self::CONTENT ),
			$this->repository( null, self::CONTENT ),
			$this->unused_splicer(),
			$this->passing_validator(),
			$audit
		);

		$this->expectException( ContentUnavailable::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'core/paragraph',
					'block_markup'        => 'anything',
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
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$use_case   = new UpdateBlock(
			$this->manager_allowing_update( false ),
			$repository,
			$repository,
			$this->unused_splicer(),
			$this->passing_validator(),
			$audit
		);

		$this->expectException( MutationForbidden::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'core/paragraph',
					'block_markup'        => 'anything',
				),
				5
			);
		} finally {
			self::assertSame( 'denied', $audit->events[0]->outcome );
		}
	}

	/**
	 * Malformed input (missing required path) throws before policy checks
	 * and records one invalid row.
	 */
	public function test_invalid_input_records_invalid(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$use_case   = new UpdateBlock(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$this->unused_splicer(),
			$this->passing_validator(),
			$audit
		);

		$this->expectException( InvalidArgumentException::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'expected_block_name' => 'core/paragraph',
					'block_markup'        => 'anything',
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
	 * Invalid replacement block markup throws InvalidBlockMarkup and records
	 * one invalid row.
	 */
	public function test_invalid_blocks_records_invalid(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'core/paragraph' ), 'unused' );
		$use_case   = new UpdateBlock(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$this->failing_validator( array( 'block 0: unregistered' ) ),
			$audit
		);

		$this->expectException( InvalidBlockMarkup::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'core/paragraph',
					'block_markup'        => '<!-- wp:acme/nope /-->',
				),
				5
			);
		} finally {
			self::assertFalse( $repository->updated );
			self::assertCount( 1, $audit->events );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_invalid_blocks', $audit->events[0]->error_code );
		}
	}

	/**
	 * A repository write failure throws MutationWriteFailed and records
	 * exactly one failure row with the stable write-failed error code.
	 */
	public function test_write_failure_records_failure(): void {
		$audit      = $this->audit_spy();
		$repository = $this->failing_repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'core/paragraph' ), 'unused' );
		$use_case   = new UpdateBlock(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$this->passing_validator(),
			$audit
		);

		$this->expectException( MutationWriteFailed::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'core/paragraph',
					'block_markup'        => 'anything',
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
	 * @return AuditLog&object{events: array<int, AuditEvent>}
	 */
	private function audit_spy(): object {
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
	 * @return BlockMarkupValidator&object{validate_calls: int}
	 */
	private function passing_validator(): object {
		return new class() implements BlockMarkupValidator {
			/**
			 * Number of validate() calls.
			 *
			 * @var int
			 */
			public int $validate_calls = 0;

			/**
			 * Always reports valid markup.
			 *
			 * @param string $markup Raw Gutenberg block markup.
			 * @return list<string>
			 */
			public function validate( string $markup ): array {
				++$this->validate_calls;

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
	 * Creates a splicer that resolves to a fixed lookup (or null for "not
	 * found") and returns a fixed spliced result.
	 *
	 * @param BlockPathLookup|null $lookup           Fixed resolve() result.
	 * @param string               $spliced_content  Fixed splice() result.
	 * @return BlockTreeSplicer&object{resolve_calls: int, splice_calls: int}
	 */
	private function splicer( ?BlockPathLookup $lookup, string $spliced_content ): object {
		return new class( $lookup, $spliced_content ) implements BlockTreeSplicer {
			/**
			 * Number of resolve() calls.
			 *
			 * @var int
			 */
			public int $resolve_calls = 0;

			/**
			 * Number of splice() calls.
			 *
			 * @var int
			 */
			public int $splice_calls = 0;

			/**
			 * Creates the fake splicer.
			 *
			 * @param BlockPathLookup|null $lookup          Fixed resolve() result.
			 * @param string               $spliced_content Fixed splice() result.
			 */
			public function __construct( private ?BlockPathLookup $lookup, private string $spliced_content ) {
			}

			/**
			 * Returns the fixed lookup.
			 *
			 * @param string $content Unused raw content.
			 * @param array  $path    Unused path.
			 * @phpstan-param list<int> $path
			 */
			public function resolve( string $content, array $path ): ?BlockPathLookup {
				++$this->resolve_calls;

				return $this->lookup;
			}

			/**
			 * Returns the fixed spliced content.
			 *
			 * @param string $content      Unused raw content.
			 * @param array  $path         Unused path.
			 * @param string $block_markup Unused replacement markup.
			 * @phpstan-param list<int> $path
			 */
			public function splice( string $content, array $path, string $block_markup ): string {
				++$this->splice_calls;

				return $this->spliced_content;
			}

			/**
			 * Not used by these tests.
			 *
			 * @param string $content    Unused raw content.
			 * @param array  $path       Unused path.
			 * @param array  $attributes Unused attributes overlay.
			 * @throws RuntimeException Always; this fake is not exercised by these tests.
			 * @phpstan-param list<int> $path
			 * @phpstan-param array<int|string, mixed> $attributes
			 */
			public function merge_attributes( string $content, array $path, array $attributes ): string {
				throw new RuntimeException( 'not used' );
			}
		};
	}

	/**
	 * Creates a splicer that must never be called (used for tests that
	 * should fail before path resolution is reached).
	 *
	 * @return BlockTreeSplicer&object{resolve_calls: int}
	 */
	private function unused_splicer(): object {
		return new class() implements BlockTreeSplicer {
			/**
			 * Number of resolve() calls.
			 *
			 * @var int
			 */
			public int $resolve_calls = 0;

			/**
			 * Counts the call; a use case reaching this indicates a defect.
			 *
			 * @param string $content Unused raw content.
			 * @param array  $path    Unused path.
			 * @phpstan-param list<int> $path
			 */
			public function resolve( string $content, array $path ): ?BlockPathLookup {
				++$this->resolve_calls;

				return null;
			}

			/**
			 * Not used by these tests.
			 *
			 * @param string $content      Unused raw content.
			 * @param array  $path         Unused path.
			 * @param string $block_markup Unused replacement markup.
			 * @throws RuntimeException Always; this fake is not exercised by these tests.
			 * @phpstan-param list<int> $path
			 */
			public function splice( string $content, array $path, string $block_markup ): string {
				throw new RuntimeException( 'not used' );
			}

			/**
			 * Not used by these tests.
			 *
			 * @param string $content    Unused raw content.
			 * @param array  $path       Unused path.
			 * @param array  $attributes Unused attributes overlay.
			 * @throws RuntimeException Always; this fake is not exercised by these tests.
			 * @phpstan-param list<int> $path
			 * @phpstan-param array<int|string, mixed> $attributes
			 */
			public function merge_attributes( string $content, array $path, array $attributes ): string {
				throw new RuntimeException( 'not used' );
			}
		};
	}

	/**
	 * Creates a mutation repository whose current version matches the given
	 * token (or reports the post as absent when null), tracking whether
	 * update() ran.
	 *
	 * @param string|null $current_token Serialized current token, or null for a missing post.
	 * @param string      $block_markup  Fixed content_snapshot() block_markup value.
	 * @return ContentMutationRepository&ContentSnapshotRepository&object{updated: bool}
	 */
	private function repository( ?string $current_token, string $block_markup ): object {
		return new class( $current_token, $block_markup ) implements ContentMutationRepository, ContentSnapshotRepository {
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
			 * @param string      $block_markup  Fixed content_snapshot() block_markup value.
			 */
			public function __construct( private ?string $current_token, private string $block_markup ) {
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
			 * Returns the fixed content snapshot.
			 *
			 * @param int $post_id Post ID.
			 * @return array{title: string, block_markup: string, excerpt: string}|null
			 */
			public function content_snapshot( int $post_id ): ?array {
				if ( null === $this->current_token ) {
					return null;
				}

				return array(
					'title'        => 'Title',
					'block_markup' => $this->block_markup,
					'excerpt'      => '',
				);
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
					'publish',
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

			/**
			 * Not used by these tests.
			 *
			 * @param int                                    $post_id       Unused post ID.
			 * @param string                                 $target_status Unused target status.
			 * @param array{local: string, utc: string}|null $scheduled_at  Unused.
			 * @throws \LogicException Always; this fake is not exercised by these tests.
			 */
			public function transition_status( int $post_id, string $target_status, ?array $scheduled_at ): MutationResult {
				throw new \LogicException( 'not used' );
			}
		};
	}

	/**
	 * Creates a mutation repository whose current version matches the given
	 * token but whose update() always throws MutationWriteFailed.
	 *
	 * @param string $current_token Serialized current token that satisfies concurrency control.
	 * @param string $block_markup  Fixed content_snapshot() block_markup value.
	 * @return ContentMutationRepository&ContentSnapshotRepository
	 */
	private function failing_repository( string $current_token, string $block_markup ): object {
		return new class( $current_token, $block_markup ) implements ContentMutationRepository, ContentSnapshotRepository {

			/**
			 * Creates the fake repository.
			 *
			 * @param string $current_token Serialized current token that satisfies concurrency control.
			 * @param string $block_markup  Fixed content_snapshot() block_markup value.
			 */
			public function __construct( private string $current_token, private string $block_markup ) {
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
			 * Returns the fixed content snapshot.
			 *
			 * @param int $post_id Post ID.
			 * @return array{title: string, block_markup: string, excerpt: string}|null
			 */
			public function content_snapshot( int $post_id ): ?array {
				return array(
					'title'        => 'Title',
					'block_markup' => $this->block_markup,
					'excerpt'      => '',
				);
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

			/**
			 * Not used by these tests.
			 *
			 * @param int                                    $post_id       Unused post ID.
			 * @param string                                 $target_status Unused target status.
			 * @param array{local: string, utc: string}|null $scheduled_at  Unused.
			 * @throws \LogicException Always; this fake is not exercised by these tests.
			 */
			public function transition_status( int $post_id, string $target_status, ?array $scheduled_at ): MutationResult {
				throw new \LogicException( 'not used' );
			}
		};
	}
}
