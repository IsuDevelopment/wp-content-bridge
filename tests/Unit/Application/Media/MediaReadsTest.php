<?php
/**
 * Media read use-case tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Media;

use IsuDev\WPContentBridge\Application\Media\AttachmentMetadataRepository;
use IsuDev\WPContentBridge\Application\Media\GetMediaById;
use IsuDev\WPContentBridge\Application\Media\MediaAccessManager;
use IsuDev\WPContentBridge\Application\Media\MediaRepository;
use IsuDev\WPContentBridge\Application\Media\MediaUnavailable;
use IsuDev\WPContentBridge\Application\Media\SearchMedia;
use IsuDev\WPContentBridge\Domain\Media\MediaItem;
use IsuDev\WPContentBridge\Domain\Media\MediaQuery;
use IsuDev\WPContentBridge\Domain\Media\MediaSearchResult;
use IsuDev\WPContentBridge\Domain\Media\MediaMetadataUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies policy and native-object ordering.
 */
final class MediaReadsTest extends TestCase {

	public const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * Disabled policy prevents repository execution.
	 */
	public function test_disabled_policy_blocks_search_before_repository_access(): void {
		$repository = $this->repository( true );
		$service    = new SearchMedia( new MediaAccessManager( false ), $repository );

		try {
			$service->execute( MediaQuery::from_input( array() ) );
			self::fail( 'Disabled media policy was bypassed.' );
		} catch ( MediaUnavailable ) {
			self::assertSame( 0, $repository->search_calls );
		}
	}

	/**
	 * Native attachment denial prevents object retrieval.
	 */
	public function test_get_requires_native_attachment_access(): void {
		$repository = $this->repository( false );
		$service    = new GetMediaById( new MediaAccessManager( true ), $repository, $this->versions() );

		try {
			$service->execute( 7 );
			self::fail( 'Unreadable attachment was returned.' );
		} catch ( MediaUnavailable ) {
			self::assertSame( 0, $repository->get_calls );
		}
	}

	/**
	 * Authorized media returns the normalized item.
	 */
	public function test_get_returns_normalized_authorized_item(): void {
		$service = new GetMediaById( new MediaAccessManager( true ), $this->repository( true ), $this->versions() );
		$result  = $service->execute( 7 );

		self::assertSame( 7, $result->media->id );
		self::assertSame( self::TOKEN, $result->version->to_string(), 'The read must issue a token update-media can submit.' );
	}

	/**
	 * Builds a fixed attachment version source.
	 *
	 * @return AttachmentMetadataRepository
	 */
	private function versions(): AttachmentMetadataRepository {
		return new class() implements AttachmentMetadataRepository {
			/**
			 * Returns one fixed token.
			 *
			 * @param int $attachment_id Attachment ID.
			 */
			public function current_version( int $attachment_id ): ?VersionToken {
				return 7 === $attachment_id ? VersionToken::from_string( MediaReadsTest::TOKEN ) : null;
			}

			/**
			 * Not used by these tests.
			 *
			 * @param MediaMetadataUpdate $update Unused update.
			 * @throws LogicException Always.
			 */
			public function apply( MediaMetadataUpdate $update ): VersionToken {
				throw new LogicException( 'not used' );
			}
		};
	}

	/**
	 * Creates a recording media repository.
	 *
	 * @param bool $readable Native-read outcome.
	 * @return MediaRepository&object{search_calls: int, get_calls: int}
	 */
	private function repository( bool $readable ): MediaRepository {
		return new class( $readable ) implements MediaRepository {

			/**
			 * Search invocation count.
			 *
			 * @var int
			 */
			public int $search_calls = 0;

			/**
			 * Detail invocation count.
			 *
			 * @var int
			 */
			public int $get_calls = 0;

			/**
			 * Creates the recording repository.
			 *
			 * @param bool $readable Native-read outcome.
			 */
			public function __construct( private bool $readable ) {
			}

			/**
			 * Records a search.
			 *
			 * @param MediaQuery $query Search query.
			 * @return MediaSearchResult
			 */
			public function search( MediaQuery $query ): MediaSearchResult {
				++$this->search_calls;

				return new MediaSearchResult( array(), $query->page, $query->per_page, 0, 0, true, false, 1000 );
			}

			/**
			 * Returns the configured native-read decision.
			 *
			 * @param int $attachment_id Attachment ID.
			 * @return bool
			 */
			public function can_read( int $attachment_id ): bool {
				unset( $attachment_id );

				return $this->readable;
			}

			/**
			 * Reads the fixture attachment.
			 *
			 * @param int $attachment_id Attachment ID.
			 * @return MediaItem|null
			 */
			public function get( int $attachment_id ): ?MediaItem {
				++$this->get_calls;

				return 7 === $attachment_id
					? new MediaItem( 7, 'Hero', 'hero.jpg', 'https://example.com/hero.jpg', '', '', '', 'image/jpeg' )
					: null;
			}
		};
	}
}
