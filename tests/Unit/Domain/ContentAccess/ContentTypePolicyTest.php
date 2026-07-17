<?php
/**
 * Content type policy tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\ContentAccess;

use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies content operation dependencies and option parsing.
 */
final class ContentTypePolicyTest extends TestCase {

	/**
	 * Deny-all is the safe baseline for custom content types.
	 */
	public function test_deny_all_disables_every_operation(): void {
		$policy = ContentTypePolicy::deny_all();

		foreach ( ContentOperation::cases() as $operation ) {
			self::assertFalse( $policy->allows( $operation ) );
		}
	}

	/**
	 * Built-in readable defaults do not enable future writes.
	 */
	public function test_default_readable_enables_only_read_operations(): void {
		$policy = ContentTypePolicy::default_readable();

		self::assertTrue( $policy->allows( ContentOperation::READ ) );
		self::assertTrue( $policy->allows( ContentOperation::SEARCH ) );
		self::assertFalse( $policy->allows( ContentOperation::CREATE ) );
		self::assertFalse( $policy->allows( ContentOperation::UPDATE ) );
		self::assertFalse( $policy->allows( ContentOperation::UPDATE_SEO ) );
		self::assertFalse( $policy->allows( ContentOperation::PUBLISH ) );
	}

	/**
	 * WordPress checkbox representations are accepted deliberately.
	 *
	 * @param mixed $input Accepted checkbox input.
	 */
	#[DataProvider( 'truthy_checkbox_values' )]
	public function test_accepts_narrow_checkbox_values( mixed $input ): void {
		$policy = ContentTypePolicy::from_input(
			array(
				ContentOperation::READ->value => $input,
			)
		);

		self::assertTrue( $policy->allows( ContentOperation::READ ) );
	}

	/**
	 * Provides accepted checkbox values.
	 *
	 * @return iterable<string, array{mixed}>
	 */
	public static function truthy_checkbox_values(): iterable {
		yield 'boolean' => array( true );
		yield 'integer' => array( 1 );
		yield 'string integer' => array( '1' );
		yield 'yes' => array( 'yes' );
		yield 'on' => array( 'on' );
	}

	/**
	 * Dependent operations are removed from invalid submitted combinations.
	 */
	public function test_write_and_search_operations_require_read(): void {
		$input                                  = array_fill_keys(
			array_map(
				static fn ( ContentOperation $operation ): string => $operation->value,
				ContentOperation::cases()
			),
			true
		);
		$input[ ContentOperation::READ->value ] = false;

		$policy = ContentTypePolicy::from_input( $input );

		foreach ( ContentOperation::cases() as $operation ) {
			self::assertFalse( $policy->allows( $operation ) );
		}
	}
}
