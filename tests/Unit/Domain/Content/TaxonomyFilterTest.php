<?php
/**
 * Taxonomy filter tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Content;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Content\TaxonomyFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the transport-independent taxonomy filter invariant.
 */
final class TaxonomyFilterTest extends TestCase {

	/**
	 * Valid input keeps order while removing duplicate term IDs.
	 */
	public function test_normalizes_valid_input(): void {
		$filter = TaxonomyFilter::from_input(
			array(
				'taxonomy' => 'product-category_2',
				'term_ids' => array( 12, 7, 12 ),
			)
		);

		self::assertSame( 'product-category_2', $filter->taxonomy );
		self::assertSame( array( 12, 7 ), $filter->term_ids );
	}

	/**
	 * Invalid shapes and values fail before WordPress is called.
	 *
	 * @param mixed $input Invalid filter input.
	 */
	#[DataProvider( 'invalid_filter_provider' )]
	public function test_rejects_invalid_input( mixed $input ): void {
		$this->expectException( InvalidArgumentException::class );

		TaxonomyFilter::from_input( $input );
	}

	/**
	 * Supplies representative invalid filters.
	 *
	 * @return iterable<string, array{mixed}>
	 */
	public static function invalid_filter_provider(): iterable {
		yield 'not object' => array( 'category' );
		yield 'missing terms' => array( array( 'taxonomy' => 'category' ) );
		yield 'unknown field' => array(
			array(
				'taxonomy' => 'category',
				'term_ids' => array( 1 ),
				'operator' => 'NOT IN',
			),
		);
		yield 'invalid taxonomy' => array(
			array(
				'taxonomy' => 'Category / unsafe',
				'term_ids' => array( 1 ),
			),
		);
		yield 'non-positive term' => array(
			array(
				'taxonomy' => 'category',
				'term_ids' => array( 0 ),
			),
		);
		yield 'too many terms' => array(
			array(
				'taxonomy' => 'category',
				'term_ids' => range( 1, 101 ),
			),
		);
	}
}
