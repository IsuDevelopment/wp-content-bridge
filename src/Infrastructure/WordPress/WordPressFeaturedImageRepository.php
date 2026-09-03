<?php
/**
 * WordPress-backed featured-image writer.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Media\FeaturedImageRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use WP_Post;

/**
 * The only place `_thumbnail_id` is written, and every write is read back.
 */
final class WordPressFeaturedImageRepository implements FeaturedImageRepository {

	/**
	 * Whether the attachment exists, is an image, and is readable by the caller.
	 *
	 * `set_post_thumbnail()` accepts any attachment ID, including a PDF, a
	 * private upload, or an ID belonging to a post that is not an attachment at
	 * all. Themes then render the result in a public image slot, so this is the
	 * gate WordPress does not provide.
	 *
	 * @param int $attachment_id Candidate attachment ID.
	 * @return bool
	 */
	public function is_assignable_image( int $attachment_id ): bool {
		if ( 0 >= $attachment_id ) {
			return false;
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
			return false;
		}
		if ( ! current_user_can( 'read_post', $attachment_id ) ) {
			return false;
		}

		return wp_attachment_is_image( $attachment_id );
	}

	/**
	 * Assigns the featured image and confirms it by re-reading storage.
	 *
	 * @param int $post_id       Target post ID.
	 * @param int $attachment_id Attachment to assign.
	 * @return void
	 * @throws MutationWriteFailed When WordPress rejects the write or stores something else.
	 */
	public function assign( int $post_id, int $attachment_id ): void {
		$result = set_post_thumbnail( $post_id, $attachment_id );
		if ( false === $result ) {
			throw new MutationWriteFailed( 'WordPress rejected the featured-image assignment.' );
		}

		/*
		 * Confirmed by re-reading rather than trusting the return value: a
		 * filter on `update_post_metadata` can short-circuit the write while
		 * the call still reports success.
		 */
		if ( $attachment_id !== $this->current( $post_id ) ) {
			throw new MutationWriteFailed( 'The featured image was not stored as requested.' );
		}
	}

	/**
	 * Removes the featured image and confirms the removal.
	 *
	 * @param int $post_id Target post ID.
	 * @return void
	 * @throws MutationWriteFailed When one remains after the write.
	 */
	public function remove( int $post_id ): void {
		/*
		 * A removal with nothing assigned returns false from
		 * `delete_post_thumbnail()`, which is indistinguishable from a genuine
		 * failure. The post-condition is what matters, so the return value is
		 * ignored and the absence is asserted instead. This also makes a
		 * retried removal idempotent.
		 */
		delete_post_thumbnail( $post_id );

		if ( null !== $this->current( $post_id ) ) {
			throw new MutationWriteFailed( 'The featured image could not be removed.' );
		}
	}

	/**
	 * Returns the currently assigned attachment ID, or null when absent.
	 *
	 * @param int $post_id Target post ID.
	 * @return int|null
	 */
	public function current( int $post_id ): ?int {
		$thumbnail_id = get_post_thumbnail_id( $post_id );

		return false === $thumbnail_id || 1 > $thumbnail_id ? null : $thumbnail_id;
	}
}
