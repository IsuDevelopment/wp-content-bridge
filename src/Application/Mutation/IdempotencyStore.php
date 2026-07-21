<?php
/**
 * Port for per-user idempotency-key persistence.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Maps a user-scoped idempotency key to a created post ID for a bounded TTL.
 */
interface IdempotencyStore {

	/**
	 * Post ID previously created for this user + key, or null.
	 *
	 * @param int    $user_id Acting principal.
	 * @param string $key     Idempotency key.
	 * @return int|null
	 */
	public function find( int $user_id, string $key ): ?int;

	/**
	 * Records that this user + key created the given post ID.
	 *
	 * @param int    $user_id Acting principal.
	 * @param string $key     Idempotency key.
	 * @param int    $post_id Created post ID.
	 * @return void
	 */
	public function remember( int $user_id, string $key, int $post_id ): void;
}
