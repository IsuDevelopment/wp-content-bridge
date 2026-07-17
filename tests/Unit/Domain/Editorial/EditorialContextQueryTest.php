<?php
/**
 * Editorial-context query tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Editorial;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Editorial\EditorialContextQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks query defaults and hard bounds.
 */
final class EditorialContextQueryTest extends TestCase {

	/**
	 * Empty input selects every bounded section and conservative limits.
	 */
	public function test_applies_defaults(): void {
		$query = EditorialContextQuery::from_input( array() );

		self::assertSame( EditorialContextQuery::SECTIONS, $query->sections );
		self::assertSame( 20, $query->recent_limit );
		self::assertSame( 50, $query->terms_per_taxonomy );
		self::assertSame( array(), $query->post_types );
	}

	/**
	 * Invalid and oversized inputs are rejected inside the domain too.
	 *
	 * @param array<string, mixed> $input Invalid input.
	 */
	#[DataProvider( 'invalid_inputs' )]
	public function test_rejects_invalid_inputs( array $input ): void {
		$this->expectException( InvalidArgumentException::class );

		EditorialContextQuery::from_input( $input );
	}

	/**
	 * Supplies invalid bounds and selections.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function invalid_inputs(): iterable {
		yield 'empty sections' => array( array( 'sections' => array() ) );
		yield 'unknown section' => array( array( 'sections' => array( 'secrets' ) ) );
		yield 'too many recent' => array( array( 'recent_limit' => 51 ) );
		yield 'invalid type slug' => array( array( 'post_types' => array( '../post' ) ) );
	}
}
