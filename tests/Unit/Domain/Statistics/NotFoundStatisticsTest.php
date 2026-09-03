<?php
/**
 * Statistics domain invariant tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Statistics;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsAvailability;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsProviderStatus;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsWindow;
use IsuDev\WPContentBridge\Domain\Statistics\NotFoundCount;
use IsuDev\WPContentBridge\Domain\Statistics\NotFoundStatistics;
use PHPUnit\Framework\TestCase;

/**
 * Covers the invariants that keep ADR 0030's three-state distinction from
 * collapsing back into a zero.
 */
final class NotFoundStatisticsTest extends TestCase {

	/**
	 * A measured result is the observation, including a legitimately empty one.
	 */
	public function test_a_measured_result_may_be_empty(): void {
		$result = new NotFoundStatistics(
			self::provider(),
			ErrorStatisticsAvailability::MEASURED,
			self::window()
		);

		self::assertTrue( $result->is_measured() );
		self::assertSame( array(), $result->to_array()['paths'] );
	}

	/**
	 * A non-measured result can never carry counts: an "unavailable" answer
	 * with numbers attached would be two claims at once.
	 */
	public function test_only_a_measured_result_may_carry_counts(): void {
		$this->expectException( InvalidArgumentException::class );

		new NotFoundStatistics(
			self::provider(),
			ErrorStatisticsAvailability::UNAVAILABLE,
			self::window(),
			array( new NotFoundCount( '/old', 4 ) )
		);
	}

	/**
	 * A disabled result exists to tell the operator which setting to change,
	 * so it must name it; without that it is a zero by another name.
	 */
	public function test_a_disabled_result_must_name_the_setting(): void {
		$this->expectException( InvalidArgumentException::class );

		new NotFoundStatistics(
			self::provider(),
			ErrorStatisticsAvailability::DISABLED,
			self::window()
		);
	}

	/**
	 * The four states are reported verbatim, so a client can branch on them.
	 */
	public function test_availability_is_serialized_as_its_stable_token(): void {
		$result = new NotFoundStatistics(
			self::provider(),
			ErrorStatisticsAvailability::FORBIDDEN,
			self::window(),
			array(),
			null,
			'Provider permission denied.'
		);

		self::assertSame( 'forbidden', $result->to_array()['availability'] );
	}

	/**
	 * A grouped count is never zero: a row exists because it was hit, so a
	 * zero means the query did not group (ADR 0030 s6) and must not be
	 * reported as a count.
	 */
	public function test_a_count_must_be_at_least_one_hit(): void {
		$this->expectException( InvalidArgumentException::class );

		new NotFoundCount( '/old', 0 );
	}

	/**
	 * A count must name a path; an empty grouping key is not an observation.
	 */
	public function test_a_count_must_name_a_path(): void {
		$this->expectException( InvalidArgumentException::class );

		new NotFoundCount( '   ', 3 );
	}

	/**
	 * The serialized aggregate carries the path and the count, and nothing
	 * else. This is the guard against a per-visitor field ever appearing in
	 * the projected surface (ADR 0030 s3).
	 */
	public function test_a_count_serializes_only_path_and_hits(): void {
		self::assertSame(
			array(
				'path' => '/old-page',
				'hits' => 12,
			),
			( new NotFoundCount( '/old-page', 12 ) )->to_array()
		);
	}

	/**
	 * A truncation flag without a requested boundary would tell a caller its
	 * own query was shortened when it never asked for a range.
	 */
	public function test_a_window_cannot_be_truncated_without_a_request(): void {
		$this->expectException( InvalidArgumentException::class );

		new ErrorStatisticsWindow( 7, null, null, true );
	}

	/**
	 * A provider that is installed but collects nothing readable reports an
	 * empty `collects`, so "the plugin is here" never implies "the counts are".
	 */
	public function test_provider_status_reports_what_it_collects(): void {
		$status = new ErrorStatisticsProviderStatus( 'redirection', '5.9.0', true, array( 'not_found' ) );

		self::assertSame(
			array(
				'provider' => 'redirection',
				'version'  => '5.9.0',
				'detected' => true,
				'collects' => array( 'not_found' ),
			),
			$status->to_array()
		);
	}

	/**
	 * Returns a detected provider status.
	 *
	 * @return ErrorStatisticsProviderStatus
	 */
	private static function provider(): ErrorStatisticsProviderStatus {
		return new ErrorStatisticsProviderStatus( 'redirection', '5.9.0', true, array( 'not_found' ) );
	}

	/**
	 * Returns an untruncated open window.
	 *
	 * @return ErrorStatisticsWindow
	 */
	private static function window(): ErrorStatisticsWindow {
		return new ErrorStatisticsWindow( 7, null, null, false );
	}
}
