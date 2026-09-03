<?php
/**
 * Result of one attachment-metadata edit.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Media;

use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Carries the effective attachment and the token a chained edit must use.
 */
final readonly class MediaMetadataResult {

	/**
	 * Creates the result.
	 *
	 * @param MediaItem    $media          Attachment re-read after the write.
	 * @param VersionToken $version        Token after the write.
	 * @param array        $changed_fields Field names only.
	 * @phpstan-param list<string> $changed_fields
	 */
	public function __construct(
		public MediaItem $media,
		public VersionToken $version,
		public array $changed_fields,
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
			'version_token'  => $this->version->to_string(),
			'changed_fields' => array_values( $this->changed_fields ),
			'provenance'     => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
		);
	}
}
