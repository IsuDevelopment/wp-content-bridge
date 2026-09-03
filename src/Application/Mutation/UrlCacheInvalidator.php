<?php
/**
 * URL-scoped cache invalidation port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Invalidates cache entries keyed by URL rather than by post ID (ADR 0032).
 *
 * The post-scoped path (ADR 0012, `wpcb_mutation` →
 * `clean_post_cache( $post_id )`) covers the object. It cannot cover the
 * **old** URL after a slug change, because that entry is keyed by a URL no
 * longer associated with the post - and the failure is a stale page rather
 * than a missing one, which is why it is worth a port of its own.
 *
 * This deliberately does not travel on the `wpcb_mutation` event: that event
 * is redacted to changed field names, and putting URLs on it would make the
 * audit sink and every subscriber a carrier of content values.
 */
interface UrlCacheInvalidator {

	/**
	 * Notifies cache layers about URLs whose cached representation is stale.
	 *
	 * Best-effort by contract: the write has already committed when this
	 * runs, so an implementation reports what it notified and never throws
	 * a completed mutation into failure.
	 *
	 * @param array $urls Absolute URLs to invalidate.
	 * @phpstan-param list<string> $urls
	 * @return array Channels notified, as stable slugs.
	 * @phpstan-return list<string>
	 */
	public function purge( array $urls ): array;
}
