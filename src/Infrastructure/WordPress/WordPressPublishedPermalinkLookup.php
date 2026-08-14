<?php
/**
 * WordPress published permalink lookup.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Redirect\PublishedPermalinkLookup;

/**
 * Answers the redirect "live-content shadow guard" (ADR 0026 s5) from core
 * WordPress permalink resolution. Depends on `url_to_postid()` and
 * `get_post_status()`, so it is exercised by WordPress runtime verification
 * (`tests/Integration`), not `tests/Unit`, which deliberately never loads
 * WordPress.
 */
final class WordPressPublishedPermalinkLookup implements PublishedPermalinkLookup {

	/**
	 * Answers whether the path resolves to a currently published object.
	 *
	 * @param string $path Site-relative path, e.g. `/old-page`.
	 * @return bool
	 */
	public function is_published_permalink( string $path ): bool {
		$post_id = url_to_postid( home_url( $path ) );
		if ( 0 === $post_id ) {
			return false;
		}

		return 'publish' === get_post_status( $post_id );
	}
}
