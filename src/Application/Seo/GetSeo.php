<?php
/**
 * Provider-neutral SEO read use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Seo;

use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Domain\Seo\SeoDocument;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;

/**
 * Authorizes one target before delegating to the selected provider.
 */
final readonly class GetSeo {

	/**
	 * Creates the read use case.
	 *
	 * @param SeoProviderRegistry $providers Provider selection.
	 * @param SeoTargetAccess     $access    Target authorization.
	 */
	public function __construct(
		private SeoProviderRegistry $providers,
		private SeoTargetAccess $access,
	) {
	}

	/**
	 * Reads one normalized SEO document.
	 *
	 * @param SeoTarget $target Validated selector.
	 * @return SeoDocument
	 * @throws ContentUnavailable When target visibility is denied.
	 */
	public function execute( SeoTarget $target ): SeoDocument {
		$readable_target = $this->access->readable_target( $target );
		if ( null === $readable_target ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		return $this->providers->active()->get( $readable_target );
	}
}
