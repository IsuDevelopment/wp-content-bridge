<?php
/**
 * Redirect provider status validation tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use PHPUnit\Framework\TestCase;

/**
 * Every redirect result reports which provider answered (ADR 0026 s4); this
 * is the safe, bounded shape that diagnostics and results share.
 */
final class RedirectProviderStatusTest extends TestCase {

	/**
	 * A valid status serializes its fields, deduplicated and sorted for
	 * deterministic output.
	 */
	public function test_serializes_deduplicated_sorted_capabilities(): void {
		$status = new RedirectProviderStatus( 'redirection', '5.5.2', true, array( 'search', 'create', 'search' ) );

		self::assertSame(
			array(
				'provider'     => 'redirection',
				'version'      => '5.5.2',
				'detected'     => true,
				'capabilities' => array( 'create', 'search' ),
			),
			$status->to_array()
		);
	}

	/**
	 * An unavailable provider still reports a stable identity with no version.
	 */
	public function test_allows_a_null_version_when_undetected(): void {
		$status = new RedirectProviderStatus( 'yoast-premium', null, false, array() );

		self::assertNull( $status->version );
		self::assertFalse( $status->detected );
	}

	/**
	 * The provider slug is public diagnostic output, not a place for free text.
	 */
	public function test_rejects_an_invalid_provider_slug(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectProviderStatus( 'Not A Slug!', null, false, array() );
	}

	/**
	 * An empty version string is ambiguous with "no version"; the constructor
	 * requires the explicit `null` instead.
	 */
	public function test_rejects_an_empty_version_string(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectProviderStatus( 'redirection', '', true, array() );
	}

	/**
	 * Capability tokens are a closed, safe vocabulary, not caller-supplied text.
	 */
	public function test_rejects_a_malformed_capability_token(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectProviderStatus( 'redirection', '5.5.2', true, array( 'not a token!' ) );
	}
}
