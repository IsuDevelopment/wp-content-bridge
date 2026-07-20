<?php
/**
 * Transient-backed idempotency store, scoped per user.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\IdempotencyStore;

/**
 * Maps a user-scoped idempotency key to a created post ID for a bounded TTL.
 */
final class WordPressTransientIdempotencyStore implements IdempotencyStore {

	/**
	 * Creates the store.
	 *
	 * @param int $ttl Transient lifetime in seconds.
	 */
	public function __construct( private int $ttl = DAY_IN_SECONDS ) {}

	/**
	 * Post ID previously created for this user + key, or null.
	 *
	 * @param int    $user_id Acting principal.
	 * @param string $key     Idempotency key.
	 * @return int|null
	 */
	public function find( int $user_id, string $key ): ?int {
		$value = get_transient( $this->name( $user_id, $key ) );
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$post_id = (int) $value;

		return 0 < $post_id ? $post_id : null;
	}

	/**
	 * Records that this user + key created the given post ID.
	 *
	 * @param int    $user_id Acting principal.
	 * @param string $key     Idempotency key.
	 * @param int    $post_id Created post ID.
	 * @return void
	 */
	public function remember( int $user_id, string $key, int $post_id ): void {
		set_transient( $this->name( $user_id, $key ), $post_id, $this->ttl );
	}

	/**
	 * Builds the user-scoped transient name for a key.
	 *
	 * @param int    $user_id Acting principal.
	 * @param string $key     Idempotency key.
	 * @return string
	 */
	private function name( int $user_id, string $key ): string {
		return 'wpcb_idem_' . $user_id . '_' . md5( $key );
	}
}
