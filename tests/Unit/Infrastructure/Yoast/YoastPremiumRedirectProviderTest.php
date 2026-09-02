<?php
/**
 * Yoast SEO Premium redirect provider tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\Yoast;

require_once __DIR__ . '/yoast-premium-runtime-stub.php';

use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderForbidden;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectRuleNotRepresentable;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastPremiumRedirectProvider;
use PHPUnit\Framework\TestCase;
use WPSEO_Redirect;
use WPSEO_Redirect_Manager;

/**
 * Covers the adapter against the Premium 28.0 behaviours read from source
 * (ADR 0026 amendment): slash-trimmed plain origins, `false` for a miss, no
 * capability check of Premium's own, and rules this plugin cannot represent.
 */
final class YoastPremiumRedirectProviderTest extends TestCase {

	private const SITE = 'https://example.com';

	/**
	 * Resets the stub store and grants the native capability.
	 */
	protected function setUp(): void {
		WPSEO_Redirect_Manager::$stored        = array();
		WPSEO_Redirect_Manager::$refuse_create = false;
		WPSEO_Redirect_Manager::$refuse_write  = false;
		WPSEO_Redirect_Manager::$saves         = 0;
		$GLOBALS['wpcb_test_capabilities']     = array( 'wpseo_manage_redirects' );
	}

	/**
	 * Clears the granted capabilities so no test leaks authority into another.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpcb_test_capabilities'] );
	}

	/**
	 * Premium's presence plus the exact called surface is what makes the
	 * provider available, and the reported version is its own.
	 */
	public function test_reports_itself_available_with_its_version(): void {
		$status = $this->provider()->status();

		self::assertTrue( $status->detected );
		self::assertSame( 'yoast-premium', $status->provider );
		self::assertSame( WPSEO_PREMIUM_VERSION, $status->version );
	}

	/**
	 * Premium stores a plain origin with both slashes trimmed, so the neutral
	 * `/old-page` and the stored `old-page` are the same rule. Getting this
	 * wrong would make every later search miss its own write.
	 */
	public function test_translates_between_neutral_and_stored_origin_forms(): void {
		self::assertSame( 'old-page', YoastPremiumRedirectProvider::to_provider_origin( '/old-page' ) );
		self::assertSame( '/old-page', YoastPremiumRedirectProvider::to_neutral_path( 'old-page' ) );
		self::assertSame( '/', YoastPremiumRedirectProvider::to_provider_origin( '/' ) );

		$provider = $this->provider();
		$provider->create( $this->candidate( '/old-page', '/new-page' ) );

		$found = $provider->search( new RedirectSourcePath( '/old-page' ) );

		self::assertNotNull( $found );
		self::assertSame( '/old-page', $found->source->value() );
		self::assertSame( '/new-page', $found->target?->value() );
		self::assertSame( 'yoast-premium', $found->provider->provider );
	}

	/**
	 * A create persists through the manager, which regenerates the derived
	 * export options the front-end matcher actually reads. Writing the
	 * canonical option alone would create a rule that never fires.
	 */
	public function test_a_create_persists_through_the_manager(): void {
		$this->provider()->create( $this->candidate( '/gone-page', '/here' ) );

		self::assertGreaterThan( 0, WPSEO_Redirect_Manager::$saves );
	}

	/**
	 * A miss is `false` from Premium, not null; the adapter must not return
	 * that object shape as if it were a rule.
	 */
	public function test_reports_no_rule_for_an_unclaimed_source(): void {
		self::assertNull( $this->provider()->search( new RedirectSourcePath( '/never-existed' ) ) );
	}

	/**
	 * Premium checks no capability of its own, so the adapter is the only
	 * gate on the native one.
	 */
	public function test_refuses_without_the_native_yoast_capability(): void {
		$GLOBALS['wpcb_test_capabilities'] = array( 'wpcb_manage_redirects' );

		$this->expectException( RedirectProviderForbidden::class );

		$this->provider()->search( new RedirectSourcePath( '/old-page' ) );
	}

	/**
	 * A regex rule can share an exact origin string with a plain one.
	 * Answering "no rule" would let the guard create a duplicate for a path
	 * Premium already claims, so this fails closed instead.
	 */
	public function test_fails_closed_on_a_regex_rule_rather_than_reporting_none(): void {
		WPSEO_Redirect_Manager::$stored['old-page'] = new WPSEO_Redirect( 'old-page', 'new-page', 301, 'regex' );

		$this->expectException( RedirectRuleNotRepresentable::class );

		$this->provider()->search( new RedirectSourcePath( '/old-page' ) );
	}

