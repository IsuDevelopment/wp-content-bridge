<?php
/**
 * Media import use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Media;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Media\CreateMedia;
use IsuDev\WPContentBridge\Application\Media\MediaAccessManager;
use IsuDev\WPContentBridge\Application\Media\MediaRepository;
use IsuDev\WPContentBridge\Application\Media\MediaUnavailable;
use IsuDev\WPContentBridge\Application\Media\MediaUploadFailed;
use IsuDev\WPContentBridge\Application\Media\MediaUploader;
use IsuDev\WPContentBridge\Application\Mutation\IdempotencyStore;
use IsuDev\WPContentBridge\Domain\Media\MediaItem;
use IsuDev\WPContentBridge\Domain\Media\MediaQuery;
use IsuDev\WPContentBridge\Domain\Media\MediaSearchResult;
use IsuDev\WPContentBridge\Domain\Media\MediaUploadRequest;
use IsuDev\WPContentBridge\Tests\Support\RecordingAuditLog;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies input bounds, replay, audit redaction, and the media gate.
 */
final class CreateMediaTest extends TestCase {

	private const KEY = 'retry-key-0001';

	/**
	 * A first import fetches once and reports created.
	 */
	public function test_imports_once_and_reports_created(): void {
		$uploader = $this->uploader();
		$audit    = new RecordingAuditLog();

		$result = $this->use_case( $uploader, $audit )->execute( $this->input(), 3 )->to_array();

		self::assertSame( 1, $uploader->calls );
		self::assertTrue( $result['created'] );
		self::assertSame( 55, $result['media']['id'] );
		self::assertSame( 'success', $audit->events[0]->outcome );
	}

	/**
	 * A repeat with the same key returns the first attachment and performs no
	 * second fetch. Without this a timed-out transport turns each retry into a
	 * duplicate upload nobody sees until they open the media library.
	 */
	public function test_replays_the_same_key_without_fetching_again(): void {
		$uploader = $this->uploader();
		$store    = $this->store();
		$use_case = $this->use_case( $uploader, null, true, $store );

		$first  = $use_case->execute( $this->input(), 3 )->to_array();
		$second = $use_case->execute( $this->input(), 3 )->to_array();

		self::assertSame( 1, $uploader->calls, 'A replay must not fetch again.' );
		self::assertTrue( $first['created'] );
		self::assertFalse( $second['created'] );
		self::assertSame( $first['media']['id'], $second['media']['id'] );
	}

	/**
	 * The same key held by a different principal is a different key, so one
	 * caller cannot learn what another imported.
	 */
	public function test_the_key_is_scoped_to_the_principal(): void {
		$uploader = $this->uploader();
		$store    = $this->store();
		$use_case = $this->use_case( $uploader, null, true, $store );

		$use_case->execute( $this->input(), 3 );
		$other = $use_case->execute( $this->input(), 9 )->to_array();

		self::assertSame( 2, $uploader->calls );
		self::assertTrue( $other['created'] );
	}

