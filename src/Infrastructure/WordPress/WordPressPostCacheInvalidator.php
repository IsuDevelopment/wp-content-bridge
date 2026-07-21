<?php
/**
 * Post-mutation cache invalidation adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use Throwable;

/**
 * Invalidates only the post affected by a successful bridge mutation.
 */
final class WordPressPostCacheInvalidator {

	/**
	 * Registers the mutation-event subscriber.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wpcb_mutation', array( $this, 'on_mutation' ), 1 );
	}

	/**
	 * Invalidates core and supported page-cache entries after a successful write.
	 *
	 * Cache maintenance is best-effort because the content transaction has
	 * already committed. A cache-plugin failure must never turn that completed
	 * mutation into a reported write failure.
	 *
	 * @param mixed $event Mutation lifecycle payload.
	 * @return void
	 */
	public function on_mutation( mixed $event ): void {
		if (
			! $event instanceof AuditEvent
			|| 'success' !== $event->outcome
			|| null === $event->object_id
		) {
			return;
		}

		$post_id   = $event->object_id;
		$providers = array( 'wordpress' );

		try {
			clean_post_cache( $post_id );

			if ( has_action( 'litespeed_purge_post' ) ) {
				/**
				 * LiteSpeed Cache public, post-scoped purge hook.
				 *
				 * @param int $post_id Post ID to purge.
				 */
				do_action( 'litespeed_purge_post', $post_id );
				$providers[] = 'litespeed-cache';
			}

			/**
			 * Fires after the affected post caches were invalidated.
			 *
			 * Additional cache adapters may observe this event without changing the
			 * mutation use cases or depending on a concrete cache plugin.
			 *
			 * @param int          $post_id   Mutated post ID.
			 * @param list<string> $providers Cache providers notified by core.
			 * @param AuditEvent   $event      Pre-redacted mutation event.
			 */
			do_action( 'wp_content_bridge_post_cache_invalidated', $post_id, $providers, $event );
		} catch ( Throwable $error ) {
			try {
				/**
				 * Fires when best-effort cache invalidation fails after a committed write.
				 *
				 * @param int       $post_id Mutated post ID.
				 * @param Throwable $error   Cache adapter failure.
				 */
				do_action( 'wp_content_bridge_cache_invalidation_failed', $post_id, $error );
			} catch ( Throwable ) {
				// Observability listeners must not change the completed write outcome.
				return;
			}
		}
	}
}
