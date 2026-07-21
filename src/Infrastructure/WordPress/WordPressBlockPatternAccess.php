<?php
/**
 * WordPress-native block-pattern authorization.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Pattern\BlockPatternAccess;

/**
 * Mirrors the native editor-level gate used by the core pattern controller.
 */
final class WordPressBlockPatternAccess implements BlockPatternAccess {

	/**
	 * Checks generic or REST-visible post-type editor capability.
	 *
	 * @return bool
	 */
	public function can_read(): bool {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			if (
				is_string( $post_type->cap->edit_posts )
				&& current_user_can( $post_type->cap->edit_posts )
			) {
				return true;
			}
		}

		return false;
	}
}