	/**
	 * A missing key is rejected: replay safety is not optional here.
	 */
	public function test_a_missing_idempotency_key_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->use_case( $this->uploader() )->execute( array( 'source_url' => 'https://example.test/a.jpg' ), 3 );
	}

	/**
	 * A short key is rejected, so a caller cannot collide on a one-character key.
	 */
	public function test_a_too_short_key_is_rejected(): void {
		$input                    = $this->input();
		$input['idempotency_key'] = 'x';

		$this->expectException( InvalidArgumentException::class );
		$this->use_case( $this->uploader() )->execute( $input, 3 );
	}

	/**
	 * An unknown field is rejected rather than silently ignored.
	 */
	public function test_an_unsupported_field_is_rejected(): void {
		$input         = $this->input();
		$input['mime'] = 'image/svg+xml';

		$this->expectException( InvalidArgumentException::class );
		$this->use_case( $this->uploader() )->execute( $input, 3 );
	}

	/**
	 * The media feature gate applies, and the fetch never happens.
	 */
	public function test_disabled_media_refuses_before_fetching(): void {
		$uploader = $this->uploader();

		$this->expectException( MediaUnavailable::class );
		try {
			$this->use_case( $uploader, null, false )->execute( $this->input(), 3 );
		} finally {
			self::assertSame( 0, $uploader->calls );
		}
	}

	/**
	 * A refused upload is audited and rethrown, with no attachment recorded.
	 */
	public function test_a_refused_upload_is_audited(): void {
		$uploader = $this->uploader( true );
		$audit    = new RecordingAuditLog();

		$this->expectException( MediaUploadFailed::class );
		try {
			$this->use_case( $uploader, $audit )->execute( $this->input(), 3 );
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_media_upload_failed', $audit->events[0]->error_code );
			self::assertNull( $audit->events[0]->object_id );
		}
	}

	/**
	 * The audit row never records the source URL: it is caller-supplied text
	 * naming an external address, and the audit contract is field names only.
	 */
	public function test_audit_never_records_the_source_url(): void {
		$audit = new RecordingAuditLog();

		$this->use_case( $this->uploader(), $audit )->execute( $this->input(), 3 );

		$event = $audit->events[0];
		self::assertSame( array( 'source_url', 'title', 'alt_text' ), $event->changed_fields );
		self::assertStringNotContainsString( 'example.test', implode( '|', $event->changed_fields ) );
		self::assertNull( $event->expected_version );
	}

	/**
	 * Builds valid input.
	 *
	 * @return array<string, mixed>
	 */
	private function input(): array {
		return array(
			'source_url'      => 'https://example.test/hero.jpg',
			'idempotency_key' => self::KEY,
			'title'           => 'Hero',
			'alt_text'        => 'A hero image',
		);
	}

	/**
	 * Builds the use case over in-memory doubles.
	 *
	 * @param object                 $uploader      Recording uploader double.
	 * @param RecordingAuditLog|null $audit         Audit sink.
	 * @param bool                   $media_enabled Whether media reads are on.
	 * @param IdempotencyStore|null  $store         Shared idempotency store.
	 * @return CreateMedia
	 */
	private function use_case(
		object $uploader,
		?RecordingAuditLog $audit = null,
		bool $media_enabled = true,
		?IdempotencyStore $store = null,
	): CreateMedia {
		self::assertInstanceOf( MediaUploader::class, $uploader );

		return new CreateMedia(
			new MediaAccessManager( $media_enabled ),
			$uploader,
			$this->reader(),
			$store ?? $this->store(),
			$audit ?? new RecordingAuditLog()
		);
	}

	/**
	 * Builds a counting uploader.
	 *
	 * @param bool $refuse Whether every upload is refused.
	 * @return object
	 */
	private function uploader( bool $refuse = false ): object {
		return new class( $refuse ) implements MediaUploader {
			/**
			 * Number of upload attempts.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Creates the double.
			 *
			 * @param bool $refuse Whether every upload is refused.
			 */
			public function __construct( private bool $refuse ) {}

			/**
			 * Records the attempt and returns a fixed attachment.
			 *
			 * @param MediaUploadRequest $request Validated request.
			 * @return MediaItem
			 * @throws MediaUploadFailed When configured to refuse.
			 */
			public function upload( MediaUploadRequest $request ): MediaItem {
				++$this->calls;
				if ( $this->refuse ) {
					throw new MediaUploadFailed( 'configured refusal' );
				}

				return new MediaItem( 55, 'Hero', 'hero.jpg', 'https://example.test/uploads/hero.jpg', 'A hero image', '', '', 'image/jpeg' );
			}
		};
	}

	/**
	 * Builds an in-memory idempotency store.
	 *
	 * @return IdempotencyStore
	 */
	private function store(): IdempotencyStore {
		return new class() implements IdempotencyStore {
			/**
			 * Remembered keys.
			 *
			 * @var array<string, int>
			 */
			private array $entries = array();

			/**
			 * Finds a remembered post ID.
			 *
			 * @param int    $user_id Acting principal.
			 * @param string $key     Idempotency key.
			 * @return int|null
			 */
			public function find( int $user_id, string $key ): ?int {
				return $this->entries[ $user_id . '|' . $key ] ?? null;
			}

			/**
			 * Remembers a created post ID.
			 *
			 * @param int    $user_id Acting principal.
			 * @param string $key     Idempotency key.
			 * @param int    $post_id Created post ID.
			 * @return void
			 */
			public function remember( int $user_id, string $key, int $post_id ): void {
				$this->entries[ $user_id . '|' . $key ] = $post_id;
			}
		};
	}

	/**
	 * Builds a media reader that knows the imported attachment.
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
				return 55 === $attachment_id;
			}

			/**
			 * Returns the known attachment.
			 *
			 * @param int $attachment_id Attachment ID.
			 */
			public function get( int $attachment_id ): ?MediaItem {
				return 55 === $attachment_id
					? new MediaItem( 55, 'Hero', 'hero.jpg', 'https://example.test/uploads/hero.jpg', 'A hero image', '', '', 'image/jpeg' )
					: null;
			}
		};
	}
}
