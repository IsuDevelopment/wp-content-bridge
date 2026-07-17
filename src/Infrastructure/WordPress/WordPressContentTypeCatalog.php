<?php
/**
 * WordPress content-type catalog.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use WP_Post_Type;

/**
 * Discovers user-facing WordPress content types.
 */
final class WordPressContentTypeCatalog implements ContentTypeCatalog {

	/**
	 * Lists eligible WordPress content types.
	 *
	 * @return list<ContentTypeDefinition>
	 */
	public function list_eligible(): array {
		$definitions = array();
		$post_types  = get_post_types( array(), 'objects' );

		foreach ( $post_types as $post_type ) {
			if ( ! $this->is_eligible( $post_type ) ) {
				continue;
			}

			$singular_label = $post_type->labels->singular_name;
			$label          = is_string( $singular_label ) && '' !== $singular_label
				? $singular_label
				: $post_type->name;

			$definitions[] = new ContentTypeDefinition(
				$post_type->name,
				$label,
				$post_type->public,
				$post_type->show_in_rest,
				$post_type->_builtin,
			);
		}

		usort(
			$definitions,
			static fn ( ContentTypeDefinition $left, ContentTypeDefinition $right ): int => strcasecmp( $left->label, $right->label )
		);

		return $definitions;
	}

	/**
	 * Checks whether a registered type is user-content-like.
	 *
	 * @param WP_Post_Type $post_type Registered content type.
	 * @return bool
	 */
	private function is_eligible( WP_Post_Type $post_type ): bool {
		if ( in_array( $post_type->name, array( 'post', 'page' ), true ) ) {
			return true;
		}

		if ( 'attachment' === $post_type->name || str_starts_with( $post_type->name, 'wp_' ) ) {
			return false;
		}

		return $post_type->show_ui && ( $post_type->public || $post_type->show_in_rest );
	}
}
