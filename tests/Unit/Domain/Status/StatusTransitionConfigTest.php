<?php
/**
 * Unit tests for the status transition configuration value object.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Status;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Status\StatusTransitionConfig;
use IsuDev\WPContentBridge\Domain\Status\StatusTransitionGraph;
use PHPUnit\Framework\TestCase;

/**
 * Verifies `from_input()`'s rejection rules, deny-by-default for absent and
 * empty input, unknown-post-type inertness, and the `to_array()` round trip.
 */
final class StatusTransitionConfigTest extends TestCase {

	/**
	 * A well-formed configuration round-trips through `to_array()`.
	 */
	public function test_builds_and_round_trips_a_valid_configuration(): void {
		$input = array(
			'post' => array(
				array(
					'from' => 'draft',
					'to'   => 'pending',
				),
				array(
					'from' => 'pending',
					'to'   => 'draft',
				),
			),
		);

		$config = StatusTransitionConfig::from_input( $input );

		self::assertTrue( $config->graph->permits( 'post', 'draft', 'pending' ) );
		self::assertFalse( $config->graph->permits( 'post', 'draft', 'publish' ) );
		self::assertSame( $input, $config->to_array() );
	}

	/**
	 * An empty input denies every transition (deny-by-default).
	 */
	public function test_empty_input_denies_everything(): void {
		$config = StatusTransitionConfig::from_input( array() );

		self::assertFalse( $config->graph->permits( 'post', 'draft', 'pending' ) );
		self::assertEquals( StatusTransitionConfig::empty(), $config );
	}

	/**
	 * A status outside the fixed five is rejected.
	 */
	public function test_rejects_unknown_status(): void {
		$this->expectException( InvalidArgumentException::class );

		StatusTransitionConfig::from_input(
			array(
				'post' => array(
					array(
						'from' => 'draft',
						'to'   => 'trash',
					),
				),
			)
		);
	}

	/**
	 * A pair whose ends are equal is rejected.
	 */
	public function test_rejects_equal_endpoints(): void {
		$this->expectException( InvalidArgumentException::class );

		StatusTransitionConfig::from_input(
			array(
				'post' => array(
					array(
						'from' => 'draft',
						'to'   => 'draft',
					),
				),
			)
		);
	}

	/**
	 * Exceeding the post-type-count bound is rejected outright at this
	 * boundary too, not only inside {@see StatusTransitionGraph}.
	 */
	public function test_rejects_too_many_post_types(): void {
		$input = array();
		for ( $i = 0; $i <= StatusTransitionGraph::MAX_POST_TYPES; $i++ ) {
			$input[ 'type_' . $i ] = array(
				array(
					'from' => 'draft',
					'to'   => 'pending',
				),
			);
		}

		$this->expectException( InvalidArgumentException::class );

		StatusTransitionConfig::from_input( $input );
	}

	/**
	 * A malformed pair entry (missing key, wrong type) is rejected.
	 */
	public function test_rejects_malformed_pair_entry(): void {
		$this->expectException( InvalidArgumentException::class );

		StatusTransitionConfig::from_input(
			array( 'post' => array( array( 'from' => 'draft' ) ) )
		);
	}

	/**
	 * A post type that no longer exists in WordPress is still accepted and
	 * kept: whether it is currently registered is a concern checked at use,
	 * not here.
	 */
	public function test_unknown_post_type_is_kept_but_inert(): void {
		$config = StatusTransitionConfig::from_input(
			array(
				'no-longer-registered' => array(
					array(
						'from' => 'draft',
						'to'   => 'pending',
					),
				),
			)
		);

		self::assertTrue( $config->graph->permits( 'no-longer-registered', 'draft', 'pending' ) );
	}
}
