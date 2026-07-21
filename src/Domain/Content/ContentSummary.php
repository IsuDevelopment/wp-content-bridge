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
	 * @param int|null    $featured_image_id  Authorized featured attachment ID.
	 * @param string|null $featured_image_url Authorized featured attachment URL.
	 * @throws \InvalidArgumentException When only one featured-image identity member is populated.
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
		public ?int $featured_image_id = null,
		public ?string $featured_image_url = null,
	) {
		if ( ( null === $featured_image_id ) !== ( null === $featured_image_url ) ) {
			throw new \InvalidArgumentException( 'Featured image ID and URL must be populated together.' );
		}
	}

	/**
	 * Serializes the result.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                 => $this->id,
			'post_type'          => $this->post_type,
			'status'             => $this->status,
			'title'              => $this->title,
			'slug'               => $this->slug,
			'url'                => $this->url,
			'excerpt'            => $this->excerpt,
			'author_id'          => $this->author_id,
			'published_at'       => $this->published_at,
			'modified_at'        => $this->modified_at,
			'featured_image_id'  => $this->featured_image_id,
			'featured_image_url' => $this->featured_image_url,
			'untrusted'          => true,
		);
	}
}
