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
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
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

	/**
	 * The optional version token serializes to its wire form when present.
	 */
	public function test_to_array_includes_version_token_when_present(): void {
		$summary = new ContentSummary(
			42,
			'post',
			'draft',
			'Title',
			'title',
			null,
			'Excerpt',
			1,
			null,
			'2026-07-20 00:00:00'
		);
		$detail  = new ContentDetail(
			$summary,
			array(),
			array(),
			null,
			new VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' )
		);

		self::assertSame( 'abcdef0123456789:2026-07-20 00:00:00', $detail->to_array()['version_token'] );
	}

	/**
	 * Featured-image identity is always emitted as an ID and URL pair.
	 */
	public function test_summary_emits_featured_image_identity_pair(): void {
		$summary = new ContentSummary( 1, 'page', 'publish', 'Page', 'page', 'https://example.com/page', '', 2, null, '2026-07-21T00:00:00+00:00', 9, 'https://example.com/media/hero.jpg' );
		$array   = $summary->to_array();

		self::assertSame( 9, $array['featured_image_id'] );
		self::assertSame( 'https://example.com/media/hero.jpg', $array['featured_image_url'] );
	}
}
