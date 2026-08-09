<?php
/**
 * Unit tests for the UpdateSeo use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use IsuDev\WPContentBridge\Application\Mutation\SeoImageUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\SeoWriter;
use IsuDev\WPContentBridge\Application\Mutation\UpdateSeo;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies the UpdateSeo write flow: policy, version, write, and audit.
 */
final class UpdateSeoTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * A valid write records success and returns the writer's effective SEO.
	 */
	public function test_writes_seo_and_records_success(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateSeo(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$this->writer_available( array( 'schema_version' => '1.1' ) ),
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'seo_title'     => 'New title',
			),
			5
		);

		self::assertSame( array( 'seo_title' ), $result->changed_fields );
		self::assertSame( array( 'schema_version' => '1.1' ), $result->effective_seo );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
	}

	/**
	 * A wire key outside the SEO allowlist is rejected and recorded.
	 */
	public function test_unknown_field_records_unsupported(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateSeo(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$this->writer_available( array() ),
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::TOKEN,
					'schema_type'   => 'Article',
				),
				5
			);
			self::fail( 'Expected SeoFieldUnsupported.' );
		} catch ( SeoFieldUnsupported $unsupported ) {
			self::assertSame( 'wpcb_seo_field_unsupported', $unsupported->error_code() );
			self::assertSame( array( 'schema_type' ), $unsupported->fields() );
		}

		self::assertCount( 1, $audit->events );
		self::assertSame( 'invalid', $audit->events[0]->outcome );
		self::assertSame( 'wpcb_seo_field_unsupported', $audit->events[0]->error_code );
	}

	/**
	 * A denied policy is recorded as denied and never reaches the writer.
	 */
	public function test_policy_denial_records_denied(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateSeo(
			$this->manager_allowing( false ),
			$this->repository( self::TOKEN ),
			$this->writer_available( array() ),
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::TOKEN,
					'seo_title'     => 'T',
				),
				5
			);
			self::fail( 'Expected MutationForbidden.' );
		} catch ( MutationForbidden $forbidden ) {
			self::assertSame( 'wpcb_forbidden', $forbidden->error_code() );
		}

		self::assertSame( 'denied', $audit->events[0]->outcome );
	}

	/**
	 * A stale version token is recorded as conflict without writing.
	 */
	public function test_stale_version_records_conflict_without_writing(): void {
		$audit    = $this->audit_spy();
		$writer   = $this->writer_available( array() );
		$use_case = new UpdateSeo(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$writer,
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => 'ffffffffffffffff:2026-07-20 12:30:00',
					'seo_title'     => 'T',
				),
				5
			);
			self::fail( 'Expected MutationConflict.' );
		} catch ( MutationConflict $conflict ) {
			self::assertSame( 'wpcb_conflict', $conflict->error_code() );
		}

		self::assertFalse( $writer->was_called );
		self::assertSame( 'conflict', $audit->events[0]->outcome );
	}

	/**
	 * An unavailable writer is recorded as unsupported, listing all changed fields.
	 */
	public function test_unavailable_writer_records_unsupported_with_all_fields(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateSeo(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$this->writer_unavailable(),
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::TOKEN,
					'seo_title'     => 'T',
					'canonical'     => 'https://example.com/x',
				),
				5
			);
			self::fail( 'Expected SeoFieldUnsupported.' );
		} catch ( SeoFieldUnsupported $unsupported ) {
			self::assertSame( array( 'seo_title', 'canonical' ), $unsupported->fields() );
		}
	}

	/**
	 * An invalid social image is classified without exposing attachment details.
	 */
	public function test_invalid_social_image_records_non_enumerating_error(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateSeo(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			new class() implements SeoWriter {
				/**
				 * Reports the failing writer as available.
				 *
				 * @return bool
				 */
				public function is_available(): bool {
					return true;
				}

				/**
				 * Simulates attachment resolution failure.
				 *
				 * @param int   $post_id Unused target post ID.
				 * @param array $fields  Unused SEO fields.
				 * @throws SeoImageUnavailable Always.
				 */
				public function write( int $post_id, array $fields ): array {
					throw new SeoImageUnavailable( 'SEO social image is unavailable.' );
				}
			},
			$audit
		);

		$this->expectException( SeoImageUnavailable::class );
		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::TOKEN,
					'og_image_id'   => 999,
				),
				5
			);
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_seo_image_unavailable', $audit->events[0]->error_code );
		}
	}

	// --- fakes -----------------------------------------------------------

	/**
	 * Builds an in-memory audit sink that records every event.
	 *
	 * @return AuditLog
	 */
	private function audit_spy(): AuditLog {
		return new class() implements AuditLog {
			/**
			 * Recorded audit events, in call order.
			 *
			 * @var array<int, AuditEvent>
			 */
			public array $events = array();

			/**
			 * Records an audit event.
			 *
			 * @param AuditEvent $event The event to record.
			 */
			public function record( AuditEvent $event ): void {
				$this->events[] = $event;
			}
		};
	}

	/**
	 * Builds a content access manager whose "post" type allows/denies update_seo.
	 *
	 * @param bool $allow Whether update_seo is allowed for "post".
	 * @return ContentAccessManager
	 */
	private function manager_allowing( bool $allow ): ContentAccessManager {
		$stored = array(
			'post' => array(
				'get_content' => true,
				'update_seo'  => $allow,
			),
		);

		$repository = new class( $stored ) implements ContentAccessSettingsRepository {
			/**
			 * Creates the fake repository.
			 *
			 * @param array $settings Stored access-matrix rows.
			 */
			public function __construct( private array $settings ) {}

			/**
			 * Loads raw stored rows.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return $this->settings;
			}
		};

		$catalog = new class() implements ContentTypeCatalog {
			/**
			 * Lists content types that may be configured.
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
	 * Builds a mutation repository fixed to a "post" and a given version token.
	 *
	 * @param string $current_token Version token the repository reports as current.
	 * @return ContentMutationRepository
	 */
	private function repository( string $current_token ): ContentMutationRepository {
		$version = VersionToken::from_string( $current_token );

		return new class( $version ) implements ContentMutationRepository {
			/**
			 * Creates the fake repository.
			 *
			 * @param VersionToken $version Version token reported as current.
			 */
			public function __construct( private VersionToken $version ) {}

			/**
			 * Always reports the post type as "post".
			 *
			 * @param int $post_id Target post ID.
			 * @return ?string
			 */
			public function post_type( int $post_id ): ?string {
				return 'post';
			}

			/**
			 * Always reports the configured version token.
			 *
			 * @param int $post_id Target post ID.
			 * @return ?VersionToken
			 */
			public function current_version( int $post_id ): ?VersionToken {
				return $this->version;
			}

			/**
			 * Not used by these tests.
			 *
			 * @param DraftInput $input Unused in this fake.
			 * @throws RuntimeException Always; this fake is not exercised by these tests.
			 */
			public function create( DraftInput $input ): MutationResult {
				throw new RuntimeException( 'not used' );
			}

			/**
			 * Not used by these tests.
			 *
			 * @param int           $post_id Unused in this fake.
			 * @param ContentUpdate $update  Unused in this fake.
			 * @throws RuntimeException Always; this fake is not exercised by these tests.
			 */
			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new RuntimeException( 'not used' );
			}

			/**
			 * Builds a fixed post-publish result for the given post ID.
			 *
			 * @param int $post_id Target post ID.
			 * @return ?MutationResult
			 */
			public function result_for( int $post_id ): ?MutationResult {
				return new MutationResult( $post_id, 'post', 'publish', $this->version, array(), false );
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
	 * Builds a writer that is available and returns a fixed effective SEO payload.
	 *
	 * @param array $effective_seo Payload the writer returns from write().
	 * @return object
	 */
	private function writer_available( array $effective_seo ): object {
		return new class( $effective_seo ) implements SeoWriter {
			/**
			 * Whether write() has been invoked.
			 *
			 * @var bool
			 */
			public bool $was_called = false;

			/**
			 * Creates the fake writer.
			 *
			 * @param array $effective_seo Payload write() returns.
			 */
			public function __construct( private array $effective_seo ) {}

			/**
			 * Always reports the writer as available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Records that write() was called and returns the configured payload.
			 *
			 * @param int   $post_id Target post ID.
			 * @param array $fields  Fields to write.
			 * @return array
			 */
			public function write( int $post_id, array $fields ): array {
				$this->was_called = true;

				return $this->effective_seo;
			}
		};
	}

	/**
	 * Builds a writer that reports itself unavailable and must not be called.
	 *
	 * @return SeoWriter
	 */
	private function writer_unavailable(): SeoWriter {
		return new class() implements SeoWriter {
			/**
			 * Always reports the writer as unavailable.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return false;
			}

			/**
			 * Not used by these tests.
			 *
			 * @param int   $post_id Unused in this fake.
			 * @param array $fields  Unused in this fake.
			 * @throws RuntimeException Always; this fake is not exercised by these tests.
			 */
			public function write( int $post_id, array $fields ): array {
				throw new RuntimeException( 'must not be called' );
			}
		};
	}
}
