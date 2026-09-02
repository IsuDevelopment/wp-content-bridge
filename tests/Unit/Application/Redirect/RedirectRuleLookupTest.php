<?php
/**
 * Cross-provider redirect rule lookup tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Redirect;

use IsuDev\WPContentBridge\Application\Redirect\RedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectRuleLookup;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use IsuDev\WPContentBridge\Tests\Support\RecordingRedirectProvider;
use IsuDev\WPContentBridge\Tests\Support\RefusingRedirectProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the cross-provider lookup ADR 0026 s4/s5 requires once a site can
 * run Redirection and Yoast Premium at the same time.
 */
final class RedirectRuleLookupTest extends TestCase {

	private const SITE = 'https://example.com';

	/**
	 * A path claimed by more than one plugin reports every claim, in registry
	 * order — that is the only signal an operator gets that two engines hold
	 * a rule for the same source.
	 */
	public function test_reports_every_provider_claiming_the_same_source(): void {
		$lookup = new RedirectRuleLookup(
			array(
				$this->provider( 'redirection', array( '/old' => $this->rule( '/old', '/a', 'redirection' ) ) ),
				$this->provider( 'yoast-premium', array( '/old' => $this->rule( '/old', '/b', 'yoast-premium' ) ) ),
			)
		);

		self::assertCount( 2, $lookup->find_all( new RedirectSourcePath( '/old' ) ) );
		self::assertSame( array( 'redirection', 'yoast-premium' ), $lookup->claimants( new RedirectSourcePath( '/old' ) ) );
	}

	/**
	 * The claim is attributed to the provider that answered, not to the
	 * `provider` field on the rule it returned. An adapter that forgets to
	 * stamp itself would otherwise make a collision name the wrong plugin,
	 * and that name is the operator's only clue where the conflict lives.
	 */
	public function test_attributes_a_claim_to_the_answering_provider_not_the_rule_stamp(): void {
		$mis_stamped = $this->rule( '/old', '/a', 'redirection' );
		$lookup      = new RedirectRuleLookup( array( $this->provider( 'yoast-premium', array( '/old' => $mis_stamped ) ) ) );

		self::assertSame( array( 'yoast-premium' ), $lookup->claimants( new RedirectSourcePath( '/old' ) ) );
	}

	/**
	 * No claim on an unclaimed path.
	 */
	public function test_reports_no_claim_for_an_unclaimed_path(): void {
		$lookup = new RedirectRuleLookup( array( $this->provider( 'redirection', array() ) ) );

		self::assertSame( array(), $lookup->claimants( new RedirectSourcePath( '/free' ) ) );
		self::assertNull( $lookup->first( new RedirectSourcePath( '/free' ) ) );
	}

	/**
	 * A provider that cannot answer must not be skipped. Silence here reads
	 * as "nobody claims this path", which is the one wrong conclusion: the
	 * guard would then allow a colliding rule.
	 */
	public function test_fails_closed_when_a_provider_cannot_answer(): void {
		$lookup = new RedirectRuleLookup(
			array(
				$this->provider( 'redirection', array() ),
				$this->unavailable_provider(),
			)
		);

		$this->expectException( RedirectProviderUnavailable::class );

		$lookup->claimants( new RedirectSourcePath( '/old' ) );
	}

	/**
	 * Builds a rule fixture.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @param string $slug   Provider slug stamped onto the rule.
	 * @return RedirectRule
	 */
	private function rule( string $source, string $target, string $slug ): RedirectRule {
		return new RedirectRule(
			null,
			new RedirectSourcePath( $source ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( self::SITE, $target ),
			true,
			new RedirectProviderStatus( $slug, '1.0', true, array() )
		);
	}

	/**
	 * Builds an available provider double over a fixed rule map.
	 *
	 * @param string                      $slug     Provider slug.
	 * @param array<string, RedirectRule> $existing Rules keyed by source path.
	 * @return RedirectProvider
	 */
	private function provider( string $slug, array $existing ): RedirectProvider {
		return new RecordingRedirectProvider( $slug, $existing );
	}

	/**
	 * Builds a provider that reports available and then fails, as a real
	 * adapter does when its dependency disappears mid-request.
	 *
	 * @return RedirectProvider
	 */
	private function unavailable_provider(): RedirectProvider {
		return new RefusingRedirectProvider( 'flaky', RefusingRedirectProvider::UNAVAILABLE );
	}
}
