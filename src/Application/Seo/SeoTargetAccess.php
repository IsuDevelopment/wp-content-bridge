<?php
/**
 * SEO target authorization port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Seo;

use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;

/**
 * Applies content-policy and native-object authorization to an SEO selector.
 */
interface SeoTargetAccess {

	/**
	 * Authorizes and canonicalizes a target for provider access.
	 *
	 * @param SeoTarget $target Validated target.
	 * @return SeoTarget|null Authorized target, or null on denial.
	 */
	public function readable_target( SeoTarget $target ): ?SeoTarget;
}
