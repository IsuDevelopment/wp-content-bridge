<?php
/**
 * Normalized media item.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Media;

use InvalidArgumentException;

/**
 * Immutable public attachment projection.
 */
final readonly class MediaItem {

	/**
	 * Creates a normalized media item.
	 *
	 * @param int    $id          Attachment ID.
	 * @param string $title       Display title.
	 * @param string $filename    Basename only.
	 * @param string $url         Public original URL.
	 * @param string $alt_text    Alternative text.
	 * @param string $caption     Caption.
	 * @param string $description Description.
	 * @param string $mime_type   MIME type.
	 * @throws InvalidArgumentException When stable identity is incomplete.
	 */
	public function __construct(
		public int $id,
		public string $title,
		public string $filename,
		public string $url,
		public string $alt_text,
		public string $caption,
		public string $description,
		public string $mime_type,
	) {
		if ( 1 > $id || '' === $filename || '' === $url || '' === $mime_type ) {
			throw new InvalidArgumentException( 'Media identity is incomplete.' );
		}
	}

	/**
	 * Serializes the bounded public projection.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'title'       => $this->title,
			'filename'    => $this->filename,
			'url'         => $this->url,
			'alt_text'    => $this->alt_text,
			'caption'     => $this->caption,
			'description' => $this->description,
			'mime_type'   => $this->mime_type,
		);
	}
}
