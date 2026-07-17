<?php
/**
 * Ability schema contract tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Adapter\Abilities;

use IsuDev\WPContentBridge\Adapter\Abilities\AbilitySchemas;
use PHPUnit\Framework\TestCase;

/**
 * Locks public input bounds and response metadata.
 */
final class AbilitySchemasTest extends TestCase {

	/**
	 * Search input is strict, bounded, and safe for an omitted argument.
	 */
	public function test_search_input_is_strict_and_bounded(): void {
		$schema = AbilitySchemas::search_input();

		self::assertFalse( $schema['additionalProperties'] );
		self::assertIsObject( $schema['default'] );
		self::assertSame( 10, $schema['properties']['taxonomy']['maxItems'] );
		self::assertSame( 100, $schema['properties']['taxonomy']['items']['properties']['term_ids']['maxItems'] );
		self::assertNotEmpty( $schema['properties']['taxonomy']['items']['properties']['taxonomy']['description'] );
		self::assertNotEmpty( $schema['properties']['taxonomy']['items']['properties']['term_ids']['description'] );
	}

	/**
	 * Detail input explains its required identifier and bounds selections.
	 */
	public function test_get_input_documents_required_identifier(): void {
		$schema = AbilitySchemas::get_input();

		self::assertSame( array( 'post_id' ), $schema['required'] );
		self::assertNotEmpty( $schema['properties']['post_id']['description'] );
		self::assertSame( 3, $schema['properties']['representations']['maxItems'] );
		self::assertFalse( $schema['additionalProperties'] );
	}

	/**
	 * Public outputs disclose bounded-search and payload semantics.
	 */
	public function test_outputs_require_safety_metadata(): void {
		$search      = AbilitySchemas::search_output();
		$get         = AbilitySchemas::get_output();
		$diagnostics = AbilitySchemas::diagnostics_output();

		self::assertContains( 'total_is_exact', $search['properties']['pagination']['required'] );
		self::assertContains( 'has_more', $search['properties']['pagination']['required'] );
		self::assertContains( 'candidate_scan_limit', $search['properties']['pagination']['required'] );
		self::assertContains( 'payload', $get['required'] );
		self::assertContains( 'total_representation_bytes', $get['properties']['payload']['required'] );
		self::assertContains( 'seo_provider', $diagnostics['required'] );
		self::assertFalse( $diagnostics['properties']['seo_provider']['additionalProperties'] );
		self::assertContains( 'module_versions', $diagnostics['properties']['seo_provider']['required'] );
	}

	/**
	 * SEO selectors are exclusive and normalized output sections are strict.
	 */
	public function test_seo_contract_is_exclusive_and_strict(): void {
		$input  = AbilitySchemas::seo_input();
		$output = AbilitySchemas::seo_output();

		self::assertFalse( $input['additionalProperties'] );
		self::assertCount( 2, $input['oneOf'] );
		self::assertSame( array( 'post_id' ), $input['oneOf'][0]['required'] );
		self::assertSame( array( 'url' ), $input['oneOf'][1]['required'] );
		self::assertFalse( $output['additionalProperties'] );
		self::assertFalse( $output['properties']['provenance']['additionalProperties'] );
		self::assertSame( 200, $output['properties']['schema_graph']['maxItems'] );
	}

	/**
	 * Editorial context is optional-section based and strictly bounded.
	 */
	public function test_editorial_context_contract_is_strict_and_bounded(): void {
		$input  = AbilitySchemas::editorial_context_input();
		$output = AbilitySchemas::editorial_context_output();

		self::assertFalse( $input['additionalProperties'] );
		self::assertIsObject( $input['default'] );
		self::assertSame( 50, $input['properties']['recent_limit']['maximum'] );
		self::assertSame( 100, $input['properties']['terms_per_taxonomy']['maximum'] );
		self::assertFalse( $output['properties']['context']['additionalProperties'] );
		self::assertSame( 50, $output['properties']['context']['properties']['recent_content']['maxItems'] );
		self::assertFalse( $output['properties']['context']['properties']['local_businesses']['additionalProperties'] );
	}
}
