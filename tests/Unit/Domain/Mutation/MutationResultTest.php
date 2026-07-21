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
}
