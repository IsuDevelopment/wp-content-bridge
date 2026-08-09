<?php
/**
 * Transition-status input tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\TransitionStatusInput;
use IsuDev\WPContentBridge\Domain\Status\ContentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the strict transition-content-status input contract, in
 * particular the presence/absence relationship between `target_status` and
 * `publish_at` (ADR 0024 gate 7's structural half).
 */
final class TransitionStatusInputTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-21 12:00:00';

	/**
	 * A valid non-future request carries no publish_at.
	 *
	 * @return void
	 */
	public function test_builds_valid_non_future_request(): void {
		$input = TransitionStatusInput::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'target_status' => 'pending',
			)
		);

		self::assertSame( 42, $input->post_id );
		self::assertSame( self::TOKEN, $input->expected_version->to_string() );
		self::assertSame( ContentStatus::PENDING, $input->target_status );
		self::assertNull( $input->publish_at );
	}

	/**
	 * A valid future request carries the raw publish_at string forward
	 * unparsed.
	 *
	 * @return void
	 */
	public function test_builds_valid_future_request_with_publish_at(): void {
		$input = TransitionStatusInput::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'target_status' => 'future',
				'publish_at'    => '2026-09-01T09:00:00',
			)
		);

		self::assertSame( ContentStatus::FUTURE, $input->target_status );
		self::assertSame( '2026-09-01T09:00:00', $input->publish_at );
	}

	/**
	 * Provides malformed transition-content-status inputs.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function invalid_inputs(): iterable {
		yield 'missing id' => array(
			array(
				'version_token' => self::TOKEN,
				'target_status' => 'draft',
			),
		);
		yield 'missing token' => array(
			array(
				'post_id'       => 42,
				'target_status' => 'draft',
			),
		);
		yield 'missing target_status' => array(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
			),
		);
		yield 'unknown target_status' => array(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'target_status' => 'trash',
			),
		);
		yield 'future without publish_at' => array(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'target_status' => 'future',
			),
		);
		yield 'future with empty publish_at' => array(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'target_status' => 'future',
				'publish_at'    => '',
			),
		);
		yield 'non-future with publish_at' => array(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'target_status' => 'draft',
				'publish_at'    => '2026-09-01T09:00:00',
			),
		);
		yield 'unknown field' => array(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'target_status' => 'draft',
				'force'         => true,
			),
		);
	}

	/**
	 * Invalid or inconsistent input is rejected.
	 *
	 * @param array<string, mixed> $input Invalid input.
	 * @return void
	 */
	#[DataProvider( 'invalid_inputs' )]
	public function test_rejects_invalid_input( array $input ): void {
		$this->expectException( InvalidArgumentException::class );

		TransitionStatusInput::from_input( $input );
	}
}
