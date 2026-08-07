<?php
/**
 * WordPress block-tree read adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Content\BlockTreeRepository;
use IsuDev\WPContentBridge\Domain\Content\BlockTree;
use IsuDev\WPContentBridge\Domain\Content\BlockTreeNode;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use WP_Post;

/**
 * Projects `parse_blocks()` output into a flat, bounded, path-addressed node
 * list. A separate projection of the same post read by WordPressContentRepository,
 * not an extension of it.
 *
 * `parse_blocks()` only guarantees its documented shape one level deep; nested
 * `innerBlocks` entries are narrowed explicitly through `as_block()` rather than
 * assumed, since core's own stub does not type them recursively.
 *
 * @phpstan-type ParsedBlock array{
 *     blockName: string|null,
 *     attrs: array<int|string, mixed>,
 *     innerBlocks: array<int|string, mixed>,
 *     innerHTML: string,
 *     innerContent: array<int|string, mixed>,
 * }
 */
final class WordPressBlockTreeRepository implements BlockTreeRepository {

	/**
	 * Maximum nodes returned before traversal stops and `truncated` is set.
	 *
	 * @var int
	 */
	public const MAX_NODES = 500;

	private const MAX_TEXT_LENGTH      = 120;
	private const MAX_ATTRS_BYTES      = 512;
	private const MIN_ATTR_TEXT_LENGTH = 3;

	/**
	 * Resolves the type for a content ID.
	 *
	 * @param int $post_id Object ID.
	 * @return string|null
	 */
	public function post_type( int $post_id ): ?string {
		$post = get_post( $post_id );

		return $post instanceof WP_Post ? $post->post_type : null;
	}

	/**
	 * Checks native read permission.
	 *
	 * @param int $post_id Object ID.
	 * @return bool
	 */
	public function can_read( int $post_id ): bool {
		return current_user_can( 'read_post', $post_id );
	}

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
	public function tree( int $post_id, array $path, ?int $max_depth, bool $include_attrs ): ?BlockTree {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$blocks    = parse_blocks( $post->post_content );
		$nodes     = array();
		$truncated = false;

		if ( array() === $path ) {
			$truncated = $this->collect( $blocks, array(), 1, $max_depth, $include_attrs, $nodes );
		} else {
			$target = $this->resolve( $blocks, $path );
			if ( null !== $target ) {
				$nodes[] = $this->build_node( $target, $path, $include_attrs );
				if ( ( null === $max_depth || 1 < $max_depth ) && array() !== $target['innerBlocks'] ) {
					$truncated = $this->collect( $target['innerBlocks'], $path, 2, $max_depth, $include_attrs, $nodes );
				}
			}
		}

		$version_token = VersionToken::for_content(
			$post->post_modified_gmt,
			$post->post_title,
			$post->post_content,
			$post->post_status
		);

		return new BlockTree( $post_id, $post->post_type, $version_token, $nodes, $truncated );
	}

	/**
	 * Resolves the block at a subtree path.
	 *
	 * @param array $raw_blocks Raw sibling entries, top-level or from an innerBlocks array.
	 * @param array $path       Zero-based indices into successive innerBlocks arrays.
	 * @return array|null
	 * @phpstan-param array<int|string, mixed> $raw_blocks
	 * @phpstan-param list<int> $path
	 * @phpstan-return ParsedBlock|null
	 */
	private function resolve( array $raw_blocks, array $path ): ?array {
		$current = $raw_blocks;
		$target  = null;

		foreach ( $path as $index ) {
			if ( ! array_key_exists( $index, $current ) || ! is_array( $current[ $index ] ) ) {
				return null;
			}
			$target  = $this->as_block( $current[ $index ] );
			$current = $target['innerBlocks'];
		}

		return $target;
	}

