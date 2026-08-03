<?php
/**
 * Unit tests for the Custom Schema write DTO.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\CustomSchemaUpdate;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the fixed field allowlist, source bounds, and clear semantics.
 */
final class CustomSchemaUpdateTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * A complete bounded Schema.org source is preserved.
	 */
	public function test_builds_complete_custom_schema_update(): void {
		$source = "{\r\n  \"@type\": \"FAQPage\"\r\n}";
		$update = CustomSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'enabled'       => true,
				'source'        => $source,
			)
		);

		self::assertSame( array( 'enabled', 'source' ), $update->changed_fields() );
		self::assertSame( "{\n  \"@type\": \"FAQPage\"\n}", $update->writable_fields()['source'] );
	}

	/**
	 * False and an empty source are explicit values rather than omission.
	 */
	public function test_preserves_disable_and_clear_operations(): void {
		$update = CustomSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'enabled'       => false,
				'source'        => '',
			)
		);

		self::assertSame(
			array(
				'enabled' => false,
				'source'  => '',
			),
			$update->writable_fields()
		);
	}

	/**
	 * Unknown keys cannot become arbitrary metadata writes.
	 */
	public function test_rejects_unknown_field(): void {
		$this->expectException( InvalidArgumentException::class );

		CustomSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'arbitrary_key' => '_private',
			)
		);
	}

	/**
	 * A request must contain at least one mutable field.
	 */
	public function test_rejects_empty_update(): void {
		$this->expectException( InvalidArgumentException::class );

		CustomSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
			)
		);
	}

	/**
	 * Byte-size enforcement matches the provider storage boundary.
	 */
	public function test_rejects_source_over_byte_limit(): void {
		$this->expectException( InvalidArgumentException::class );

		CustomSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'source'        => str_repeat( 'ą', 50001 ),
			)
		);
	}
}
