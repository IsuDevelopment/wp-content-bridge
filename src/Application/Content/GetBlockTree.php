<?php
/**
 * Get-block-tree use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Content;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\Content\BlockTree;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;

/**
 * Reads one object's block structure without revealing whether a denial was
 * policy or capability based. Mirrors GetContent's gates exactly.
 */
final readonly class GetBlockTree {

	/**
	 * Creates the block-tree use case.
	 *
	 * @param ContentAccessManager $access     Shared content policy.
	 * @param BlockTreeRepository  $repository Block-tree reader port.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private BlockTreeRepository $repository,
	) {
	}

	/**
	 * Reads one object's bounded block tree.
	 *
	 * @param int      $post_id       Object ID.
	 * @param array    $path          Zero-based indices identifying a subtree root; empty for the whole document.
	 * @param int|null $max_depth     Maximum node depth to return, counted from the returned root.
	 * @param bool     $include_attrs Whether to include each node's raw attributes.
	 * @return BlockTree
	 * @phpstan-param list<int> $path
	 * @phpstan-param int|null $max_depth
	 * @throws ContentUnavailable When missing, disabled, or unreadable.
	 */
	public function execute( int $post_id, array $path, ?int $max_depth, bool $include_attrs ): BlockTree {
		$post_type = $this->repository->post_type( $post_id );

		if (
			null === $post_type
			|| ! $this->access->allows( $post_type, ContentOperation::READ )
			|| ! $this->repository->can_read( $post_id )
		) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		$tree = $this->repository->tree( $post_id, $path, $max_depth, $include_attrs );
		if ( null === $tree ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		return $tree;
	}
}
