<?php
/**
 * Unit tests for the WordPress-free logic in the 404-statistics adapter.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\Redirection;

use DateTimeImmutable;
use DateTimeZone;
use IsuDev\WPContentBridge\Application\Statistics\NotFoundStatisticsQuery;
use IsuDev\WPContentBridge\Infrastructure\Redirection\RedirectionErrorStatisticsProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers retention truncation (ADR 0030 s4), the one piece of this adapter
 * that is pure arithmetic. The table read, the schema probe, and the
 * provider-capability resolution all need a live WordPress and Redirection
 * install, so they are exercised by runtime verification instead.
 */
final class RedirectionErrorStatisticsProviderTest extends TestCase {

	private const NOW = '2026-09-03T12:00:00Z';

	/**
	 * A range that fits inside retention is reported as asked for.
	 */
	public function test_a_range_inside_retention_is_not_truncated(): void {
		$window = RedirectionErrorStatisticsProvider::window( $this->query( '2026-08-30T12:00:00Z' ), 7 );

		self::assertFalse( $window->truncated );
		self::assertSame( '2026-08-30T12:00:00Z', $window->effective_since );
		self::assertSame( 7, $window->retention_days );
	}

	/**
	 * A range older than retention returns less than was asked for, because
	 * the provider's own cron already deleted the rest. Reported silently, a
	 * monitoring caller would read the missing rows as 404s that stopped
	 * happening - so the boundary actually used is reported too.
	 */
	public function test_a_range_older_than_retention_is_truncated_and_says_so(): void {
		$window = RedirectionErrorStatisticsProvider::window( $this->query( '2026-06-01T00:00:00Z' ), 7 );

		self::assertTrue( $window->truncated );
		self::assertSame( '2026-06-01T00:00:00Z', $window->requested_since );
		self::assertSame( '2026-08-27T12:00:00Z', $window->effective_since );
	}

	/**
	 * Retention of zero means the provider prunes nothing, so no range can be
	 * truncated and the window has no outer bound to report.
	 */
	public function test_unlimited_retention_never_truncates(): void {
		$window = RedirectionErrorStatisticsProvider::window( $this->query( '2020-01-01T00:00:00Z' ), 0 );

		self::assertFalse( $window->truncated );
		self::assertNull( $window->retention_days );
	}

	/**
	 * A caller that asked for no range cannot have its range shortened, but
	 * the retention window is still reported: it is the outer bound of the
	 * answer either way.
	 */
	public function test_an_open_request_reports_retention_without_truncation(): void {
		$window = RedirectionErrorStatisticsProvider::window( $this->query( null ), 7 );

		self::assertFalse( $window->truncated );
		self::assertNull( $window->requested_since );
		self::assertSame( 7, $window->retention_days );
	}

	/**
	 * A boundary exactly on the retention edge is inside it, not outside.
	 */
	public function test_the_retention_edge_is_inclusive(): void {
		$window = RedirectionErrorStatisticsProvider::window( $this->query( '2026-08-27T12:00:00Z' ), 7 );

		self::assertFalse( $window->truncated );
	}

	/**
	 * Builds a query at the fixed test clock.
	 *
	 * @param string|null $since ISO 8601 boundary, or null.
	 * @return NotFoundStatisticsQuery
	 */
	private function query( ?string $since ): NotFoundStatisticsQuery {
		$utc = new DateTimeZone( 'UTC' );

		return new NotFoundStatisticsQuery(
			new DateTimeImmutable( self::NOW, $utc ),
			null === $since ? null : new DateTimeImmutable( $since, $utc )
		);
	}
}
