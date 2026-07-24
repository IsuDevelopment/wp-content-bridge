<?php
/**
 * WordPress attachment resolver for SEO social images.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\SeoImageRepository;
use WP_Post;

/**
 * Resolves only existing image attachments readable by the current principal.
 */
final class WordPressSeoImageRepository implements SeoImageRepository {

	/**
	 * Returns the public URL for one readable image attachment.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return string|null
	 */
	public function image_url( int $attachment_id ): ?string {
		$post = get_post( $attachment_id );
		if (
			! $post instanceof WP_Post
			|| 'attachment' !== $post->post_type
			|| ! current_user_can( 'read_post', $attachment_id )
			|| ! wp_attachment_is_image( $attachment_id )
		) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment_id );

		return is_string( $url ) && '' !== $url ? $url : null;
	}
}
