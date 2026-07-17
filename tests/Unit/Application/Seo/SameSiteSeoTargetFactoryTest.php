<?php
/**
 * Same-site SEO target tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Seo;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Seo\SameSiteSeoTargetFactory;
use PHPUnit\Framework\TestCase;

/**
 * Verifies exactly-one selector and strict origin validation.
 */
final class SameSiteSeoTargetFactoryTest extends TestCase {

	/**
	 * Post IDs remain transport-neutral selectors.
	 */
	public function test_creates_post_target(): void {
		$target = ( new SameSiteSeoTargetFactory( 'https://example.com/' ) )->from_input( array( 'post_id' => 42 ) );

		self::assertSame( array( 'post_id' => 42 ), $target->to_array() );
	}

	/**
	 * Same-origin URLs are normalized and fragments are not part of the target.
	 */
	public function test_normalizes_same_origin_url(): void {
		$target = ( new SameSiteSeoTargetFactory( 'https://example.com/' ) )->from_input(
			array( 'url' => 'https://EXAMPLE.com:443/blog/?page=2#section' )
		);

		self::assertSame( array( 'url' => 'https://example.com/blog/?page=2' ), $target->to_array() );
	}

	/**
	 * Cross-origin and parser-confusion inputs are rejected.
	 */
	public function test_rejects_unsafe_urls(): void {
		$factory  = new SameSiteSeoTargetFactory( 'https://example.com/' );
		$unsafe   = array(
			'https://attacker.example/path',
			'http://example.com/path',
			'https://example.com:444/path',
			'https://user:pass@example.com/path',
			'https://example.com/a/../private',
			'https://example.com/a/%2e%2e/private',
			'https://example.com\\@attacker.example/path',
			'//example.com/path',
		);
		$rejected = 0;

		foreach ( $unsafe as $url ) {
			try {
				$factory->from_input( array( 'url' => $url ) );
				self::fail( 'Unsafe URL was accepted: ' . $url );
			} catch ( InvalidArgumentException ) {
				++$rejected;
			}
		}
		self::assertSame( count( $unsafe ), $rejected );
	}

	/**
	 * A request cannot omit selectors or provide ambiguous selectors.
	 */
	public function test_requires_exactly_one_selector(): void {
		$factory = new SameSiteSeoTargetFactory( 'https://example.com/' );

		$invalid  = array(
			array(),
			array(
				'post_id' => 1,
				'url'     => 'https://example.com/',
			),
		);
		$rejected = 0;
		foreach ( $invalid as $input ) {
			try {
				$factory->from_input( $input );
				self::fail( 'Invalid selector combination was accepted.' );
			} catch ( InvalidArgumentException ) {
				++$rejected;
			}
		}
		self::assertSame( count( $invalid ), $rejected );
	}

	/**
	 * Invalid WordPress URL configuration does not break post-ID selectors.
	 */
	public function test_invalid_site_url_only_disables_url_selector(): void {
		$factory = new SameSiteSeoTargetFactory( 'http:/' );

		self::assertSame( array( 'post_id' => 9 ), $factory->from_input( array( 'post_id' => 9 ) )->to_array() );
		$this->expectException( InvalidArgumentException::class );
		$factory->from_input( array( 'url' => 'https://example.com/' ) );
	}
}
