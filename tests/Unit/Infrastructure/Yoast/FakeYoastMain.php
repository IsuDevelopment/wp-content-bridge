<?php
/**
 * Fake for Yoast's documented main integration surface.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\Yoast;

/**
 * Fakes Yoast's documented main integration surface.
 */
final class FakeYoastMain {

	/**
	 * Creates the fake main surface.
	 *
	 * @param FakeYoastMetaSurface $surface Meta surface returned for `meta`.
	 */
	public function __construct( private FakeYoastMetaSurface $surface ) {
	}

	/**
	 * Reads one documented magic surface.
	 *
	 * @param string $name Surface name.
	 * @return FakeYoastMetaSurface|null
	 */
	public function __get( string $name ): ?FakeYoastMetaSurface {
		return 'meta' === $name ? $this->surface : null;
	}
}
