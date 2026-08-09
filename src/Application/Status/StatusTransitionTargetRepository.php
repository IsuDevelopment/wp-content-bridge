<?php
/**
 * Status-transition target resolution port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Status;

use IsuDev\WPContentBridge\Domain\Mutation\MutationTarget;

/**
 * Resolves the current state of a status-transition target and the native
 * WordPress capabilities that gate it.
 *
 * Deliberately independent of
 * {@see \IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository}:
 * that port's only implementation is instantiated solely while
 * `wpcb_writes_enabled` is on, but `get-status-transitions` (a read) must
 * keep working regardless — see `Plugin::boot()`. The global `wpcb_edit_content`
 * and `wpcb_publish_content` plugin capabilities are deliberately not
 * exposed here: they gate the write path only and are checked directly by
 * {@see \IsuDev\WPContentBridge\Adapter\Abilities\TransitionContentStatusAbilities}
 * and {@see self::has_publish_capability()} respectively, never bundled into
 * the native per-object checks below, so a principal who can read but not
 * edit is not silently granted the native-edit gate `get-status-transitions`
 * relies on for its own non-enumerating check.
 */
interface StatusTransitionTargetRepository {

	/**
	 * Resolves one target snapshot, or null when absent.
	 *
	 * @param int $post_id Target post ID.
	 * @return MutationTarget|null
	 */
	public function target( int $post_id ): ?MutationTarget;

	/**
	 * Checks native per-object edit permission only (`current_user_can( 'edit_post', $post_id )`).
	 *
	 * @param int $post_id Target post ID.
	 * @return bool
	 */
	public function native_can_edit( int $post_id ): bool;

	/**
	 * Checks the dedicated plugin publication capability (`wpcb_publish_content`).
	 *
	 * @return bool
	 */
	public function has_publish_capability(): bool;

	/**
	 * Checks native per-object publish permission only (`current_user_can( 'publish_post', $post_id )`).
	 *
	 * @param int $post_id Target post ID.
	 * @return bool
	 */
	public function native_can_publish( int $post_id ): bool;
}
