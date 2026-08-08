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

	/**
	 * Answers whether a target is `noindex`, independent of any other target
	 * resolved earlier in the same request.
	 *
	 * This is a separate method rather than a convenience for reading
	 * `get()->resolved['robots']` because that path goes through a provider's
	 * rendered *presentation*, and a provider is not required to make that
	 * presentation safe to compute for many targets within one request.
	 * Yoast's is not: `YoastSEO()->meta->for_post()` memoizes internal
	 * rendering context by static state that outlives the call, so resolving
	 * post B and then post A in the same request can return post B's `robots`
	 * (and other presentation fields) for post A too. Implementations of this
	 * method must answer from stored data instead, so the result does not
	 * depend on what else was resolved earlier in the request.
	 *
	 * @param SeoTarget $target Validated target.
	 * @return bool|null `true` when definitely noindex, `false` when definitely
	 *                    indexable, `null` when it cannot be determined.
	 */
	public function is_noindex( SeoTarget $target ): ?bool;
}
