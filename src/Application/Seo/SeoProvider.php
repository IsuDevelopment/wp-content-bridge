<?php
/**
 * SEO provider port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Seo;

use IsuDev\WPContentBridge\Domain\Seo\SeoDocument;
use IsuDev\WPContentBridge\Domain\Seo\SeoProviderStatus;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;

/**
 * Provider-neutral read contract implemented by optional SEO adapters.
 */
interface SeoProvider {

	/**
	 * Whether the provider dependency is currently available.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Returns safe provider identity and normalized capabilities.
	 *
	 * @return SeoProviderStatus
	 */
	public function status(): SeoProviderStatus;

	/**
	 * Reads one normalized SEO target.
	 *
	 * @param SeoTarget $target Validated target.
	 * @return SeoDocument
	 */
	public function get( SeoTarget $target ): SeoDocument;
}
