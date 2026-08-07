<?php
/**
 * Repeatable runtime verification for the plugin's Abilities projection.
 *
 * Execute with: wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/abilities-runtime-verification.php";'
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail this CLI verifier fast.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics, not rendered HTML.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies discovery, schemas, permissions, direct execution, and REST projection.
 */
final class WPCB_Abilities_Runtime_Verification {

	/**
	 * Core read abilities this verifier exercises. They are always registered,
	 * so they are a precondition rather than the complete inventory.
	 *
	 * @var list<string>
	 */
	private const NAMES = array(
		'wp-content-bridge/search-content',
		'wp-content-bridge/get-content',
		'wp-content-bridge/get-url-seo',
		'wp-content-bridge/get-diagnostics',
		'wp-content-bridge/get-editorial-context',
	);

	/**
	 * Every ability this plugin may ever register. Abilities beyond the core
	 * reads appear only when their feature flag and, where applicable, their
	 * optional provider are active, so the runtime inventory is a subset of
	 * this profile — never a superset. Anything registered outside this list is
	 * an unintended public surface and fails the run.
	 *
	 * @var list<string>
	 */
	private const CLOSED_PROFILE = array(
		'wp-content-bridge/search-content',
		'wp-content-bridge/get-content',
		'wp-content-bridge/get-url-seo',
		'wp-content-bridge/get-diagnostics',
		'wp-content-bridge/get-editorial-context',
		'wp-content-bridge/get-media',
		'wp-content-bridge/get-media-by-id',
		'wp-content-bridge/list-block-patterns',
		'wp-content-bridge/create-draft',
		'wp-content-bridge/update-content',
		'wp-content-bridge/update-seo',
		'wp-content-bridge/trash-content',
		'wp-content-bridge/get-service-schema',
		'wp-content-bridge/preview-service-schema',
		'wp-content-bridge/update-service-schema',
		'wp-content-bridge/get-custom-schema',
		'wp-content-bridge/preview-custom-schema',
		'wp-content-bridge/update-custom-schema',
	);

