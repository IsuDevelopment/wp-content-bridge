<?php
/**
 * Resolves authorized WordPress image attachments for SEO social overrides.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Keeps WordPress attachment lookup outside the Yoast adapter.
 */
interface SeoImageRepository {

	/**
	 * Returns the public URL for one readable image attachment.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return string|null Null when the object is absent, unreadable, not an image, or has no URL.
	 */
	public function image_url( int $attachment_id ): ?string;
}
