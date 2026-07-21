<?php
/**
 * Block-pattern catalog port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Pattern;

use IsuDev\WPContentBridge\Domain\Pattern\PatternQuery;
use IsuDev\WPContentBridge\Domain\Pattern\PatternSearchResult;

/**
 * Reads the currently registered pattern inventory.
 */
interface BlockPatternCatalog {

	/**
	 * Lists bounded, normalized patterns.
	 *
	 * @param PatternQuery $query Listing criteria.
	 * @return PatternSearchResult
	 * @throws PatternPayloadTooLarge When requested markup exceeds the response limit.
	 */
	public function list( PatternQuery $query ): PatternSearchResult;
}
