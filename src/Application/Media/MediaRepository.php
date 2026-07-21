<?php
/**
 * Media read port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use IsuDev\WPContentBridge\Domain\Media\MediaItem;
use IsuDev\WPContentBridge\Domain\Media\MediaQuery;
use IsuDev\WPContentBridge\Domain\Media\MediaSearchResult;

/**
 * Reads attachments through an infrastructure adapter.
 */
interface MediaRepository {

	/**
	 * Searches authorized attachments.
	 *
	 * @param MediaQuery $query Search criteria.
	 * @return MediaSearchResult
	 */
	public function search( MediaQuery $query ): MediaSearchResult;

	/**
	 * Checks native access to one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function can_read( int $attachment_id ): bool;

	/**
	 * Reads one normalized attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return MediaItem|null
	 */
	public function get( int $attachment_id ): ?MediaItem;
}
