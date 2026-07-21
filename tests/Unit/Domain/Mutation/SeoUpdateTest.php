<?php
/**
 * Unit tests for the SeoUpdate write DTO.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\SeoUpdate;
use PHPUnit\Framework\TestCase;

/**
 * Verifies SeoUpdate validation and field extraction.
 */
final class SeoUpdateTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * A single allowlisted field builds a valid update.
	 */
	public function test_from_input_builds_single_field_update(): void {
		$update = SeoUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'seo_title'     => 'New title',
			)
		);

		self::assertSame( 42, $update->post_id );
		self::assertSame( 'New title', $update->seo_title );
		self::assertNull( $update->meta_description );
		self::assertSame( array( 'seo_title' ), $update->changed_fields() );
		self::assertSame( array( 'seo_title' => 'New title' ), $update->writable_fields() );
	}

	/**
	 * All ten allowlisted fields, including booleans, build a valid update.
	 */
	public function test_from_input_builds_full_update_including_booleans(): void {
		$update = SeoUpdate::from_input(
			array(
				'post_id'             => 7,
				'version_token'       => self::TOKEN,
				'seo_title'           => 'T',
				'meta_description'    => 'D',
				'focus_keyphrase'     => 'kp',
				'canonical'           => 'https://example.com/post',
				'robots_index'        => false,
				'robots_follow'       => true,
				'og_title'            => 'OG T',
				'og_description'      => 'OG D',
				'twitter_title'       => 'TW T',
				'twitter_description' => 'TW D',
			)
		);

		self::assertSame(
			array(
				'seo_title',
				'meta_description',
				'focus_keyphrase',
				'canonical',
				'robots_index',
				'robots_follow',
				'og_title',
				'og_description',
				'twitter_title',
				'twitter_description',
			),
			$update->changed_fields()
		);
		self::assertFalse( $update->writable_fields()['robots_index'] );
		self::assertTrue( $update->writable_fields()['robots_follow'] );
	}

	/**
	 * A key outside the allowlist is rejected.
	 */
	public function test_from_input_rejects_unknown_key(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
				'schema_type'   => 'Article',
			)
		);
	}

	/**
	 * At least one updatable field is required.
	 */
	public function test_from_input_rejects_no_updatable_fields(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
			)
		);
	}

	/**
	 * A missing version token is rejected.
	 */
	public function test_from_input_rejects_missing_version_token(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'   => 7,
				'seo_title' => 'T',
			)
		);
	}

	/**
	 * A non-positive post ID is rejected.
	 */
	public function test_from_input_rejects_non_positive_post_id(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'       => 0,
				'version_token' => self::TOKEN,
				'seo_title'     => 'T',
			)
		);
	}

	/**
	 * A non-boolean robots value is rejected.
	 */
	public function test_from_input_rejects_non_boolean_robots_value(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
				'robots_index'  => 'yes',
			)
		);
	}

	/**
	 * A non-http(s) canonical URL is rejected.
	 */
	public function test_from_input_rejects_non_http_canonical(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
				'canonical'     => 'javascript:alert(1)',
			)
		);
	}

	/**
	 * An overlong meta description is rejected.
	 */
	public function test_from_input_rejects_overlong_meta_description(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'          => 7,
				'version_token'    => self::TOKEN,
				'meta_description' => str_repeat( 'a', 321 ),
			)
		);
	}
}
