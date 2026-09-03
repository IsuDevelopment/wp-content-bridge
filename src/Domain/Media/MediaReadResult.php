<?php
/**
 * Result of reading one attachment.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Media;

use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Carries the attachment together with the token an edit must submit.
 *
 * The token lives here and not on `MediaItem` because `MediaItem` is also every
 * row of a media search, where a per-row token would be output nobody asked for
 * and a postmeta digest per result to produce it.
 */
final readonly class MediaReadResult {

	/**
	 * Creates the result.
	 *
	 * @param MediaItem    $media   Normalized attachment projection.
	 * @param VersionToken $version Current optimistic-concurrency token.
	 */
	public function __construct(
		public MediaItem $media,
		public VersionToken $version,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'item'          => $this->media->to_array(),
			'version_token' => $this->version->to_string(),
		);
	}
}
