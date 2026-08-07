<?php
/**
 * Port for reading current content field values for preview comparison.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Read-only companion to ContentMutationRepository. Never writes.
 */
interface ContentSnapshotRepository {

	/**
	 * Current content field values, or null when the target is absent.
	 *
	 * @param int $post_id Target post ID.
	 * @return array{title: string, block_markup: string, excerpt: string}|null
	 */
	public function content_snapshot( int $post_id ): ?array;
}
