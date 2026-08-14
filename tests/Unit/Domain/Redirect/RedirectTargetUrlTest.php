<?php
/**
 * Redirect target URL validation tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use PHPUnit\Framework\TestCase;

/**
 * P0 (ADR 0026, roadmap Slice 5) restricts redirect targets to the same site.
 * A bare path is trivially same-site; an absolute URL must match the
 * configured site origin exactly and is normalized down to a path so no
 * stale host survives a future site-URL change.
 */
final class RedirectTargetUrlTest extends TestCase {

	private const SITE = 'https://example.com';

	/**
	 * A bare relative path needs no origin comparison and passes through.
	 */
	public function test_accepts_a_bare_relative_path(): void {
		self::assertSame( '/new-page', ( new RedirectTargetUrl( self::SITE, '/new-page' ) )->value() );
	}

	/**
	 * An absolute same-site URL is normalized to its path so the stored value
	 * never carries a scheme/host that could go stale.
	 */
	public function test_normalizes_an_absolute_same_site_url_to_a_path(): void {
		self::assertSame(
			'/new-page',
			( new RedirectTargetUrl( self::SITE, 'https://example.com/new-page' ) )->value()
		);
	}

	/**
	 * A query string is a legitimate target shape (e.g. redirecting to a
	 * filtered search page) and must survive normalization.
	 */
	public function test_preserves_query_string_on_an_absolute_url(): void {
		self::assertSame(
			'/search?q=archived',
			( new RedirectTargetUrl( self::SITE, 'https://example.com/search?q=archived' ) )->value()
		);
	}

	/**
	 * A different host would silently send visitors off-site; P0 forbids it.
	 */
	public function test_rejects_a_cross_origin_url(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectTargetUrl( self::SITE, 'https://elsewhere.example/new-page' );
	}

	/**
	 * A scheme mismatch on the same host is still a different origin.
	 */
	public function test_rejects_a_scheme_mismatch(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectTargetUrl( self::SITE, 'http://example.com/new-page' );
	}

	/**
	 * Embedded credentials have no legitimate purpose in a redirect target.
	 */
	public function test_rejects_url_credentials(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectTargetUrl( self::SITE, 'https://user:pass@example.com/new-page' );
	}

	/**
	 * Traversal segments could reference a path outside the intended tree.
	 */
	public function test_rejects_path_traversal_segment(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectTargetUrl( self::SITE, '/a/../b' );
	}

	/**
	 * A fragment is never sent to the server and can never be a real target.
	 */
	public function test_rejects_fragment(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectTargetUrl( self::SITE, '/new-page#section' );
	}

	/**
	 * The empty string is never a valid destination.
	 */
	public function test_rejects_empty_target(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectTargetUrl( self::SITE, '' );
	}

	/**
	 * An absolute URL cannot be validated as same-site without a configured
	 * site origin to compare against.
	 */
	public function test_rejects_absolute_url_when_site_url_is_not_configured(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectTargetUrl( 'not-a-url', 'https://example.com/new-page' );
	}
}
