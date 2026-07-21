<?php
/**
 * List-block-patterns use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Pattern;

use IsuDev\WPContentBridge\Domain\Pattern\PatternQuery;
use IsuDev\WPContentBridge\Domain\Pattern\PatternSearchResult;

/**
 * Enforces access before pattern inventory discovery.
 */
final readonly class ListBlockPatterns {

	/**
	 * Creates the use case.
	 *
	 * @param PatternAccessManager $access  Pattern access policy.
	 * @param BlockPatternCatalog  $catalog Pattern catalog.
	 */
	public function __construct(
		private PatternAccessManager $access,
		private BlockPatternCatalog $catalog,
	) {
	}

	/**
	 * Lists authorized patterns.
	 *
	 * @param PatternQuery $query Listing criteria.
	 * @return PatternSearchResult
	 */
	public function execute( PatternQuery $query ): PatternSearchResult {
		$this->access->require_read();

		return $this->catalog->list( $query );
	}
}
