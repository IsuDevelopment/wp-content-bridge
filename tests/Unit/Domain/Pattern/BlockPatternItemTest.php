<?php
/**
 * Pattern result tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Pattern;

use IsuDev\WPContentBridge\Domain\Pattern\BlockPatternItem;
use IsuDev\WPContentBridge\Domain\Pattern\PatternSearchResult;
use PHPUnit\Framework\TestCase;

/**
 * Locks the normalized pattern envelope and content accounting.
 */
final class BlockPatternItemTest extends TestCase {

	/**
	 * Content is complete, byte-counted, and marked untrusted.
	 */
	public function test_serializes_complete_content_in_an_object_envelope(): void {
		$item   = new BlockPatternItem(
			'theme/hero',
			'theme',
			'Hero',
			'Lead section',
			'theme',
			1200,
			true,
			array( 'featured' ),
			array( 'lead' ),
			array( 'core/cover' ),
			array( 'page' ),
			array(),
			'<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->'
		);
		$result = new PatternSearchResult( array( $item ), 1, 20, 1, 1, true, false, 1000, 2097152 );
		$array  = $result->to_array();

		self::assertSame( '1.0', $array['schema_version'] );
		self::assertSame( 'theme/hero', $array['items'][0]['name'] );
		self::assertSame( strlen( (string) $array['items'][0]['content'] ), $array['items'][0]['content_bytes'] );
		self::assertTrue( $array['items'][0]['untrusted'] );
		self::assertSame( 2097152, $array['limits']['content_response_bytes'] );
	}
}
