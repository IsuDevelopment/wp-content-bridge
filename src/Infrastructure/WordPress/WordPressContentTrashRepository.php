<?php
/**
 * WordPress-backed reversible trash repository.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\ContentTrashRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\MutationTarget;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use WP_Post;

/**
 * Uses WordPress trash APIs while refusing permanent-delete fallback.
 */
final class WordPressContentTrashRepository implements ContentTrashRepository {

	/**
	 * Checks whether WordPress retains trashed posts.
	 *
	 * @return bool
	 */
	public function trash_supported(): bool {
		$constants = get_defined_constants();
		$days      = $constants['EMPTY_TRASH_DAYS'] ?? 0;

		return is_numeric( $days ) && 0 < (int) $days;
	}

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

		return new MutationTarget( $post_id, $post->post_type, $post->post_status, $this->version_for( $post ) );
	}

	/**
	 * Moves a post to reversible trash.
	 *
	 * @param int $post_id Target post ID.
	 * @return MutationResult
	 * @throws MutationWriteFailed When trash is unavailable or WordPress rejects the mutation.
	 */
	public function trash( int $post_id ): MutationResult {
		if ( ! $this->trash_supported() ) {
			throw new MutationWriteFailed( 'Reversible WordPress trash is disabled.' );
		}

		wp_save_post_revision( $post_id );
		$trashed = wp_trash_post( $post_id );
		$post    = get_post( $post_id );

		if ( ! $trashed instanceof WP_Post || ! $post instanceof WP_Post || 'trash' !== $post->post_status ) {
			throw new MutationWriteFailed( 'WordPress did not move the post to trash.' );
		}

		return new MutationResult( $post_id, $post->post_type, $post->post_status, $this->version_for( $post ), array( 'status' ), false );
	}

	/**
	 * Builds the optimistic-concurrency version for a post.
	 *
	 * @param WP_Post $post WordPress post object.
	 * @return VersionToken
	 */
	private function version_for( WP_Post $post ): VersionToken {
		return VersionToken::for_content( $post->post_modified_gmt, $post->post_title, $post->post_content, $post->post_status );
	}
}
