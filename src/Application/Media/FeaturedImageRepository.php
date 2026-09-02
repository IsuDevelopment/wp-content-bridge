<?php
/**
 * Featured-image write port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;

/**
 * Assigns and removes one post's featured image.
 */
interface FeaturedImageRepository {

	/**
	 * Whether the attachment exists, is an image, and is readable by the caller.
	 *
	 * An unreadable or non-image attachment must be refused rather than
	 * assigned: WordPress itself accepts any attachment ID as a thumbnail, so
	 * this is the only place a PDF or a private upload is kept out of a
	 * public featured-image slot.
	 *
	 * @param int $attachment_id Candidate attachment ID.
	 * @return bool
	 */
	public function is_assignable_image( int $attachment_id ): bool;

	/**
	 * Assigns the featured image and confirms it by re-reading storage.
	 *
	 * @param int $post_id       Target post ID.
	 * @param int $attachment_id Attachment to assign.
	 * @return void
	 * @throws MutationWriteFailed When WordPress rejects the write or stores something else.
	 */
	public function assign( int $post_id, int $attachment_id ): void;

	/**
	 * Removes the featured image and confirms the removal.
	 *
	 * Removing an image that is already absent is a success, so a retried
	 * removal is idempotent rather than an error.
	 *
	 * @param int $post_id Target post ID.
	 * @return void
	 * @throws MutationWriteFailed When WordPress rejects the write or one remains.
	 */
	public function remove( int $post_id ): void;

	/**
	 * Returns the currently assigned attachment ID, or null when absent.
	 *
	 * @param int $post_id Target post ID.
	 * @return int|null
	 */
	public function current( int $post_id ): ?int;
}
