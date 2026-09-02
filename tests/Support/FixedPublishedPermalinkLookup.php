<?php
/**
 * Fixed published-permalink lookup test double.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Support;

use IsuDev\WPContentBridge\Application\Redirect\PublishedPermalinkLookup;

/**
 * Answers the same fixed verdict for every path.
 */
final readonly class FixedPublishedPermalinkLookup implements PublishedPermalinkLookup {

	/**
	 * Creates the double.
	 *
	 * @param bool $published Fixed answer for every path.
	 */
	public function __construct( private bool $published = false ) {
	}

	/**
	 * Returns the fixed answer.
	 *
	 * @param string $path Unused path.
	 * @return bool
	 */
	public function is_published_permalink( string $path ): bool {
		unset( $path );

		return $this->published;
	}
}
