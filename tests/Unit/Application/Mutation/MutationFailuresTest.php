<?php
/**
 * Mutation failure contract tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\Mutation\InvalidBlockMarkup;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use PHPUnit\Framework\TestCase;

/**
 * Verifies stable error codes and carried detail.
 */
final class MutationFailuresTest extends TestCase {

	/**
	 * Verifies MutationConflict returns stable error code.
	 */
	public function test_conflict_exposes_stable_code(): void {
		self::assertSame( 'wpcb_conflict', ( new MutationConflict( 'stale' ) )->error_code() );
	}

	/**
	 * Verifies InvalidBlockMarkup carries reasons and error code.
	 */
	public function test_invalid_block_markup_carries_reasons(): void {
		$failure = new InvalidBlockMarkup( array( 'block 0: unregistered type core/does-not-exist' ) );

		self::assertSame( 'wpcb_invalid_blocks', $failure->error_code() );
		self::assertSame( array( 'block 0: unregistered type core/does-not-exist' ), $failure->reasons() );
	}

	/**
	 * Verifies SeoFieldUnsupported carries fields and error code.
	 */
	public function test_seo_unsupported_carries_fields(): void {
		$failure = new SeoFieldUnsupported( array( 'twitter_card', 'schema_type' ) );

		self::assertSame( 'wpcb_seo_field_unsupported', $failure->error_code() );
		self::assertSame( array( 'twitter_card', 'schema_type' ), $failure->fields() );
	}
}
