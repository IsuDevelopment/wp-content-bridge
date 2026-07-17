<?php
/**
 * WordPress editorial-context metadata adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Editorial\EditorialContextRepository;
use IsuDev\WPContentBridge\Application\Editorial\GetEditorialContext;

/**
 * Reads public vocabulary and bounded public author labels through Core APIs.
 */
final class WordPressEditorialContextRepository implements EditorialContextRepository {

	/**
	 * Lists eligible taxonomies with optional bounded terms.
	 *
	 * @param array $post_types      Effective content types.
	 * @param array $requested       Optional taxonomy filter.
	 * @param bool  $include_terms   Whether terms are needed.
	 * @param int   $terms_per_taxonomy Maximum terms per taxonomy.
	 * @return list<array<string, mixed>>
	 * @phpstan-param non-empty-list<string> $post_types
	 * @phpstan-param list<string> $requested
	 */
	public function taxonomies( array $post_types, array $requested, bool $include_terms, int $terms_per_taxonomy ): array {
		$rows = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public && ! $taxonomy->show_in_rest ) {
				continue;
			}
			if ( array() !== $requested && ! in_array( $taxonomy->name, $requested, true ) ) {
				continue;
			}
			$object_types = array_values( array_intersect( $post_types, $taxonomy->object_type ) );
			if ( array() === $object_types ) {
				continue;
			}

			$terms     = array();
			$truncated = false;
			if ( $include_terms ) {
				$found = get_terms(
					array(
						'taxonomy'   => $taxonomy->name,
						'hide_empty' => false,
						'number'     => $terms_per_taxonomy + 1,
						'orderby'    => 'name',
						'order'      => 'ASC',
					)
				);
				if ( ! is_wp_error( $found ) ) {
					$truncated = count( $found ) > $terms_per_taxonomy;
					foreach ( array_slice( $found, 0, $terms_per_taxonomy ) as $term ) {
						$terms[] = array(
							'id'     => $term->term_id,
							'name'   => $term->name,
							'slug'   => $term->slug,
							'parent' => $term->parent,
						);
					}
				}
			}

			$rows[] = array(
				'name'            => $taxonomy->name,
				'label'           => $taxonomy->label,
				'hierarchical'    => $taxonomy->hierarchical,
				'object_types'    => $object_types,
				'terms'           => $terms,
				'terms_truncated' => $truncated,
			);
		}

		usort( $rows, static fn ( array $left, array $right ): int => strcasecmp( (string) $left['label'], (string) $right['label'] ) );

		return array_slice( $rows, 0, GetEditorialContext::MAX_TAXONOMIES );
	}

	/**
	 * Resolves display labels for already observed authors.
	 *
	 * @param array $author_ids Observed author IDs.
	 * @return list<array{id: int, display_name: string}>
	 * @phpstan-param list<int> $author_ids
	 */
	public function authors( array $author_ids ): array {
		$authors = array();
		foreach ( array_slice( array_values( array_unique( $author_ids ) ), 0, 50 ) as $author_id ) {
			$user = get_userdata( $author_id );
			if ( false !== $user ) {
				$authors[] = array(
					'id'           => $user->ID,
					'display_name' => $user->display_name,
				);
			}
		}
		usort( $authors, static fn ( array $left, array $right ): int => strcasecmp( $left['display_name'], $right['display_name'] ) );

		return $authors;
	}
}
