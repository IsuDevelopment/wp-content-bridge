<?php
/**
 * WordPress status-transition target resolution adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Status\StatusTransitionTargetRepository;
use IsuDev\WPContentBridge\Domain\Mutation\MutationTarget;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use WP_Post;

/**
 * Resolves the current state of a status-transition target and the native
 * capabilities that gate it, through public WordPress APIs only.
 *
 * Deliberately independent of {@see WordPressContentMutationRepository}:
 * that class is instantiated only while `wpcb_writes_enabled` is on, but
 * `get-status-transitions` (a read) must keep working regardless of that
 * flag — see `Plugin::boot()`.
 */
final class WordPressStatusTransitionTargetRepository implements StatusTransitionTargetRepository {

	/**
	 * Resolves one target snapshot.
	 *
	 * @param int $post_id Target post ID.
	 * @return MutationTarget|null
	 */
	public function target( int $post_id ): ?MutationTarget {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return new MutationTarget(
			$post_id,
			$post->post_type,
			$post->post_status,
			PostVersionTokenFactory::for_post( $post )
		);
	}

	/**
	 * Checks native per-object edit permission only.
	 *
	 * @param int $post_id Target post ID.
	 * @return bool
	 */
	public function native_can_edit( int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Checks the dedicated plugin publication capability.
	 *
	 * @return bool
	 */
	public function has_publish_capability(): bool {
		return current_user_can( 'wpcb_publish_content' );
	}

	/**
	 * Checks native per-object publish permission only.
	 *
	 * @param int $post_id Target post ID.
	 * @return bool
	 */
	public function native_can_publish( int $post_id ): bool {
		return current_user_can( 'publish_post', $post_id );
	}
}
