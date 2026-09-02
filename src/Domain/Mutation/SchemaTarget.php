<?php
/**
 * Post identity a schema author needs before writing.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * The identity fields a JSON-LD document is normally built from.
 *
 * Authoring a schema document means filling in `name`, `url`, `datePublished`,
 * `dateModified` and `image` from the post itself. Without those fields on the
 * schema read, a caller has to fetch the post separately - which is what made a
 * single-page schema edit cost four round trips in practice.
 *
 * The excerpt is deliberately absent. `get_the_excerpt()` renders blocks when a
 * post has no manual excerpt, which is the one expensive read on this path, and
 * this whole projection exists to be cheap.
 */
final readonly class SchemaTarget {

	/**
	 * Creates the projection.
	 *
	 * @param string      $title              Display title.
	 * @param string      $slug               Post slug.
	 * @param string|null $url                Permalink when resolvable.
	 * @param string      $status             Post status.
	 * @param string|null $published_at       Publication time, or null when unpublished.
	 * @param string      $modified_at        Modification time.
	 * @param int|null    $featured_image_id  Authorized featured attachment ID.
	 * @param string|null $featured_image_url Authorized featured attachment URL.
	 * @throws InvalidArgumentException When only one featured-image member is populated.
	 */
	public function __construct(
		public string $title,
		public string $slug,
		public ?string $url,
		public string $status,
		public ?string $published_at,
		public string $modified_at,
		public ?int $featured_image_id = null,
		public ?string $featured_image_url = null,
	) {
		if ( ( null === $featured_image_id ) !== ( null === $featured_image_url ) ) {
			throw new InvalidArgumentException( 'Featured image ID and URL must be populated together.' );
		}
	}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'title'              => $this->title,
			'slug'               => $this->slug,
			'url'                => $this->url,
			'status'             => $this->status,
			'published_at'       => $this->published_at,
			'modified_at'        => $this->modified_at,
			'featured_image_id'  => $this->featured_image_id,
			'featured_image_url' => $this->featured_image_url,
		);
	}
}
