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
 * (Redirection, Yoast SEO Premium — ADR 0026).
 *
 * Never dual-written: a write goes to exactly one provider the caller names
 * (`RedirectProviderRegistry::select()`), and a provider error never triggers
 * a fallback write to the other. Reads, and the candidate guard, span every
 * available provider, because a site running both plugins has two live
 * redirect engines and whichever hooks first wins.
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
	 * @throws RedirectProviderForbidden When the backend's own capability is missing.
	 * @throws RedirectRuleNotRepresentable When a rule exists but falls outside
	 *                                       this contract. Never answered as
	 *                                       `null`: the path is taken, and a
	 *                                       null would let a caller create a
	 *                                       second rule for it.
	 */
	public function search( RedirectSourcePath $source ): ?RedirectRule;

	/**
	 * Creates a new redirect rule and returns it with its assigned identity.
	 *
	 * @param RedirectRule $candidate Rule with a null `id`.
	 * @return RedirectRule
	 * @throws RedirectProviderUnavailable When the provider is unavailable or the write did not persist.
	 * @throws RedirectProviderForbidden When the backend's own capability is missing.
	 * @throws RedirectRuleNotRepresentable When the stored rule cannot be read back under this contract.
	 */
	public function create( RedirectRule $candidate ): RedirectRule;

	/**
	 * Replaces the target and status of the rule answering an exact source
	 * path, and returns it as the provider stored it.
	 *
	 * The source is the identity, not a provider row ID: this contract only
	 * ever addresses exact paths, and a caller that had to carry a provider's
	 * internal ID would break the moment the rule moved between backends.
	 *
	 * @param RedirectSourcePath $source      Source path of the rule to change.
	 * @param RedirectRule       $replacement Desired end state for that source.
	 * @return RedirectRule
	 * @throws RedirectProviderUnavailable When the provider is unavailable, holds no such rule, or the write did not persist.
	 * @throws RedirectProviderForbidden When the backend's own capability is missing.
	 * @throws RedirectRuleNotRepresentable When the stored rule cannot be read back under this contract.
	 */
	public function update( RedirectSourcePath $source, RedirectRule $replacement ): RedirectRule;

	/**
	 * Removes the rule answering an exact source path.
	 *
	 * Removal, not disabling: Yoast Premium stores no per-rule enabled flag,
	 * so a rule it holds is always live and "disable" cannot mean the same
	 * thing in both backends. One operation that means the same everywhere is
	 * worth more than two that quietly differ.
	 *
	 * @param RedirectSourcePath $source Source path of the rule to remove.
	 * @return void
	 * @throws RedirectProviderUnavailable When the provider is unavailable, holds no such rule, or the removal did not persist.
	 * @throws RedirectProviderForbidden When the backend's own capability is missing.
	 */
	public function delete( RedirectSourcePath $source ): void;
}
