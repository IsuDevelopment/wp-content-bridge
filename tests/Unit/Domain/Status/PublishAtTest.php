<?php
/**
 * PublishAt validation tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Status;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Status\PublishAt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the pure `publish_at` parsing and validation logic — no
 * WordPress dependency, so an arbitrary timezone and "now" can be injected
 * directly, including the DST gap/fold and fixed-offset cases ADR 0024
 * requires (task 5).
 */
final class PublishAtTest extends TestCase {

	/**
	 * A valid future local time, in a named zone observing DST, resolves to
	 * the correct UTC instant and round-trips through both wire forms.
	 *
	 * @return void
	 */
	public function test_valid_local_time_resolves_to_expected_utc(): void {
		$publish_at = PublishAt::from_local_string(
			'2026-09-01T09:00:00',
			new DateTimeZone( 'Europe/Warsaw' ),
			new DateTimeImmutable( '2026-08-01T00:00:00Z' )
		);

		// Europe/Warsaw is CEST (+02:00) on 2026-09-01.
		self::assertSame( '2026-09-01 07:00:00', $publish_at->utc_mysql() );
		self::assertSame( '2026-09-01 09:00:00', $publish_at->local_mysql() );
		self::assertSame(
			array(
				'local' => '2026-09-01T09:00:00+02:00',
				'utc'   => '2026-09-01T07:00:00Z',
			),
			$publish_at->to_array()
		);
	}

	/**
	 * A fixed UTC-offset timezone (the reference site's own configuration,
	 * `timezone_string` empty) is the default case, not an edge case, and
	 * needs no special handling: local and UTC coincide.
	 *
	 * @return void
	 */
	public function test_fixed_offset_timezone_has_no_dst_and_local_equals_utc(): void {
		$publish_at = PublishAt::from_local_string(
			'2026-09-01T09:00:00',
			new DateTimeZone( '+00:00' ),
			new DateTimeImmutable( '2026-08-01T00:00:00Z' )
		);

		self::assertSame( '2026-09-01 09:00:00', $publish_at->utc_mysql() );
		self::assertSame( '2026-09-01 09:00:00', $publish_at->local_mysql() );
	}

	/**
	 * A local time inside the Europe/Warsaw 2026 spring-forward gap
	 * (02:00–03:00 on 2026-03-29 does not exist) is rejected rather than
	 * silently shifted forward, which is what PHP itself does by default.
	 *
	 * @return void
	 */
	public function test_rejects_local_time_in_dst_spring_forward_gap(): void {
		$this->expectException( InvalidArgumentException::class );

		PublishAt::from_local_string(
			'2026-03-29T02:30:00',
			new DateTimeZone( 'Europe/Warsaw' ),
			new DateTimeImmutable( '2026-01-01T00:00:00Z' )
		);
	}

	/**
	 * A local time inside the Europe/Warsaw 2026 autumn fold (02:00–03:00 on
	 * 2026-10-25 occurs twice) is accepted, not rejected: the wall-clock
	 * string exists, it is merely ambiguous, and PHP resolves it
	 * deterministically.
	 *
	 * @return void
	 */
	public function test_accepts_local_time_in_dst_autumn_fold(): void {
		$publish_at = PublishAt::from_local_string(
			'2026-10-25T02:30:00',
			new DateTimeZone( 'Europe/Warsaw' ),
			new DateTimeImmutable( '2026-01-01T00:00:00Z' )
		);

		// Measured: PHP resolves the ambiguous fold to the later,
		// post-transition (CET, +01:00) occurrence.
		self::assertSame( '2026-10-25 01:30:00', $publish_at->utc_mysql() );
	}

	/**
	 * A `publish_at` that is not strictly in the future is rejected rather
	 * than accepted and later downgraded by WordPress.
	 *
	 * @return void
	 */
	public function test_rejects_non_future_instant(): void {
		$this->expectException( InvalidArgumentException::class );

		PublishAt::from_local_string(
			'2026-01-01T00:00:00',
			new DateTimeZone( 'UTC' ),
			new DateTimeImmutable( '2026-01-01T00:00:00Z' )
		);
	}

	/**
	 * Provides malformed or out-of-range wire strings.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function invalid_strings(): iterable {
		yield 'contains an offset' => array( '2026-09-01T09:00:00+02:00' );
		yield 'missing time' => array( '2026-09-01' );
		yield 'out of range month' => array( '2026-13-01T09:00:00' );
		yield 'out of range day' => array( '2026-02-30T09:00:00' );
		yield 'out of range hour' => array( '2026-09-01T25:00:00' );
		yield 'garbage' => array( 'not-a-date' );
		yield 'empty' => array( '' );
	}

	/**
	 * Malformed or out-of-range strings are rejected.
	 *
	 * @param string $raw Invalid candidate.
	 * @return void
	 */
	#[DataProvider( 'invalid_strings' )]
	public function test_rejects_malformed_input( string $raw ): void {
		$this->expectException( InvalidArgumentException::class );

		PublishAt::from_local_string( $raw, new DateTimeZone( 'UTC' ), new DateTimeImmutable( '2020-01-01T00:00:00Z' ) );
	}
}
