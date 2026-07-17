<?php
/**
 * Content persistence port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Content;

use IsuDev\WPContentBridge\Domain\Content\ContentDetail;
use IsuDev\WPContentBridge\Domain\Content\ContentQuery;
use IsuDev\WPContentBridge\Domain\Content\ContentSearchResult;

/**
 * Read operations implemented by the WordPress infrastructure adapter.
 */
interface ContentRepository {

	/**
	 * Searches readable content.
	 *
	 * @param ContentQuery $query Normalized query.
	 * @return ContentSearchResult
	 */
	public function search( ContentQuery $query ): ContentSearchResult;

	/**
	 * Resolves a post type without exposing the object to the caller.
	 *
	 * @param int $post_id Object ID.
	 * @return string|null
	 */
	public function post_type( int $post_id ): ?string;

	/**
	 * Checks the current principal's native object capability.
	 *
	 * @param int $post_id Object ID.
	 * @return bool
	 */
	public function can_read( int $post_id ): bool;

	/**
	 * Reads one selected content representation.
	 *
	 * @param int   $post_id         Object ID.
	 * @param array $representations Requested forms.
	 * @param array $relationships   Requested relationships.
	 * @return ContentDetail|null
	 * @phpstan-param list<string> $representations
	 * @phpstan-param list<string> $relationships
	 */
	public function get( int $post_id, array $representations, array $relationships ): ?ContentDetail;
}
