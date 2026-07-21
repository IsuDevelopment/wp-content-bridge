<?php
/**
 * Get-media-by-ID use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use IsuDev\WPContentBridge\Domain\Media\MediaItem;

/**
 * Retrieves one attachment without turning denial into an enumeration oracle.
 */
final readonly class GetMediaById {

	/**
	 * Creates the use case.
	 *
	 * @param MediaAccessManager $access     Media policy.
	 * @param MediaRepository    $repository Media reader.
	 */
	public function __construct(
		private MediaAccessManager $access,
		private MediaRepository $repository,
	) {
	}

	/**
	 * Reads one authorized attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return MediaItem
	 * @throws MediaUnavailable When disabled, missing, or unreadable.
	 */
	public function execute( int $attachment_id ): MediaItem {
		$this->access->require_read();
		if ( 1 > $attachment_id || ! $this->repository->can_read( $attachment_id ) ) {
			throw new MediaUnavailable( 'Media is unavailable.' );
		}

		$item = $this->repository->get( $attachment_id );
		if ( null === $item ) {
			throw new MediaUnavailable( 'Media is unavailable.' );
		}

		return $item;
	}
}
