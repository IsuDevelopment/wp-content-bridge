<?php
/**
 * Flat, path-addressed block-tree result.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Content;

use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * A bounded projection of one content object's Gutenberg block structure.
 *
 * Nodes are flat and in document order, each carrying its own explicit path
 * rather than nesting, because the caller passes a path straight back to a
 * later block-level write.
 */
final readonly class BlockTree {

	/**
	 * Creates the result.
	 *
	 * @param int          $post_id    Target post ID.
	 * @param string       $post_type  Target post type.
	 * @param VersionToken $version    Optimistic-concurrency token.
	 * @param array        $nodes      Flat, document-ordered nodes.
	 * @param bool         $truncated  Whether the node cap stopped traversal early.
	 * @phpstan-param list<BlockTreeNode> $nodes
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public VersionToken $version,
		public array $nodes,
		public bool $truncated,
	) {
	}

	/**
	 * Serializes the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => '1.0',
			'post_id'        => $this->post_id,
			'post_type'      => $this->post_type,
			'version_token'  => $this->version->to_string(),
			'nodes'          => array_map(
				static fn ( BlockTreeNode $node ): array => $node->to_array(),
				$this->nodes
			),
			'truncated'      => $this->truncated,
			'provenance'     => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
		);
	}
}
