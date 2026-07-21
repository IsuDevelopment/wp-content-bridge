<?php
/**
 * Content-access settings adapter tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Adapter\Admin;

use IsuDev\WPContentBridge\Adapter\Admin\ContentAccessSettingsPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies strict normalization of feature switches.
 */
final class ContentAccessSettingsPageTest extends TestCase {

	/**
	 * Provides accepted and rejected checkbox values.
	 *
	 * @return iterable<string, array{mixed, bool}>
	 */
	public static function checkbox_values(): iterable {
		yield 'boolean true' => array( true, true );
		yield 'integer one' => array( 1, true );
		yield 'string one' => array( '1', true );
		yield 'yes' => array( 'yes', true );
		yield 'on' => array( 'on', true );
		yield 'boolean false' => array( false, false );
		yield 'integer zero' => array( 0, false );
		yield 'string zero' => array( '0', false );
		yield 'false string' => array( 'false', false );
		yield 'unknown string' => array( 'enabled', false );
		yield 'array' => array( array( '1' ), false );
		yield 'null' => array( null, false );
	}

	/**
	 * Verifies that only explicit checkbox values enable a feature.
	 *
	 * @param mixed $input    Submitted value.
	 * @param bool  $expected Expected normalized value.
	 * @return void
	 */
	#[DataProvider( 'checkbox_values' )]
	public function test_sanitizes_checkbox_values_strictly( mixed $input, bool $expected ): void {
		self::assertSame( $expected, ContentAccessSettingsPage::sanitize_checkbox( $input ) );
	}
}
