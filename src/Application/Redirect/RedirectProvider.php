<?php
/**
 * Redirect provider port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;

/**
 * Provider-neutral contract implemented by optional redirect adapters
 * (Redirection, Yoast SEO Premium — ADR 0026). Never dual-written: exactly
 * one provider is active per `RedirectProviderRegistry` selection.
 */
interface RedirectProvider {

	/**
	 * Whether the provider dependency is currently available and its
	 * compatible surface was verified (ADR 0026 s2/s3).
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Returns safe provider identity and normalized capabilities.
	 *
	 * @return RedirectProviderStatus
	 */
	public function status(): RedirectProviderStatus;

	/**
	 * Returns the enabled rule for an exact source path, or null when none
	 * exists. Used for collision checks before create/update and for
	 * `get-redirect`/`search-redirects`.
	 *
	 * @param RedirectSourcePath $source Exact source path.
	 * @return RedirectRule|null
	 * @throws RedirectProviderUnavailable When the provider is unavailable.
	 */
	public function search( RedirectSourcePath $source ): ?RedirectRule;

	/**
	 * Creates a new redirect rule and returns it with its assigned identity.
	 *
	 * @param RedirectRule $candidate Rule with a null `id`.
	 * @return RedirectRule
	 * @throws RedirectProviderUnavailable When the provider is unavailable.
	 */
	public function create( RedirectRule $candidate ): RedirectRule;
}
