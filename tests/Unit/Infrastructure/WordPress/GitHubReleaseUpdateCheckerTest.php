<?php
/**
 * Unit tests for the GitHub release update-checker policy.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Infrastructure\WordPress\GitHubReleaseUpdateChecker;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that self-updates run only in safe packaged-install contexts.
 */
final class GitHubReleaseUpdateCheckerTest extends TestCase {

	/**
	 * An admin request in a packaged install may register the updater.
	 */
	public function test_allows_packaged_admin_request(): void {
		self::assertTrue( GitHubReleaseUpdateChecker::should_register( true, false, true, false, false, true ) );
	}

	/**
	 * WordPress cron may register the updater outside wp-admin.
	 */
	public function test_allows_cron_request(): void {
		self::assertTrue( GitHubReleaseUpdateChecker::should_register( false, true, true, false, false, true ) );
	}

	/**
	 * Front-end traffic does not initialize remote update checks.
	 */
	public function test_rejects_front_end_request(): void {
		self::assertFalse( GitHubReleaseUpdateChecker::should_register( false, false, true, false, false, true ) );
	}

	/**
	 * A Git source checkout cannot be overwritten by the WordPress updater.
	 */
	public function test_rejects_source_checkout(): void {
		self::assertFalse( GitHubReleaseUpdateChecker::should_register( true, false, true, true, false, true ) );
	}

	/**
	 * Missing production dependencies fail closed.
	 */
	public function test_rejects_missing_update_checker_factory(): void {
		self::assertFalse( GitHubReleaseUpdateChecker::should_register( true, false, false, false, false, true ) );
	}

	/**
	 * Both explicit site-level opt-out mechanisms are authoritative.
	 */
	public function test_rejects_constant_or_filter_opt_out(): void {
		self::assertFalse( GitHubReleaseUpdateChecker::should_register( true, false, true, false, true, true ) );
		self::assertFalse( GitHubReleaseUpdateChecker::should_register( true, false, true, false, false, false ) );
	}
}
