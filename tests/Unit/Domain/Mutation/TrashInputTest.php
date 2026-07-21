<?php
/**
 * Trash input tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\TrashInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the strict trash-content input contract.
 */
final class TrashInputTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-21 12:00:00';

	/**
	 * Valid input produces an immutable request.
	 *
	 * @return void
	 */
	public function test_builds_valid_request(): void {
		$input = TrashInput::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
			)
		);

		self::assertSame( 42, $input->post_id );
		self::assertSame( self::TOKEN, $input->expected_version->to_string() );
	}

	/**
	 * Provides malformed trash-content inputs.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function invalid_inputs(): iterable {
		yield 'missing id' => array( array( 'version_token' => self::TOKEN ) );
		yield 'non-integer id' => array(
			array(
				'post_id'       => '42',
				'version_token' => self::TOKEN,
			),
		);
		yield 'missing token' => array( array( 'post_id' => 42 ) );
		yield 'unknown field' => array(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'force'         => true,
			),
		);
	}

	/**
	 * Invalid or expanded input is rejected.
	 *
	 * @param array<string, mixed> $input Invalid input.
	 * @return void
	 */
	#[DataProvider( 'invalid_inputs' )]
	public function test_rejects_invalid_input( array $input ): void {
		$this->expectException( InvalidArgumentException::class );

		TrashInput::from_input( $input );
	}
}