	/**
	 * Walks sibling blocks depth-first in document order, bounded by the node cap and max depth.
	 *
	 * @param array    $raw_blocks    Raw sibling entries, top-level or from an innerBlocks array.
	 * @param array    $parent_path   Path of the shared parent.
	 * @param int      $depth         Depth of $raw_blocks' entries, counted from the returned root (1-based).
	 * @param int|null $max_depth     Maximum depth to include; null for unbounded.
	 * @param bool     $include_attrs Whether to include each node's raw attributes.
	 * @param array    $nodes         Accumulator, appended in place.
	 * @return bool True when the node cap was reached and traversal stopped early.
	 * @phpstan-param array<int|string, mixed> $raw_blocks
	 * @phpstan-param list<int> $parent_path
	 * @phpstan-param list<BlockTreeNode> $nodes
	 */
	private function collect( array $raw_blocks, array $parent_path, int $depth, ?int $max_depth, bool $include_attrs, array &$nodes ): bool {
		foreach ( $raw_blocks as $index => $raw_block ) {
			if ( ! is_int( $index ) || ! is_array( $raw_block ) ) {
				continue;
			}
			if ( count( $nodes ) >= self::MAX_NODES ) {
				return true;
			}

			$block     = $this->as_block( $raw_block );
			$node_path = array_merge( $parent_path, array( $index ) );
			$nodes[]   = $this->build_node( $block, $node_path, $include_attrs );

			if ( count( $nodes ) >= self::MAX_NODES ) {
				return true;
			}

			if ( ( null === $max_depth || $depth < $max_depth ) && array() !== $block['innerBlocks'] ) {
				if ( $this->collect( $block['innerBlocks'], $node_path, $depth + 1, $max_depth, $include_attrs, $nodes ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Narrows one raw `parse_blocks()` entry into its expected shape.
	 *
	 * Core's own stub only types this shape one level deep, so nested
	 * `innerBlocks` entries arrive as plain arrays and are narrowed here,
	 * defensively defaulting a malformed field rather than failing the read.
	 *
	 * @param array $raw Raw block entry.
	 * @return array
	 * @phpstan-param array<int|string, mixed> $raw
	 * @phpstan-return ParsedBlock
	 */
	private function as_block( array $raw ): array {
		return array(
			'blockName'    => isset( $raw['blockName'] ) && is_string( $raw['blockName'] ) ? $raw['blockName'] : null,
			'attrs'        => isset( $raw['attrs'] ) && is_array( $raw['attrs'] ) ? $raw['attrs'] : array(),
			'innerBlocks'  => isset( $raw['innerBlocks'] ) && is_array( $raw['innerBlocks'] ) ? $raw['innerBlocks'] : array(),
			'innerHTML'    => isset( $raw['innerHTML'] ) && is_string( $raw['innerHTML'] ) ? $raw['innerHTML'] : '',
			'innerContent' => isset( $raw['innerContent'] ) && is_array( $raw['innerContent'] ) ? $raw['innerContent'] : array(),
		);
	}

	/**
	 * Builds one bounded node projection.
	 *
	 * @param array $block         Normalized parsed block.
	 * @param array $path          Node's explicit path.
	 * @param bool  $include_attrs Whether to include the node's raw attributes.
	 * @return BlockTreeNode
	 * @phpstan-param ParsedBlock $block
	 * @phpstan-param list<int> $path
	 */
	private function build_node( array $block, array $path, bool $include_attrs ): BlockTreeNode {
		$inner_count            = count( $block['innerBlocks'] );
		[ $text, $text_source ] = $this->node_text( $block['innerHTML'], $block['attrs'] );

		$attrs         = null;
		$attrs_omitted = false;
		if ( $include_attrs ) {
			[ $attrs, $attrs_omitted ] = $this->node_attrs( $block['attrs'] );
		}

		return new BlockTreeNode( $path, $block['blockName'], $inner_count, $text, $text_source, $attrs, $attrs_omitted );
	}

	/**
	 * Derives a bounded plain-text preview and its source.
	 *
	 * Tries the node's own innerHTML first. Most prose on some sites lives in
	 * block attributes instead (custom blocks storing labels/descriptions as
	 * attrs), so a node with empty innerHTML falls back to its prose-bearing
	 * string attributes.
	 *
	 * @param string $inner_html Node's own innerHTML, excluding descendant markup.
	 * @param array  $attrs      Raw block attributes.
	 * @return array{0: string|null, 1: string|null}
	 * @phpstan-param array<int|string, mixed> $attrs
	 */
	private function node_text( string $inner_html, array $attrs ): array {
		$stripped = trim( wp_strip_all_tags( $inner_html ) );
		if ( '' !== $stripped ) {
			return array( $this->truncate_text( $stripped ), 'inner_html' );
		}

		$from_attrs = $this->attrs_text( $attrs );
		if ( null !== $from_attrs ) {
			return array( $this->truncate_text( $from_attrs ), 'attrs' );
		}

		return array( null, null );
	}

	/**
	 * Concatenates prose-bearing string attributes in attribute-name order.
	 *
	 * A value is treated as prose when it contains whitespace and is at least
	 * three characters long after stripping tags and trimming; this excludes
	 * CSS classes, colors, and other non-prose identifiers.
	 *
	 * @param array $attrs Raw block attributes.
	 * @return string|null
	 * @phpstan-param array<int|string, mixed> $attrs
	 */
	private function attrs_text( array $attrs ): ?string {
		if ( array() === $attrs ) {
			return null;
		}

		ksort( $attrs );
		$parts = array();
		foreach ( $attrs as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			$candidate = trim( wp_strip_all_tags( $value ) );
			if ( self::MIN_ATTR_TEXT_LENGTH > mb_strlen( $candidate ) || 1 !== preg_match( '/\s/u', $candidate ) ) {
				continue;
			}

			$parts[] = $candidate;
		}

		return array() === $parts ? null : implode( ' ', $parts );
	}

	/**
	 * Truncates a non-empty text preview to the shared bound.
	 *
	 * @param string $value Stripped, trimmed text.
	 * @return string
	 */
	private function truncate_text( string $value ): string {
		return function_exists( 'mb_substr' )
			? mb_substr( $value, 0, self::MAX_TEXT_LENGTH, 'UTF-8' )
			: substr( $value, 0, self::MAX_TEXT_LENGTH );
	}

	/**
	 * Bounds one node's attributes by encoded size.
	 *
	 * @param array $attrs Raw block attributes.
	 * @return array{0: array<string, mixed>|null, 1: bool}
	 * @phpstan-param array<int|string, mixed> $attrs
	 */
	private function node_attrs( array $attrs ): array {
		if ( array() === $attrs ) {
			return array( null, false );
		}

		$encoded = wp_json_encode( $attrs );
		if ( ! is_string( $encoded ) || self::MAX_ATTRS_BYTES < strlen( $encoded ) ) {
			return array( null, true );
		}

		$string_keyed = array();
		foreach ( $attrs as $key => $value ) {
			$string_keyed[ (string) $key ] = $value;
		}

		return array( $string_keyed, false );
	}
}
