<?php
/**
 * Local Schema projection tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\Yoast;

use IsuDev\WPContentBridge\Infrastructure\Yoast\LocalSchemaProjector;
use PHPUnit\Framework\TestCase;

/**
 * Ensures only public local-business Schema fields enter the normalized profile.
 */
final class LocalSchemaProjectorTest extends TestCase {

	/**
	 * Place entities are projected while unrelated and unknown fields are removed.
	 */
	public function test_projects_allowlisted_place_fields(): void {
		$result = ( new LocalSchemaProjector() )->project(
			array(
				array(
					'@type' => 'WebPage',
					'@id'   => 'https://example.com/page',
				),
				array(
					'@type'                     => array( 'Organization', 'Place', 'Dentist' ),
					'@id'                       => 'https://example.com/#organization',
					'name'                      => 'Example Clinic',
					'telephone'                 => '+48 123 456 789',
					'openingHoursSpecification' => array(
						array(
							'opens'  => '09:00',
							'closes' => '17:00',
						),
					),
					'address'                   => array(
						'@id'         => 'https://example.com/#address',
						'license_key' => 'must-not-leak',
					),
					'license_key'               => 'must-not-leak',
				),
				array(
					'@type'         => 'PostalAddress',
					'@id'           => 'https://example.com/#address',
					'streetAddress' => 'Example Street 1',
					'private_note'  => 'must-not-leak',
				),
			)
		);

		self::assertCount( 1, $result );
		self::assertSame( 'Example Clinic', $result[0]['name'] );
		self::assertSame( 'Example Street 1', $result[0]['address']['streetAddress'] );
		self::assertArrayNotHasKey( 'license_key', $result[0] );
		self::assertArrayNotHasKey( 'private_note', $result[0]['address'] );
	}

	/**
	 * A normal Organization without Place semantics is not treated as Local data.
	 */
	public function test_ignores_non_local_organization(): void {
		$result = ( new LocalSchemaProjector() )->project(
			array(
				array(
					'@type' => 'Organization',
					'name'  => 'Publisher',
				),
			)
		);

		self::assertSame( array(), $result );
	}

	/**
	 * Multi-location branch data is projected through the same bounded contract.
	 */
	public function test_projects_multi_location_branch_references(): void {
		$result = ( new LocalSchemaProjector() )->project(
			array(
				array(
					'@type'                     => 'LocalBusiness',
					'@id'                       => 'https://example.com/location/krakow/#business',
					'name'                      => 'Example Krakow',
					'branchOf'                  => array( '@id' => 'https://example.com/#organization' ),
					'address'                   => array( '@id' => 'https://example.com/location/krakow/#address' ),
					'geo'                       => array(
						'@type'     => 'GeoCoordinates',
						'latitude'  => 50.06143,
						'longitude' => 19.93658,
						'internal'  => 'must-not-leak',
					),
					'openingHoursSpecification' => array(
						array(
							'@type'     => 'OpeningHoursSpecification',
							'dayOfWeek' => array( 'Monday', 'Tuesday' ),
							'opens'     => '08:00',
							'closes'    => '16:00',
						),
					),
				),
				array(
					'@type'         => 'PostalAddress',
					'@id'           => 'https://example.com/location/krakow/#address',
					'streetAddress' => 'Rynek 1',
					'postalCode'    => '31-042',
				),
				array(
					'@type' => 'Organization',
					'@id'   => 'https://example.com/#organization',
					'name'  => 'Example Group',
				),
			)
		);

		self::assertCount( 1, $result );
		self::assertSame( 'Rynek 1', $result[0]['address']['streetAddress'] );
		self::assertSame( 50.06143, $result[0]['geo']['latitude'] );
		self::assertSame( 'Example Group', $result[0]['branchOf']['name'] );
		self::assertSame( array( 'Monday', 'Tuesday' ), $result[0]['openingHoursSpecification'][0]['dayOfWeek'] );
		self::assertArrayNotHasKey( 'internal', $result[0]['geo'] );
	}
}
