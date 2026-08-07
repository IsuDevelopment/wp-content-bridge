<?php
/**
 * One flat, path-addressed block-tree node.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Content;

/**
 * A single `parse_blocks()` entry projected without its full markup.
 *
 * `block_name` is `null` for the freeform whitespace nodes the parser emits
 * between blocks. Those nodes occupy real indices in the array a later write
 * mutates and are represented, not skipped.
 */
final readonly class BlockTreeNode {

	/**
	 * Creates one node.
	 *
	 * @param array       $path          Zero-based indices into successive `innerBlocks` arrays.
	 * @param string|null $block_name    Registered block name, or null for a freeform node.
	 * @param int         $inner_blocks  Immediate child count.
	 * @param string|null $text          Bounded plain-text preview, from innerHTML or a prose-bearing attribute.
	 * @param string|null $text_source   Where text came from: `inner_html`, `attrs`, or null when text is null.
	 * @param array|null  $attrs         Raw block attributes when requested, or null when empty, not requested, or omitted.
	 * @param bool        $attrs_omitted Whether attrs was withheld for exceeding the size bound.
	 * @phpstan-param list<int> $path
	 * @phpstan-param array<string, mixed>|null $attrs
	 */
	public function __construct(
		public array $path,
		public ?string $block_name,
		public int $inner_blocks,
		public ?string $text,
		public ?string $text_source = null,
		public ?array $attrs = null,
		public bool $attrs_omitted = false,
	) {
	}

	/**
	 * Serializes the public projection.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$result = array(
			'path'         => $this->path,
			'block_name'   => $this->block_name,
			'inner_blocks' => $this->inner_blocks,
			'text'         => $this->text,
			'text_source'  => $this->text_source,
		);

		if ( null !== $this->attrs ) {
			$result['attrs'] = $this->attrs;
		}
		if ( $this->attrs_omitted ) {
			$result['attrs_omitted'] = true;
		}

		return $result;
	}
}
