<?php
/**
 * Unit tests for the permalink result wire shape.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\PermalinkMutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;

/**
 * Covers the old-URL cache reporting ADR 0032 added, whose whole purpose is
 * that a caller must not read a successful rename as a cold old URL.
 */
final class PermalinkMutationResultTest extends TestCase {

	/**
	 * The bounded set is exactly the old and new URL. Anything wider would be
	 * a guess at the site's template graph.
	 */
	public function test_the_cache_set_is_exactly_the_old_and_new_url(): void {
		$cache = $this->permalink_result( array( 'litespeed-cache', 'wp-content-bridge' ) )->to_array()['permalink']['cache'];

		self::assertSame(
			array( 'https://example.com/old/', 'https://example.com/new/' ),
			$cache['urls']
		);
	}

	/**
	 * A real cache channel having been notified is reported as not delegated.
	 */
	public function test_a_bound_cache_channel_is_not_delegated(): void {
		$cache = $this->permalink_result( array( 'litespeed-cache', 'wp-content-bridge' ) )->to_array()['permalink']['cache'];

		self::assertFalse( $cache['delegated'] );
		self::assertContains( 'litespeed-cache', $cache['notified'] );
	}

	/**
	 * With only this plugin's own action available, the purge depends on
	 * site-level glue - so the result says so rather than implying the old
	 * URL is now cold.
	 */
	public function test_only_the_plugins_own_action_reports_delegated(): void {
		$cache = $this->permalink_result( array( 'wp-content-bridge' ) )->to_array()['permalink']['cache'];

		self::assertTrue( $cache['delegated'] );
	}

	/**
	 * Nothing notified at all is also delegated, never a silent success.
	 */
	public function test_no_channel_reports_delegated(): void {
		$cache = $this->permalink_result( array() )->to_array()['permalink']['cache'];

		self::assertTrue( $cache['delegated'] );
		self::assertSame( array(), $cache['notified'] );
	}

	/**
	 * The URLs a caller needs for a redirect are unaffected by the addition.
	 */
	public function test_both_urls_are_still_reported_for_a_redirect(): void {
		$permalink = $this->permalink_result( array() )->to_array()['permalink'];

		self::assertSame( 'https://example.com/old/', $permalink['previous_url'] );
		self::assertSame( 'https://example.com/new/', $permalink['url'] );
	}

	/**
	 * Builds a result with the given notified channels.
	 *
	 * @param array $channels Cache channels notified.
	 * @phpstan-param list<string> $channels
	 * @return PermalinkMutationResult
	 */
	private function permalink_result( array $channels ): PermalinkMutationResult {
		return new PermalinkMutationResult(
			new MutationResult(
				42,
				'page',
				'publish',
				new VersionToken( 'abcdef0123456789', '2026-07-20 12:30:00' ),
				array( 'slug' ),
				false
			),
			array(
				'slug' => 'old',
				'url'  => 'https://example.com/old/',
			),
			array(
				'slug' => 'new',
				'url'  => 'https://example.com/new/',
			),
			$channels
		);
	}
}
