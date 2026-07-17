<?php
/**
 * Compact content result.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Content;

/**
 * A bounded summary safe for search output.
 */
final readonly class ContentSummary {

	/**
	 * Creates a compact content result.
	 *
	 * @param int         $id           Object ID.
	 * @param string      $post_type    Post type.
	 * @param string      $status       Post status.
	 * @param string      $title        Display title.
	 * @param string      $slug         Object slug.
	 * @param string|null $url          Permalink when available.
	 * @param string      $excerpt      Normalized excerpt.
	 * @param int         $author_id    Author ID.
	 * @param string|null $published_at Publication time.
	 * @param string      $modified_at  Modification time.
	 */
	public function __construct(
		public int $id,
		public string $post_type,
		public string $status,
		public string $title,
		public string $slug,
		public ?string $url,
		public string $excerpt,
		public int $author_id,
		public ?string $published_at,
		public string $modified_at,
	) {
	}

	/**
	 * Serializes the result.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'post_type'    => $this->post_type,
			'status'       => $this->status,
			'title'        => $this->title,
			'slug'         => $this->slug,
			'url'          => $this->url,
			'excerpt'      => $this->excerpt,
			'author_id'    => $this->author_id,
			'published_at' => $this->published_at,
			'modified_at'  => $this->modified_at,
			'untrusted'    => true,
		);
	}
}
