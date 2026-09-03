<?php
/**
 * Shared authorized featured-media projection.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use WP_Post;

/**
 * The single place a featured attachment is projected for output.
 *
 * Two adapters expose featured-media identity (content reads and schema-target
 * reads). They must agree on the authorization check, or one of them would leak
 * an attachment the caller cannot read while the other hides it.
 */
final class FeaturedMediaProjection {

	/**
	 * Returns a safe featured-media projection, or null when absent or unreadable.
	 *
	 * @param WP_Post $post Content object.
	 * @return array{id: int, url: string, alt_text: string}|null
	 */
	public static function for_post( WP_Post $post ): ?array {
		$attachment_id = get_post_thumbnail_id( $post );
		if ( false === $attachment_id || $attachment_id < 1 || ! current_user_can( 'read_post', $attachment_id ) ) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return array(
			'id'       => $attachment_id,
			'url'      => $url,
			'alt_text' => is_string( $alt ) ? $alt : '',
		);
	}
}
