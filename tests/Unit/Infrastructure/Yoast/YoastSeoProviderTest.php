<?php
/**
 * Yoast resolved SEO normalization tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\Yoast;

require_once __DIR__ . '/yoast-runtime-stub.php';

use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoProvider;
use PHPUnit\Framework\TestCase;

/**
 * Confirms resolved Open Graph/Twitter image output never leaks filesystem paths.
 */
final class YoastSeoProviderTest extends TestCase {

	/**
	 * Active fake Yoast main surface consumed by the global `YoastSEO()` test double.
	 *
	 * @var FakeYoastMain|null
	 */
	public static ?FakeYoastMain $main = null;

	/**
	 * Resets the fake surface between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::$main = null;

		parent::tearDown();
	}

	/**
	 * Open Graph images strip the filesystem path and unknown keys, keeping only the public allowlist.
	 */
	public function test_resolved_open_graph_images_strip_filesystem_path(): void {
		self::$main = new FakeYoastMain(
			new FakeYoastMetaSurface(
				new FakeYoastMetaValue(
					array(
						'open_graph_images' => array(
							array(
								'url'    => 'https://example.com/wp-content/uploads/2026/07/photo.jpg',
								'width'  => 800,
								'height' => 600,
								'path'   => '/Users/lukaszbiedron/Local Sites/kormas-isu/app/public/content/uploads/2026/07/photo.jpg',
							),
						),
					)
				)
			)
		);

		$provider = new YoastSeoProvider();
		$document = $provider->get( SeoTarget::for_url( 'https://example.com/example-page/' ) );

		$images = $document->resolved['open_graph']->value['images'];

		self::assertCount( 1, $images );
		self::assertArrayHasKey( 'url', $images[0] );
		self::assertArrayHasKey( 'width', $images[0] );
		self::assertArrayHasKey( 'height', $images[0] );
		self::assertArrayNotHasKey( 'path', $images[0] );

		foreach ( $images[0] as $value ) {
			if ( is_string( $value ) ) {
				self::assertStringNotContainsString( '/Users/', $value );
			}
		}
	}

	/**
	 * Yoast's real `open_graph_images` shape is a URL-keyed map (not a list); each mapped
	 * image must still be sanitized to the public allowlist, keeping url/width/height and
	 * dropping path plus every other non-public key (filesize, size, id, pixels).
	 */
	public function test_resolved_open_graph_images_survive_url_keyed_map_shape(): void {
		self::$main = new FakeYoastMain(
			new FakeYoastMetaSurface(
				new FakeYoastMetaValue(
					array(
						'open_graph_images' => array(
							'http:///content/uploads/2026/06/img-slider3.jpeg' => array(
								'width'    => 1400,
								'height'   => 500,
								'filesize' => 62778,
								'url'      => 'http:///content/uploads/2026/06/img-slider3.jpeg',
								'path'     => '/Users/lukaszbiedron/Local Sites/kormas-isu/app/public/content/uploads/2026/06/img-slider3.jpeg',
								'size'     => 'full',
								'id'       => 67,
								'alt'      => '',
								'pixels'   => 700000,
								'type'     => 'image/jpeg',
							),
						),
					)
				)
			)
		);

		$provider = new YoastSeoProvider();
		$document = $provider->get( SeoTarget::for_url( 'https://example.com/example-page/' ) );

		$images = $document->resolved['open_graph']->value['images'];

		self::assertCount( 1, $images );
		self::assertSame( 'http:///content/uploads/2026/06/img-slider3.jpeg', $images[0]['url'] );
		self::assertSame( 1400, $images[0]['width'] );
		self::assertSame( 500, $images[0]['height'] );
		self::assertArrayNotHasKey( 'path', $images[0] );
		self::assertArrayNotHasKey( 'filesize', $images[0] );
		self::assertArrayNotHasKey( 'size', $images[0] );
		self::assertArrayNotHasKey( 'id', $images[0] );
		self::assertArrayNotHasKey( 'pixels', $images[0] );

		foreach ( $images[0] as $value ) {
			if ( is_string( $value ) ) {
				self::assertStringNotContainsString( '/Users/', $value );
			}
		}
	}
}
