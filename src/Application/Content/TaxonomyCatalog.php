<?php
/**
 * Taxonomy discovery port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Content;

/**
 * Confirms that a taxonomy may constrain every effective content type.
 */
interface TaxonomyCatalog {

	/**
	 * Checks taxonomy eligibility and object-type assignments.
	 *
	 * @param string $taxonomy  Taxonomy name.
	 * @param array  $post_types Effective post types.
	 * @return bool
	 * @phpstan-param non-empty-list<string> $post_types
	 */
	public function supports( string $taxonomy, array $post_types ): bool;
}
