<?php
/**
 * Media item tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Media;

use IsuDev\WPContentBridge\Domain\Media\MediaItem;
use IsuDev\WPContentBridge\Domain\Media\MediaSearchResult;
use PHPUnit\Framework\TestCase;

/**
 * Locks the normalized media response shape.
 */
final class MediaItemTest extends TestCase {

	/**
	 * Search output is an object rather than a bare array.
	 */
	public function test_search_serializes_as_an_object_envelope(): void {
		$item   = new MediaItem( 7, 'Hero', 'hero.webp', 'https://example.com/hero.webp', 'Alt', 'Caption', 'Description', 'image/webp' );
		$result = new MediaSearchResult( array( $item ), 1, 20, 1, 1, true, false, 1000 );
		$array  = $result->to_array();

		self::assertSame( '1.0', $array['schema_version'] );
		self::assertSame( 7, $array['items'][0]['id'] );
		self::assertSame( 'hero.webp', $array['items'][0]['filename'] );
		self::assertSame( 'image/webp', $array['items'][0]['mime_type'] );
		self::assertSame( 1, $array['pagination']['total_items'] );
		self::assertTrue( $array['provenance']['untrusted'] );
	}
}
