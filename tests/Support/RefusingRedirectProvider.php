<?php
/**
 * Refusing redirect provider test double.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Support;

use IsuDev\WPContentBridge\Application\Redirect\RedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderForbidden;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectRuleNotRepresentable;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use RuntimeException;

/**
 * Reports itself available and then refuses every call in one chosen way.
 *
 * Models the two states that must never be mistaken for "this provider holds
 * nothing": a provider whose dependency disappeared mid-request, and one
 * holding a rule this plugin's contract cannot express.
 */
final readonly class RefusingRedirectProvider implements RedirectProvider {

	public const UNAVAILABLE       = 'unavailable';
	public const NOT_REPRESENTABLE = 'not_representable';
	public const FORBIDDEN         = 'forbidden';

	/**
	 * Creates the double.
	 *
	 * @param string $slug Provider slug.
	 * @param string $mode One of the mode constants.
	 */
	public function __construct(
		private string $slug,
		private string $mode = self::UNAVAILABLE,
	) {
	}

	/**
	 * Reported available: the refusal has to come from the call, not from
	 * availability, or the registry would simply skip this provider.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Returns fake status.
	 *
	 * @return RedirectProviderStatus
	 */
	public function status(): RedirectProviderStatus {
		return new RedirectProviderStatus( $this->slug, '1.0', true, array() );
	}

	/**
	 * Refuses.
	 *
	 * @param RedirectSourcePath $source Unused source.
	 * @throws RuntimeException Always, per the configured mode.
	 */
	public function search( RedirectSourcePath $source ): never {
		unset( $source );

		throw $this->refusal();
	}

	/**
	 * Refuses.
	 *
	 * @param RedirectRule $candidate Unused candidate.
	 * @throws RuntimeException Always, per the configured mode.
	 */
	public function create( RedirectRule $candidate ): never {
		unset( $candidate );

		throw $this->refusal();
	}

	/**
	 * Refuses.
	 *
	 * @param RedirectSourcePath $source      Unused source.
	 * @param RedirectRule       $replacement Unused replacement.
	 * @throws RuntimeException Always, per the configured mode.
	 */
	public function update( RedirectSourcePath $source, RedirectRule $replacement ): never {
		unset( $source, $replacement );

		throw $this->refusal();
	}

	/**
	 * Refuses.
	 *
	 * @param RedirectSourcePath $source Unused source.
	 * @throws RuntimeException Always, per the configured mode.
	 */
	public function delete( RedirectSourcePath $source ): never {
		unset( $source );

		throw $this->refusal();
	}

	/**
	 * Builds the configured refusal.
	 *
	 * @return RuntimeException
	 */
	private function refusal(): RuntimeException {
		return match ( $this->mode ) {
			self::NOT_REPRESENTABLE => new RedirectRuleNotRepresentable( 'regex rule' ),
			self::FORBIDDEN => new RedirectProviderForbidden( 'missing backend capability' ),
			default => new RedirectProviderUnavailable( 'gone mid-request' ),
		};
	}
}
