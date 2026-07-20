<?php
/**
 * Unit tests for the DraftInput write DTO.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\TaxonomyAssignment;
use PHPUnit\Framework\TestCase;

/**
 * Verifies DraftInput input normalization.
 */
final class DraftInputTest extends TestCase {

	/**
	 * Minimal valid input builds a draft with default values.
	 */
	public function test_from_input_builds_minimal_draft(): void {
		$draft = DraftInput::from_input(
			array(
				'post_type' => 'post',
				'title'     => '  Hello  ',
			)
		);

		self::assertSame( 'post', $draft->post_type );
		self::assertSame( 'Hello', $draft->title );
		self::assertSame( '', $draft->block_markup );
		self::assertNull( $draft->excerpt );
		self::assertSame( array(), $draft->taxonomies );
		self::assertNull( $draft->idempotency_key );
	}

	/**
	 * Full input builds a draft with all optional fields populated.
	 */
	public function test_from_input_builds_full_draft(): void {
		$draft = DraftInput::from_input(
			array(
				'post_type'       => 'page',
				'title'           => 'Title',
				'block_markup'    => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
				'excerpt'         => 'Summary',
				'taxonomies'      => array(
					array(
						'taxonomy' => 'category',
						'term_ids' => array( 5 ),
					),
				),
				'idempotency_key' => 'abc-123',
			)
		);

		self::assertSame( 'page', $draft->post_type );
		self::assertSame( 'Summary', $draft->excerpt );
		self::assertCount( 1, $draft->taxonomies );
		self::assertContainsOnlyInstancesOf( TaxonomyAssignment::class, $draft->taxonomies );
		self::assertSame( 'abc-123', $draft->idempotency_key );
	}

	/**
	 * Empty titles are rejected.
	 */
	public function test_from_input_rejects_empty_title(): void {
		$this->expectException( InvalidArgumentException::class );

		DraftInput::from_input(
			array(
				'post_type' => 'post',
				'title'     => '   ',
			)
		);
	}

	/**
	 * Titles longer than 500 characters are rejected.
	 */
	public function test_from_input_rejects_overlong_title(): void {
		$this->expectException( InvalidArgumentException::class );

		DraftInput::from_input(
			array(
				'post_type' => 'post',
				'title'     => str_repeat( 'a', 501 ),
			)
		);
	}

	/**
	 * Invalid post type names are rejected.
	 */
	public function test_from_input_rejects_bad_post_type(): void {
		$this->expectException( InvalidArgumentException::class );

		DraftInput::from_input(
			array(
				'post_type' => 'Not Valid',
				'title'     => 'Title',
			)
		);
	}

	/**
	 * Unknown keys are rejected.
	 */
	public function test_from_input_rejects_unknown_key(): void {
		$this->expectException( InvalidArgumentException::class );

		DraftInput::from_input(
			array(
				'post_type' => 'post',
				'title'     => 'Title',
				'status'    => 'publish',
			)
		);
	}

	/**
	 * Idempotency keys with invalid characters are rejected.
	 */
	public function test_from_input_rejects_bad_idempotency_key(): void {
		$this->expectException( InvalidArgumentException::class );

		DraftInput::from_input(
			array(
				'post_type'       => 'post',
				'title'           => 'Title',
				'idempotency_key' => 'bad key with spaces',
			)
		);
	}
}
