<?php
/**
 * Rendered-graph outcome invariants.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Seo;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Seo\RenderedGraph;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that a failure can never masquerade as a captured graph.
 */
final class RenderedGraphTest extends TestCase {

	/**
	 * A failure outcome carrying nodes is rejected, because that combination
	 * would let a caller trust data the capture never actually obtained.
	 */
	public function test_a_failed_outcome_cannot_carry_nodes(): void {
		$this->expectException( InvalidArgumentException::class );
		new RenderedGraph( array( array( '@type' => 'Thing' ) ), RenderedGraph::REQUEST_FAILED );
	}

	/**
	 * An unknown outcome is rejected rather than passed through as a string.
	 */
	public function test_an_unknown_outcome_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new RenderedGraph( array(), 'probably_fine' );
	}

	/**
	 * Transport detail is bounded, so a verbose upstream message cannot become
	 * unbounded ability output.
	 */
	public function test_transport_detail_is_bounded(): void {
		$this->expectException( InvalidArgumentException::class );
		RenderedGraph::failed( RenderedGraph::REQUEST_FAILED, 12, null, str_repeat( 'x', 501 ) );
	}

	/**
	 * The explanation never carries a duration or a transport message.
	 *
	 * It reaches ability output through the SEO warnings, and identical input
	 * must hash identically: a duration differs on every call, and a transport
	 * message can carry its own timing.
	 */
	public function test_the_explanation_is_deterministic(): void {
		$first  = RenderedGraph::failed( RenderedGraph::REQUEST_FAILED, 41, null, 'cURL error 28: timed out after 5001 milliseconds' );
		$second = RenderedGraph::failed( RenderedGraph::REQUEST_FAILED, 4718, null, 'cURL error 28: timed out after 5122 milliseconds' );

		self::assertSame( $first->diagnosis(), $second->diagnosis() );
		self::assertStringNotContainsString( '41', (string) $first->diagnosis() );
		self::assertStringNotContainsString( 'cURL', (string) $first->diagnosis() );
	}

	/**
	 * Success reports no diagnosis; every failure reports one.
	 */
	public function test_every_failure_explains_itself(): void {
		self::assertNull( ( new RenderedGraph( array( array( '@type' => 'Thing' ) ), RenderedGraph::CAPTURED ) )->diagnosis() );
		self::assertNull( ( new RenderedGraph( array(), RenderedGraph::CACHED ) )->diagnosis() );

		foreach ( array( RenderedGraph::EMPTY_GRAPH, RenderedGraph::NOT_SAME_ORIGIN, RenderedGraph::REQUEST_FAILED, RenderedGraph::HTTP_ERROR, RenderedGraph::BODY_TOO_LARGE, RenderedGraph::NO_HTTP_API ) as $outcome ) {
			$diagnosis = RenderedGraph::failed( $outcome, 41, 500 )->diagnosis();
			self::assertIsString( $diagnosis, $outcome . ' must explain itself.' );
			self::assertStringContainsString( $outcome, (string) $diagnosis );
		}
	}

	/**
	 * A cached hit is a success even when the cached page had no graph, so a
	 * warm cache cannot be mistaken for a transport failure.
	 */
	public function test_a_cached_empty_graph_is_still_a_success(): void {
		$cached = new RenderedGraph( array(), RenderedGraph::CACHED, 1 );

		self::assertTrue( $cached->is_success() );
		self::assertFalse( $cached->has_nodes() );
	}
}
