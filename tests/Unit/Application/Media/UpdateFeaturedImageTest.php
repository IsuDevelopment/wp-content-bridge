<?php
/**
 * Featured-image write use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Media;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Media\MediaAccessManager;
use IsuDev\WPContentBridge\Application\Media\MediaRepository;
use IsuDev\WPContentBridge\Application\Media\MediaUnavailable;
use IsuDev\WPContentBridge\Application\Media\UpdateFeaturedImage;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Media\MediaItem;
use IsuDev\WPContentBridge\Domain\Media\MediaQuery;
use IsuDev\WPContentBridge\Domain\Media\MediaSearchResult;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use IsuDev\WPContentBridge\Tests\Support\RecordingAuditLog;
use IsuDev\WPContentBridge\Tests\Support\RecordingFeaturedImageRepository;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies policy, concurrency, assignability, audit redaction, and removal.
 */
final class UpdateFeaturedImageTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * A valid assignment writes once and returns the effective attachment.
	 */
	public function test_assigns_and_returns_the_effective_attachment(): void {
		$featured = new RecordingFeaturedImageRepository();
		$audit    = new RecordingAuditLog();

		$result = $this->use_case( $featured, $audit )->execute( $this->input( 7 ), 3 )->to_array();

		self::assertSame( array( 7 ), $featured->assigned );
		self::assertSame( array(), $featured->removed );
		self::assertSame( 7, $result['featured_image']['id'] );
		self::assertSame( array( 'featured_image' ), $result['changed_fields'] );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
	}

	/**
	 * A null attachment removes the image and reports no effective attachment.
	 */
	public function test_null_attachment_removes_the_featured_image(): void {
		$featured = new RecordingFeaturedImageRepository( 7 );

		$result = $this->use_case( $featured )->execute( $this->input( null ), 3 )->to_array();

		self::assertSame( array( 42 ), $featured->removed );
		self::assertSame( array(), $featured->assigned );
		self::assertNull( $result['featured_image'] );
	}

	/**
	 * An attachment that is not an assignable image is refused, and nothing is
	 * written. WordPress itself would accept a PDF or a private upload here.
	 */
	public function test_refuses_an_attachment_that_is_not_an_assignable_image(): void {
		$featured = new RecordingFeaturedImageRepository( null, array( 7 ) );
		$audit    = new RecordingAuditLog();

		try {
			$this->use_case( $featured, $audit )->execute( $this->input( 99 ), 3 );
			self::fail( 'An unassignable attachment must be refused.' );
		} catch ( MutationForbidden $error ) {
			self::assertStringNotContainsString( '99', $error->getMessage(), 'The refusal must not echo the probed ID.' );
		}

		self::assertSame( array(), $featured->assigned );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'denied', $audit->events[0]->outcome );
	}

	/**
	 * A stale token is refused before the attachment is even examined, so a
	 * caller without a current token cannot probe which attachments exist.
	 */
	public function test_stale_token_is_refused_before_the_attachment_is_examined(): void {
		$featured = new RecordingFeaturedImageRepository();

		$input                  = $this->input( 7 );
		$input['version_token'] = 'ffffffffffffffff:2020-01-01 00:00:00';

		$this->expectException( MutationConflict::class );
		try {
			$this->use_case( $featured )->execute( $input, 3 );
		} finally {
			self::assertSame( array(), $featured->assigned );
		}
	}

	/**
	 * The media feature gate applies to this write, because the result
	 * re-reads the attachment through the media read port.
	 */
	public function test_disabled_media_refuses_the_write(): void {
		$featured = new RecordingFeaturedImageRepository();

		$this->expectException( MediaUnavailable::class );
		try {
			$this->use_case( $featured, null, false )->execute( $this->input( 7 ), 3 );
		} finally {
			self::assertSame( array(), $featured->assigned );
		}
	}

	/**
	 * A post type without the featured-image policy is refused even though the
	 * same type permits reads and SEO writes.
	 */
	public function test_policy_denies_a_type_without_the_featured_image_operation(): void {
		$featured = new RecordingFeaturedImageRepository();

		$this->expectException( MutationForbidden::class );
		try {
			$this->use_case( $featured, null, true, false )->execute( $this->input( 7 ), 3 );
		} finally {
			self::assertSame( array(), $featured->assigned );
		}
	}

	/**
	 * An absent post is unavailable, and the audit row still exists.
	 */
	public function test_absent_post_is_unavailable(): void {
		$audit = new RecordingAuditLog();
		$input = $this->input( 7 );

		$input['post_id'] = 4242;

		$this->expectException( ContentUnavailable::class );
		try {
			$this->use_case( new RecordingFeaturedImageRepository(), $audit )->execute( $input, 3 );
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
		}
	}

	/**
	 * An omitted attachment_id is rejected rather than read as a removal.
	 */
	public function test_omitted_attachment_id_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->use_case( new RecordingFeaturedImageRepository() )->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
			),
			3
		);
	}

	/**
	 * The audit row records the field name only, never the attachment ID.
	 */
	public function test_audit_records_field_names_only(): void {
		$audit = new RecordingAuditLog();

		$this->use_case( new RecordingFeaturedImageRepository(), $audit )->execute( $this->input( 7 ), 3 );

		self::assertSame( array( 'featured_image' ), $audit->events[0]->changed_fields );
		self::assertStringNotContainsString( '7', implode( '|', $audit->events[0]->changed_fields ) );
	}

	/**
	 * Builds valid input for the fixture post.
	 *
	 * @param int|null $attachment_id Attachment to assign, or null to remove.
	 * @return array<string, mixed>
	 */
	private function input( ?int $attachment_id ): array {
		return array(
			'post_id'       => 42,
			'version_token' => self::TOKEN,
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * Builds the use case over in-memory doubles.
	 *
	 * @param RecordingFeaturedImageRepository $featured       Featured-image store.
	 * @param RecordingAuditLog|null           $audit          Audit sink.
	 * @param bool                             $media_enabled  Whether media reads are on.
	 * @param bool                             $policy_allows  Whether the type permits featured-image writes.
	 * @return UpdateFeaturedImage
	 */
	private function use_case(
		RecordingFeaturedImageRepository $featured,
		?RecordingAuditLog $audit = null,
		bool $media_enabled = true,
		bool $policy_allows = true,
	): UpdateFeaturedImage {
		return new UpdateFeaturedImage(
			$this->manager( $policy_allows ),
			new MediaAccessManager( $media_enabled ),
			$this->repository(),
			$featured,
			$this->reader(),
			$audit ?? new RecordingAuditLog()
		);
	}

	/**
	 * Builds a content-access manager for the fixture type.
	 *
	 * @param bool $policy_allows Whether featured-image writes are permitted.
	 * @return ContentAccessManager
	 */
	private function manager( bool $policy_allows ): ContentAccessManager {
		$settings = new class( $policy_allows ) implements ContentAccessSettingsRepository {
			/**
			 * Creates the settings double.
			 *
			 * @param bool $policy_allows Whether featured-image writes are permitted.
			 */
			public function __construct( private bool $policy_allows ) {}

			/**
			 * Returns one configured row.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return array(
					'post' => array(
						'get_content'           => true,
						'update_seo'            => true,
						'update_featured_image' => $this->policy_allows,
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
	 * Builds a fixed mutation repository for post 42.
	 *
	 * @return ContentMutationRepository
	 */
	private function repository(): ContentMutationRepository {
		$version = VersionToken::from_string( self::TOKEN );

		return new class( $version ) implements ContentMutationRepository {
			/**
			 * Creates the repository double.
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
			 * Not used by these tests.
			 *
			 * @param DraftInput $input Unused draft input.
			 * @throws LogicException Always.
			 */
			public function create( DraftInput $input ): MutationResult {
				throw new LogicException( 'not used' );
			}

			/**
			 * Not used by these tests.
			 *
			 * @param int           $post_id Unused post ID.
			 * @param ContentUpdate $update  Unused update.
			 * @throws LogicException Always.
			 */
			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new LogicException( 'not used' );
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
			 * @param string                                 $target_status Unused status.
			 * @param array{local: string, utc: string}|null $scheduled_at  Unused.
			 * @throws LogicException Always.
			 */
			public function transition_status( int $post_id, string $target_status, ?array $scheduled_at ): MutationResult {
				throw new LogicException( 'not used' );
			}
		};
	}

	/**
	 * Builds a media reader that knows one attachment.
	 *
	 * @return MediaRepository
	 */
	private function reader(): MediaRepository {
		return new class() implements MediaRepository {
			/**
			 * Not used by these tests.
			 *
			 * @param MediaQuery $query Unused query.
			 * @throws LogicException Always.
			 */
			public function search( MediaQuery $query ): MediaSearchResult {
				throw new LogicException( 'not used' );
			}

			/**
			 * Reports the known attachment as readable.
			 *
			 * @param int $attachment_id Attachment ID.
			 */
			public function can_read( int $attachment_id ): bool {
				return 7 === $attachment_id;
			}

			/**
			 * Returns the known attachment.
			 *
			 * @param int $attachment_id Attachment ID.
			 */
			public function get( int $attachment_id ): ?MediaItem {
				return 7 === $attachment_id
					? new MediaItem( 7, 'Hero', 'hero.jpg', 'https://example.test/hero.jpg', 'A hero', '', '', 'image/jpeg' )
					: null;
			}
		};
	}
}
