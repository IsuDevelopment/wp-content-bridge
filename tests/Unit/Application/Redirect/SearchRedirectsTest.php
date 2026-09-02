<?php
/**
 * Cross-provider redirect read tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Redirect\NullRedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderClaim;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderRegistry;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectRuleNotRepresentable;
use IsuDev\WPContentBridge\Application\Redirect\SearchRedirects;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use IsuDev\WPContentBridge\Tests\Support\RecordingRedirectProvider;
use IsuDev\WPContentBridge\Tests\Support\RefusingRedirectProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the per-provider read ADR 0026 s4 requires once a site can run two
 * redirect engines at once.
 */
final class SearchRedirectsTest extends TestCase {

	private const SITE = 'https://example.com';

	/**
	 * Two engines holding the same path is the routing hazard neither
	 * plugin's own screen shows, so it is reported explicitly.
	 */
	public function test_reports_a_path_held_by_two_engines(): void {
		$result = $this->search(
			array(
				$this->provider( 'redirection', array( '/old' => $this->rule( '/old', '/a' ) ) ),
				$this->provider( 'yoast-premium', array( '/old' => $this->rule( '/old', '/b' ) ) ),
			)
		);

		self::assertSame( array( 'redirection', 'yoast-premium' ), $result['held_by'] );
		self::assertTrue( $result['held_by_multiple'] );
	}

	/**
	 * One provider holding an unreadable rule must not blank out the other
	 * provider's perfectly readable answer, and must not be reported as free.
	 */
	public function test_one_unreadable_provider_does_not_hide_the_other(): void {
		$result = $this->search(
			array(
				$this->unreadable_provider( 'yoast-premium' ),
				$this->provider( 'redirection', array( '/old' => $this->rule( '/old', '/a' ) ) ),
			)
		);

		self::assertSame( RedirectProviderClaim::NOT_REPRESENTABLE, $result['claims'][0]['state'] );
		self::assertSame( RedirectProviderClaim::CLAIMED, $result['claims'][1]['state'] );
		self::assertSame( array( 'yoast-premium', 'redirection' ), $result['held_by'] );
	}

	/**
	 * A provider that cannot answer is reported as unavailable, never as
	 * holding nothing — a caller would read "free" as safe to write.
	 */
	public function test_an_unavailable_provider_is_not_reported_as_free(): void {
		$result = $this->search( array( $this->failing_provider( 'redirection' ) ) );

		self::assertSame( RedirectProviderClaim::UNAVAILABLE, $result['claims'][0]['state'] );
		self::assertSame( array(), $result['held_by'] );
	}

	/**
	 * With no provider active there are no claims, but the configured
	 * providers still appear, so "no provider" stays distinguishable from
	 * "no redirects".
	 */
	public function test_reports_configured_providers_when_none_is_available(): void {
		$result = $this->search( array( $this->provider( 'redirection', array(), false ) ) );

		self::assertSame( array(), $result['claims'] );
		self::assertSame( 'redirection', $result['configured_providers'][0]['provider'] );
		self::assertSame( 'none', $result['configured_providers'][1]['provider'] );
	}

	/**
	 * A malformed source path is refused by the domain, not passed to a
	 * provider.
	 */
	public function test_refuses_a_source_that_is_not_a_site_relative_path(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->search( array( $this->provider( 'redirection', array() ) ), 'https://elsewhere.example/x' );
	}

	/**
	 * Runs the use case.
	 *
	 * @param array  $providers Provider fakes.
	 * @param string $source    Source path to look up.
	 * @phpstan-param list<RedirectProvider> $providers
	 * @return array<string, mixed>
	 */
	private function search( array $providers, string $source = '/old' ): array {
		$registry = new RedirectProviderRegistry( $providers, new NullRedirectProvider() );

		return ( new SearchRedirects( $registry ) )->execute( array( 'source' => $source ) );
	}

	/**
	 * Builds a rule fixture.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return RedirectRule
	 */
	private function rule( string $source, string $target ): RedirectRule {
		return new RedirectRule(
			null,
			new RedirectSourcePath( $source ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( self::SITE, $target ),
			true,
			new RedirectProviderStatus( 'redirection', '1.0', true, array() )
		);
	}

	/**
	 * Builds a provider double over a fixed rule map.
	 *
	 * @param string                      $slug      Provider slug.
	 * @param array<string, RedirectRule> $existing  Rules keyed by source path.
	 * @param bool                        $available Availability flag.
	 * @return RedirectProvider
	 */
	private function provider( string $slug, array $existing, bool $available = true ): RedirectProvider {
		return new RecordingRedirectProvider( $slug, $existing, $available );
	}

	/**
	 * Builds a provider holding a rule outside the neutral contract.
	 *
	 * @param string $slug Provider slug.
	 * @return RedirectProvider
	 */
	private function unreadable_provider( string $slug ): RedirectProvider {
		return new RefusingRedirectProvider( $slug, RefusingRedirectProvider::NOT_REPRESENTABLE );
	}

	/**
	 * Builds a provider that reports available and then fails.
	 *
	 * @param string $slug Provider slug.
	 * @return RedirectProvider
	 */
	private function failing_provider( string $slug ): RedirectProvider {
		return new RefusingRedirectProvider( $slug, RefusingRedirectProvider::UNAVAILABLE );
	}
}
