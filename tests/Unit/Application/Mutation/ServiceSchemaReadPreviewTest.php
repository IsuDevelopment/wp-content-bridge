<?php
/**
 * Unit tests for Service schema read and preview use cases.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\GetServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\PreviewServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\ServiceSchemaReader;
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
final class ServiceSchemaReadPreviewTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * Read returns independently persisted configuration and its current token.
	 */
	public function test_reads_saved_configuration_with_current_version(): void {
		$reader = $this->reader();
		$result = ( new GetServiceSchema( $this->manager(), $this->repository(), $reader ) )->execute( array( 'post_id' => 42 ) )->to_array();

		self::assertSame( 42, $result['post_id'] );
		self::assertSame( self::TOKEN, $result['version_token'] );
		self::assertSame( 'Saved service', $result['service_schema']['name'] );
		self::assertTrue( $result['provenance']['untrusted'] );
		self::assertSame( 1, $reader->read_calls );
		self::assertSame( 0, $reader->preview_calls );
	}

	/**
	 * Preview applies provider normalization without calling a write surface.
	 */
	public function test_previews_sanitized_configuration_without_writing(): void {
		$reader = $this->reader();
		$result = ( new PreviewServiceSchema( $this->manager(), $this->repository(), $reader ) )->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'name'          => ' Proposed service ',
			),
		)->to_array();

		self::assertTrue( $result['dry_run'] );
		self::assertSame( array( 'name' ), $result['changed_fields'] );
		self::assertSame( 'Saved service', $result['current_service_schema']['name'] );
		self::assertSame( 'Proposed service', $result['preview_service_schema']['name'] );
		self::assertSame( 1, $reader->read_calls );
		self::assertSame( 1, $reader->preview_calls );
	}

	/**
	 * Preview rejects a stale token before consulting the provider.
	 */
	public function test_stale_preview_is_rejected_before_provider_access(): void {
		$reader = $this->reader();
		$use    = new PreviewServiceSchema( $this->manager(), $this->repository(), $reader );

		$this->expectException( MutationConflict::class );
		try {
			$use->execute(
				array(
					'post_id'       => 42,
					'version_token' => 'ffffffffffffffff:2026-07-20 12:30:00',
					'enabled'       => true,
				)
			);
		} finally {
			self::assertSame( 0, $reader->read_calls );
			self::assertSame( 0, $reader->preview_calls );
		}
	}

	/**
	 * Builds an access manager with Service/SEO access enabled.
	 */
	private function manager(): ContentAccessManager {
		$repository = new class() implements ContentAccessSettingsRepository {
			/**
			 * Returns enabled test settings.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return array(
					'post' => array(
						'get_content' => true,
						'update_seo'  => true,
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
	 */
	private function repository(): ContentMutationRepository {
		$version = VersionToken::from_string( self::TOKEN );

		return new class( $version ) implements ContentMutationRepository {
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
			 * Rejects unused content mutation.
			 *
			 * @param int           $post_id Unused post ID.
			 * @param ContentUpdate $update  Unused content update.
			 * @throws RuntimeException Always.
			 */
			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new RuntimeException( 'not used' );
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
	 * @return object
	 */
	private function reader(): object {
		return new class() implements ServiceSchemaReader {
			/**
			 * Number of read calls.
			 *
			 * @var int
			 */
			public int $read_calls = 0;

			/**
			 * Number of preview calls.
			 *
			 * @var int
			 */
			public int $preview_calls = 0;

			/**
			 * Reports the provider as available.
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Supports only the test post type.
			 *
			 * @param string $post_type Candidate post type.
			 */
			public function supports_post_type( string $post_type ): bool {
				return 'post' === $post_type;
			}

			/**
			 * Returns the saved test configuration.
			 *
			 * @param int $post_id Target post ID.
			 * @return array<string, mixed>
			 */
			public function read( int $post_id ): array {
				++$this->read_calls;

				return $this->configuration( 'Saved service' );
			}

			/**
			 * Returns a normalized prospective configuration.
			 *
			 * @param int                  $post_id Target post ID.
			 * @param array<string, mixed> $fields Fields to preview.
			 * @return array<string, mixed>
			 */
			public function preview( int $post_id, array $fields ): array {
				++$this->preview_calls;
				$name = isset( $fields['name'] ) && is_string( $fields['name'] ) ? trim( $fields['name'] ) : 'Saved service';

				return $this->configuration( $name );
			}

			/**
			 * Builds one complete test configuration.
			 *
			 * @param string $name Service name.
			 * @return array<string, mixed>
			 */
			private function configuration( string $name ): array {
				return array(
					'schema_version' => '1.0',
					'enabled'        => true,
					'name'           => $name,
					'service_type'   => 'Monitoring',
					'description'    => '',
					'areas'          => array(),
					'brands'         => array(),
					'catalog_name'   => '',
					'offers'         => array(),
					'provider'       => array(
						'name'    => 'isudev-schema-extended',
						'version' => '1.0.0',
					),
				);
			}
		};
	}
}
