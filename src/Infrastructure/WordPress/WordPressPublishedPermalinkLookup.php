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
 * WordPress permalink resolution. Depends on `url_to_postid()`,
 * `get_post_status()` and archive-link helpers, so it is exercised by
 * WordPress runtime verification (`tests/Integration`), not `tests/Unit`,
 * which deliberately never loads WordPress.
 *
 * **`url_to_postid()` alone is not enough, and a live probe proved it.**
 * Creating a redirect for `/` succeeded on the reference site, because
 * `url_to_postid()` answers `0` for the site root whether the front page is
 * a static page or the blog index — so the guard read the busiest URL on the
 * site as dead. The root and post-type archives are handled explicitly here
 * for that reason.
 *
 * Residual gap, stated rather than papered over: term archives and other
 * rewrite-driven routes are still not resolved. Matching the request against
 * the rewrite rules does not help — with pretty permalinks the generic
 * `pagename` rule matches nearly every path, so a rule match cannot
 * distinguish a live route from a dead one, and resolving each candidate
 * properly would mean running the query WordPress itself would run. Until
 * that exists, the reserved-prefix denylist and operator review are the
 * defence for those paths.
 */
final class WordPressPublishedPermalinkLookup implements PublishedPermalinkLookup {

	/**
	 * Answers whether the path resolves to live, currently served content.
	 *
	 * @param string $path Site-relative path, e.g. `/old-page`.
	 * @return bool
	 */
	public function is_published_permalink( string $path ): bool {
		if ( self::is_site_root( $path ) ) {
			// The front page is always live: as a static page, as the blog
			// index, or as whatever a theme puts there.
			return true;
		}

		if ( self::is_post_type_archive( $path ) ) {
			return true;
		}

		$post_id = url_to_postid( home_url( $path ) );
		if ( 0 === $post_id ) {
			return false;
		}

		return 'publish' === get_post_status( $post_id );
	}

	/**
	 * Whether the path addresses the site root.
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	private static function is_site_root( string $path ): bool {
		return '' === trim( $path, '/' );
	}

	/**
	 * Whether the path is the archive link of a public post type.
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	private static function is_post_type_archive( string $path ): bool {
		$candidate = self::comparable( $path );

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( ! $post_type->has_archive ) {
				continue;
			}

			$link = get_post_type_archive_link( $post_type->name );
			if ( ! is_string( $link ) ) {
				continue;
			}

			if ( self::comparable( self::path_of( $link ) ) === $candidate ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reduces a path to a slash-insensitive comparison form, because the
	 * site's trailing-slash convention must not decide whether a live URL is
	 * protected.
	 *
	 * @param string $path Candidate path.
	 * @return string
	 */
	private static function comparable( string $path ): string {
		return trim( $path, '/' );
	}

	/**
	 * Extracts the path component of an absolute URL.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	private static function path_of( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return is_string( $path ) ? $path : '';
	}
}
