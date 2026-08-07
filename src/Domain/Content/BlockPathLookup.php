<?php
/**
 * Result of resolving one path within a parsed block tree.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Content;

/**
 * Carries the block name found at a resolved path. A `null` `$block_name`
 * legally means a freeform (`blockName === null`) node was found there;
 * resolution failure is represented separately by a `null` lookup itself,
 * never by this value object.
 */
final readonly class BlockPathLookup {

	/**
	 * Creates the lookup result.
	 *
	 * @param string|null $block_name Registered block name, or null for a freeform node.
	 */
	public function __construct(
		public ?string $block_name,
	) {
	}
}
