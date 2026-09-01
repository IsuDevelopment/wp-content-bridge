<?php
/**
 * MCP projection discovery tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Adapter\Mcp;

require_once __DIR__ . '/wordpress-abilities-stub.php';

use IsuDev\WPContentBridge\Adapter\Abilities\AbilityCategory;
use IsuDev\WPContentBridge\Adapter\Mcp\McpServerProvider;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use PHPUnit\Framework\TestCase;
use WP_Ability;

/**
 * Locks the property that replaced the hand-maintained projection list: the
 * projected tool set is whatever this plugin registered, and a filter may only
 * subtract from it.
 */
final class McpServerProviderTest extends TestCase {

	/**
	 * Clears arranged registry, filter, and option state.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wpcb_test_abilities'] = array();
		$GLOBALS['wpcb_test_filters']   = array();
		$GLOBALS['wpcb_test_options']   = array();
	}

	/**
	 * Clears arranged registry, filter, and option state.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpcb_test_abilities'], $GLOBALS['wpcb_test_filters'], $GLOBALS['wpcb_test_options'] );

		parent::tearDown();
	}

	/**
	 * Every ability in this plugin's category is projected, and only those.
	 *
	 * This is also the fail-open assertion for declarative discovery. Since
	 * WordPress 7.1 `discover()` asks `wp_get_abilities()` to filter by
	 * category, but PHP silently ignores arguments a userland function does not
	 * declare — and this suite's stub declares none, so it hands back the whole
	 * registry exactly as a non-conforming WordPress would. The foreign ability
	 * below must still not be projected, which is only true while `discover()`
	 * keeps its own category comparison. Do not delete either half.
	 */
	public function test_projects_every_ability_in_this_plugins_category(): void {
		$this->arrange_registry(
			array(
				array( 'wp-content-bridge/update-block', AbilityCategory::SLUG ),
				array( 'wp-content-bridge/get-content', AbilityCategory::SLUG ),
				array( 'other-plugin/delete-everything', 'other-plugin' ),
			)
		);

		self::assertSame(
			array( 'wp-content-bridge/get-content', 'wp-content-bridge/update-block' ),
			McpServerProvider::abilities()
		);
	}

	/**
	 * A newly registered ability needs no configuration to be projected. This is
	 * the regression the hand-maintained profile could not hold: adding an
	 * ability used to require editing a separate name list.
	 */
	public function test_a_new_ability_is_projected_without_configuration(): void {
		$this->arrange_registry( array( array( 'wp-content-bridge/get-content', AbilityCategory::SLUG ) ) );

		$before = McpServerProvider::abilities();

		$this->arrange_registry(
			array(
				array( 'wp-content-bridge/get-content', AbilityCategory::SLUG ),
				array( 'wp-content-bridge/brand-new-intent', AbilityCategory::SLUG ),
			)
		);

		self::assertSame( array( 'wp-content-bridge/get-content' ), $before );
		self::assertContains( 'wp-content-bridge/brand-new-intent', McpServerProvider::abilities() );
	}

	/**
	 * A site filter may narrow the projection.
	 */
	public function test_filter_may_narrow_the_projection(): void {
		$this->arrange_registry(
			array(
				array( 'wp-content-bridge/get-content', AbilityCategory::SLUG ),
				array( 'wp-content-bridge/update-content', AbilityCategory::SLUG ),
			)
		);

		$this->arrange_filter(
			static fn ( array $names ): array => array_values(
				array_filter( $names, static fn ( string $name ): bool => 'wp-content-bridge/update-content' !== $name )
			)
		);

		self::assertSame( array( 'wp-content-bridge/get-content' ), McpServerProvider::abilities() );
	}

	/**
	 * A filter can never add an ability this plugin did not register, so no
	 * unrelated plugin's tools can enter this server.
	 */
	public function test_filter_cannot_widen_the_projection(): void {
		$this->arrange_registry(
			array(
				array( 'wp-content-bridge/get-content', AbilityCategory::SLUG ),
				array( 'other-plugin/run-sql', 'other-plugin' ),
			)
		);

		$this->arrange_filter(
			static fn ( array $names ): array => array_merge( $names, array( 'other-plugin/run-sql', 'core/execute-php' ) )
		);

		self::assertSame( array( 'wp-content-bridge/get-content' ), McpServerProvider::abilities() );
	}

	/**
	 * A filter returning a non-array degrades to the discovered set rather than
	 * silently emptying the server.
	 */
	public function test_invalid_filter_return_falls_back_to_discovery(): void {
		$this->arrange_registry( array( array( 'wp-content-bridge/get-content', AbilityCategory::SLUG ) ) );
		$this->arrange_filter( static fn ( array $names ): string => implode( ',', $names ) );

		self::assertSame( array( 'wp-content-bridge/get-content' ), McpServerProvider::abilities() );
	}

	/**
	 * An absent option row projects; an explicit false does not.
	 */
	public function test_projection_defaults_to_enabled(): void {
		self::assertTrue( McpServerProvider::is_enabled() );

		$GLOBALS['wpcb_test_options'] = array( Installer::MCP_SERVER_ENABLED_OPTION => false );

		self::assertFalse( McpServerProvider::is_enabled() );
	}

	/**
	 * Diagnostics report the discovered names, so a missing tool can be told
	 * from a missing ability.
	 */
	public function test_projection_status_reports_discovery(): void {
		$this->arrange_registry( array( array( 'wp-content-bridge/get-content', AbilityCategory::SLUG ) ) );

		$status = McpServerProvider::projection_status();

		self::assertTrue( $status['enabled'] );
		self::assertSame( array( 'wp-content-bridge/get-content' ), $status['projected_abilities'] );
	}

	/**
	 * A disabled switch reports no endpoint and no projected abilities, however
	 * many are registered and whatever the adapter is doing.
	 */
	public function test_projection_status_is_empty_while_disabled(): void {
		$this->arrange_registry( array( array( 'wp-content-bridge/get-content', AbilityCategory::SLUG ) ) );
		$GLOBALS['wpcb_test_options'] = array( Installer::MCP_SERVER_ENABLED_OPTION => false );

		$status = McpServerProvider::projection_status();

		self::assertFalse( $status['enabled'] );
		self::assertNull( $status['endpoint'] );
		self::assertSame( array(), $status['projected_abilities'] );
	}

	/**
	 * Arranges the ability registry.
	 *
	 * @param list<array{0: string, 1: string}> $abilities Name/category pairs.
	 * @return void
	 */
	private function arrange_registry( array $abilities ): void {
		$GLOBALS['wpcb_test_abilities'] = array_map(
			static fn ( array $ability ): WP_Ability => new WP_Ability( $ability[0], $ability[1] ),
			$abilities
		);
	}

	/**
	 * Arranges the projection filter.
	 *
	 * @param callable $callback Filter callback.
	 * @return void
	 */
	private function arrange_filter( callable $callback ): void {
		$GLOBALS['wpcb_test_filters'] = array( McpServerProvider::ABILITIES_FILTER => $callback );
	}
}
