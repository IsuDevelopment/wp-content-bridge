<?php
/**
 * Detailed-content result tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Content;

use IsuDev\WPContentBridge\Domain\Content\ContentDetail;
use IsuDev\WPContentBridge\Domain\Content\ContentSummary;
use PHPUnit\Framework\TestCase;

/**
 * Verifies byte accounting in the public detail envelope.
 */
final class ContentDetailTest extends TestCase {

	/**
	 * Representation byte counts use bytes and serialize consistently.
	 */
	public function test_reports_representation_byte_sizes(): void {
		$detail = new ContentDetail(
			new ContentSummary( 1, 'post', 'publish', 'Title', 'title', null, '', 2, null, '2026-07-17T00:00:00+00:00' ),
			array(
				'raw'        => 'ąć',
				'plain_text' => 'abc',
			),
			array(),
			null
		);

		self::assertSame(
			array(
				'raw'        => 4,
				'plain_text' => 3,
			),
			$detail->representation_bytes()
		);
		self::assertSame( 7, $detail->total_representation_bytes() );
		self::assertSame( 7, $detail->to_array()['payload']['total_representation_bytes'] );
	}
}
