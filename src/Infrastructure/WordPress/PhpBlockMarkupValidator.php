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

		foreach ( $blocks as $index => $block ) {
			$name = $block['blockName'];

			if ( null === $name ) {
				$inner = (string) $block['innerHTML'];
				if ( str_contains( $inner, '<!-- wp:' ) ) {
					$reasons[] = sprintf( 'Block %d: stray block delimiter in freeform content.', $index );
				}
				continue;
			}

			if ( ! $registry->is_registered( $name ) ) {
				$reasons[] = sprintf( 'Block %d: unregistered block type.', $index );
			}

			if ( count( $reasons ) >= self::MAX_REASONS ) {
				break;
			}
		}

		if ( count( $reasons ) < self::MAX_REASONS && ! $this->round_trips( $blocks ) ) {
			$reasons[] = 'Markup does not survive a parse/serialize round-trip.';
		}

		return array_slice( $reasons, 0, self::MAX_REASONS );
	}

	/**
	 * True when re-parsing the serialized blocks yields the same top-level name sequence.
	 *
	 * @param array<int|string, ParsedBlock> $blocks Parsed blocks.
	 */
	private function round_trips( array $blocks ): bool {
		$reparsed = parse_blocks( serialize_blocks( $blocks ) );

		return $this->names( $blocks ) === $this->names( $reparsed );
	}

	/**
	 * Extracts the top-level block-name sequence.
	 *
	 * @param array<int|string, ParsedBlock> $blocks Parsed blocks.
	 * @return list<string|null>
	 */
	private function names( array $blocks ): array {
		return array_values(
			array_map(
				static function ( array $block ): ?string {
					$name = $block['blockName'];
					return is_string( $name ) ? $name : null;
				},
				$blocks
			)
		);
	}
}
