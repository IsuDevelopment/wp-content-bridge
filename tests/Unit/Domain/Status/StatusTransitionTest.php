<?php
/**
 * Unit tests for the status transition pair value object.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Status;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Status\ContentStatus;
use IsuDev\WPContentBridge\Domain\Status\StatusTransition;
use PHPUnit\Framework\TestCase;

/**
 * Verifies pair construction, the equal-endpoints rejection, the fixed
 * five-status vocabulary, and the full-grid enumeration.
 */
final class StatusTransitionTest extends TestCase {

	/**
	 * A pair whose ends are identical is rejected at construction.
	 */
	public function test_rejects_equal_endpoints(): void {
		$this->expectException( InvalidArgumentException::class );

		new StatusTransition( ContentStatus::DRAFT, ContentStatus::DRAFT );
	}

	/**
	 * `from_strings()` accepts any two distinct statuses from the fixed five.
	 */
	public function test_from_strings_accepts_known_statuses(): void {
		$transition = StatusTransition::from_strings( 'publish', 'draft' );

		self::assertSame( ContentStatus::PUBLISH, $transition->from );
		self::assertSame( ContentStatus::DRAFT, $transition->to );
	}

	/**
	 * A status outside the fixed five is rejected, not silently dropped.
	 */
	public function test_from_strings_rejects_unknown_status(): void {
		$this->expectException( InvalidArgumentException::class );

		StatusTransition::from_strings( 'draft', 'trash' );
	}

	/**
	 * `all_possible()` enumerates exactly the 5 x 4 grid, with no diagonal.
	 */
	public function test_all_possible_enumerates_full_grid_without_diagonal(): void {
		$pairs = StatusTransition::all_possible();

		self::assertCount( 20, $pairs );

		foreach ( $pairs as $pair ) {
			self::assertNotSame( $pair->from, $pair->to );
		}
	}
}
