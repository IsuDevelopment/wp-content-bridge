<?php
/**
 * Non-enumerating failure for an invalid or unreadable SEO social image.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Prevents attachment existence or authorization details from leaking.
 */
final class SeoImageUnavailable extends RuntimeException {

	/**
	 * Stable public error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_seo_image_unavailable';
	}
}
