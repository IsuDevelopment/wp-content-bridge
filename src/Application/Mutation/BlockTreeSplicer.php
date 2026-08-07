<?php
/**
 * Port for resolving and rewriting one path within a parsed block tree.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use IsuDev\WPContentBridge\Domain\Content\BlockPathLookup;

/**
 * Backs update-block and preview-update-block. Path semantics must match
 * BlockTreeRepository exactly: zero-based indices into successive
 * innerBlocks arrays, with freeform (blockName === null) nodes counted.
 */
interface BlockTreeSplicer {

	/**
	 * Resolves the node at a path within raw content, without mutating it.
	 *
	 * @param string $content Raw post_content to parse.
	 * @param array  $path    Zero-based indices into successive innerBlocks arrays.
	 * @return BlockPathLookup|null Null when no node exists at path.
	 * @phpstan-param list<int> $path
	 */
	public function resolve( string $content, array $path ): ?BlockPathLookup;

	/**
	 * Replaces the subtree at a path and re-serializes the whole tree.
	 *
	 * The other blocks are byte-identical by construction: they are never
	 * re-emitted by the caller and never re-parsed from caller input.
	 *
	 * @param string $content      Raw post_content to parse.
	 * @param array  $path         Zero-based indices into successive innerBlocks arrays. Must already
	 *                              have been confirmed to resolve via resolve().
	 * @param string $block_markup Replacement markup for the subtree; empty deletes it.
	 * @return string The whole re-serialized post_content.
	 * @phpstan-param list<int> $path
	 */
	public function splice( string $content, array $path, string $block_markup ): string;

	/**
	 * Merges a shallow attribute overlay into the block at a path and
	 * re-serializes the whole tree.
	 *
	 * A `null` value in `$attributes` removes the corresponding key from the
	 * block's `attrs`; any other value sets it; keys not present in
	 * `$attributes` are left untouched. The other blocks are byte-identical
	 * by construction, exactly as splice().
	 *
	 * @param string $content    Raw post_content to parse.
	 * @param array  $path       Zero-based indices into successive innerBlocks arrays. Must already
	 *                            have been confirmed to resolve to a non-freeform node via resolve().
	 * @param array  $attributes Shallow attrs overlay; a null value removes that key.
	 * @return string The whole re-serialized post_content.
	 * @phpstan-param list<int> $path
	 * @phpstan-param array<int|string, mixed> $attributes
	 */
	public function merge_attributes( string $content, array $path, array $attributes ): string;
}
