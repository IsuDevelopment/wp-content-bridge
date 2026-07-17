<?php
/**
 * WordPress taxonomy discovery adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Content\TaxonomyCatalog;

/**
 * Restricts filters to public/REST taxonomies assigned to all effective types.
 */
final class WordPressTaxonomyCatalog implements TaxonomyCatalog {

	/**
	 * Checks taxonomy eligibility and object-type assignments.
	 *
	 * @param string $taxonomy  Taxonomy name.
	 * @param array  $post_types Effective post types.
	 * @return bool
	 * @phpstan-param non-empty-list<string> $post_types
	 */
	public function supports( string $taxonomy, array $post_types ): bool {
		$definition = get_taxonomy( $taxonomy );
		if ( false === $definition || ( ! $definition->public && ! $definition->show_in_rest ) ) {
			return false;
		}

		foreach ( $post_types as $post_type ) {
			if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				return false;
			}
		}

		return true;
	}
}
