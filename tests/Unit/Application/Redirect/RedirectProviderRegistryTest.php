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
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the registry never picks a write target on its own (ADR 0026
 * s4, amended 2026-09-01), that a named provider is either returned or
 * refused, and that reads span every available provider.
 */
final class RedirectProviderRegistryTest extends TestCase {

	/**
	 * There is deliberately no implicit selection. On a site running both
	 * plugins an ordered "first available" pick would silently choose a
	 * backend whose rule may not be the one that fires, so the accessor that
	 * did that no longer exists.
	 */
	public function test_has_no_implicit_active_provider_accessor(): void {
		self::assertFalse(
			method_exists( RedirectProviderRegistry::class, 'active' ),
			'Reintroducing an implicit active-provider accessor would restore the two-plugin defect ADR 0026 s4 was amended for.'
		);
	}

	/**
	 * Every available provider is returned, in registry order, because a read
	 * on a two-plugin site must show both backends' rules.
	 */
	public function test_available_returns_every_available_provider_in_order(): void {
		$registry = new RedirectProviderRegistry(
			array(
				$this->provider( 'yoast-premium', true ),
				$this->provider( 'unavailable-one', false ),
				$this->provider( 'redirection', true ),
			),
			new NullRedirectProvider()
		);

		$slugs = array_map(
			static fn ( RedirectProvider $provider ): string => $provider->status()->provider,
			$registry->available()
		);

		self::assertSame( array( 'yoast-premium', 'redirection' ), $slugs );
	}

	/**
	 * A write names its provider and gets exactly that one.
	 */
	public function test_select_returns_the_named_provider(): void {
		$registry = new RedirectProviderRegistry(
			array( $this->provider( 'yoast-premium', true ), $this->provider( 'redirection', true ) ),
			new NullRedirectProvider()
		);

		self::assertSame( 'redirection', $registry->select( 'redirection' )->status()->provider );
	}

	/**
	 * A write addressed to an unavailable provider is refused, never quietly
	 * redirected to the other one — the rule would land in a backend the
	 * caller did not choose.
	 */
	public function test_select_refuses_an_unavailable_provider_instead_of_substituting(): void {
		$registry = new RedirectProviderRegistry(
			array( $this->provider( 'yoast-premium', false ), $this->provider( 'redirection', true ) ),
			new NullRedirectProvider()
		);

		$this->expectException( RedirectProviderUnavailable::class );

		$registry->select( 'yoast-premium' );
	}

	/**
	 * With no compatible provider active, `available()` is empty and the null
	 * object appears in the reported statuses, so "no provider" stays
	 * distinguishable from "no redirects".
	 */
	public function test_reports_the_null_provider_when_none_is_available(): void {
		$registry = new RedirectProviderRegistry(
			array( $this->provider( 'redirection', false ) ),
			new NullRedirectProvider()
		);

		self::assertSame( array(), $registry->available() );

		$slugs = array_map(
			static fn ( RedirectProviderStatus $status ): string => $status->provider,
			$registry->statuses()
		);

		self::assertSame( array( 'redirection', 'none' ), $slugs );
	}

	/**
	 * The cross-provider lookup is built from the available providers only.
	 */
	public function test_lookup_spans_the_available_providers(): void {
		$registry = new RedirectProviderRegistry(
			array( $this->provider( 'yoast-premium', true ), $this->provider( 'redirection', false ) ),
			new NullRedirectProvider()
		);

		self::assertSame( array(), $registry->lookup()->find_all( new RedirectSourcePath( '/nothing' ) ) );
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
