<?php
/**
 * Media query tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Media;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Media\MediaQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies selector exclusivity and search bounds.
 */
final class MediaQueryTest extends TestCase {

	/**
	 * Omitted optional fields receive stable defaults.
	 */
	public function test_applies_stable_defaults(): void {
		$query = MediaQuery::from_input( array() );

		self::assertNull( $query->id );
		self::assertSame( '', $query->query );
		self::assertSame( 1, $query->page );
		self::assertSame( 20, $query->per_page );
	}

	/**
	 * Exact basenames are valid selectors.
	 */
	public function test_accepts_exact_filename(): void {
		$query = MediaQuery::from_input( array( 'filename' => 'hero-image.webp' ) );

		self::assertSame( 'hero-image.webp', $query->filename );
	}

	/**
	 * Invalid inputs fail before reaching WordPress.
	 *
	 * @param array<string, mixed> $input Invalid input.
	 */
	#[DataProvider( 'invalid_inputs' )]
	public function test_rejects_ambiguous_or_unbounded_input( array $input ): void {
		$this->expectException( InvalidArgumentException::class );

		MediaQuery::from_input( $input );
	}

	/**
	 * Supplies unsafe or ambiguous inputs.
	 *
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function invalid_inputs(): iterable {
		yield 'two selectors' => array(
			array(
				'id'       => 2,
				'filename' => 'photo.jpg',
			),
		);
		yield 'filename path' => array( array( 'filename' => '../photo.jpg' ) );
		yield 'invalid id' => array( array( 'id' => 0 ) );
		yield 'oversized page' => array( array( 'per_page' => 101 ) );
		yield 'invalid URL' => array( array( 'url' => 'not-a-url' ) );
	}
}
