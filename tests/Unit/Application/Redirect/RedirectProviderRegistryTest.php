<?php
/**
 * Redirect provider registry tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Redirect;

use IsuDev\WPContentBridge\Application\Redirect\NullRedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderRegistry;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use PHPUnit\Framework\TestCase;

/**
 * Verifies explicit provider priority (ADR 0026 s1: Yoast Premium first,
 * Redirection second, never both) and the no-plugin fallback.
 */
final class RedirectProviderRegistryTest extends TestCase {

	/**
	 * The first available configured provider wins; order is explicit
	 * priority, not availability order discovered at runtime.
	 */
	public function test_selects_first_available_provider_in_configured_order(): void {
		$unavailable = $this->provider( 'yoast-premium', false );
		$available   = $this->provider( 'redirection', true );
		$registry    = new RedirectProviderRegistry( array( $unavailable, $available ), new NullRedirectProvider() );

		self::assertSame( 'redirection', $registry->active()->status()->provider );
	}

	/**
	 * An earlier, available provider wins over a later one, even if the later
	 * one is also available — this is priority, not first-match-by-chance.
	 */
	public function test_earlier_available_provider_takes_priority(): void {
		$first    = $this->provider( 'yoast-premium', true );
		$second   = $this->provider( 'redirection', true );
		$registry = new RedirectProviderRegistry( array( $first, $second ), new NullRedirectProvider() );

		self::assertSame( 'yoast-premium', $registry->active()->status()->provider );
	}

	/**
	 * With no compatible provider active, the registry falls back to the null
	 * object rather than returning null or throwing.
	 */
	public function test_falls_back_to_null_provider_when_none_available(): void {
		$registry = new RedirectProviderRegistry( array( $this->provider( 'redirection', false ) ), new NullRedirectProvider() );

		self::assertSame( 'none', $registry->active()->status()->provider );
		self::assertFalse( $registry->active()->is_available() );
	}

	/**
	 * Creates a provider fake with deterministic availability.
	 *
	 * @param string $name      Provider slug.
	 * @param bool   $available Availability flag.
	 * @return RedirectProvider
	 */
	private function provider( string $name, bool $available ): RedirectProvider {
		return new class( $name, $available ) implements RedirectProvider {

			/**
			 * Creates the fake.
			 *
			 * @param string $name      Provider slug.
			 * @param bool   $available Availability flag.
			 */
			public function __construct( private string $name, private bool $available ) {
			}

			/**
			 * Returns configured availability.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Returns fake status.
			 *
			 * @return RedirectProviderStatus
			 */
			public function status(): RedirectProviderStatus {
				return new RedirectProviderStatus( $this->name, '1.0', $this->available, array() );
			}

			/**
			 * Never used by any test that exercises a real search result.
			 *
			 * @param RedirectSourcePath $source Unused source.
			 * @return RedirectRule|null
			 */
			public function search( RedirectSourcePath $source ): ?RedirectRule {
				unset( $source );

				return null;
			}

			/**
			 * Never used by any test that exercises a real create result.
			 *
			 * @param RedirectRule $candidate Candidate rule.
			 * @return RedirectRule
			 */
			public function create( RedirectRule $candidate ): RedirectRule {
				return $candidate;
			}
		};
	}
}
