<?php
/**
 * WordPress slug normalizer.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\SlugNormalizer;

/**
 * Defers entirely to `sanitize_title()`, filters included.
 */
final class WordPressSlugNormalizer implements SlugNormalizer {

	/**
	 * Returns the normalized slug, or null when nothing usable remains.
	 *
	 * A slug of only punctuation normalizes to an empty string. That must be
	 * reported as unusable rather than stored: an empty `post_name` makes
	 * WordPress generate one from the title, so the caller would receive a URL
	 * it never requested, with no error to notice.
	 *
	 * @param string $requested Caller's raw slug.
	 * @return string|null
	 */
	public function normalize( string $requested ): ?string {
		$slug = sanitize_title( $requested );

		return '' === $slug ? null : $slug;
	}
}
