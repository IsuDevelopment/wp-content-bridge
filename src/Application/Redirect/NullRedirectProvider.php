<?php
/**
 * Null redirect provider.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;

/**
 * Keeps redirect Abilities operational, with explicit unavailable answers,
 * when neither Redirection nor Yoast Premium is active (ADR 0026 s4).
 */
final readonly class NullRedirectProvider implements RedirectProvider {

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
	 * @return RedirectProviderStatus
	 */
	public function status(): RedirectProviderStatus {
		return new RedirectProviderStatus( 'none', null, false, array() );
	}

	/**
	 * Fails closed rather than reporting a false "no existing redirect".
	 *
	 * @param RedirectSourcePath $source Exact source path.
	 * @throws RedirectProviderUnavailable Always.
	 */
	public function search( RedirectSourcePath $source ): never {
		unset( $source );

		throw new RedirectProviderUnavailable( 'No redirect provider is active.' );
	}

	/**
	 * A write against no provider is always rejected.
	 *
	 * @param RedirectRule $candidate Candidate rule.
	 * @throws RedirectProviderUnavailable Always.
	 */
	public function create( RedirectRule $candidate ): never {
		unset( $candidate );

		throw new RedirectProviderUnavailable( 'No redirect provider is active.' );
	}

	/**
	 * An update against no provider is always rejected.
	 *
	 * @param RedirectSourcePath $source      Source path.
	 * @param RedirectRule       $replacement Desired end state.
	 * @throws RedirectProviderUnavailable Always.
	 */
	public function update( RedirectSourcePath $source, RedirectRule $replacement ): never {
		unset( $source, $replacement );

		throw new RedirectProviderUnavailable( 'No redirect provider is active.' );
	}

	/**
	 * A removal against no provider is always rejected.
	 *
	 * @param RedirectSourcePath $source Source path.
	 * @throws RedirectProviderUnavailable Always.
	 */
	public function delete( RedirectSourcePath $source ): never {
		unset( $source );

		throw new RedirectProviderUnavailable( 'No redirect provider is active.' );
	}
}
