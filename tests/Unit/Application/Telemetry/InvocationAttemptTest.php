<?php
/**
 * Invocation attempt DTO contract tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Telemetry;

use IsuDev\WPContentBridge\Application\Telemetry\InvocationAttempt;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locks the one property that makes this DTO safe to store: it has nowhere to
 * put ability input, an error message, or a result payload.
 */
final class InvocationAttemptTest extends TestCase {

	/**
	 * The record can hold nothing but shapes.
	 *
	 * `wp_ability_invoked` hands the listener the raw ability input, which can
	 * carry the site's content. The absence of a field is what keeps it out of
	 * storage, so a new field here is a security decision and this test is where
	 * it gets noticed.
	 */
	public function test_the_record_has_no_field_that_could_hold_content(): void {
		$properties = array();
		foreach ( ( new ReflectionClass( InvocationAttempt::class ) )->getProperties() as $property ) {
			$properties[] = $property->getName();
		}
		sort( $properties );

		self::assertSame(
			array( 'ability', 'channel', 'occurred_at', 'outcome', 'user_id' ),
			$properties
		);
	}

	/**
	 * Completing an attempt changes the outcome and nothing else.
	 */
	public function test_completing_preserves_every_other_field(): void {
		$attempt   = new InvocationAttempt( 'wp-content-bridge/get-content', 7, 'rest', InvocationAttempt::ATTEMPTED, '2026-09-01 10:00:00' );
		$completed = $attempt->completed();

		self::assertSame( InvocationAttempt::COMPLETED, $completed->outcome );
		self::assertSame( $attempt->ability, $completed->ability );
		self::assertSame( $attempt->user_id, $completed->user_id );
		self::assertSame( $attempt->channel, $completed->channel );
		self::assertSame( $attempt->occurred_at, $completed->occurred_at );
		self::assertSame( InvocationAttempt::ATTEMPTED, $attempt->outcome, 'completed() must not mutate the original.' );
	}

	/**
	 * The stored shape is exactly the five declared fields.
	 */
	public function test_storable_shape_is_closed(): void {
		$stored = ( new InvocationAttempt( 'wp-content-bridge/get-content', 0, 'cli', InvocationAttempt::ATTEMPTED, '2026-09-01 10:00:00' ) )->to_array();

		self::assertSame(
			array( 'ability', 'user_id', 'channel', 'outcome', 'occurred_at' ),
			array_keys( $stored )
		);
	}
}
