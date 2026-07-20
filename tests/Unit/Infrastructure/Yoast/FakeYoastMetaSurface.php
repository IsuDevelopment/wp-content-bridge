<?php
/**
 * Fake for Yoast's documented Meta surface accessor.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\Yoast;

/**
 * Fakes Yoast's documented Meta surface accessor.
 */
final class FakeYoastMetaSurface {

	/**
	 * Creates the fake surface.
	 *
	 * @param FakeYoastMetaValue $meta Meta value returned for any target.
	 */
	public function __construct( private FakeYoastMetaValue $meta ) {
	}

	/**
	 * Returns the fake meta value for a URL target.
	 *
	 * @param string $url Target URL.
	 * @return FakeYoastMetaValue
	 */
	public function for_url( string $url ): FakeYoastMetaValue {
		unset( $url );

		return $this->meta;
	}

	/**
	 * Returns the fake meta value for a post target.
	 *
	 * @param int $post_id Target post ID.
	 * @return FakeYoastMetaValue
	 */
	public function for_post( int $post_id ): FakeYoastMetaValue {
		unset( $post_id );

		return $this->meta;
	}
}
