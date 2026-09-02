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
	 * Statuses safe to restore to. Never `publish` or `future`.
	 *
	 * @var list<string>
	 */
	private const RESTORABLE_STATUSES = array( 'draft', 'pending', 'private' );

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
	 * Restores a trashed post to its recorded pre-trash status, or `draft`
	 * when that status is missing, unparseable, or unsafe.
	 *
	 * @param int $post_id Target post ID.
	 * @return MutationResult
	 * @throws MutationWriteFailed When trash is unavailable, the post is not
	 *                              currently trashed, or WordPress does not
	 *                              land it on a safe status.
	 */
	public function untrash( int $post_id ): MutationResult {
		if ( ! $this->trash_supported() ) {
			throw new MutationWriteFailed( 'Reversible WordPress trash is disabled.' );
		}

		$pre = get_post( $post_id );
		if ( ! $pre instanceof WP_Post || 'trash' !== $pre->post_status ) {
			throw new MutationWriteFailed( 'WordPress did not have the post in trash.' );
		}

		$target_status = $this->safe_restore_status( $post_id );
		$override      = static function () use ( $target_status ): string {
			return $target_status;
		};

		add_filter( 'wp_untrash_post_status', $override, PHP_INT_MAX );
		try {
			$restored = wp_untrash_post( $post_id );
		} finally {
			remove_filter( 'wp_untrash_post_status', $override, PHP_INT_MAX );
		}

		$post = get_post( $post_id );

		if ( ! $restored instanceof WP_Post || ! $post instanceof WP_Post
			|| ! in_array( $post->post_status, self::RESTORABLE_STATUSES, true )
		) {
			throw new MutationWriteFailed( 'WordPress did not restore the post to a safe status.' );
		}

		return new MutationResult( $post_id, $post->post_type, $post->post_status, $this->version_for( $post ), array( 'status' ), false );
	}

	/**
	 * Determines the safe status to restore to.
	 *
	 * Restores to the recorded pre-trash status only when it is one of
	 * `draft`, `pending`, or `private`. Missing meta, an unparseable value, or
	 * a recorded `publish`/`future` status all fall back to `draft` — untrash
	 * must never republish content.
	 *
	 * @param int $post_id Target post ID.
	 * @return string
	 */
	private function safe_restore_status( int $post_id ): string {
		$recorded = get_post_meta( $post_id, '_wp_trash_meta_status', true );

		return is_string( $recorded ) && in_array( $recorded, self::RESTORABLE_STATUSES, true )
			? $recorded
			: 'draft';
	}

	/**
	 * Builds the optimistic-concurrency version for a post.
	 *
	 * @param WP_Post $post WordPress post object.
	 * @return VersionToken
	 */
	private function version_for( WP_Post $post ): VersionToken {
		return PostVersionTokenFactory::for_post( $post );
	}
}
