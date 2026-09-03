<?php
/**
 * Result of one media import.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Media;

/**
 * Carries the stored attachment and whether this call created it.
 */
final readonly class MediaUploadResult {

	/**
	 * Creates the result.
	 *
	 * @param MediaItem $media   Stored attachment, re-read after the write.
	 * @param bool      $created False when an idempotency key replayed an earlier call.
	 */
	public function __construct(
		public MediaItem $media,
		public bool $created,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => '1.0',
			'media'          => $this->media->to_array(),
			'created'        => $this->created,
			'provenance'     => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
		);
	}
}
