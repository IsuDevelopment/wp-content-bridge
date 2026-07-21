<?php
/**
 * Normalized block-pattern item.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Pattern;

use InvalidArgumentException;

/**
 * Immutable, allowlisted pattern projection.
 */
final readonly class BlockPatternItem {

	/**
	 * Creates one pattern item.
	 *
	 * @param string      $name           Namespaced pattern name.
	 * @param string      $pattern_namespace Pattern namespace.
	 * @param string      $title          Human-readable title.
	 * @param string      $description    Human-readable description.
	 * @param string|null $source         Registered source label.
	 * @param int|null    $viewport_width Preview width.
	 * @param bool        $inserter       Whether visible in the inserter.
	 * @param array       $categories        Category slugs.
	 * @param array       $keywords          Search keywords.
	 * @param array       $block_types       Contextual block types.
	 * @param array       $post_types        Allowed post types.
	 * @param array       $template_types    Allowed template types.
	 * @param string|null $content        Complete block markup when requested.
	 * @phpstan-param list<string> $categories
	 * @phpstan-param list<string> $keywords
	 * @phpstan-param list<string> $block_types
	 * @phpstan-param list<string> $post_types
	 * @phpstan-param list<string> $template_types
	 * @throws InvalidArgumentException When identity or payload metadata is invalid.
	 */
	public function __construct(
		public string $name,
		public string $pattern_namespace,
		public string $title,
		public string $description,
		public ?string $source,
		public ?int $viewport_width,
		public bool $inserter,
		public array $categories,
		public array $keywords,
		public array $block_types,
		public array $post_types,
		public array $template_types,
		public ?string $content,
	) {
		if ( '' === $name || '' === $pattern_namespace || ! str_starts_with( $name, $pattern_namespace . '/' ) ) {
			throw new InvalidArgumentException( 'Pattern identity is incomplete.' );
		}
		if ( null !== $viewport_width && 0 > $viewport_width ) {
			throw new InvalidArgumentException( 'Pattern viewport width is invalid.' );
		}
	}

	/**
	 * Returns the complete content size, or zero when content was omitted.
	 *
	 * @return int
	 */
	public function content_bytes(): int {
		return null === $this->content ? 0 : strlen( $this->content );
	}

	/**
	 * Serializes the public projection.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'name'           => $this->name,
			'namespace'      => $this->pattern_namespace,
			'title'          => $this->title,
			'description'    => $this->description,
			'source'         => $this->source,
			'viewport_width' => $this->viewport_width,
			'inserter'       => $this->inserter,
			'categories'     => $this->categories,
			'keywords'       => $this->keywords,
			'block_types'    => $this->block_types,
			'post_types'     => $this->post_types,
			'template_types' => $this->template_types,
			'content'        => $this->content,
			'content_bytes'  => $this->content_bytes(),
			'untrusted'      => true,
		);
	}
}
