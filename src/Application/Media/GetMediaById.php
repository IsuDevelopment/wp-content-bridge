<?php
/**
 * Get-media-by-ID use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use IsuDev\WPContentBridge\Domain\Media\MediaReadResult;

/**
 * Retrieves one attachment without turning denial into an enumeration oracle.
 */
final readonly class GetMediaById {

	/**
	 * Creates the use case.
	 *
	 * @param MediaAccessManager           $access     Media policy.
	 * @param MediaRepository              $repository Media reader.
	 * @param AttachmentMetadataRepository $versions   Concurrency-token source.
	 */
	public function __construct(
		private MediaAccessManager $access,
		private MediaRepository $repository,
		private AttachmentMetadataRepository $versions,
	) {
	}

	/**
	 * Reads one authorized attachment.
	 *
	 * The result carries a version token so `update-media` has something to
	 * submit. Without it the write contract would be unreachable: there would be
	 * no read that hands out a current token for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return MediaReadResult
	 * @throws MediaUnavailable When disabled, missing, or unreadable.
	 */
	public function execute( int $attachment_id ): MediaReadResult {
		$this->access->require_read();
		if ( 1 > $attachment_id || ! $this->repository->can_read( $attachment_id ) ) {
			throw new MediaUnavailable( 'Media is unavailable.' );
		}

		$item    = $this->repository->get( $attachment_id );
		$version = $this->versions->current_version( $attachment_id );
		if ( null === $item || null === $version ) {
			throw new MediaUnavailable( 'Media is unavailable.' );
		}

		return new MediaReadResult( $item, $version );
	}
}
