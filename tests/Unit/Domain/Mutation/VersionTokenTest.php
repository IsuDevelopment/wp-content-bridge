<?php
/**
 * Version token tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the concurrency token round-trips and compares safely.
 */
final class VersionTokenTest extends TestCase {

	/**
	 * The wire form is hash-first so the colon-bearing timestamp is parseable.
	 */
	public function test_round_trips_through_string_form(): void {
		$token  = VersionToken::for_content( '2026-07-20 12:30:00', 'Title', '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', 'draft' );
		$parsed = VersionToken::from_string( $token->to_string() );

		self::assertSame( $token->content_hash, $parsed->content_hash );
		self::assertSame( '2026-07-20 12:30:00', $parsed->modified_gmt );
		self::assertTrue( $token->equals( $parsed ) );
	}

	/**
	 * Any change to title, content, or status changes the hash.
	 */
	public function test_hash_changes_when_content_changes(): void {
		$base   = VersionToken::for_content( '2026-07-20 12:30:00', 'Title', 'Body', 'draft' );
		$body   = VersionToken::for_content( '2026-07-20 12:30:00', 'Title', 'Body edited', 'draft' );
		$status = VersionToken::for_content( '2026-07-20 12:30:00', 'Title', 'Body', 'publish' );

		self::assertFalse( $base->equals( $body ) );
		self::assertFalse( $base->equals( $status ) );
	}

	/**
	 * Two posts modified at different times never compare equal.
	 */
	public function test_differs_when_modified_time_differs(): void {
		$a = VersionToken::for_content( '2026-07-20 12:30:00', 'T', 'B', 'draft' );
		$b = VersionToken::for_content( '2026-07-20 12:31:00', 'T', 'B', 'draft' );

		self::assertFalse( $a->equals( $b ) );
	}

	/**
	 * Malformed token strings are rejected.
	 *
	 * @param string $value Malformed token.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'malformed_tokens' )]
	public function test_rejects_malformed_tokens( string $value ): void {
		$this->expectException( InvalidArgumentException::class );

		VersionToken::from_string( $value );
	}

	/**
	 * Provides malformed token strings.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function malformed_tokens(): iterable {
		yield 'empty' => array( '' );
		yield 'no separator' => array( 'abcdef0123456789' );
		yield 'short hash' => array( 'abc:2026-07-20 12:30:00' );
		yield 'non hex hash' => array( 'ZZZZZZZZZZZZZZZZ:2026-07-20 12:30:00' );
		yield 'missing timestamp' => array( 'abcdef0123456789:' );
	}
	/**
	 * The meta fingerprint is part of the hash.
	 *
	 * Without it the token was blind to every meta-only write this plugin
	 * performs — SEO fields and Custom/Service Schema all live in post meta —
	 * so a successful write returned the token it was given and a concurrent
	 * write could not be detected.
	 */
	public function test_meta_fingerprint_changes_the_token(): void {
		$before = VersionToken::for_content( '2026-09-02 10:00:00', 'Title', 'content', 'publish', 'aaaaaaaaaaaaaaaa' );
		$after  = VersionToken::for_content( '2026-09-02 10:00:00', 'Title', 'content', 'publish', 'bbbbbbbbbbbbbbbb' );

		self::assertNotSame( $before->to_string(), $after->to_string() );
		self::assertFalse( $before->equals( $after ) );
	}

	/**
	 * Identical inputs still produce identical tokens, so an unchanged post
	 * does not spuriously conflict.
	 */
	public function test_identical_inputs_produce_the_same_token(): void {
		$first  = VersionToken::for_content( '2026-09-02 10:00:00', 'Title', 'content', 'publish', 'aaaaaaaaaaaaaaaa' );
		$second = VersionToken::for_content( '2026-09-02 10:00:00', 'Title', 'content', 'publish', 'aaaaaaaaaaaaaaaa' );

		self::assertTrue( $first->equals( $second ) );
	}
}
