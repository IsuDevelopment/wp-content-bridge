<?php
/**
 * Pattern query tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Pattern;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Pattern\PatternQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies stable defaults and input bounds.
 */
final class PatternQueryTest extends TestCase {

	/**
	 * Omitted filters use the metadata-only first page.
	 */
	public function test_applies_metadata_only_defaults(): void {
		$query = PatternQuery::from_input( array() );

		self::assertSame( '', $query->query );
		self::assertNull( $query->pattern_namespace );
		self::assertFalse( $query->include_content );
		self::assertSame( 1, $query->page );
		self::assertSame( 20, $query->per_page );
	}

	/**
	 * Supported filters remain independent and exact.
	 */
	public function test_accepts_bounded_filters_and_content_opt_in(): void {
		$query = PatternQuery::from_input(
			array(
				'query'           => 'Hero',
				'namespace'       => 'theme-name',
				'category'        => 'featured',
				'post_type'       => 'page',
				'include_content' => true,
				'page'            => 2,
				'per_page'        => 50,
			)
		);

		self::assertSame( 'theme-name', $query->pattern_namespace );
		self::assertSame( 'featured', $query->category );
		self::assertSame( 'page', $query->post_type );
		self::assertTrue( $query->include_content );
	}

	/**
	 * Invalid values fail before infrastructure access.
	 *
	 * @param array<string, mixed> $input Invalid input.
	 */
	#[DataProvider( 'invalid_inputs' )]
	public function test_rejects_unbounded_or_ambiguous_values( array $input ): void {
		$this->expectException( InvalidArgumentException::class );

		PatternQuery::from_input( $input );
	}

	/**
	 * Supplies invalid inputs.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function invalid_inputs(): iterable {
		yield 'namespace path' => array( array( 'namespace' => '../theme' ) );
		yield 'category slash' => array( array( 'category' => 'hero/large' ) );
		yield 'post type too long' => array( array( 'post_type' => str_repeat( 'p', 21 ) ) );
		yield 'string boolean' => array( array( 'include_content' => 'true' ) );
		yield 'oversized page' => array( array( 'per_page' => 51 ) );
		yield 'oversized query' => array( array( 'query' => str_repeat( 'q', 201 ) ) );
	}
}
