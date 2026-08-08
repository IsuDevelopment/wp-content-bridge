<?php
/**
 * Unit tests for the pure request-conditional logic in LlmsTxtEndpoint.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Infrastructure\WordPress\LlmsTxtEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * Covers only the three static, WordPress-free methods the request handler
 * delegates to: `If-None-Match` matching, `If-Modified-Since` comparison, and
 * `generated_at` to HTTP-date conversion. The handler itself is not
 * unit-tested — it depends on `WP`, `add_action()`, and option reads that
 * only exist in a WordPress runtime, which `tests/Unit` deliberately never
 * loads.
 */
final class LlmsTxtEndpointTest extends TestCase {

	/**
	 * An exact strong-tag match is the common case: a client echoing back the
	 * `ETag` this endpoint sent it.
	 *
	 * @return void
	 */
	public function test_if_none_match_matches_exact_strong_etag(): void {
		self::assertTrue( LlmsTxtEndpoint::if_none_match_matches( '"abc123"', '"abc123"' ) );
	}

	/**
	 * A different tag must not match, or a stale client cache would never
	 * revalidate.
	 *
	 * @return void
	 */
	public function test_if_none_match_rejects_different_etag(): void {
		self::assertFalse( LlmsTxtEndpoint::if_none_match_matches( '"abc123"', '"def456"' ) );
	}

	/**
	 * `*` matches any current representation.
	 *
	 * @return void
	 */
	public function test_if_none_match_matches_wildcard(): void {
		self::assertTrue( LlmsTxtEndpoint::if_none_match_matches( '*', '"abc123"' ) );
	}

	/**
	 * A comma-separated list of cached tags — the shape a browser sends after
	 * having seen more than one prior version — must be scanned in full.
	 *
	 * @return void
	 */
	public function test_if_none_match_matches_within_comma_separated_list(): void {
		self::assertTrue( LlmsTxtEndpoint::if_none_match_matches( '"zzz", "abc123", "yyy"', '"abc123"' ) );
	}

	/**
	 * `If-None-Match` permits weak comparison, so a `W/`-prefixed candidate
	 * must still match the endpoint's strong tag once the prefix is stripped.
	 *
	 * @return void
	 */
	public function test_if_none_match_matches_weak_tag_by_stripping_prefix(): void {
		self::assertTrue( LlmsTxtEndpoint::if_none_match_matches( 'W/"abc123"', '"abc123"' ) );
	}

	/**
	 * No header at all must never be treated as a match.
	 *
	 * @return void
	 */
	public function test_if_none_match_rejects_empty_header(): void {
		self::assertFalse( LlmsTxtEndpoint::if_none_match_matches( '', '"abc123"' ) );
	}

	/**
	 * A request timestamp equal to, or later than, `Last-Modified` means the
	 * client's cached copy is still current.
	 *
	 * @return void
	 */
	public function test_if_modified_since_true_when_request_date_at_or_after_last_modified(): void {
		self::assertTrue(
			LlmsTxtEndpoint::if_modified_since_satisfied(
				'Tue, 08 Aug 2026 12:00:00 GMT',
				'Tue, 08 Aug 2026 12:00:00 GMT'
			)
		);
		self::assertTrue(
			LlmsTxtEndpoint::if_modified_since_satisfied(
				'Tue, 08 Aug 2026 13:00:00 GMT',
				'Tue, 08 Aug 2026 12:00:00 GMT'
			)
		);
	}

	/**
	 * A request timestamp earlier than `Last-Modified` means the client's
	 * cached copy predates the current snapshot and must not be treated as
	 * current.
	 *
	 * @return void
	 */
	public function test_if_modified_since_false_when_request_date_before_last_modified(): void {
		self::assertFalse(
			LlmsTxtEndpoint::if_modified_since_satisfied(
				'Tue, 08 Aug 2026 11:00:00 GMT',
				'Tue, 08 Aug 2026 12:00:00 GMT'
			)
		);
	}

	/**
	 * An unparseable header must fail closed, not be treated as a match.
	 *
	 * @return void
	 */
	public function test_if_modified_since_false_when_header_does_not_parse(): void {
		self::assertFalse(
			LlmsTxtEndpoint::if_modified_since_satisfied(
				'not a date',
				'Tue, 08 Aug 2026 12:00:00 GMT'
			)
		);
	}

	/**
	 * The stored `generated_at` shape (`Y-m-d\TH:i:s\Z`, UTC) must format to
	 * the exact RFC 7231 HTTP-date shape `Last-Modified` requires.
	 *
	 * @return void
	 */
	public function test_http_date_from_generated_at_formats_utc_timestamp(): void {
		self::assertSame(
			'Sat, 08 Aug 2026 12:34:56 GMT',
			LlmsTxtEndpoint::http_date_from_generated_at( '2026-08-08T12:34:56Z' )
		);
	}

	/**
	 * Malformed input must degrade to null rather than throw or produce a
	 * misleading date, since a caller uses null to skip the header entirely.
	 *
	 * @return void
	 */
	public function test_http_date_from_generated_at_returns_null_for_malformed_input(): void {
		self::assertNull( LlmsTxtEndpoint::http_date_from_generated_at( 'not-a-timestamp' ) );
	}
}
