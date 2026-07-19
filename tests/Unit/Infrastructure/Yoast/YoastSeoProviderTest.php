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
}
