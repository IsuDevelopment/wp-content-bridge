<?php
/**
 * Content query tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Content;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Content\ContentQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies adapter input normalization independently from WordPress.
 */
final class ContentQueryTest extends TestCase {

	/**
	 * Missing optional input receives stable application defaults.
	 */
	public function test_applies_defaults_without_schema_injection(): void {
		$query = ContentQuery::from_input( array() );

		self::assertSame( '', $query->query );
		self::assertSame( array( 'publish' ), $query->statuses );
		self::assertSame( 1, $query->page );
		self::assertSame( 20, $query->per_page );
		self::assertSame( 'relevance', $query->order_by );
		self::assertSame( 'desc', $query->order );
		self::assertSame( array(), $query->taxonomy_filters );
	}

	/**
	 * Taxonomy filters are normalized into bounded value objects.
	 */
	public function test_normalizes_taxonomy_filters(): void {
		$query = ContentQuery::from_input(
			array(
				'taxonomy' => array(
					array(
						'taxonomy' => 'category',
						'term_ids' => array( 9, 4, 9 ),
					),
				),
			)
		);

		self::assertCount( 1, $query->taxonomy_filters );
		self::assertSame( 'category', $query->taxonomy_filters[0]->taxonomy );
		self::assertSame( array( 9, 4 ), $query->taxonomy_filters[0]->term_ids );
	}

	/**
	 * Duplicate selectors are removed without reordering values.
	 */
	public function test_normalizes_selector_lists(): void {
		$query = ContentQuery::from_input(
			array(
				'post_types' => array( 'page', 'post', 'page' ),
				'author_ids' => array( 3, 2, 3 ),
				'order'      => 'ASC',
			)
		);

		self::assertSame( array( 'page', 'post' ), $query->post_types );
		self::assertSame( array( 3, 2 ), $query->author_ids );
		self::assertSame( 'asc', $query->order );
	}

	/**
	 * Invalid and dangerous bounds fail before reaching WP_Query.
	 *
	 * @param array<string, mixed> $input Invalid input.
	 */
	#[DataProvider( 'invalid_input_provider' )]
	public function test_rejects_invalid_input( array $input ): void {
		$this->expectException( InvalidArgumentException::class );

		ContentQuery::from_input( $input );
	}

	/**
	 * Supplies representative invalid values.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function invalid_input_provider(): iterable {
		yield 'oversized page' => array( array( 'per_page' => 101 ) );
		yield 'non-string query' => array( array( 'query' => array( 'unexpected' ) ) );
		yield 'invalid sort' => array( array( 'order_by' => 'meta_value' ) );
		yield 'invalid author' => array( array( 'author_ids' => array( 0 ) ) );
		yield 'invalid date' => array( array( 'modified_after' => 'not-a-date' ) );
		yield 'empty taxonomy terms' => array(
			array(
				'taxonomy' => array(
					array(
						'taxonomy' => 'category',
						'term_ids' => array(),
					),
				),
			),
		);
		yield 'duplicate taxonomy' => array(
			array(
				'taxonomy' => array(
					array(
						'taxonomy' => 'category',
						'term_ids' => array( 1 ),
					),
					array(
						'taxonomy' => 'category',
						'term_ids' => array( 2 ),
					),
				),
			),
		);
	}

	/**
	 * Effective post types can be applied without mutating other criteria.
	 */
	public function test_creates_access_constrained_copy(): void {
		$query       = ContentQuery::from_input(
			array(
				'query'      => 'seo',
				'post_types' => array( 'post', 'secret' ),
			)
		);
		$constrained = $query->with_post_types( array( 'post' ) );

		self::assertSame( array( 'post', 'secret' ), $query->post_types );
		self::assertSame( array( 'post' ), $constrained->post_types );
		self::assertSame( 'seo', $constrained->query );
	}
}
