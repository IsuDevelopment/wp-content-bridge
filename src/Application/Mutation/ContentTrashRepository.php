<?php
/**
 * Port for reversible WordPress trash mutations.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\MutationTarget;

/**
 * Resolves and trashes content without exposing permanent deletion.
 */
interface ContentTrashRepository {

	/**
	 * Whether WordPress is configured to retain trashed posts.
	 *
	 * @return bool
	 */
	public function trash_supported(): bool;

	/**
	 * Resolves the current target or null when absent.
	 *
	 * @param int $post_id Target post ID.
	 * @return MutationTarget|null
	 */
	public function target( int $post_id ): ?MutationTarget;

	/**
	 * Moves one post to trash and verifies the resulting state.
	 *
	 * @param int $post_id Target post ID.
	 * @return MutationResult
	 * @throws MutationWriteFailed When WordPress rejects or bypasses trash.
	 */
	public function trash( int $post_id ): MutationResult;
}
