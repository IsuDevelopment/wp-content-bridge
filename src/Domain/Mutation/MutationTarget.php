<?php
/**
 * Current state of a content mutation target.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Immutable target identity, state, and concurrency version.
 */
final readonly class MutationTarget {

	/**
	 * Creates a mutation target snapshot.
	 *
	 * @param int          $post_id   Target post ID.
	 * @param string       $post_type Target post type.
	 * @param string       $status    Current post status.
	 * @param VersionToken $version   Current object version.
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public string $status,
		public VersionToken $version,
	) {}
}
