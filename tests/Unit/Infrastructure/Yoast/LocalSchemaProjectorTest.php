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
	 * A Yoast Local branch node links to its parent through parentOrganization.
	 *
	 * This mirrors the real Yoast Local 15.8 front-end graph, which uses
	 * `parentOrganization` (not schema.org `branchOf`) on the branch node.
	 */
	public function test_projects_yoast_branch_parent_organization(): void {
		$result = ( new LocalSchemaProjector() )->project(
			array(
				array(
					'@type'                     => array( 'Organization', 'Place', 'Dentist' ),
					'@id'                       => 'https://example.com/locations/warsaw/#local-branch-organization',
					'name'                      => 'Example Warsaw',
					'parentOrganization'        => array( '@id' => 'https://example.com/#organization' ),
					'address'                   => array( '@id' => 'https://example.com/locations/warsaw/#local-branch-place-address' ),
					'geo'                       => array(
						'@type'     => 'GeoCoordinates',
						'latitude'  => 52.22977,
						'longitude' => 21.01178,
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
					'@id'           => 'https://example.com/locations/warsaw/#local-branch-place-address',
					'streetAddress' => 'Marszałkowska 10',
					'postalCode'    => '00-590',
				),
				array(
					'@type' => array( 'Organization', 'Place' ),
					'@id'   => 'https://example.com/#organization',
					'name'  => 'Example Group',
				),
			)
		);

		// Both the branch node and the parent organization are Place-typed.
		self::assertCount( 2, $result );
		$branch = $result[0];
		self::assertSame( 'Example Warsaw', $branch['name'] );
		self::assertSame( 'Marszałkowska 10', $branch['address']['streetAddress'] );
		self::assertSame( 52.22977, $branch['geo']['latitude'] );
		self::assertSame( 'Example Group', $branch['parentOrganization']['name'] );
		self::assertSame( array( 'Monday', 'Tuesday' ), $branch['openingHoursSpecification'][0]['dayOfWeek'] );
		self::assertArrayNotHasKey( 'internal', $branch['geo'] );
	}

	/**
	 * The schema.org branchOf reference is still supported for provider neutrality.
	 */
	public function test_projects_branch_of_reference(): void {
		$result = ( new LocalSchemaProjector() )->project(
			array(
				array(
					'@type'    => array( 'LocalBusiness', 'Place' ),
					'@id'      => 'https://example.com/location/krakow/#business',
					'name'     => 'Example Krakow',
					'branchOf' => array( '@id' => 'https://example.com/#organization' ),
				),
				array(
					'@type' => 'Organization',
					'@id'   => 'https://example.com/#organization',
					'name'  => 'Example Group',
				),
			)
		);

		self::assertNotEmpty( $result );
		self::assertSame( 'Example Group', $result[0]['branchOf']['name'] );
	}
}
