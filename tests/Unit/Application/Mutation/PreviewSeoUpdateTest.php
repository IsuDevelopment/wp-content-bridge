<?php
/**
 * Unit tests for the preview-update-seo use case.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\PreviewSeoUpdate;
use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use IsuDev\WPContentBridge\Application\Mutation\SeoPreviewProvider;
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
final class PreviewSeoUpdateTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * A changing payload reports the right changed fields and causes no write.
	 */
	public function test_previews_changing_payload_reports_changed_fields_and_no_write(): void {
		$repository = $this->repository();
		$provider   = $this->provider( true );
		$use        = new PreviewSeoUpdate( $this->manager(), $repository, $provider );

		$result = $use->execute(
			array(
				'post_id'          => 42,
				'version_token'    => self::TOKEN,
				'seo_title'        => 'New title',
				'meta_description' => '',
			)
		)->to_array();

		self::assertFalse( $result['writes_performed'] );
		self::assertSame( array( 'seo_title', 'meta_description' ), $result['changed_fields'] );
		self::assertSame( array( 'title' => 'Saved title' ), $result['current_seo'] );
		self::assertSame(
			array(
				'seo_title'        => 'New title',
				'meta_description' => '',
			),
			$result['preview_seo']
		);
		self::assertContains( 'field_cleared', array_column( $result['warnings'], 'code' ) );
		self::assertSame( 1, $provider->current_calls );
		self::assertSame( 1, $provider->preview_calls );
		self::assertFalse( $repository->updated );
	}

	/**
	 * A stale token is rejected before the provider is even consulted.
	 */
	public function test_stale_token_is_rejected_before_provider_access(): void {
		$repository = $this->repository();
		$provider   = $this->provider( true );
		$use        = new PreviewSeoUpdate( $this->manager(), $repository, $provider );

		$this->expectException( MutationConflict::class );
		try {
			$use->execute(
				array(
					'post_id'       => 42,
					'version_token' => 'ffffffffffffffff:2026-07-20 12:30:00',
					'seo_title'     => 'Should never be read against',
				)
			);
		} finally {
			self::assertSame( 0, $provider->current_calls );
			self::assertSame( 0, $provider->preview_calls );
			self::assertFalse( $repository->updated );
		}
	}

	/**
	 * Policy denial behaves exactly as it does for the write.
	 */
	public function test_policy_denial_is_rejected(): void {
		$repository = $this->repository();
		$provider   = $this->provider( true );
		$use        = new PreviewSeoUpdate( $this->denying_manager(), $repository, $provider );

		$this->expectException( MutationForbidden::class );
		$use->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'seo_title'     => 'Denied',
			)
		);
	}

	/**
	 * An unsupported field is rejected exactly as it is for the write, before
	 * the target is even resolved.
	 */
	public function test_unsupported_field_is_rejected_before_target_resolution(): void {
		$repository = $this->repository();
		$provider   = $this->provider( true );
		$use        = new PreviewSeoUpdate( $this->manager(), $repository, $provider );

		$this->expectException( SeoFieldUnsupported::class );
		try {
			$use->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::TOKEN,
					'schema_type'   => 'Article',
				)
			);
		} finally {
			self::assertSame( 0, $provider->current_calls );
			self::assertSame( 0, $provider->preview_calls );
		}
	}

	/**
	 * Builds an access manager with Update SEO access enabled for 'post'.
	 */
	private function manager(): ContentAccessManager {
		return $this->manager_with( true );
	}

	/**
	 * Builds an access manager that denies Update SEO access for 'post'.
	 */
	private function denying_manager(): ContentAccessManager {
		return $this->manager_with( false );
	}

	/**
	 * Builds an access manager with a configurable Update SEO policy.
	 *
	 * @param bool $update_allowed Whether the update_seo policy is enabled.
	 */
	private function manager_with( bool $update_allowed ): ContentAccessManager {
		$repository = new class( $update_allowed ) implements ContentAccessSettingsRepository {
			/**
			 * Creates the settings repository.
			 *
			 * @param bool $update_allowed Whether update_seo is enabled.
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
						'get_content' => true,
						'update_seo'  => $this->update_allowed,
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
	 * Builds a fixed target repository.
	 *
	 * @return ContentMutationRepository&object{updated: bool}
	 */
	private function repository(): object {
		$version = VersionToken::from_string( self::TOKEN );

		return new class( $version ) implements ContentMutationRepository {
			/**
			 * Whether update() has been invoked.
			 *
			 * @var bool
			 */
			public bool $updated = false;

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
	 * Builds a side-effect-free provider spy.
	 *
	 * @param bool $available Whether the provider reports itself available.
	 * @return SeoPreviewProvider&object{current_calls: int, preview_calls: int}
	 */
	private function provider( bool $available ): object {
		return new class( $available ) implements SeoPreviewProvider {
			/**
			 * Number of current() calls.
			 *
			 * @var int
			 */
			public int $current_calls = 0;

			/**
			 * Number of preview() calls.
			 *
			 * @var int
			 */
			public int $preview_calls = 0;

			/**
			 * Creates the provider.
			 *
			 * @param bool $available Whether the provider reports itself available.
			 */
			public function __construct( private bool $available ) {}

			/**
			 * Reports the configured availability.
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Returns a fixed current document.
			 *
			 * @param int $post_id Target post ID.
			 * @return array<string, mixed>
			 */
			public function current( int $post_id ): array {
				++$this->current_calls;

				return array( 'title' => 'Saved title' );
			}

			/**
			 * Echoes back the requested fields as the prospective values.
			 *
			 * @param int   $post_id Target post ID.
			 * @param array $fields  Requested allowlisted fields.
			 * @phpstan-param array<string, mixed> $fields
			 * @return array<string, mixed>
			 */
			public function preview( int $post_id, array $fields ): array {
				++$this->preview_calls;

				return $fields;
			}
		};
	}
}
