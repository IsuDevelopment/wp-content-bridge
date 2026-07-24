<?php
/**
 * Provider-neutral SEO document tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Seo;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Seo\SeoCompleteness;
use IsuDev\WPContentBridge\Domain\Seo\SeoDocument;
use IsuDev\WPContentBridge\Domain\Seo\SeoField;
use IsuDev\WPContentBridge\Domain\Seo\SeoProviderStatus;
use IsuDev\WPContentBridge\Domain\Seo\SeoValueState;
use PHPUnit\Framework\TestCase;

/**
 * Locks the normalized allowlist, provenance, and resource bounds.
 */
final class SeoDocumentTest extends TestCase {

	/**
	 * Normalized sections preserve value state and provider provenance.
	 */
	public function test_serializes_normalized_sections_and_provenance(): void {
		$document = new SeoDocument(
			array(
				'title' => new SeoField( 'Configured title', SeoValueState::EXPLICIT, 'fixture.configured' ),
			),
			array(
				'title'  => new SeoField( 'Resolved title', SeoValueState::GENERATED, 'fixture.resolved' ),
				'robots' => new SeoField( array( 'index' => true ), SeoValueState::INHERITED, 'fixture.resolved' ),
			),
			array(
				'seo' => new SeoField( 82, SeoValueState::EXPLICIT, 'fixture.analysis' ),
			),
			array(
				array(
					'@type' => 'WebPage',
					'@id'   => 'https://example.com/#webpage',
				),
			),
			new SeoProviderStatus( 'fixture', '1.2.3', true, array( 'local' ), array( 'analysis', 'schema' ), array( 'local' => '15.8' ) ),
			SeoCompleteness::PARTIAL,
			array( 'Configured social metadata is unavailable.' )
		);

		$output = $document->to_array();

		self::assertSame(
			array(
				'title' => array(
					'value'  => 'Configured title',
					'state'  => 'explicit',
					'source' => 'fixture.configured',
					'reason' => null,
				),
			),
			$output['configured']
		);
		self::assertSame(
			array(
				'provider'                     => array(
					'provider'        => 'fixture',
					'version'         => '1.2.3',
					'detected'        => true,
					'modules'         => array( 'local' ),
					'module_versions' => array( 'local' => '15.8' ),
					'capabilities'    => array( 'analysis', 'schema' ),
				),
				'normalization_schema_version' => '1.3',
				'completeness'                 => 'partial',
			),
			$output['provenance']
		);
	}

	/**
	 * Arbitrary provider keys cannot become public fields.
	 */
	public function test_rejects_non_allowlisted_field(): void {
		$this->expectException( InvalidArgumentException::class );

		new SeoDocument(
			array( 'license_key' => new SeoField( 'secret', SeoValueState::EXPLICIT, 'fixture.configured' ) ),
			array(),
			array(),
			array(),
			new SeoProviderStatus( 'fixture', null, true, array(), array() ),
			SeoCompleteness::PARTIAL,
			array()
		);
	}

	/**
	 * Objects cannot be hidden inside normalized field arrays.
	 */
	public function test_rejects_non_json_field_value(): void {
		$this->expectException( InvalidArgumentException::class );

		new SeoField( array( 'unsafe' => new \stdClass() ), SeoValueState::EXPLICIT, 'fixture.configured' );
	}

	/**
	 * Provider-native Schema remains bounded by node count.
	 */
	public function test_rejects_schema_graph_over_node_limit(): void {
		$this->expectException( InvalidArgumentException::class );

		new SeoDocument(
			array(),
			array(),
			array(),
			array_fill( 0, SeoDocument::MAX_SCHEMA_NODES + 1, array( '@type' => 'Thing' ) ),
			new SeoProviderStatus( 'fixture', null, true, array(), array() ),
			SeoCompleteness::PARTIAL,
			array()
		);
	}
}
