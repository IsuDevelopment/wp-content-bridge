<?php
/**
 * Unit tests for the preview-update-block use case.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
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
use IsuDev\WPContentBridge\Application\Mutation\PreviewBlockUpdate;
use IsuDev\WPContentBridge\Domain\Content\BlockPathLookup;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies authorization, concurrency, path/identity assertions, and the
 * side-effect-free preview behavior required by ADR 0021: no write, no
 * audit dependency at all, and writes_performed: false.
 */
final class PreviewBlockUpdateTest extends TestCase {

	private const TOKEN   = 'abcdef0123456789:2026-07-20 12:30:00';
	private const CONTENT = '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->';

	/**
	 * A valid preview reports writes_performed: false, the resolved
	 * changed_fields, and the round-tripped preview content, without writing.
	 */
	public function test_previews_replacement_without_writing(): void {
		$repository = $this->repository( self::TOKEN, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'core/paragraph' ), '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->' );
		$use        = new PreviewBlockUpdate( $this->manager(), $repository, $repository, $splicer, $this->passing_validator() );

		$result = $use->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::TOKEN,
				'path'                => array( 0 ),
				'expected_block_name' => 'core/paragraph',
				'block_markup'        => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->',
			)
		)->to_array();

		self::assertFalse( $result['writes_performed'] );
		self::assertSame( array( 'content' ), $result['changed_fields'] );
		self::assertSame( self::CONTENT, $result['current_content'] );
		self::assertSame( '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->', $result['preview_content'] );
		self::assertFalse( $repository->updated );
	}

	/**
	 * A stale token is rejected before the current content is even read, and
	 * before the splicer is ever consulted.
	 */
	public function test_stale_token_is_rejected_before_snapshot_read(): void {
		$repository = $this->repository( self::TOKEN, self::CONTENT );
		$splicer    = $this->unused_splicer();
		$use        = new PreviewBlockUpdate( $this->manager(), $repository, $repository, $splicer, $this->passing_validator() );

		$this->expectException( MutationConflict::class );
		try {
			$use->execute(
				array(
					'post_id'             => 42,
					'version_token'       => 'ffffffffffffffff:2026-07-20 12:30:00',
					'path'                => array( 0 ),
					'expected_block_name' => 'core/paragraph',
					'block_markup'        => 'unused',
				)
			);
		} finally {
			self::assertSame( 0, $repository->snapshot_calls );
			self::assertSame( 0, $splicer->resolve_calls );
			self::assertFalse( $repository->updated );
		}
	}

	/**
	 * Policy denial behaves exactly as it does for the write.
	 */
	public function test_policy_denial_is_rejected(): void {
		$repository = $this->repository( self::TOKEN, self::CONTENT );
		$use        = new PreviewBlockUpdate( $this->denying_manager(), $repository, $repository, $this->unused_splicer(), $this->passing_validator() );

		$this->expectException( MutationForbidden::class );
		$use->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::TOKEN,
				'path'                => array( 0 ),
				'expected_block_name' => 'core/paragraph',
				'block_markup'        => 'unused',
			)
		);
	}

	/**
	 * An out-of-range path throws BlockPathNotFound.
	 */
	public function test_path_not_found_is_rejected(): void {
		$repository = $this->repository( self::TOKEN, self::CONTENT );
		$splicer    = $this->splicer( null, 'unused' );
		$use        = new PreviewBlockUpdate( $this->manager(), $repository, $repository, $splicer, $this->passing_validator() );

		$this->expectException( BlockPathNotFound::class );
		$use->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::TOKEN,
				'path'                => array( 99 ),
				'expected_block_name' => 'core/paragraph',
				'block_markup'        => 'unused',
			)
		);
	}

	/**
	 * A wrong expected_block_name throws BlockMismatch even though the path
	 * resolved to a real node.
	 */
	public function test_block_mismatch_is_rejected(): void {
		$repository = $this->repository( self::TOKEN, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'core/heading' ), 'unused' );
		$use        = new PreviewBlockUpdate( $this->manager(), $repository, $repository, $splicer, $this->passing_validator() );

		$this->expectException( BlockMismatch::class );
		$use->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::TOKEN,
				'path'                => array( 0 ),
				'expected_block_name' => 'core/paragraph',
				'block_markup'        => 'unused',
			)
		);
	}

	/**
	 * A freeform node asserted with expected_block_name: null previews
	 * successfully.
	 */
	public function test_freeform_node_asserted_with_null_previews(): void {
		$repository = $this->repository( self::TOKEN, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( null ), 'spliced' );
		$use        = new PreviewBlockUpdate( $this->manager(), $repository, $repository, $splicer, $this->passing_validator() );

		$result = $use->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::TOKEN,
				'path'                => array( 1 ),
				'expected_block_name' => null,
				'block_markup'        => 'freeform text',
			)
		)->to_array();

		self::assertSame( 'spliced', $result['preview_content'] );
	}

	/**
	 * Invalid block markup behaves exactly as it does for the write.
	 */
	public function test_invalid_block_markup_is_rejected(): void {
		$repository = $this->repository( self::TOKEN, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'core/paragraph' ), 'unused' );
		$validator  = $this->failing_validator( array( 'Block 0: unregistered block type.' ) );
		$use        = new PreviewBlockUpdate( $this->manager(), $repository, $repository, $splicer, $validator );

		$this->expectException( InvalidBlockMarkup::class );
		try {
			$use->execute(
				array(
					'post_id'             => 42,
					'version_token'       => self::TOKEN,
					'path'                => array( 0 ),
					'expected_block_name' => 'core/paragraph',
					'block_markup'        => '<!-- wp:unregistered/block /-->',
				)
			);
		} finally {
			self::assertFalse( $repository->updated );
		}
	}

	/**
	 * An empty block_markup deletes the subtree and skips validation.
	 */
	public function test_empty_markup_skips_validation(): void {
		$repository = $this->repository( self::TOKEN, self::CONTENT );
		$splicer    = $this->splicer( new BlockPathLookup( 'core/paragraph' ), '' );
		$validator  = $this->passing_validator();
		$use        = new PreviewBlockUpdate( $this->manager(), $repository, $repository, $splicer, $validator );

		$result = $use->execute(
			array(
				'post_id'             => 42,
				'version_token'       => self::TOKEN,
				'path'                => array( 0 ),
				'expected_block_name' => 'core/paragraph',
				'block_markup'        => '',
			)
		)->to_array();

		self::assertSame( 0, $validator->validate_calls );
		self::assertSame( '', $result['preview_content'] );
	}

	/**
	 * Builds an access manager with Update access enabled for 'post'.
	 */
	private function manager(): ContentAccessManager {
		return $this->manager_with( true );
	}

	/**
	 * Builds an access manager that denies Update access for 'post'.
	 */
	private function denying_manager(): ContentAccessManager {
		return $this->manager_with( false );
	}

	/**
	 * Builds an access manager with a configurable Update policy.
	 *
	 * @param bool $update_allowed Whether the update_content policy is enabled.
	 */
	private function manager_with( bool $update_allowed ): ContentAccessManager {
		$repository = new class( $update_allowed ) implements ContentAccessSettingsRepository {
			/**
			 * Creates the settings repository.
			 *
			 * @param bool $update_allowed Whether update_content is enabled.
			 */
			public function __construct( private bool $update_allowed ) {}

			/**
			 * Returns test settings.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return array(
					'post' => array(
						'get_content'    => true,
						'update_content' => $this->update_allowed,
					),
				);
			}
		};
		$catalog    = new class() implements ContentTypeCatalog {
			/**
			 * Returns one eligible test type.
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
	 * Builds a fixed target repository implementing both mutation ports.
	 *
	 * @param string $token        Serialized current version token.
	 * @param string $block_markup Fixed content_snapshot() block_markup value.
	 * @return ContentMutationRepository&ContentSnapshotRepository&object{updated: bool, snapshot_calls: int}
	 */
	private function repository( string $token, string $block_markup ): object {
		$version = VersionToken::from_string( $token );

		return new class( $version, $block_markup ) implements ContentMutationRepository, ContentSnapshotRepository {
			/**
			 * Whether update() has been invoked.
			 *
			 * @var bool
			 */
			public bool $updated = false;

			/**
			 * Number of content_snapshot() calls.
			 *
			 * @var int
			 */
			public int $snapshot_calls = 0;

			/**
			 * Creates the test repository.
			 *
			 * @param VersionToken $version      Current test version.
			 * @param string       $block_markup Fixed content_snapshot() block_markup value.
			 */
			public function __construct( private VersionToken $version, private string $block_markup ) {}

			/**
			 * Returns the fixed post type.
			 *
			 * @param int $post_id Target post ID.
			 */
			public function post_type( int $post_id ): ?string {
				return 42 === $post_id ? 'post' : null;
			}

			/**
			 * Returns the fixed current version.
			 *
			 * @param int $post_id Target post ID.
			 */
			public function current_version( int $post_id ): ?VersionToken {
				return 42 === $post_id ? $this->version : null;
			}

			/**
			 * Returns the fixed current content snapshot.
			 *
			 * @param int $post_id Target post ID.
			 * @return array{title: string, block_markup: string, excerpt: string}|null
			 */
			public function content_snapshot( int $post_id ): ?array {
				++$this->snapshot_calls;

				if ( 42 !== $post_id ) {
					return null;
				}

				return array(
					'title'        => 'Title',
					'block_markup' => $this->block_markup,
					'excerpt'      => '',
				);
			}

			/**
			 * Rejects unused draft creation.
			 *
			 * @param DraftInput $input Unused draft input.
			 * @throws RuntimeException Always.
			 */
			public function create( DraftInput $input ): MutationResult {
				throw new RuntimeException( 'not used' );
			}

			/**
			 * Records that a write would have occurred.
			 *
			 * @param int           $post_id Unused post ID.
			 * @param ContentUpdate $update  Unused content update.
			 * @throws RuntimeException Always; a preview must never reach this.
			 */
			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				$this->updated = true;

				throw new RuntimeException( 'a preview must never call update()' );
			}

			/**
			 * Returns no mutation result because writes are not used.
			 *
			 * @param int $post_id Unused post ID.
			 */
			public function result_for( int $post_id ): ?MutationResult {
				return null;
			}
		};
	}

	/**
	 * Creates a splicer that resolves to a fixed lookup (or null for "not
	 * found") and returns a fixed spliced result.
	 *
	 * @param BlockPathLookup|null $lookup          Fixed resolve() result.
	 * @param string               $spliced_content Fixed splice() result.
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
	 * Builds a validator that always passes.
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
			 * @throws RuntimeException Always; this fake is not exercised by these tests.
			 */
			public function normalize( string $markup ): string {
				throw new RuntimeException( 'not used' );
			}
		};
	}

	/**
	 * Builds a validator that always fails with the given reasons.
	 *
	 * @param array<int, string> $reasons Failure reasons to return.
	 */
	private function failing_validator( array $reasons ): BlockMarkupValidator {
		return new class( $reasons ) implements BlockMarkupValidator {
			/**
			 * Creates the validator.
			 *
			 * @param array<int, string> $reasons Failure reasons to return.
			 */
			public function __construct( private array $reasons ) {}

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
			 * Not used by this fake.
			 *
			 * @param string $markup Unused markup.
			 * @throws RuntimeException Always; validation fails before normalization.
			 */
			public function normalize( string $markup ): string {
				throw new RuntimeException( 'not used' );
			}
		};
	}
}
