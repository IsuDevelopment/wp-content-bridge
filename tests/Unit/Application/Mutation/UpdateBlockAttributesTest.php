<?php
/**
 * Unit tests for the UpdateBlockAttributes use case.
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
use IsuDev\WPContentBridge\Application\Mutation\BlockMismatch;
use IsuDev\WPContentBridge\Application\Mutation\BlockPathNotFound;
use IsuDev\WPContentBridge\Application\Mutation\BlockTreeSplicer;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\ContentSnapshotRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\UpdateBlockAttributes;
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
 * Verifies the update-block-attributes write flow: policy, optimistic
 * concurrency, path resolution, block-identity assertion, the freeform-node
 * fail-closed rule, the bounded attributes overlay, and the
 * exactly-one-audit-row-per-attempt guarantee. Neither a block path nor an
 * attribute name may ever enter the audit row, which records field names
 * only.
 */
final class UpdateBlockAttributesTest extends TestCase {

	private const CURRENT = 'abcdef0123456789:2026-07-20 00:00:00';
	private const STALE   = '0000000000000000:2026-07-19 00:00:00';
	private const CONTENT = '<!-- wp:isudev/icon-link {"label":"Old"} /-->';

	/**
	 * A permitted, valid request with a matching token, valid path, and
	 * matching block name is handed to the splicer's merge_attributes()
	 * unchanged, writes, and records one success row whose changed_fields is
	 * exactly ["content"] — never the path or an attribute name.
	 */
	public function test_merges_and_records_success(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'isudev/icon-link' ), 'merged-content' );
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::CURRENT,
				'path'                => array( 0 ),
				'expected_block_name' => 'isudev/icon-link',
				'attributes'          => array( 'label' => 'New label' ),
			),
			5
		);

		self::assertFalse( $result->created );
		self::assertSame( array( 'content' ), $result->changed_fields );
		self::assertSame( 1, $splicer->merge_calls );
		self::assertSame( array( 'label' => 'New label' ), $splicer->received_attributes );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
		self::assertSame( array( 'content' ), $audit->events[0]->changed_fields );
		self::assertSame( self::CURRENT, $audit->events[0]->expected_version );
		self::assertTrue( $repository->updated );
	}

	/**
	 * A null value in the overlay is passed through to the splicer intact,
	 * proving the use case never strips it: removal is the splicer's
	 * contract to fulfil, not something the use case may silently drop.
	 */
	public function test_null_value_removes_a_key(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'isudev/icon-link' ), 'merged-content' );
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$audit
		);

		$use_case->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::CURRENT,
				'path'                => array( 0 ),
				'expected_block_name' => 'isudev/icon-link',
				'attributes'          => array(
					'label'      => 'Kept',
					'deprecated' => null,
				),
			),
			5
		);

		self::assertSame(
			array(
				'label'      => 'Kept',
				'deprecated' => null,
			),
			$splicer->received_attributes
		);
		self::assertTrue( $repository->updated );
	}

	/**
	 * A freeform node addressed with expected_block_name: null passes the
	 * identity assertion (both sides are null) but still fails closed,
	 * because a freeform node has no attributes to merge into. No write
	 * occurs.
	 */
	public function test_freeform_node_fails_closed(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( null ), 'unused' );
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 1 ),
					'expected_block_name' => null,
					'attributes'          => array( 'label' => 'New' ),
				),
				5
			);
			self::fail( 'Expected BlockMismatch.' );
		} catch ( BlockMismatch $error ) {
			self::assertSame( 'wpcb_block_mismatch', $error->error_code() );
		}

		self::assertFalse( $repository->updated );
		self::assertSame( 0, $splicer->merge_calls );
		self::assertSame( 'invalid', $audit->events[0]->outcome );
		self::assertSame( 'wpcb_block_mismatch', $audit->events[0]->error_code );
	}

	/**
	 * A wrong expected_block_name throws BlockMismatch and does not write,
	 * even though the version token and path both resolved successfully.
	 */
	public function test_block_mismatch_does_not_write(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'core/heading' ), 'unused' );
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'isudev/icon-link',
					'attributes'          => array( 'label' => 'New' ),
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
	 * An out-of-range path throws BlockPathNotFound and does not write.
	 */
	public function test_path_not_found_does_not_write(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( null, 'unused' );
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 99 ),
					'expected_block_name' => 'isudev/icon-link',
					'attributes'          => array( 'label' => 'New' ),
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
	 * A stale version token throws MutationConflict, skips path resolution
	 * and the write, and records one conflict row.
	 */
	public function test_stale_token_conflicts_and_does_not_resolve_or_write(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->unused_splicer();
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::STALE,
					'path'                => array( 0 ),
					'expected_block_name' => 'isudev/icon-link',
					'attributes'          => array( 'label' => 'New' ),
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
	 * A missing post throws ContentUnavailable and records one invalid row.
	 */
	public function test_missing_post_is_unavailable(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$this->repository( null, self::CONTENT ),
			$this->repository( null, self::CONTENT ),
			$this->unused_splicer(),
			$audit
		);

		$this->expectException( ContentUnavailable::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'isudev/icon-link',
					'attributes'          => array( 'label' => 'New' ),
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
	 * Policy denial throws MutationForbidden and records exactly one denied
	 * row.
	 */
	public function test_policy_denial_is_forbidden(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( false ),
			$repository,
			$repository,
			$this->unused_splicer(),
			$audit
		);

		$this->expectException( MutationForbidden::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'isudev/icon-link',
					'attributes'          => array( 'label' => 'New' ),
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
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$this->unused_splicer(),
			$audit
		);

		$this->expectException( InvalidArgumentException::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'expected_block_name' => 'isudev/icon-link',
					'attributes'          => array( 'label' => 'New' ),
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
	 * Attributes must be a JSON object; a JSON-array-shaped payload is
	 * rejected before any read or write.
	 */
	public function test_attributes_must_be_an_object_not_a_list(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$this->unused_splicer(),
			$audit
		);

		$this->expectException( InvalidArgumentException::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'isudev/icon-link',
					'attributes'          => array( 'first', 'second' ),
				),
				5
			);
		} finally {
			self::assertFalse( $repository->updated );
			self::assertSame( 'wpcb_invalid_input', $audit->events[0]->error_code );
		}
	}

	/**
	 * An attributes object with more top-level keys than the bound allows
	 * is rejected before any read or write.
	 */
	public function test_attributes_exceeding_key_bound_is_rejected(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$this->unused_splicer(),
			$audit
		);

		$attributes = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$attributes[ 'key' . $i ] = $i;
		}

		$this->expectException( InvalidArgumentException::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'isudev/icon-link',
					'attributes'          => $attributes,
				),
				5
			);
		} finally {
			self::assertFalse( $repository->updated );
			self::assertSame( 'wpcb_invalid_input', $audit->events[0]->error_code );
		}
	}

	/**
	 * An attributes object whose canonical JSON encoding exceeds the byte
	 * bound is rejected before any read or write.
	 */
	public function test_attributes_exceeding_byte_bound_is_rejected(): void {
		$audit      = $this->audit_spy();
		$repository = $this->repository( self::CURRENT, self::CONTENT );
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$this->unused_splicer(),
			$audit
		);

		$this->expectException( InvalidArgumentException::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'isudev/icon-link',
					'attributes'          => array( 'label' => str_repeat( 'a', 100001 ) ),
				),
				5
			);
		} finally {
			self::assertFalse( $repository->updated );
			self::assertSame( 'wpcb_invalid_input', $audit->events[0]->error_code );
		}
	}

	/**
	 * A repository write failure throws MutationWriteFailed and records
	 * exactly one failure row with the stable write-failed error code.
	 */
	public function test_write_failure_records_failure(): void {
		$audit      = $this->audit_spy();
		$repository = $this->failing_repository( self::CURRENT, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'isudev/icon-link' ), 'unused' );
		$use_case   = new UpdateBlockAttributes(
			$this->manager_allowing_update( true ),
			$repository,
			$repository,
			$splicer,
			$audit
		);

		$this->expectException( MutationWriteFailed::class );

		try {
			$use_case->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::CURRENT,
					'path'                => array( 0 ),
					'expected_block_name' => 'isudev/icon-link',
					'attributes'          => array( 'label' => 'New' ),
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
	 * Creates a splicer that resolves to a fixed lookup (or null for "not
	 * found") and returns a fixed merged result, recording the attributes it
	 * was asked to merge.
	 *
	 * @param BlockPathLookup|null $lookup          Fixed resolve() result.
	 * @param string               $merged_content  Fixed merge_attributes() result.
	 * @return BlockTreeSplicer&object{resolve_calls: int, merge_calls: int, received_attributes: array<string, mixed>|null}
	 */
	private function splicer( ?BlockPathLookup $lookup, string $merged_content ): object {
		return new class( $lookup, $merged_content ) implements BlockTreeSplicer {
			/**
			 * Number of resolve() calls.
			 *
			 * @var int
			 */
			public int $resolve_calls = 0;

			/**
			 * Number of merge_attributes() calls.
			 *
			 * @var int
			 */
			public int $merge_calls = 0;

			/**
			 * The attributes overlay received by the last merge_attributes() call.
			 *
			 * @var array<string, mixed>|null
			 */
			public ?array $received_attributes = null;

			/**
			 * Creates the fake splicer.
			 *
			 * @param BlockPathLookup|null $lookup         Fixed resolve() result.
			 * @param string               $merged_content Fixed merge_attributes() result.
			 */
			public function __construct( private ?BlockPathLookup $lookup, private string $merged_content ) {
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
			 * Records the requested overlay and returns the fixed merged content.
			 *
			 * @param string $content    Unused raw content.
			 * @param array  $path       Unused path.
			 * @param array  $attributes Attributes overlay to record.
			 * @phpstan-param list<int> $path
			 * @phpstan-param array<string, mixed> $attributes
			 */
			public function merge_attributes( string $content, array $path, array $attributes ): string {
				++$this->merge_calls;
				$this->received_attributes = $attributes;

				return $this->merged_content;
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
			 * @phpstan-param array<string, mixed> $attributes
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
