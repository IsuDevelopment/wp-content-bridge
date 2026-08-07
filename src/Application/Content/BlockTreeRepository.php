<?php
/**
 * Block-tree read port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Content;

use IsuDev\WPContentBridge\Domain\Content\BlockTree;

/**
 * Read-only projection of one content object's parsed block structure.
 *
 * Deliberately separate from ContentRepository: this is a different
 * projection of the same post, not an extension of the whole-document read.
 */
interface BlockTreeRepository {

	/**
	 * Resolves the type for a content ID.
	 *
	 * @param int $post_id Object ID.
	 * @return string|null
	 */
	public function post_type( int $post_id ): ?string;

	/**
	 * Checks the current principal's native object capability.
	 *
	 * @param int $post_id Object ID.
	 * @return bool
	 */
	public function can_read( int $post_id ): bool;

	/**
	 * Reads the bounded block tree, optionally rooted at a subtree path.
	 *
	 * @param int   $post_id       Object ID.
	 * @param array $path          Zero-based indices identifying a subtree root; empty for the whole document.
	 * @param int   $max_depth     Maximum node depth to return, counted from the returned root; null for unbounded.
	 * @param bool  $include_attrs Whether to include each node's raw attributes.
	 * @return BlockTree|null
	 * @phpstan-param list<int> $path
	 * @phpstan-param int|null $max_depth
	 */
	public function tree( int $post_id, array $path, ?int $max_depth, bool $include_attrs ): ?BlockTree;
}