	/**
	 * Executes the verifier and prints machine-readable evidence.
	 *
	 * @return void
	 */
	public function run(): void {
		$admin_id = $this->administrator_id();
		wp_set_current_user( $admin_id );
		$abilities = $this->abilities();

		$diagnostics = $this->execute_array( $abilities['wp-content-bridge/get-diagnostics'] );
		$post_id     = $this->published_readable_post_id( $diagnostics );
		$post_type   = get_post_type( $post_id );
		if ( ! is_string( $post_type ) ) {
			throw new RuntimeException( 'Verification content type could not be resolved.' );
		}

		$inputs = array(
			'wp-content-bridge/search-content'        => array(
				'post_types' => array( $post_type ),
				'statuses'   => array( 'publish' ),
				'per_page'   => 5,
				'order_by'   => 'id',
				'order'      => 'asc',
			),
			'wp-content-bridge/get-content'           => array(
				'post_id'         => $post_id,
				'representations' => array( 'raw', 'plain_text' ),
			),
			'wp-content-bridge/get-url-seo'           => array(
				'post_id' => $post_id,
			),
			'wp-content-bridge/get-editorial-context' => array(
				'sections'           => array( 'post_types', 'taxonomies', 'terms', 'authors', 'recent_content', 'local_businesses' ),
				'post_types'         => array( $post_type ),
				'recent_limit'       => 5,
				'terms_per_taxonomy' => 5,
			),
			'wp-content-bridge/get-diagnostics'       => null,
		);

		$annotations = array();
		$definitions = array();
		$hashes      = array();
		$schemas     = array();
		foreach ( $abilities as $name => $ability ) {
			$this->assert_true( 'wp-content-bridge' === $ability->get_category(), $name . ' has an unexpected category.' );
			$this->assert_true( '' !== trim( $ability->get_label() ), $name . ' has an empty label.' );
			$this->assert_true( '' !== trim( $ability->get_description() ), $name . ' has an empty description.' );
			$definitions[ $name ] = array(
				'category'            => $ability->get_category(),
				'label_present'       => true,
				'description_present' => true,
			);
			$meta                 = $ability->get_meta();
			$annotations[ $name ] = $meta['annotations'] ?? null;
			// Write and preview abilities carry their own annotation contracts and
			// are verified by their own runtime verifiers.
			if ( in_array( $name, self::NAMES, true ) ) {
				$this->assert_read_annotations( $annotations[ $name ], $name );
			}
			$this->assert_true( true === ( $meta['show_in_rest'] ?? false ), $name . ' is not exposed in REST.' );

			$missing          = $this->missing_required_descriptions( $ability->get_input_schema() );
			$schemas[ $name ] = array(
				'input_additional_properties'   => $ability->get_input_schema()['additionalProperties'] ?? null,
				'missing_required_descriptions' => $missing,
			);
			$this->assert_true( array() === $missing, $name . ' has required input properties without descriptions.' );

			$first           = $this->execute_array( $ability, $inputs[ $name ] );
			$second          = $this->execute_array( $ability, $inputs[ $name ] );
			$hashes[ $name ] = array(
				'first'  => hash( 'sha256', (string) wp_json_encode( $first ) ),
				'second' => hash( 'sha256', (string) wp_json_encode( $second ) ),
			);
			$this->assert_true( $hashes[ $name ]['first'] === $hashes[ $name ]['second'], $name . ' is not stable across twin invocations.' );
		}

		$error_codes = array(
			'schema_validation'      => $this->error_code( $abilities['wp-content-bridge/search-content'], array( 'per_page' => 101 ) ),
			'invalid_search'         => $this->error_code(
				$abilities['wp-content-bridge/search-content'],
				array(
					'post_types' => array( 'page' ),
					'taxonomy'   => array(
						array(
							'taxonomy' => 'category',
							'term_ids' => array( 1 ),
						),
					),
				)
			),
			'content_unavailable'    => $this->error_code( $abilities['wp-content-bridge/get-content'], array( 'post_id' => PHP_INT_MAX ) ),
			'invalid_seo_selector'   => $this->error_code(
				$abilities['wp-content-bridge/get-url-seo'],
				array(
					'post_id' => $post_id,
					'url'     => 'https://example.invalid/',
				)
			),
			'seo_unavailable'        => $this->error_code( $abilities['wp-content-bridge/get-url-seo'], array( 'post_id' => PHP_INT_MAX ) ),
			'invalid_editorial_type' => $this->error_code( $abilities['wp-content-bridge/get-editorial-context'], array( 'post_types' => array( 'wpcb_missing_type' ) ) ),
		);
		$this->assert_true( 'ability_invalid_input' === $error_codes['schema_validation'], 'Core schema-validation error code drifted.' );
		$this->assert_true( 'wpcb_invalid_input' === $error_codes['invalid_search'], 'Invalid-search error code drifted.' );
		$this->assert_true( 'wpcb_content_unavailable' === $error_codes['content_unavailable'], 'Unavailable-content error code drifted.' );
		$this->assert_true( 'ability_invalid_input' === $error_codes['invalid_seo_selector'], 'SEO selector schema-validation error code drifted.' );
		$this->assert_true( 'wpcb_content_unavailable' === $error_codes['seo_unavailable'], 'Unavailable SEO target error code drifted.' );
		$this->assert_true( 'wpcb_invalid_input' === $error_codes['invalid_editorial_type'], 'Editorial type error code drifted.' );

		wp_set_current_user( 0 );
		$anonymous = array();
		foreach ( $abilities as $name => $ability ) {
			$anonymous[ $name ] = $ability->check_permissions( $inputs[ $name ] );
			$this->assert_true( false === $anonymous[ $name ], $name . ' granted anonymous access.' );
		}

		wp_set_current_user( $admin_id );
		$rest = $this->verify_rest( $post_id );

		echo wp_json_encode(
			array(
				'status'                 => 'PASS',
				'wordpress_version'      => get_bloginfo( 'version' ),
				'ability_names'          => array_keys( $abilities ),
				'definitions'            => $definitions,
				'annotations'            => $annotations,
				'schemas'                => $schemas,
				'permissions'            => array(
					'anonymous'     => $anonymous,
					'administrator' => true,
				),
				'twin_invocation_hashes' => $hashes,
				'error_codes'            => $error_codes,
				'rest'                   => $rest,
				'content_fixture_id'     => $post_id,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		), PHP_EOL;
	}

	/**
	 * Resolves the first administrator.
	 *
	 * @return int
	 */
	private function administrator_id(): int {
		$ids = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( array() === $ids || ! is_numeric( $ids[0] ) ) {
			throw new RuntimeException( 'An administrator is required.' );
		}

		return (int) $ids[0];
	}

	/**
	 * Resolves and validates the owned ability inventory.
	 *
	 * @return array<string, WP_Ability>
	 */
	private function abilities(): array {
		$owned = array_filter(
			wp_get_abilities(),
			static fn ( WP_Ability $ability ): bool => str_starts_with( $ability->get_name(), 'wp-content-bridge/' )
		);
		$owned = array_combine( array_map( static fn ( WP_Ability $ability ): string => $ability->get_name(), $owned ), $owned );
		ksort( $owned );
		$registered = array_keys( $owned );
		$this->assert_true(
			array() === array_diff( self::NAMES, $registered ),
			'Core read abilities are missing from the runtime inventory.'
		);
		$this->assert_true(
			array() === array_diff( $registered, self::CLOSED_PROFILE ),
			'Runtime inventory contains an ability outside the closed profile: '
				. implode( ', ', array_diff( $registered, self::CLOSED_PROFILE ) )
		);

		/*
		 * This verifier executes every ability it returns, so it must hand back
		 * only the read abilities it is written for. Write, preview, and
		 * provider-dependent abilities are covered by their own verifiers and
		 * must never be executed here.
		 */
		return array_intersect_key( $owned, array_flip( self::NAMES ) );
	}

	/**
	 * Finds one published object from an enabled readable type.
	 *
	 * @param array<mixed, mixed> $diagnostics Bridge diagnostics.
	 * @return int
	 */
	private function published_readable_post_id( array $diagnostics ): int {
		$types = $diagnostics['readable_post_types'] ?? array();
		if ( ! is_array( $types ) ) {
			throw new RuntimeException( 'Readable post types are unavailable.' );
		}
		$ids = get_posts(
			array(
				'post_type'      => array_values( array_filter( $types, 'is_string' ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( array() === $ids ) {
			throw new RuntimeException( 'A published object in an enabled readable type is required.' );
		}

		return (int) $ids[0];
	}

	/**
	 * Executes one ability and requires an array response.
	 *
	 * @param WP_Ability $ability Ability instance.
	 * @param mixed      $input   Optional input.
	 * @return array<mixed, mixed>
	 */
	private function execute_array( WP_Ability $ability, mixed $input = null ): array {
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $ability->get_name() . ': ' . $result->get_error_code() . ' — ' . $result->get_error_message() );
		}
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( $ability->get_name() . ' returned a non-array result.' );
		}

		return $result;
	}

	/**
	 * Executes one expected failure and returns its stable code.
	 *
	 * @param WP_Ability $ability Ability instance.
	 * @param mixed      $input   Failure-triggering input.
	 * @return string
	 */
	private function error_code( WP_Ability $ability, mixed $input ): string {
		$result = $ability->execute( $input );
		if ( ! is_wp_error( $result ) ) {
			throw new RuntimeException( $ability->get_name() . ' unexpectedly succeeded.' );
		}

		$code = $result->get_error_code();
		if ( ! is_string( $code ) ) {
			throw new RuntimeException( $ability->get_name() . ' returned a non-string error code.' );
		}

		return $code;
	}

	/**
	 * Verifies REST discovery and execution.
	 *
	 * @param int $post_id Readable post ID.
	 * @return array<string, mixed>
	 */
	private function verify_rest( int $post_id ): array {
		rest_get_server();
		$list_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' );
		$list_request->set_query_params(
			array(
				'category' => 'wp-content-bridge',
				'per_page' => 100,
			)
		);
		$list = rest_do_request( $list_request );
		$this->assert_true( 200 === $list->get_status(), 'REST ability discovery failed.' );
		$listed = array_column( (array) $list->get_data(), 'name' );
		sort( $listed );
		$this->assert_true(
			array() === array_diff( self::NAMES, $listed ),
			'REST discovery is missing a core read ability.'
		);
		$this->assert_true(
			array() === array_diff( $listed, self::CLOSED_PROFILE ),
			'REST discovery exposes an ability outside the closed profile: '
				. implode( ', ', array_diff( $listed, self::CLOSED_PROFILE ) )
		);

		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/wp-content-bridge/get-content/run' );
		$request->set_query_params(
			array(
				'input' => array(
					'post_id'         => $post_id,
					'representations' => array( 'plain_text' ),
				),
			)
		);
		$run = rest_do_request( $request );
		$this->assert_true( 200 === $run->get_status(), 'REST ability execution failed.' );

		return array(
			'discovery_status' => $list->get_status(),
			'listed_names'     => $listed,
			'run_status'       => $run->get_status(),
		);
	}

	/**
	 * Finds required input properties without descriptions recursively.
	 *
	 * @param array  $schema Schema fragment.
	 * @param string $path   Current property path.
	 * @return list<string>
	 * @phpstan-param array<mixed, mixed> $schema
	 */
	private function missing_required_descriptions( array $schema, string $path = '$' ): array {
		$missing    = array();
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		foreach ( $properties as $name => $property ) {
			if ( ! is_string( $name ) || ! is_array( $property ) ) {
				continue;
			}
			if ( in_array( $name, $required, true ) && empty( $property['description'] ) ) {
				$missing[] = $path . '.' . $name;
			}
			$missing = array_merge( $missing, $this->missing_required_descriptions( $property, $path . '.' . $name ) );
			if ( isset( $property['items'] ) && is_array( $property['items'] ) ) {
				$missing = array_merge( $missing, $this->missing_required_descriptions( $property['items'], $path . '.' . $name . '[]' ) );
			}
		}

		return $missing;
	}

	/**
	 * Requires the standard read-only annotation triple.
	 *
	 * @param mixed  $annotations Ability annotations.
	 * @param string $name        Ability name.
	 * @return void
	 */
	private function assert_read_annotations( mixed $annotations, string $name ): void {
		$this->assert_true(
			is_array( $annotations )
			&& true === ( $annotations['readonly'] ?? null )
			&& false === ( $annotations['destructive'] ?? null )
			&& true === ( $annotations['idempotent'] ?? null ),
			$name . ' has incorrect read annotations.'
		);
	}

	/**
	 * Throws on a failed verification assertion.
	 *
	 * @param bool   $condition Assertion condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
}

( new WPCB_Abilities_Runtime_Verification() )->run();
