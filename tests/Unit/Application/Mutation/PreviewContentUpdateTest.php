<?php
/**
 * Unit tests for the preview-update-content use case.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Mutation\BlockMarkupValidator;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\ContentSnapshotRepository;
use IsuDev\WPContentBridge\Application\Mutation\InvalidBlockMarkup;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\PreviewContentUpdate;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies authorization, concurrency, and side-effect-free preview behavior.
 */
final class PreviewContentUpdateTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * A changing payload reports the right changed fields and causes no write.
	 */
	public function test_previews_changing_payload_reports_changed_fields_and_no_write(): void {
		$repository = $this->repository();
		$validator  = $this->passing_validator( '<!-- wp:paragraph --><p>Normalized</p><!-- /wp:paragraph -->' );
		$use        = new PreviewContentUpdate( $this->manager(), $validator, $repository, $repository );

		$result = $use->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'title'         => 'New title',
				'block_markup'  => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->',
				'excerpt'       => 'New excerpt',
			)
		)->to_array();

		self::assertFalse( $result['writes_performed'] );
		self::assertSame( array( 'title', 'content', 'excerpt' ), $result['changed_fields'] );
		self::assertSame(
			array(
				'title'        => 'Old title',
				'block_markup' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
				'excerpt'      => 'Old excerpt',
			),
			$result['current_content']
		);
		self::assertSame(
			array(
				'title'        => 'New title',
				'block_markup' => '<!-- wp:paragraph --><p>Normalized</p><!-- /wp:paragraph -->',
				'excerpt'      => 'New excerpt',
			),
			$result['preview_content']
		);
		self::assertSame( array(), $result['preview_taxonomies'] );
		self::assertContains( 'content_replaced', array_column( $result['warnings'], 'code' ) );
		self::assertFalse( $repository->updated );
	}

	/**
	 * A stale token is rejected before the current content is even read.
	 */
	public function test_stale_token_is_rejected_before_snapshot_read(): void {
		$repository = $this->repository();
		$validator  = $this->passing_validator( 'unused' );
		$use        = new PreviewContentUpdate( $this->manager(), $validator, $repository, $repository );

		$this->expectException( MutationConflict::class );
		try {
			$use->execute(
				array(
					'post_id'       => 42,
					'version_token' => 'ffffffffffffffff:2026-07-20 12:30:00',
					'title'         => 'Should never be read against',
				)
			);
		} finally {
			self::assertSame( 0, $repository->snapshot_calls );
			self::assertSame( 0, $validator->validate_calls );
			self::assertFalse( $repository->updated );
		}
	}

	/**
	 * Policy denial behaves exactly as it does for the write.
	 */
	public function test_policy_denial_is_rejected(): void {
		$repository = $this->repository();
		$validator  = $this->passing_validator( 'unused' );
		$use        = new PreviewContentUpdate( $this->denying_manager(), $validator, $repository, $repository );

		$this->expectException( MutationForbidden::class );
		$use->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'title'         => 'Denied',
			)
		);
	}

	/**
	 * Invalid block markup behaves exactly as it does for the write.
	 */
	public function test_invalid_block_markup_is_rejected(): void {
		$repository = $this->repository();
		$validator  = $this->failing_validator( array( 'Block 0: unregistered block type.' ) );
		$use        = new PreviewContentUpdate( $this->manager(), $validator, $repository, $repository );

		$this->expectException( InvalidBlockMarkup::class );
		try {
			$use->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::TOKEN,
					'block_markup'  => '<!-- wp:unregistered/block /-->',
				)
			);
		} finally {
			self::assertFalse( $repository->updated );
		}
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
	 * @return ContentMutationRepository&ContentSnapshotRepository&object{updated: bool, snapshot_calls: int}
	 */
	private function repository(): object {
		$version = VersionToken::from_string( self::TOKEN );

		return new class( $version ) implements ContentMutationRepository, ContentSnapshotRepository {
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
			 * @param VersionToken $version Current test version.
			 */
			public function __construct( private VersionToken $version ) {}

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
					'title'        => 'Old title',
					'block_markup' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
					'excerpt'      => 'Old excerpt',
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
	 * Builds a validator that always passes and normalizes to a fixed value.
	 *
	 * @param string $normalized Fixed normalized markup to return.
	 * @return BlockMarkupValidator&object{validate_calls: int}
	 */
	private function passing_validator( string $normalized ): object {
		return new class( $normalized ) implements BlockMarkupValidator {
			/**
			 * Number of validate() calls.
			 *
			 * @var int
			 */
			public int $validate_calls = 0;

			/**
			 * Creates the validator.
			 *
			 * @param string $normalized Fixed normalized markup to return.
			 */
			public function __construct( private string $normalized ) {}

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
			 * Returns the configured fixed normalized markup.
			 *
			 * @param string $markup Raw Gutenberg block markup.
			 */
			public function normalize( string $markup ): string {
				return $this->normalized;
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
