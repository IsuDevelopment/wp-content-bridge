<?php
/**
 * Unit tests for the MutationResult DTO.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;

/**
 * Verifies MutationResult wire representation.
 */
final class MutationResultTest extends TestCase {

	/**
	 * Wire representation matches expected schema.
	 */
	public function test_to_array_emits_wire_shape(): void {
		$version = new VersionToken( 'abcdef0123456789', '2026-07-20 12:30:00' );
		$result  = new MutationResult( 42, 'post', 'draft', $version, array( 'title', 'content' ), true );

		$array = $result->to_array();

		self::assertSame( '1.0', $array['schema_version'] );
		self::assertSame( 42, $array['post_id'] );
		self::assertSame( 'post', $array['post_type'] );
		self::assertSame( 'draft', $array['status'] );
		self::assertSame( 'abcdef0123456789:2026-07-20 12:30:00', $array['version_token'] );
		self::assertSame( array( 'title', 'content' ), $array['changed_fields'] );
		self::assertTrue( $array['created'] );
		self::assertSame(
			array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
			$array['provenance']
		);
	}

	/**
	 * The wire representation includes effective_seo when present.
	 */
	public function test_to_array_includes_effective_seo_when_present(): void {
		$version = new VersionToken( 'abcdef0123456789', '2026-07-20 12:30:00' );
		$result  = new MutationResult(
			42,
			'post',
			'publish',
			$version,
			array( 'seo_title' ),
			false,
			array( 'schema_version' => '1.1' )
		);

		$array = $result->to_array();

		self::assertSame( array( 'schema_version' => '1.1' ), $array['effective_seo'] );
	}

	/**
	 * The wire representation omits effective_seo when absent.
	 */
	public function test_to_array_omits_effective_seo_when_absent(): void {
		$version = new VersionToken( 'abcdef0123456789', '2026-07-20 12:30:00' );
		$result  = new MutationResult( 42, 'post', 'draft', $version, array( 'title' ), true );

		self::assertArrayNotHasKey( 'effective_seo', $result->to_array() );
	}
}
