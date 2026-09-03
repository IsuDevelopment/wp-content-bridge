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
		self::assertContains( 'mcp_projection', $diagnostics['required'] );
		self::assertFalse( $diagnostics['properties']['mcp_projection']['additionalProperties'] );
		self::assertSame(
			array( 'enabled', 'endpoint', 'projected_abilities' ),
			$diagnostics['properties']['mcp_projection']['required']
		);
	}

	/**
	 * Diagnostics report redirect provider detection separately from the
	 * feature switch (ADR 0026 s4). An operator whose redirect abilities are
	 * missing must be able to tell "the switch is off" from "no redirect
	 * plugin is installed", and one merged answer could not.
	 */
	public function test_diagnostics_report_redirect_providers_and_the_switch_separately(): void {
		$diagnostics = AbilitySchemas::diagnostics_output();
		$redirects   = $diagnostics['properties']['redirects'];

		self::assertContains( 'redirects', $diagnostics['required'] );
		self::assertSame( array( 'enabled', 'providers' ), $redirects['required'] );
		self::assertFalse( $redirects['additionalProperties'] );
		self::assertSame(
			array( 'provider', 'version', 'detected', 'capabilities' ),
			$redirects['properties']['providers']['items']['required']
		);
	}

	/**
	 * Diagnostics make an unsupported WordPress diagnosable from one read.
	 *
	 * `abilities_api` is a boolean that cannot tell 7.0 from 7.1, so "the
	 * feature is missing" and "the API is missing" used to look identical here.
	 * The requirement and the one capability this plugin's own projection
	 * depends on are now both reported, and both are required rather than
	 * optional so their absence is a contract failure instead of a silent gap.
	 */
	public function test_diagnostics_report_the_supported_wordpress_surface(): void {
		$diagnostics = AbilitySchemas::diagnostics_output();

		self::assertContains( 'minimum_wordpress_version', $diagnostics['required'] );
		self::assertContains( 'abilities_api_features', $diagnostics['required'] );
		self::assertFalse( $diagnostics['properties']['abilities_api_features']['additionalProperties'] );
		self::assertSame(
			array( 'declarative_filtering' ),
			$diagnostics['properties']['abilities_api_features']['required']
		);
		self::assertNotEmpty(
			$diagnostics['properties']['abilities_api_features']['properties']['declarative_filtering']['description']
		);
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
		self::assertArrayHasKey( 'keyphrase_synonyms', $output['properties']['configured']['properties'] );
		self::assertArrayHasKey( 'related_keyphrases', $output['properties']['configured']['properties'] );
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

	/**
	 * Media reads use strict object envelopes and deterministic item identity.
	 */
	public function test_media_contract_is_strict_and_bounded(): void {
		$input  = AbilitySchemas::media_search_input();
		$output = AbilitySchemas::media_search_output();
		$detail = AbilitySchemas::media_by_id_output();

		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( 100, $input['properties']['per_page']['maximum'] );
		self::assertSame( 'object', $output['type'] );
		self::assertContains( 'items', $output['required'] );
		self::assertFalse( $output['properties']['items']['items']['additionalProperties'] );
		self::assertContains( 'filename', $output['properties']['items']['items']['required'] );
		self::assertContains( 'item', $detail['required'] );
	}

	/**
	 * Pattern discovery is metadata-first, strict, and payload bounded.
	 */
	public function test_pattern_contract_is_strict_and_bounded(): void {
		$input  = AbilitySchemas::pattern_list_input();
		$output = AbilitySchemas::pattern_list_output();

		self::assertFalse( $input['additionalProperties'] );
		self::assertFalse( $input['properties']['include_content']['default'] );
		self::assertSame( 50, $input['properties']['per_page']['maximum'] );
		self::assertSame( 50, $output['properties']['items']['maxItems'] );
		self::assertFalse( $output['properties']['items']['items']['additionalProperties'] );
		self::assertContains( 'content_bytes', $output['properties']['items']['items']['required'] );
		self::assertContains( 'candidate_scan_limit', $output['properties']['pagination']['required'] );
		self::assertFalse( $output['properties']['limits']['additionalProperties'] );
	}

	/**
	 * Premium keyphrase writes are explicit, bounded arrays rather than raw
	 * provider JSON.
	 */
	public function test_update_seo_premium_keyphrases_are_strict_and_bounded(): void {
		$input = AbilitySchemas::update_seo_input();

		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( 20, $input['properties']['keyphrase_synonyms']['maxItems'] );
		self::assertSame( 191, $input['properties']['keyphrase_synonyms']['items']['maxLength'] );
		self::assertSame( '^[^,]+$', $input['properties']['keyphrase_synonyms']['items']['pattern'] );
		self::assertSame( 20, $input['properties']['related_keyphrases']['maxItems'] );
		self::assertSame( 191, $input['properties']['related_keyphrases']['items']['maxLength'] );
	}

	/**
	 * Advanced robots and social images use normalized, non-provider inputs.
	 */
	public function test_update_seo_advanced_robots_and_social_images_are_explicit(): void {
		$input = AbilitySchemas::update_seo_input();

		foreach ( array( 'robots_noarchive', 'robots_noimageindex', 'robots_nosnippet' ) as $field ) {
			self::assertSame( array( 'boolean', 'null' ), $input['properties'][ $field ]['type'] );
		}
		foreach ( array( 'og_image_id', 'twitter_image_id' ) as $field ) {
			self::assertSame( array( 'integer', 'null' ), $input['properties'][ $field ]['type'] );
			self::assertSame( 0, $input['properties'][ $field ]['minimum'] );
		}
		self::assertArrayNotHasKey( 'og_image', $input['properties'] );
		self::assertArrayNotHasKey( 'twitter_image', $input['properties'] );
	}

	/**
	 * Service schema writes expose only bounded, typed Service and OfferCatalog fields.
	 */
	public function test_update_service_schema_contract_is_strict_and_bounded(): void {
		$input          = AbilitySchemas::update_service_schema_input();
		$output         = AbilitySchemas::update_service_schema_output();
		$get_input      = AbilitySchemas::get_service_schema_input();
		$get_output     = AbilitySchemas::get_service_schema_output();
		$preview_input  = AbilitySchemas::preview_service_schema_input();
		$preview_output = AbilitySchemas::preview_service_schema_output();

		self::assertSame( array( 'post_id', 'version_token' ), $input['required'] );
		self::assertSame( array( 'post_id' ), $get_input['required'] );
		self::assertSame( array( 'post_id', 'version_token' ), $preview_input['required'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( 100, $input['properties']['areas']['maxItems'] );
		self::assertSame( array( 'City', 'AdministrativeArea', 'Country' ), $input['properties']['areas']['items']['properties']['type']['enum'] );
		self::assertFalse( $input['properties']['areas']['items']['additionalProperties'] );
		self::assertSame( 50, $input['properties']['brands']['maxItems'] );
		self::assertSame( 20, $input['properties']['offers']['maxItems'] );
		self::assertFalse( $input['properties']['offers']['items']['additionalProperties'] );
		self::assertContains( 'effective_service_schema', $output['required'] );
		self::assertContains( 'service_schema', $get_output['required'] );
		self::assertContains( 'current_service_schema', $preview_output['required'] );
		self::assertContains( 'preview_service_schema', $preview_output['required'] );
		self::assertContains( 'writes_performed', $preview_output['required'] );
		self::assertSame( 'boolean', $preview_output['properties']['writes_performed']['type'] );
		self::assertFalse( $output['properties']['effective_service_schema']['additionalProperties'] );
		self::assertFalse( $output['properties']['effective_service_schema']['properties']['provider']['additionalProperties'] );
	}

	/**
	 * Custom Schema exposes bounded JSON as data without opening WordPress fields.
	 */
	public function test_custom_schema_contract_is_strict_bounded_and_versioned(): void {
		$input          = AbilitySchemas::update_custom_schema_input();
		$output         = AbilitySchemas::update_custom_schema_output();
		$get_input      = AbilitySchemas::get_custom_schema_input();
		$get_output     = AbilitySchemas::get_custom_schema_output();
		$preview_output = AbilitySchemas::preview_custom_schema_output();

		self::assertSame( array( 'post_id', 'version_token' ), $input['required'] );
		self::assertSame( array( 'post_id' ), $get_input['required'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'post_id', 'version_token', 'enabled', 'source' ), array_keys( $input['properties'] ) );
		self::assertSame( 100000, $input['properties']['source']['maxLength'] );
		self::assertContains( 'effective_custom_schema', $output['required'] );
		self::assertContains( 'custom_schema', $get_output['required'] );
		self::assertContains( 'current_custom_schema', $preview_output['required'] );
		self::assertContains( 'preview_custom_schema', $preview_output['required'] );
		self::assertContains( 'writes_performed', $preview_output['required'] );
		self::assertSame( 'boolean', $preview_output['properties']['writes_performed']['type'] );
		self::assertSame( 20, $get_output['properties']['custom_schema']['properties']['validation']['properties']['nodes']['maxItems'] );
		self::assertTrue( $get_output['properties']['custom_schema']['properties']['validation']['properties']['nodes']['items']['additionalProperties'] );
		self::assertFalse( $get_output['properties']['custom_schema']['additionalProperties'] );
	}

	/**
	 * Trash requires exact target identity and optimistic concurrency.
	 *
	 * @return void
	 */
	public function test_trash_contract_is_strict_and_versioned(): void {
		$input  = AbilitySchemas::trash_content_input();
		$output = AbilitySchemas::trash_content_output();

		self::assertSame( array( 'post_id', 'version_token' ), $input['required'] );
		self::assertFalse( $input['additionalProperties'] );
		self::assertContains( 'status', $output['required'] );
		self::assertContains( 'version_token', $output['required'] );
		self::assertFalse( $output['additionalProperties'] );
	}
}
