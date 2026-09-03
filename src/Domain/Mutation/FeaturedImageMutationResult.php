<?php
/**
 * Result of one featured-image write.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

use IsuDev\WPContentBridge\Domain\Media\MediaItem;

/**
 * Carries the standard mutation envelope plus the effective assignment.
 */
final readonly class FeaturedImageMutationResult {

	/**
	 * Creates the result.
	 *
	 * @param MutationResult $mutation        Standard post mutation envelope.
	 * @param MediaItem|null $featured_image  Effective attachment re-read after the write, or null when removed.
	 */
	public function __construct(
		public MutationResult $mutation,
		public ?MediaItem $featured_image,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$document = $this->mutation->to_array();

		$document['featured_image'] = $this->featured_image?->to_array();

		return $document;
	}
}
