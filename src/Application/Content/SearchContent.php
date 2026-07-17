<?php
/**
 * Search-content use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Content;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\Content\ContentQuery;
use IsuDev\WPContentBridge\Domain\Content\ContentSearchResult;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;

/**
 * Applies content-type policy before querying WordPress.
 */
final readonly class SearchContent {

	/**
	 * Creates the search use case.
	 *
	 * @param ContentAccessManager $access     Shared content policy.
	 * @param ContentRepository    $repository Content reader port.
	 * @param TaxonomyCatalog      $taxonomies Taxonomy discovery port.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentRepository $repository,
		private TaxonomyCatalog $taxonomies,
	) {
	}

	/**
	 * Executes an access-constrained search.
	 *
	 * @param ContentQuery $query Normalized criteria.
	 * @return ContentSearchResult
	 * @throws InvalidArgumentException When a type or taxonomy is unavailable.
	 */
	public function execute( ContentQuery $query ): ContentSearchResult {
		$allowed = array();
		foreach ( $this->access->content_types() as $definition ) {
			if (
				$this->access->allows( $definition->name, ContentOperation::SEARCH )
				&& $this->access->allows( $definition->name, ContentOperation::READ )
			) {
				$allowed[] = $definition->name;
			}
		}

		$effective = array() === $query->post_types
			? $allowed
			: array_values( array_intersect( $query->post_types, $allowed ) );

		if ( array() === $effective ) {
			throw new InvalidArgumentException( 'No requested content type is available for search.' );
		}

		foreach ( $query->taxonomy_filters as $filter ) {
			if ( ! $this->taxonomies->supports( $filter->taxonomy, $effective ) ) {
				throw new InvalidArgumentException( 'A taxonomy is unavailable for the effective content types.' );
			}
		}

		return $this->repository->search( $query->with_post_types( $effective ) );
	}
}
