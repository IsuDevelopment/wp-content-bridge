<?php
/**
 * Unit tests for the TaxonomyAssignment write DTO.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\TaxonomyAssignment;
use PHPUnit\Framework\TestCase;

/**
 * Verifies TaxonomyAssignment input normalization.
 */
final class TaxonomyAssignmentTest extends TestCase {

	/**
	 * Valid assignments are accepted and deduplicated.
	 */
	public function test_from_input_accepts_valid_assignment(): void {
		$assignment = TaxonomyAssignment::from_input(
			array(
				'taxonomy' => 'category',
				'term_ids' => array( 3, 3, 7 ),
			)
		);

		self::assertSame( 'category', $assignment->taxonomy );
		self::assertSame( array( 3, 7 ), $assignment->term_ids );
	}

	/**
	 * Unknown keys are rejected.
	 */
	public function test_from_input_rejects_unknown_key(): void {
		$this->expectException( InvalidArgumentException::class );

		TaxonomyAssignment::from_input(
			array(
				'taxonomy' => 'category',
				'term_ids' => array( 1 ),
				'extra'    => true,
			)
		);
	}

	/**
	 * Invalid taxonomy names are rejected.
	 */
	public function test_from_input_rejects_bad_taxonomy_name(): void {
		$this->expectException( InvalidArgumentException::class );

		TaxonomyAssignment::from_input(
			array(
				'taxonomy' => 'Not A Taxonomy!',
				'term_ids' => array( 1 ),
			)
		);
	}

	/**
	 * Empty term ID arrays are rejected.
	 */
	public function test_from_input_rejects_empty_term_ids(): void {
		$this->expectException( InvalidArgumentException::class );

		TaxonomyAssignment::from_input(
			array(
				'taxonomy' => 'category',
				'term_ids' => array(),
			)
		);
	}

	/**
	 * Non-positive term IDs are rejected.
	 */
	public function test_from_input_rejects_non_positive_term_id(): void {
		$this->expectException( InvalidArgumentException::class );

		TaxonomyAssignment::from_input(
			array(
				'taxonomy' => 'category',
				'term_ids' => array( 0 ),
			)
		);
	}
}
