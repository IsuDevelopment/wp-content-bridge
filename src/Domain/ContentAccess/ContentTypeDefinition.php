<?php
/**
 * Content-type definition.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\ContentAccess;

/**
 * Transport-neutral description of a registered content type.
 */
final readonly class ContentTypeDefinition {

	/**
	 * Creates a content-type descriptor.
	 *
	 * @param string $name         Machine name.
	 * @param string $label        Human-readable singular label.
	 * @param bool   $is_public    Whether the type is public.
	 * @param bool   $show_in_rest Whether the type is REST-exposed.
	 * @param bool   $built_in     Whether WordPress Core registered the type.
	 */
	public function __construct(
		public string $name,
		public string $label,
		public bool $is_public,
		public bool $show_in_rest,
		public bool $built_in,
	) {
	}
}
