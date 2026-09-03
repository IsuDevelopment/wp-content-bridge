<?php
/**
 * URL-scoped cache invalidation adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\UrlCacheInvalidator;
use Throwable;

/**
 * Dispatches public, single-URL purge hooks for a bounded URL set (ADR 0032).
 *
 * What it may do is deliberately narrow: dispatch a cache plugin's **public,
 * documented, single-URL** hook when `has_action()` says something is
 * listening, and emit this plugin's own action so a site can bind whatever its
 * stack actually uses.
 *
 * What it must never do: read a cache plugin's options, call its classes,
 * purge the whole site, purge a URL outside the set it was handed, or make an
 * HTTP request of its own. A site-wide flush as a "safe default" would turn an
 * agent renaming one page into an outage of the cache tier.
 *
 * It reports the channels it notified, and never that a purge was confirmed:
 * dispatching an action proves a listener ran, not that a cached page was
 * dropped.
 */
final class WordPressUrlCacheInvalidator implements UrlCacheInvalidator {

	/**
	 * Notifies cache layers about stale URLs.
	 *
	 * @param array $urls Absolute URLs to invalidate.
	 * @phpstan-param list<string> $urls
	 * @return array Channels notified.
	 * @phpstan-return list<string>
	 */
	public function purge( array $urls ): array {
		$urls = array_values(
			array_unique(
				array_filter( $urls, static fn ( string $url ): bool => '' !== trim( $url ) )
			)
		);

		if ( array() === $urls ) {
			return array();
		}

		$channels = array();

		try {
			if ( has_action( 'litespeed_purge_url' ) ) {
				foreach ( $urls as $url ) {
					/**
					 * LiteSpeed Cache public, single-URL purge hook.
					 *
					 * @param string $url URL to purge.
					 */
					do_action( 'litespeed_purge_url', $url );
				}
				$channels[] = 'litespeed-cache';
			}

			/**
			 * Fires with the bounded set of URLs whose cached representation is
			 * stale after a bridge write.
			 *
			 * Bind this to whatever page cache, object cache, or edge the site
			 * actually runs. The set is bounded by the write that produced it
			 * and never widens to a site-wide purge.
			 *
			 * @param list<string> $urls URLs to invalidate.
			 */
			do_action( 'wp_content_bridge_purge_urls', $urls );
			$channels[] = 'wp-content-bridge';
		} catch ( Throwable $error ) {
			try {
				/**
				 * Fires when best-effort cache invalidation fails after a committed write.
				 *
				 * @param int       $post_id Zero: this failure is URL-scoped, not post-scoped.
				 * @param Throwable $error   Cache adapter failure.
				 */
				do_action( 'wp_content_bridge_cache_invalidation_failed', 0, $error );
			} catch ( Throwable ) {
				// Observability listeners must not change the completed write outcome.
				return $channels;
			}
		}

		return $channels;
	}
}