	/**
	 * Premium supports 307 and 451; the neutral allowlist does not. The rule
	 * still exists, so this is "cannot represent", never "not found".
	 */
	public function test_fails_closed_on_a_status_outside_the_neutral_allowlist(): void {
		WPSEO_Redirect_Manager::$stored['old-page'] = new WPSEO_Redirect( 'old-page', 'new-page', 307, 'plain' );

		$this->expectException( RedirectRuleNotRepresentable::class );

		$this->provider()->search( new RedirectSourcePath( '/old-page' ) );
	}

	/**
	 * Premium permits off-site targets; this plugin's contract does not.
	 */
	public function test_fails_closed_on_an_off_site_target(): void {
		WPSEO_Redirect_Manager::$stored['old-page'] = new WPSEO_Redirect( 'old-page', 'https://elsewhere.example/x', 301, 'plain' );

		$this->expectException( RedirectRuleNotRepresentable::class );

		$this->provider()->search( new RedirectSourcePath( '/old-page' ) );
	}

	/**
	 * A Gone rule has no target on either side of the boundary.
	 */
	public function test_maps_a_gone_rule_without_a_target(): void {
		WPSEO_Redirect_Manager::$stored['discontinued'] = new WPSEO_Redirect( 'discontinued', '', 410, 'plain' );

		$found = $this->provider()->search( new RedirectSourcePath( '/discontinued' ) );

		self::assertNotNull( $found );
		self::assertSame( RedirectStatusCode::GONE, $found->status );
		self::assertNull( $found->target );
	}

	/**
	 * A refused write is reported, never reported as success with no rule.
	 */
	public function test_reports_a_refused_create(): void {
		WPSEO_Redirect_Manager::$refuse_create = true;

		$this->expectException( RedirectProviderUnavailable::class );

		$this->provider()->create( $this->candidate( '/old-page', '/new-page' ) );
	}

	/**
	 * An update replaces target and status and persists through the manager,
	 * so the derived export options the front end reads are regenerated.
	 */
	public function test_updates_an_existing_rule_and_persists(): void {
		$provider = $this->provider();
		$provider->create( $this->candidate( '/old-page', '/first' ) );
		WPSEO_Redirect_Manager::$saves = 0;

		$updated = $provider->update(
			new RedirectSourcePath( '/old-page' ),
			$this->candidate( '/old-page', '/second' )
		);

		self::assertSame( '/second', $updated->target?->value() );
		self::assertGreaterThan( 0, WPSEO_Redirect_Manager::$saves );
		self::assertSame( '/second', $provider->search( new RedirectSourcePath( '/old-page' ) )?->target?->value() );
	}

	/**
	 * Updating a source Premium does not hold is reported, not silently
	 * turned into a create.
	 */
	public function test_refuses_to_update_a_rule_that_does_not_exist(): void {
		$this->expectException( RedirectProviderUnavailable::class );

		$this->provider()->update(
			new RedirectSourcePath( '/never-existed' ),
			$this->candidate( '/never-existed', '/somewhere' )
		);
	}

	/**
	 * A delete removes the rule, confirmed by reading back.
	 */
	public function test_deletes_an_existing_rule(): void {
		$provider = $this->provider();
		$provider->create( $this->candidate( '/old-page', '/new-page' ) );

		$provider->delete( new RedirectSourcePath( '/old-page' ) );

		self::assertNull( $provider->search( new RedirectSourcePath( '/old-page' ) ) );
	}

	/**
	 * Deleting a source Premium does not hold is reported rather than
	 * answered as success.
	 */
	public function test_refuses_to_delete_a_rule_that_does_not_exist(): void {
		$this->expectException( RedirectProviderUnavailable::class );

		$this->provider()->delete( new RedirectSourcePath( '/never-existed' ) );
	}

	/**
	 * Premium's `delete_redirects()` reports whether *any* removal happened,
	 * not whether this one did, so the adapter reads back and refuses to
	 * report success for a rule that is still there.
	 */
	public function test_reports_a_delete_that_did_not_persist(): void {
		$provider = $this->provider();
		$provider->create( $this->candidate( '/old-page', '/new-page' ) );
		WPSEO_Redirect_Manager::$refuse_write = true;

		$this->expectException( RedirectProviderUnavailable::class );

		$provider->delete( new RedirectSourcePath( '/old-page' ) );
	}

	/**
	 * Builds the adapter under test.
	 *
	 * @return YoastPremiumRedirectProvider
	 */
	private function provider(): YoastPremiumRedirectProvider {
		return new YoastPremiumRedirectProvider( self::SITE );
	}

	/**
	 * Builds a candidate rule.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return RedirectRule
	 */
	private function candidate( string $source, string $target ): RedirectRule {
		return new RedirectRule(
			null,
			new RedirectSourcePath( $source ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( self::SITE, $target ),
			true,
			new RedirectProviderStatus( 'yoast-premium', null, true, array() )
		);
	}
}
