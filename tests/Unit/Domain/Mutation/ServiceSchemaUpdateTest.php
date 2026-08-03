<?php
/**
 * Unit tests for the Service schema write DTO.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\ServiceSchemaUpdate;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the fixed Service field allowlist and nested bounds.
 */
final class ServiceSchemaUpdateTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * A complete local Service document is preserved in stable field order.
	 */
	public function test_builds_complete_service_update(): void {
		$update = ServiceSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'enabled'       => true,
				'name'          => 'Monitoring Wrocław',
				'service_type'  => 'Instalacja monitoringu',
				'description'   => 'Projekt, montaż i serwis systemów monitoringu.',
				'areas'         => array(
					array(
						'type' => 'City',
						'name' => 'Wrocław',
					),
					array(
						'type' => 'AdministrativeArea',
						'name' => 'dolnośląskie',
					),
				),
				'brands'        => array( 'Hikvision', 'Dahua' ),
				'catalog_name'  => 'Usługi monitoringu',
				'offers'        => array(
					array(
						'name'        => 'Montaż kamer',
						'description' => 'Dobór i instalacja kamer.',
					),
				),
			)
		);

		self::assertSame( 42, $update->post_id );
		self::assertSame(
			array( 'enabled', 'name', 'service_type', 'description', 'areas', 'brands', 'catalog_name', 'offers' ),
			$update->changed_fields()
		);
		self::assertSame( 'AdministrativeArea', $update->writable_fields()['areas'][1]['type'] );
		self::assertSame( 'Montaż kamer', $update->writable_fields()['offers'][0]['name'] );
	}

	/**
	 * False, empty strings, and empty arrays are explicit clear operations.
	 */
	public function test_preserves_explicit_clear_operations(): void {
		$update = ServiceSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'enabled'       => false,
				'catalog_name'  => '',
				'areas'         => array(),
				'brands'        => array(),
				'offers'        => array(),
			)
		);

		self::assertSame(
			array(
				'enabled'      => false,
				'areas'        => array(),
				'brands'       => array(),
				'catalog_name' => '',
				'offers'       => array(),
			),
			$update->writable_fields()
		);
	}

	/**
	 * Unknown fields cannot become arbitrary post metadata writes.
	 */
	public function test_rejects_unknown_field(): void {
		$this->expectException( InvalidArgumentException::class );

		ServiceSchemaUpdate::from_input(
			array(
				'post_id'         => 42,
				'version_token'   => self::TOKEN,
				'arbitrary_field' => '_private',
			)
		);
	}

	/**
	 * A request must contain at least one mutable Service field.
	 */
	public function test_rejects_empty_update(): void {
		$this->expectException( InvalidArgumentException::class );

		ServiceSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
			)
		);
	}

	/**
	 * AreaServed accepts only the Schema Extended type vocabulary.
	 */
	public function test_rejects_invalid_area_type(): void {
		$this->expectException( InvalidArgumentException::class );

		ServiceSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'areas'         => array(
					array(
						'type' => 'Region',
						'name' => 'Dolny Śląsk',
					),
				),
			)
		);
	}

	/**
	 * Brand delimiters are rejected because the provider stores a line list.
	 */
	public function test_rejects_ambiguous_brand_delimiter(): void {
		$this->expectException( InvalidArgumentException::class );

		ServiceSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'brands'        => array( 'Hikvision, Dahua' ),
			)
		);
	}

	/**
	 * Offer names must be non-empty and unique.
	 */
	public function test_rejects_duplicate_offer_names(): void {
		$this->expectException( InvalidArgumentException::class );

		ServiceSchemaUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'offers'        => array(
					array( 'name' => 'Montaż' ),
					array(
						'name'        => 'Montaż',
						'description' => 'Drugi wpis.',
					),
				),
			)
		);
	}
}
