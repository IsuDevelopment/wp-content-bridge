<?php
/**
 * Redirect source path validation tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A redirect source is a bounded, exact, site-relative path — never a full
 * URL, a regex, or a query/fragment target (P0 scope, ADR 0026 s5).
 */
final class RedirectSourcePathTest extends TestCase {

	/**
	 * A plain site-relative path is accepted and returned unchanged.
	 */
	public function test_accepts_a_plain_site_relative_path(): void {
		self::assertSame( '/old-page', ( new RedirectSourcePath( '/old-page' ) )->value() );
	}

	/**
	 * Nested segments are a normal, supported shape.
	 */
	public function test_accepts_a_nested_path(): void {
		self::assertSame( '/2024/old-post', ( new RedirectSourcePath( '/2024/old-post' ) )->value() );
	}

	/**
	 * Every stable ability in this plugin rejects the empty string rather than
	 * treating it as "unset".
	 */
	public function test_rejects_empty_string(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectSourcePath( '' );
	}

	/**
	 * A relative path without a leading slash is ambiguous against the site
	 * root and is rejected rather than guessed at.
	 */
	public function test_rejects_path_without_leading_slash(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectSourcePath( 'old-page' );
	}

	/**
	 * A full URL is a target concern, not a source concern; accepting one here
	 * would silently ignore the host the caller specified.
	 */
	public function test_rejects_an_absolute_url(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectSourcePath( 'https://example.com/old-page' );
	}

	/**
	 * Traversal segments could otherwise be used to reference a path outside
	 * the intended tree once resolved by a provider.
	 */
	public function test_rejects_path_traversal_segment(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectSourcePath( '/a/../b' );
	}

	/**
	 * A source is a path only; a query string belongs to source-matching
	 * configuration this plugin does not expose in P0.
	 */
	public function test_rejects_query_string(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectSourcePath( '/old-page?x=1' );
	}

	/**
	 * Fragments are never sent to the server and can never match a request.
	 */
	public function test_rejects_fragment(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectSourcePath( '/old-page#section' );
	}

	/**
	 * P0 is exact, non-regex matching only; characters with special meaning to
	 * either provider's regex mode must not reach the adapter as if literal.
	 *
	 * @param string $path Candidate path containing one pattern-matching character.
	 */
	#[DataProvider( 'provide_pattern_characters' )]
	public function test_rejects_pattern_metacharacters( string $path ): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectSourcePath( $path );
	}

	/**
	 * Provides paths containing one forbidden pattern-matching character each.
	 *
	 * @return array<string, list<string>>
	 */
	public static function provide_pattern_characters(): array {
		return array(
			'asterisk'    => array( '/old*page' ),
			'parentheses' => array( '/old(page)' ),
			'brackets'    => array( '/old[page]' ),
			'braces'      => array( '/old{page}' ),
			'caret'       => array( '/old^page' ),
			'dollar'      => array( '/old$page' ),
			'backslash'   => array( '/old\\page' ),
		);
	}

	/**
	 * A doubled slash is never a distinct WordPress permalink and is rejected
	 * rather than silently collapsed.
	 */
	public function test_rejects_double_slash(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectSourcePath( '/old//page' );
	}

	/**
	 * Control characters have no legitimate place in a permalink path.
	 */
	public function test_rejects_control_characters(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectSourcePath( "/old\npage" );
	}

	/**
	 * Bounded like every other public input in this plugin.
	 */
	public function test_rejects_overlong_path(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectSourcePath( '/' . str_repeat( 'a', 2048 ) );
	}
}
