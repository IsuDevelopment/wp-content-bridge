<?php
/**
 * Unit tests for the per-post-type status transition allowlist.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Status;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Status\StatusTransition;
use IsuDev\WPContentBridge\Domain\Status\StatusTransitionGraph;
use PHPUnit\Framework\TestCase;

/**
 * Verifies `permits()`, `permitted_targets()`, deny-by-default, direction
 * asymmetry (ADR 0024's motivating case), unknown-post-type inertness, and
 * the two construction-time bounds.
 */
final class StatusTransitionGraphTest extends TestCase {

	/**
	 * A listed pair is permitted.
	 */
	public function test_permits_true_for_listed_pair(): void {
		$graph = new StatusTransitionGraph(
			array(
				'post' => array( StatusTransition::from_strings( 'draft', 'pending' ) ),
			)
		);

		self::assertTrue( $graph->permits( 'post', 'draft', 'pending' ) );
	}

	/**
	 * Listing `publish -> draft` must not imply `draft -> publish` —
	 * the exact case ADR 0024 exists for.
	 */
	public function test_direction_is_asymmetric(): void {
		$graph = new StatusTransitionGraph(
			array(
				'post' => array( StatusTransition::from_strings( 'publish', 'draft' ) ),
			)
		);

		self::assertTrue( $graph->permits( 'post', 'publish', 'draft' ) );
		self::assertFalse( $graph->permits( 'post', 'draft', 'publish' ) );
	}

	/**
	 * A post type absent from the graph denies every transition.
	 */
	public function test_unknown_post_type_is_inert_and_denies(): void {
		$graph = new StatusTransitionGraph(
			array(
				'post' => array( StatusTransition::from_strings( 'draft', 'pending' ) ),
			)
		);

		self::assertFalse( $graph->permits( 'page', 'draft', 'pending' ) );
		self::assertSame( array(), $graph->permitted_targets( 'page', 'draft' ) );
	}

	/**
	 * `permitted_targets()` lists only the targets reachable from the given status.
	 */
	public function test_permitted_targets_lists_targets_from_given_status(): void {
		$graph = new StatusTransitionGraph(
			array(
				'post' => array(
					StatusTransition::from_strings( 'draft', 'pending' ),
					StatusTransition::from_strings( 'draft', 'private' ),
					StatusTransition::from_strings( 'pending', 'draft' ),
				),
			)
		);

		self::assertSame( array( 'pending', 'private' ), $graph->permitted_targets( 'post', 'draft' ) );
	}

	/**
	 * An empty graph denies every transition (deny-by-default).
	 */
	public function test_empty_graph_denies_everything(): void {
		$graph = StatusTransitionGraph::empty();

		self::assertFalse( $graph->permits( 'post', 'draft', 'pending' ) );
	}

	/**
	 * Exceeding the per-post-type pair bound is a rejection, not a truncation.
	 */
	public function test_rejects_too_many_pairs_for_one_post_type(): void {
		$this->expectException( InvalidArgumentException::class );

		new StatusTransitionGraph(
			array( 'post' => array_merge( StatusTransition::all_possible(), array( StatusTransition::from_strings( 'draft', 'pending' ) ) ) )
		);
	}

	/**
	 * Exceeding the post-type-count bound is a rejection, not a truncation.
	 */
	public function test_rejects_too_many_post_types(): void {
		$rows = array();
		for ( $i = 0; $i <= StatusTransitionGraph::MAX_POST_TYPES; $i++ ) {
			$rows[ 'type_' . $i ] = array( StatusTransition::from_strings( 'draft', 'pending' ) );
		}

		$this->expectException( InvalidArgumentException::class );

		new StatusTransitionGraph( $rows );
	}
}
