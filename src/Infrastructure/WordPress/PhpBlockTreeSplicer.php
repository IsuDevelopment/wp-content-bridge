<?php
/**
 * Block-tree path resolution and splice adapter backed by WordPress core
 * block parsing.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\BlockTreeSplicer;
use IsuDev\WPContentBridge\Domain\Content\BlockPathLookup;

/**
 * Mirrors WordPressBlockTreeRepository's path semantics exactly: zero-based
 * indices into successive `innerBlocks` arrays of `parse_blocks()`, with
 * freeform (`blockName === null`) nodes counted, because they occupy real
 * indices in the array a write mutates.
 *
 * `parse_blocks()` only guarantees its documented shape one level deep, so
 * every raw entry is narrowed defensively through `as_block()`/`as_blocks()`
 * rather than assumed, and each level accessed by `replace()` is re-narrowed
 * rather than trusted from a parent's declared (necessarily shallow) type.
 *
 * @phpstan-type ParsedBlock array{
 *     blockName: string|null,
 *     attrs: array<int|string, mixed>,
 *     innerBlocks: array<int, array<int|string, mixed>>,
 *     innerHTML: string,
 *     innerContent: array<int|string, mixed>,
 * }
 */
final class PhpBlockTreeSplicer implements BlockTreeSplicer {

	/**
	 * Resolves the node at a path within raw content, without mutating it.
	 *
	 * @param string $content Raw post_content to parse.
	 * @param array  $path    Zero-based indices into successive innerBlocks arrays.
	 * @return BlockPathLookup|null Null when no node exists at path.
	 * @phpstan-param list<int> $path
	 */
	public function resolve( string $content, array $path ): ?BlockPathLookup {
		$target = $this->resolve_block( parse_blocks( $content ), $path );

		return null === $target ? null : new BlockPathLookup( $target['blockName'] );
	}

	/**
	 * Replaces the subtree at a path and re-serializes the whole tree. The
	 * other blocks are byte-identical by construction: only the addressed
	 * index is ever touched.
	 *
	 * @param string $content      Raw post_content to parse.
	 * @param array  $path         Zero-based indices into successive innerBlocks arrays. Must already
	 *                              have been confirmed to resolve via resolve().
	 * @param string $block_markup Replacement markup for the subtree; empty deletes it.
	 * @return string The whole re-serialized post_content.
	 * @phpstan-param list<int> $path
	 */
	public function splice( string $content, array $path, string $block_markup ): string {
		$blocks      = $this->as_blocks( parse_blocks( $content ) );
		$replacement = '' === $block_markup ? array() : $this->as_blocks( parse_blocks( $block_markup ) );

		return serialize_blocks( $this->replace( $blocks, $path, $replacement ) );
	}

	/**
	 * Walks down successive innerBlocks arrays to the node at a path,
	 * re-narrowing at each level rather than trusting a parent's declared
	 * (necessarily shallow) innerBlocks type.
	 *
	 * @param array $raw_blocks Top-level parsed blocks.
	 * @param array $path       Zero-based indices into successive innerBlocks arrays.
	 * @return array|null
	 * @phpstan-param array<int|string, mixed> $raw_blocks
	 * @phpstan-param list<int> $path
	 * @phpstan-return ParsedBlock|null
	 */
	private function resolve_block( array $raw_blocks, array $path ): ?array {
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
	 * Replaces the single element at a path with zero or more replacement
	 * blocks, returning a new tree.
	 *
	 * @param array $blocks      Normalized sibling entries, top-level or from an innerBlocks array.
	 * @param array $path        Remaining path indices, outermost first.
	 * @param array $replacement Normalized replacement blocks; empty deletes the addressed subtree.
	 * @return array
	 * @phpstan-param array<int, ParsedBlock> $blocks
	 * @phpstan-param list<int> $path
	 * @phpstan-param array<int, ParsedBlock> $replacement
	 * @phpstan-return array<int, ParsedBlock>
	 */
	private function replace( array $blocks, array $path, array $replacement ): array {
		$index = $path[0];
		if ( ! array_key_exists( $index, $blocks ) ) {
			// The caller must have already confirmed the path resolves via resolve(); a mismatch here
			// means the content changed between resolution and splice, so the tree is left untouched.
			return $blocks;
		}

		if ( 1 === count( $path ) ) {
			return array_merge( array_slice( $blocks, 0, $index ), $replacement, array_slice( $blocks, $index + 1 ) );
		}

		$block                = $blocks[ $index ];
		$block['innerBlocks'] = $this->replace( $this->as_blocks( $block['innerBlocks'] ), array_slice( $path, 1 ), $replacement );
		$blocks[ $index ]     = $block;

		return $blocks;
	}

	/**
	 * Narrows a list of raw sibling entries into their expected shape.
	 *
	 * @param array $raw_blocks Raw sibling entries, top-level or from an innerBlocks array.
	 * @return array
	 * @phpstan-param array<int|string, mixed> $raw_blocks
	 * @phpstan-return array<int, ParsedBlock>
	 */
	private function as_blocks( array $raw_blocks ): array {
		$blocks = array();
		foreach ( $raw_blocks as $raw_block ) {
			if ( is_array( $raw_block ) ) {
				$blocks[] = $this->as_block( $raw_block );
			}
		}

		return $blocks;
	}

	/**
	 * Narrows one raw `parse_blocks()` entry into its expected shape,
	 * eagerly normalizing `innerBlocks` so descendants are safe to hand back
	 * to `serialize_blocks()` without further narrowing.
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
			'innerBlocks'  => isset( $raw['innerBlocks'] ) && is_array( $raw['innerBlocks'] ) ? $this->as_blocks( $raw['innerBlocks'] ) : array(),
			'innerHTML'    => isset( $raw['innerHTML'] ) && is_string( $raw['innerHTML'] ) ? $raw['innerHTML'] : '',
			'innerContent' => isset( $raw['innerContent'] ) && is_array( $raw['innerContent'] ) ? $raw['innerContent'] : array(),
		);
	}
}
