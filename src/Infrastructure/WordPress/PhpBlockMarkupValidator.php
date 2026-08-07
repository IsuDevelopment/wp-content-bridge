<?php
/**
 * Block-markup validator backed by WordPress core block parsing.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\BlockMarkupValidator;
use WP_Block_Type_Registry;

/**
 * Basic parse round-trip validation. Deeper per-attribute schema validation is
 * intentionally deferred to a later milestone.
 *
 * @phpstan-type ParsedBlock array{
 *     blockName: string|null,
 *     attrs: array<int|string, mixed>,
 *     innerBlocks: array<int|string, array<int|string, mixed>>,
 *     innerHTML: string,
 *     innerContent: array<int|string, mixed>,
 * }
 */
final class PhpBlockMarkupValidator implements BlockMarkupValidator {

	private const MAX_REASONS = 20;

	private const MAX_DEPTH = 64;

	/**
	 * Validates block markup.
	 *
	 * @param string $markup Raw Gutenberg block markup (may be empty).
	 * @return list<string> Bounded failure reasons; empty means valid.
	 */
	public function validate( string $markup ): array {
		if ( '' === trim( $markup ) ) {
			return array();
		}

		$reasons = array();
		$blocks  = parse_blocks( $markup );

		$meaningful = array_filter(
			$blocks,
			static function ( array $block ): bool {
				if ( null !== $block['blockName'] ) {
					return true;
				}

				return '' !== trim( (string) $block['innerHTML'] );
			}
		);

		if ( array() === $meaningful ) {
			$reasons[] = 'Markup contains no blocks.';
			return $reasons;
		}

		$registry = WP_Block_Type_Registry::get_instance();

		$this->validate_tree( $blocks, array(), $registry, $reasons );

		if ( count( $reasons ) < self::MAX_REASONS && ! $this->round_trips( $blocks ) ) {
			$reasons[] = 'Markup does not survive a parse/serialize round-trip.';
		}

		return array_slice( $reasons, 0, self::MAX_REASONS );
	}

	/**
	 * Walks a parsed block tree depth-first, recording bounded failure
	 * reasons for unregistered block types and stray delimiters in freeform
	 * content. Recurses into `innerBlocks` so a nested block is checked with
	 * the same rigor as a top-level one.
	 *
	 * `innerBlocks` is only known to be "an array of arrays shaped like this
	 * one" (see `parse_blocks()`), not recursively typed, so each sibling is
	 * narrowed defensively rather than assumed to be a `ParsedBlock`.
	 *
	 * @param array                  $blocks   Sibling blocks at this depth.
	 * @param array                  $path     Indices of ancestors, outermost first.
	 * @param WP_Block_Type_Registry $registry Block type registry.
	 * @param array                  $reasons  Accumulated failure reasons, by reference.
	 * @phpstan-param array<int|string, mixed> $blocks
	 * @phpstan-param list<int> $path
	 * @phpstan-param list<string> $reasons
	 */
	private function validate_tree( array $blocks, array $path, WP_Block_Type_Registry $registry, array &$reasons ): void {
		if ( count( $path ) >= self::MAX_DEPTH ) {
			if ( count( $reasons ) < self::MAX_REASONS ) {
				$reasons[] = sprintf( 'Block %s: exceeds maximum nesting depth.', $this->format_path( $path ) );
			}
			return;
		}

		foreach ( $blocks as $index => $block ) {
			if ( count( $reasons ) >= self::MAX_REASONS ) {
				return;
			}

			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_path = array_merge( $path, array( (int) $index ) );
			$name       = $block['blockName'] ?? null;

			if ( ! is_string( $name ) ) {
				$inner_html = $block['innerHTML'] ?? '';
				$inner      = is_string( $inner_html ) ? $inner_html : '';
				if ( str_contains( $inner, '<!-- wp:' ) ) {
					$reasons[] = sprintf( 'Block %s: stray block delimiter in freeform content.', $this->format_path( $block_path ) );
				}
				continue;
			}

			if ( ! $registry->is_registered( $name ) ) {
				$reasons[] = sprintf( 'Block %s: unregistered block type.', $this->format_path( $block_path ) );
			}

			if ( count( $reasons ) >= self::MAX_REASONS ) {
				return;
			}

			$inner_blocks = $block['innerBlocks'] ?? array();
			if ( is_array( $inner_blocks ) && array() !== $inner_blocks ) {
				$this->validate_tree( $inner_blocks, $block_path, $registry, $reasons );
			}
		}
	}

	/**
	 * Formats a tree path the way callers pass it back to path-addressed
	 * abilities, e.g. `[7,3,0]`.
	 *
	 * @param array $path Indices of ancestors, outermost first.
	 * @phpstan-param list<int> $path
	 */
	private function format_path( array $path ): string {
		return '[' . implode( ',', $path ) . ']';
	}

	/**
	 * Round-trips block markup through parsing and re-serialization only,
	 * without applying content filters that could mutate stored source.
	 *
	 * @param string $markup Raw Gutenberg block markup (may be empty).
	 * @return string The markup that would actually be stored.
	 */
	public function normalize( string $markup ): string {
		if ( '' === trim( $markup ) ) {
			return '';
		}

		return serialize_blocks( parse_blocks( $markup ) );
	}

	/**
	 * True when re-parsing the serialized blocks yields the same block-name
	 * tree, depth included.
	 *
	 * @param array<int|string, ParsedBlock> $blocks Parsed blocks.
	 */
	private function round_trips( array $blocks ): bool {
		$reparsed = parse_blocks( serialize_blocks( $blocks ) );

		return $this->names( $blocks ) === $this->names( $reparsed );
	}

	/**
	 * Extracts the full block-name tree: each node's own name alongside its
	 * inner blocks' names, recursively. Comparing two such trees for
	 * equality catches structural drift at any depth, not only the top
	 * level.
	 *
	 * @param array $blocks Parsed blocks.
	 * @phpstan-param array<int|string, mixed> $blocks
	 * @return list<array{name: string|null, inner: list<mixed>}>
	 */
	private function names( array $blocks ): array {
		return array_values(
			array_map(
				function ( $block ): array {
					if ( ! is_array( $block ) ) {
						return array(
							'name'  => null,
							'inner' => array(),
						);
					}

					$name         = $block['blockName'] ?? null;
					$inner_blocks = $block['innerBlocks'] ?? array();

					return array(
						'name'  => is_string( $name ) ? $name : null,
						'inner' => is_array( $inner_blocks ) ? $this->names( $inner_blocks ) : array(),
					);
				},
				$blocks
			)
		);
	}
}
