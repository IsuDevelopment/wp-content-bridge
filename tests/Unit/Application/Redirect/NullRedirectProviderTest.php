<?php
/**
 * Null redirect provider tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Redirect;

use IsuDev\WPContentBridge\Application\Redirect\NullRedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use PHPUnit\Framework\TestCase;

/**
 * Keeps redirect Abilities operational, with an explicit unavailable answer,
 * when neither Redirection nor Yoast Premium is active.
 */
final class NullRedirectProviderTest extends TestCase {

	/**
	 * The null object is a fallback, not a detected provider.
	 */
	public function test_is_never_available(): void {
		self::assertFalse( ( new NullRedirectProvider() )->is_available() );
	}

	/**
	 * Diagnostics report explicit non-detection rather than an empty guess.
	 */
	public function test_status_reports_no_provider(): void {
		$status = ( new NullRedirectProvider() )->status();

		self::assertSame( 'none', $status->provider );
		self::assertFalse( $status->detected );
		self::assertNull( $status->version );
	}

	/**
	 * A read against no provider fails closed rather than reporting a false
	 * "no existing redirect" that a caller could mistake for a clear collision
	 * check.
	 */
	public function test_search_throws_when_unavailable(): void {
		$this->expectException( RedirectProviderUnavailable::class );

		( new NullRedirectProvider() )->search( new RedirectSourcePath( '/old-page' ) );
	}
}
