<?php
/**
 * Unit tests for the UpdateServiceSchema use case.
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
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\ServiceSchemaUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\ServiceSchemaWriter;
use IsuDev\WPContentBridge\Application\Mutation\UpdateServiceSchema;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies policy, version, provider, write, re-read, and audit ordering.
 */
final class UpdateServiceSchemaTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * A valid request writes the fixed fields and returns effective configuration.
	 */
	public function test_writes_service_schema_and_records_success(): void {
		$audit  = $this->audit_spy();
		$writer = $this->writer( true, true );
		$result = ( new UpdateServiceSchema(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$writer,
			$audit
		) )->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'enabled'       => true,
				'areas'         => array(
					array(
						'type' => 'City',
						'name' => 'Wrocław',
					),
				),
				'catalog_name'  => 'Usługi monitoringu',
				'offers'        => array( array( 'name' => 'Montaż kamer' ) ),
			),
			5
		);

		self::assertTrue( $writer->was_called );
		self::assertSame( array( 'enabled', 'areas', 'catalog_name', 'offers' ), $result->mutation->changed_fields );
		self::assertSame( '1.0', $result->effective_service_schema['schema_version'] );
		self::assertCount( 1, $audit->events );
		self::assertSame( UpdateServiceSchema::ABILITY, $audit->events[0]->ability );
		self::assertSame( 'success', $audit->events[0]->outcome );
	}

	/**
	 * The per-type SEO policy is enforced before the writer is called.
	 */
	public function test_policy_denial_is_audited_without_writing(): void {
		$audit  = $this->audit_spy();
		$writer = $this->writer( true, true );
		$use    = new UpdateServiceSchema( $this->manager_allowing( false ), $this->repository( self::TOKEN ), $writer, $audit );

		$this->expectException( MutationForbidden::class );
		try {
			$use->execute( $this->minimal_input(), 5 );
		} finally {
			self::assertFalse( $writer->was_called );
			self::assertSame( 'denied', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_forbidden', $audit->events[0]->error_code );
		}
	}

	/**
	 * A stale content token blocks the metadata write.
	 */
	public function test_stale_version_is_audited_as_conflict(): void {
		$audit                  = $this->audit_spy();
		$writer                 = $this->writer( true, true );
		$use                    = new UpdateServiceSchema( $this->manager_allowing( true ), $this->repository( self::TOKEN ), $writer, $audit );
		$input                  = $this->minimal_input();
		$input['version_token'] = 'ffffffffffffffff:2026-07-20 12:30:00';

		$this->expectException( MutationConflict::class );
		try {
			$use->execute( $input, 5 );
		} finally {
			self::assertFalse( $writer->was_called );
			self::assertSame( 'conflict', $audit->events[0]->outcome );
		}
	}

	/**
	 * Provider deactivation between registration and execution fails closed.
	 */
	public function test_unavailable_provider_fails_closed(): void {
		$audit = $this->audit_spy();
		$use   = new UpdateServiceSchema(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$this->writer( false, true ),
			$audit
		);

		$this->expectException( ServiceSchemaUnavailable::class );
		try {
			$use->execute( $this->minimal_input(), 5 );
		} finally {
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_service_schema_unavailable', $audit->events[0]->error_code );
		}
	}

	/**
	 * A loaded provider may still reject post types outside its configured set.
	 */
	public function test_unsupported_post_type_fails_closed(): void {
		$audit = $this->audit_spy();
		$use   = new UpdateServiceSchema(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$this->writer( true, false ),
			$audit
		);

		$this->expectException( ServiceSchemaUnavailable::class );
		$use->execute( $this->minimal_input(), 5 );
	}

	/**
	 * Returns a minimal valid update input.
	 *
	 * @return array<string, mixed>
	 */
	private function minimal_input(): array {
		return array(
			'post_id'       => 42,
			'version_token' => self::TOKEN,
			'enabled'       => true,
		);
	}

	/**
	 * Builds an in-memory audit sink.
	 *
	 * @return AuditLog
	 */
	private function audit_spy(): AuditLog {
		return new class() implements AuditLog {
			/**
			 * Recorded events.
			 *
			 * @var list<AuditEvent>
			 */
			public array $events = array();

			/**
			 * Records an event.
			 *
			 * @param AuditEvent $event Audit event.
			 */
			public function record( AuditEvent $event ): void {
				$this->events[] = $event;
			}
		};
	}

	/**
	 * Builds an access manager with a fixed update_seo policy.
	 *
	 * @param bool $allow Whether the operation is allowed.
	 */
	private function manager_allowing( bool $allow ): ContentAccessManager {
		$settings   = array(
			'post' => array(
				'get_content' => true,
				'update_seo'  => $allow,
			),
		);
		$repository = new class( $settings ) implements ContentAccessSettingsRepository {
			/**
			 * Creates the repository.
			 *
			 * @param array<string, array<string, mixed>> $settings Stored settings.
			 */
			public function __construct( private array $settings ) {}

			/**
			 * Loads settings.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return $this->settings;
			}
		};
		$catalog    = new class() implements ContentTypeCatalog {
			/**
			 * Lists eligible content types.
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
	 * Builds a fixed post repository.
	 *
	 * @param string $token Current version token.
	 */
	private function repository( string $token ): ContentMutationRepository {
		$version = VersionToken::from_string( $token );

		return new class( $version ) implements ContentMutationRepository {
			/**
			 * Creates the repository.
			 *
			 * @param VersionToken $version Current version.
			 */
			public function __construct( private VersionToken $version ) {}

			/**
			 * Returns the fixed post type.
			 *
			 * @param int $post_id Unused post ID.
			 */
			public function post_type( int $post_id ): ?string {
				return 'post';
			}

			/**
			 * Returns the fixed version.
			 *
			 * @param int $post_id Unused post ID.
			 */
			public function current_version( int $post_id ): ?VersionToken {
				return $this->version;
			}

			/**
			 * Rejects unused creates.
			 *
			 * @param DraftInput $input Unused input.
			 * @throws RuntimeException Always.
			 */
			public function create( DraftInput $input ): MutationResult {
				throw new RuntimeException( 'not used' );
			}

			/**
			 * Rejects unused content updates.
			 *
			 * @param int           $post_id Unused post ID.
			 * @param ContentUpdate $update  Unused update.
			 * @throws RuntimeException Always.
			 */
			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new RuntimeException( 'not used' );
			}

			/**
			 * Returns a fixed mutation result.
			 *
			 * @param int $post_id Target post ID.
			 */
			public function result_for( int $post_id ): ?MutationResult {
				return new MutationResult( $post_id, 'post', 'publish', $this->version, array(), false );
			}
		};
	}

	/**
	 * Builds a controllable Service schema writer.
	 *
	 * @param bool $available Whether the optional provider is loaded.
	 * @param bool $supports  Whether it supports the target post type.
	 * @return object
	 */
	private function writer( bool $available, bool $supports ): object {
		return new class( $available, $supports ) implements ServiceSchemaWriter {
			/**
			 * Whether write was called.
			 *
			 * @var bool
			 */
			public bool $was_called = false;

			/**
			 * Creates the writer.
			 *
			 * @param bool $available Provider availability.
			 * @param bool $supports  Post-type support.
			 */
			public function __construct(
				private bool $available,
				private bool $supports,
			) {}

			/**
			 * Reports provider availability.
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Reports post-type support.
			 *
			 * @param string $post_type Unused post type.
			 */
			public function supports_post_type( string $post_type ): bool {
				return $this->supports;
			}

			/**
			 * Records a write and returns a fixed document.
			 *
			 * @param int                  $post_id Unused post ID.
			 * @param array<string, mixed> $fields  Unused fields.
			 * @return array<string, mixed>
			 */
			public function write( int $post_id, array $fields ): array {
				$this->was_called = true;

				return array(
					'schema_version' => '1.0',
					'enabled'        => true,
				);
			}
		};
	}
}
