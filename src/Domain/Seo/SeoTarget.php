<?php
/**
 * SEO lookup target.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Seo;

use InvalidArgumentException;

/**
 * Exactly one normalized selector for an SEO read.
 */
final readonly class SeoTarget {

	/**
	 * Creates a target internally.
	 *
	 * @param int|null    $post_id WordPress object ID.
	 * @param string|null $url     Same-site normalized URL.
	 */
	private function __construct(
		public ?int $post_id,
		public ?string $url,
	) {
	}

	/**
	 * Creates a post target.
	 *
	 * @param int $post_id WordPress object ID.
	 * @return self
	 * @throws InvalidArgumentException When the ID is invalid.
	 */
	public static function for_post( int $post_id ): self {
		if ( $post_id < 1 ) {
			throw new InvalidArgumentException( 'SEO post_id must be a positive integer.' );
		}

		return new self( $post_id, null );
	}

	/**
	 * Creates an already validated same-site URL target.
	 *
	 * @param string $url Normalized URL.
	 * @return self
	 * @throws InvalidArgumentException When the URL is empty.
	 */
	public static function for_url( string $url ): self {
		if ( '' === $url ) {
			throw new InvalidArgumentException( 'SEO URL must not be empty.' );
		}

		return new self( null, $url );
	}

	/**
	 * Serializes the selector.
	 *
	 * @return array<string, int|string>
	 */
	public function to_array(): array {
		return null !== $this->post_id
			? array( 'post_id' => $this->post_id )
			: array( 'url' => (string) $this->url );
	}
}
