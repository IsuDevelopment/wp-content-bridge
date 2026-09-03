<?php
/**
 * Attachment-metadata write port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\Media\MediaMetadataUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Reads concurrency state for, and writes descriptive fields of, one attachment.
 */
interface AttachmentMetadataRepository {

	/**
	 * Current version token for an existing attachment, or null when absent or
	 * not an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return VersionToken|null
	 */
	public function current_version( int $attachment_id ): ?VersionToken;

	/**
	 * Applies the present descriptive fields and confirms them by re-reading.
	 *
	 * @param MediaMetadataUpdate $update Validated update.
	 * @return VersionToken
	 * @throws MutationWriteFailed When WordPress rejects the write or stores something else.
	 */
	public function apply( MediaMetadataUpdate $update ): VersionToken;
}
