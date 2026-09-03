<?php
/**
 * Unit tests for Custom Schema application use cases.
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
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\CustomSchemaReader;
use IsuDev\WPContentBridge\Application\Mutation\CustomSchemaWriter;
use IsuDev\WPContentBridge\Application\Mutation\GetCustomSchema;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\PreviewCustomSchema;
use IsuDev\WPContentBridge\Application\Mutation\SchemaTargetReader;
use IsuDev\WPContentBridge\Application\Mutation\UpdateCustomSchema;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\SchemaTarget;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies read-before-write, dry-run, concurrency, and redacted audit behavior.
 */
final class CustomSchemaUseCasesTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * Read returns saved source and current content version.
	 */
	public function test_reads_saved_custom_schema_configuration(): void {
		$provider = $this->provider();
		$result   = ( new GetCustomSchema( $this->manager(), $this->repository(), $provider, $this->target() ) )->execute( array( 'post_id' => 42 ) )->to_array();

		self::assertSame( self::TOKEN, $result['version_token'] );
		self::assertSame( '{"@type":"Service"}', $result['custom_schema']['source'] );
		self::assertTrue( $result['custom_schema']['render_eligible'] );
		self::assertSame( 1, $provider->read_calls );
		self::assertSame( 'Fixture page', $result['target']['title'] );
		self::assertSame( 'https://example.test/fixture-page/', $result['target']['url'] );
		self::assertSame( '2026-07-01T09:00:00+00:00', $result['target']['published_at'] );
		self::assertSame( 7, $result['target']['featured_image_id'] );
	}

	/**
	 * Preview reports invalid prospective JSON but performs no write.
	 */
	public function test_previews_invalid_source_without_writing(): void {
		$provider = $this->provider();
		$result   = ( new PreviewCustomSchema( $this->manager(), $this->repository(), $provider ) )->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'source'        => '{invalid',
			)
		)->to_array();

		self::assertFalse( $result['writes_performed'] );
		self::assertFalse( $result['preview_custom_schema']['validation']['valid'] );
		self::assertFalse( $result['preview_custom_schema']['save_allowed'] );
		self::assertSame( 0, $provider->write_calls );
	}

	/**
	 * Preview rejects a stale content token before provider access.
	 */
	public function test_stale_preview_is_rejected_before_provider_access(): void {
		$provider = $this->provider();
		$use      = new PreviewCustomSchema( $this->manager(), $this->repository(), $provider );

		$this->expectException( MutationConflict::class );
		try {
			$use->execute(
				array(
					'post_id'       => 42,
					'version_token' => 'ffffffffffffffff:2026-07-20 12:30:00',
					'enabled'       => false,
				)
			);
		} finally {
			self::assertSame( 0, $provider->read_calls );
			self::assertSame( 0, $provider->preview_calls );
		}
	}

	/**
	 * Update returns effective values and audits field names without JSON source.
	 */
	public function test_updates_custom_schema_and_redacts_audit_payload(): void {
		$provider = $this->provider();
		$audit    = $this->audit_spy();
		$result   = ( new UpdateCustomSchema( $this->manager(), $this->repository(), $provider, $audit ) )->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'enabled'       => true,
				'source'        => '{"@type":"FAQPage","name":"secret-marker"}',
			),
			5
		);

		self::assertSame( 1, $provider->write_calls );
		self::assertSame( array( 'enabled', 'source' ), $result->mutation->changed_fields );
		self::assertTrue( $result->effective_custom_schema['validation']['valid'] );
		self::assertSame( 'success', $audit->events[0]->outcome );
		self::assertSame( UpdateCustomSchema::ABILITY, $audit->events[0]->ability );
		self::assertSame( array( 'enabled', 'source' ), $audit->events[0]->changed_fields );
	}

	/**
	 * Builds an access manager with SEO updates enabled.
	 */
	private function manager(): ContentAccessManager {
		$settings = new class() implements ContentAccessSettingsRepository {
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
		$catalog  = new class() implements ContentTypeCatalog {
			/**
			 * Returns one eligible content type.
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
	 * An unreadable target is reported as unavailable content, not as an
	 * incomplete document: a schema write needs the identity fields.
	 */
	public function test_absent_target_identity_is_unavailable(): void {
		$reader = new class() implements SchemaTargetReader {
			/**
			 * Reports every post as absent.
			 *
			 * @param int $post_id Unused post ID.
			 */
			public function read( int $post_id ): ?SchemaTarget {
				return null;
			}
		};

		$this->expectException( ContentUnavailable::class );
		( new GetCustomSchema( $this->manager(), $this->repository(), $this->provider(), $reader ) )->execute( array( 'post_id' => 42 ) );
	}

	/**
	 * Builds a fixed schema-target reader.
	 */
	private function target(): SchemaTargetReader {
		return new class() implements SchemaTargetReader {
			/**
			 * Returns one fixed identity projection.
			 *
			 * @param int $post_id Target post ID.
			 */
			public function read( int $post_id ): ?SchemaTarget {
				return 42 === $post_id
					? new SchemaTarget(
						'Fixture page',
						'fixture-page',
						'https://example.test/fixture-page/',
						'publish',
						'2026-07-01T09:00:00+00:00',
						'2026-07-20T12:30:00+00:00',
						7,
						'https://example.test/wp-content/uploads/hero.jpg',
					)
					: null;
			}
		};
	}

	/**
	 * Builds a fixed mutation repository.
	 */
	private function repository(): ContentMutationRepository {
		$version = VersionToken::from_string( self::TOKEN );

		return new class( $version ) implements ContentMutationRepository {
			/**
			 * Creates the repository.
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
			 * Returns one fixed post-write result.
			 *
			 * @param int $post_id Target post ID.
			 */
			public function result_for( int $post_id ): ?MutationResult {
				return 42 === $post_id
					? new MutationResult( 42, 'post', 'publish', $this->version, array(), false )
					: null;
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
	 * Builds an in-memory provider implementing both ports.
	 *
	 * @return object
	 */
	private function provider(): object {
		return new class() implements CustomSchemaReader, CustomSchemaWriter {
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
			 * Number of write calls.
			 *
			 * @var int
			 */
			public int $write_calls = 0;

			/**
			 * Reports provider availability.
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Returns the saved configuration.
			 *
			 * @param int $post_id Target post ID.
			 * @return array<string, mixed>
			 */
			public function read( int $post_id ): array {
				++$this->read_calls;

				return $this->configuration( true, '{"@type":"Service"}' );
			}

			/**
			 * Returns the prospective configuration.
			 *
			 * @param int                        $post_id Target post ID.
			 * @param array<string, bool|string> $fields  Proposed fields.
			 * @return array<string, mixed>
			 */
			public function preview( int $post_id, array $fields ): array {
				++$this->preview_calls;
				$enabled = isset( $fields['enabled'] ) && is_bool( $fields['enabled'] ) ? $fields['enabled'] : true;
				$source  = isset( $fields['source'] ) && is_string( $fields['source'] ) ? $fields['source'] : '{"@type":"Service"}';

				return $this->configuration( $enabled, $source );
			}

			/**
			 * Records and projects a write.
			 *
			 * @param int                        $post_id Target post ID.
			 * @param array<string, bool|string> $fields  Proposed fields.
			 * @return array<string, mixed>
			 */
			public function write( int $post_id, array $fields ): array {
				++$this->write_calls;

				return $this->preview( $post_id, $fields );
			}

			/**
			 * Builds one complete test configuration.
			 *
			 * @param bool   $enabled Whether rendering is enabled.
			 * @param string $source  JSON source.
			 * @return array<string, mixed>
			 */
			private function configuration( bool $enabled, string $source ): array {
				$valid = null !== json_decode( $source, true );

				return array(
					'contract_version' => '1.0',
					'enabled'          => $enabled,
					'source'           => $source,
					'save_allowed'     => ! $enabled || $valid,
					'render_eligible'  => $enabled && $valid,
					'validation'       => array(
						'valid'            => $valid,
						'context_resolved' => false,
						'nodes'            => $valid ? array( array( '@type' => 'Thing' ) ) : array(),
						'errors'           => $valid ? array() : array(
							array(
								'code'    => 'invalid_json',
								'message' => 'Invalid JSON.',
							),
						),
						'warnings'         => array(),
					),
					'provider'         => array(
						'name'    => 'isudev-schema-extended',
						'version' => '0.3.0',
					),
				);
			}
		};
	}

	/**
	 * Builds an in-memory audit sink.
	 *
	 * @return object
	 */
	private function audit_spy(): object {
		return new class() implements AuditLog {
			/**
			 * Recorded events.
			 *
			 * @var list<AuditEvent>
			 */
			public array $events = array();

			/**
			 * Records one event.
			 *
			 * @param AuditEvent $event Audit event.
			 */
			public function record( AuditEvent $event ): void {
				$this->events[] = $event;
			}
		};
	}
}
