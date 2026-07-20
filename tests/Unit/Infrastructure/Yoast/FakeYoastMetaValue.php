<?php
/**
 * Fake for Yoast's documented Meta value magic surface.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\Yoast;

/**
 * Fakes Yoast's documented Meta value magic surface.
 */
final class FakeYoastMetaValue {

	/**
	 * Creates the fake meta value.
	 *
	 * @param array $values Documented property values keyed by name.
	 * @phpstan-param array<string, mixed> $values
	 */
	public function __construct( private array $values ) {
	}

	/**
	 * Reads one documented magic property.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ): mixed {
		return $this->values[ $name ] ?? null;
	}
}
