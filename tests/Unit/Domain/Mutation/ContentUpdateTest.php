<?php
/**
 * Unit tests for the ContentUpdate write DTO.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ContentUpdate input normalization.
 */
final class ContentUpdateTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * Valid title-only update is built correctly.
	 */
	public function test_from_input_builds_title_only_update(): void {
		$update = ContentUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'title'         => 'New title',
			)
		);

		self::assertSame( 42, $update->post_id );
		self::assertSame( 'New title', $update->title );
		self::assertNull( $update->block_markup );
		self::assertNull( $update->taxonomies );
		self::assertSame( array( 'title' ), $update->changed_fields() );
		self::assertSame( self::TOKEN, $update->expected_version->to_string() );
	}

	/**
	 * Changed fields lists all present fields with correct names.
	 */
	public function test_changed_fields_lists_every_present_field(): void {
		$update = ContentUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
				'title'         => 'T',
				'block_markup'  => '',
				'excerpt'       => 'E',
				'taxonomies'    => array(
					array(
						'taxonomy' => 'category',
						'term_ids' => array( 1 ),
					),
				),
			)
		);

		self::assertSame(
			array( 'title', 'content', 'excerpt', 'taxonomies' ),
			$update->changed_fields()
		);
	}

	/**
	 * Updates with no updatable fields are rejected.
	 */
	public function test_from_input_rejects_no_updatable_fields(): void {
		$this->expectException( InvalidArgumentException::class );

		ContentUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
			)
		);
	}

	/**
	 * Missing version token is rejected.
	 */
	public function test_from_input_rejects_missing_version_token(): void {
		$this->expectException( InvalidArgumentException::class );

		ContentUpdate::from_input(
			array(
				'post_id' => 7,
				'title'   => 'T',
			)
		);
	}

	/**
	 * Non-positive post IDs are rejected.
	 */
	public function test_from_input_rejects_non_positive_post_id(): void {
		$this->expectException( InvalidArgumentException::class );

		ContentUpdate::from_input(
			array(
				'post_id'       => 0,
				'version_token' => self::TOKEN,
				'title'         => 'T',
			)
		);
	}

	/**
	 * Status field in input is rejected.
	 */
	public function test_from_input_rejects_status_field(): void {
		$this->expectException( InvalidArgumentException::class );

		ContentUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
				'status'        => 'publish',
			)
		);
	}
}
