<?php
/**
 * Null SEO provider.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Seo;

use IsuDev\WPContentBridge\Domain\Seo\SeoCompleteness;
use IsuDev\WPContentBridge\Domain\Seo\SeoDocument;
use IsuDev\WPContentBridge\Domain\Seo\SeoProviderStatus;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;

/**
 * Keeps content features operational when no SEO plugin is active.
 */
final readonly class NullSeoProvider implements SeoProvider {

	/**
	 * The null object is a fallback, not a detected provider.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return false;
	}

	/**
	 * Returns explicit no-provider diagnostics.
	 *
	 * @return SeoProviderStatus
	 */
	public function status(): SeoProviderStatus {
		return new SeoProviderStatus( 'none', null, false, array(), array() );
	}

	/**
	 * Returns a valid unavailable document instead of throwing.
	 *
	 * @param SeoTarget $target Validated target.
	 * @return SeoDocument
	 */
	public function get( SeoTarget $target ): SeoDocument {
		unset( $target );

		return new SeoDocument(
			array(),
			array(),
			array(),
			array(),
			$this->status(),
			SeoCompleteness::UNAVAILABLE,
			array( 'No supported SEO provider is active.' )
		);
	}
}
