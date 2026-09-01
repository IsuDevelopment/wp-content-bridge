<?php
/**
 * Repeatable runtime verification for WordPress 7.1 REST input coercion.
 *
 * WordPress 7.1 added a `sanitize_callback` on the run endpoint's `input`
 * argument that coerces request input to the ability's declared input schema
 * (`WP_REST_Abilities_V1_Run_Controller::coerce_input_to_schema()`). It is not
 * opt-in and it applies to an already-shipped public surface, so this verifier
 * pins the behavior our schemas actually get rather than what a dev note
 * describes.
 *
 * The measured contract, which the assertions below encode:
 *
 * 1. Coercion lives at the REST boundary only. The same string that succeeds
 *    over REST still fails when handed to `WP_Ability::execute()` directly, so
 *    the domain contract is unchanged and strict.
 * 2. Coercion runs only on input that already validates, and falls back to the
 *    raw input on any sanitization error. Every schema bound therefore still
 *    holds, and an uncoercible value is rejected exactly as before.
 * 3. A comma in a `type: string` field is not split. Only `type: array` fields
 *    accept the comma-separated form.
 * 4. Coercion is not authorization. A principal without the capability is still
 *    refused on a perfectly coercible request.
 *
 * Execute with: wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/rest-input-coercion-verification.php";'
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
 * Verifies 7.1 input coercion against this plugin's shipped read schemas.
 */
final class WPCB_Rest_Input_Coercion_Verification {

	/**
	 * Title fragment containing a comma, used to prove a string field survives
	 * intact. If coercion split it, the value would become an array and the
	 * request would fail schema validation instead of matching this post.
	 */
	private const COMMA_PHRASE = 'Alpha,Beta';

	/**
	 * Runs the verifier and prints machine-readable evidence.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			throw new RuntimeException( 'The Abilities API is unavailable.' );
		}

		$version = get_bloginfo( 'version' );
		if ( version_compare( $version, '7.1', '<' ) ) {
			throw new RuntimeException( 'This verifier requires WordPress 7.1 or later; found ' . $version . '.' );
		}

		wp_set_current_user( $this->administrator_id() );
		rest_get_server();

		$post_id     = 0;
		$denied_user = 0;
		try {
			$post_id     = $this->create_fixture_post();
			$denied_user = $this->create_denied_user();

			$evidence = array(
				'wordpress_version' => $version,
				'coercion_boundary' => $this->verify_coercion_is_rest_only( $post_id ),
				'uncoercible'       => $this->verify_uncoercible_still_rejected( $post_id ),
				'bounds'            => $this->verify_bounds_survive_coercion(),
				'lists'             => $this->verify_list_coercion_equivalence( $post_id ),
				'string_intact'     => $this->verify_string_field_not_split( $post_id ),
				'booleans'          => $this->verify_boolean_coercion( $post_id ),
				'policy'            => $this->verify_policy_still_applies(),
				'error_statuses'    => $this->verify_error_statuses( $post_id ),
				'authorization'     => $this->verify_coercion_is_not_authorization( $denied_user, $post_id ),
			);
		} finally {
			wp_set_current_user( $this->administrator_id() );
			if ( 0 !== $post_id ) {
				wp_delete_post( $post_id, true );
			}
			if ( 0 !== $denied_user ) {
				wp_delete_user( $denied_user );
			}
		}

		echo 'PASS: rest-input-coercion ', wp_json_encode( $evidence ), "\n";
	}

	/**
	 * A numeric string succeeds over REST and still fails a direct execution.
	 *
	 * @param int $post_id Fixture post.
	 * @return array<string, mixed>
	 */
	private function verify_coercion_is_rest_only( int $post_id ): array {
		$rest = $this->rest_run( 'wp-content-bridge/get-content', array( 'post_id' => (string) $post_id ) );
		$this->assert_true( 200 === $rest['status'], 'A numeric string post_id was not coerced over REST: ' . $rest['code'] );

		$ability = wp_get_ability( 'wp-content-bridge/get-content' );
		if ( ! $ability instanceof WP_Ability ) {
			throw new RuntimeException( 'get-content is not registered.' );
		}
		$direct = $ability->execute( array( 'post_id' => (string) $post_id ) );
		$this->assert_true(
			is_wp_error( $direct ),
			'A direct execution accepted a string post_id. Coercion has leaked past the REST boundary into the domain contract.'
		);

		$native = $this->rest_run( 'wp-content-bridge/get-content', array( 'post_id' => $post_id ) );
		$this->assert_true( 200 === $native['status'], 'A native integer post_id failed over REST: ' . $native['code'] );

		return array(
			'rest_string_status' => $rest['status'],
			'rest_native_status' => $native['status'],
			'direct_error'       => is_wp_error( $direct ) ? $direct->get_error_code() : null,
		);
	}

	/**
	 * Values that cannot be coerced are still rejected.
	 *
	 * @param int $post_id Fixture post.
	 * @return array<string, string>
	 */
	private function verify_uncoercible_still_rejected( int $post_id ): array {
		$cases = array(
			'non_numeric_integer' => array( 'wp-content-bridge/get-content', array( 'post_id' => 'abc' ) ),
			'float_for_integer'   => array( 'wp-content-bridge/get-content', array( 'post_id' => $post_id . '.5' ) ),
			'garbage_boolean'     => array(
				'wp-content-bridge/get-block-tree',
				array(
					'post_id'       => (string) $post_id,
					'include_attrs' => 'yes-please',
				),
			),
			'object_from_string'  => array( 'wp-content-bridge/search-content', array( 'taxonomy' => 'category,3' ) ),
		);

		$codes = array();
		foreach ( $cases as $label => $case ) {
			$result = $this->rest_run( $case[0], $case[1] );
			$this->assert_true(
				400 === $result['status'],
				'Coercion accepted an uncoercible value (' . $label . '): status ' . $result['status'] . ' ' . $result['code']
			);
			$codes[ $label ] = $result['code'];
		}

		return $codes;
	}

	/**
	 * Schema bounds are enforced through the coercion path.
	 *
	 * @return array<string, string>
	 */
	private function verify_bounds_survive_coercion(): array {
		$cases = array(
			'per_page_below_minimum' => array( 'per_page' => '0' ),
			'per_page_above_maximum' => array( 'per_page' => '101' ),
			'page_below_minimum'     => array( 'page' => '0' ),
			'author_ids_above_max'   => array( 'author_ids' => implode( ',', range( 1, 101 ) ) ),
		);

		$codes = array();
		foreach ( $cases as $label => $input ) {
			$result = $this->rest_run( 'wp-content-bridge/search-content', $input );
			$this->assert_true(
				400 === $result['status'],
				'A schema bound was bypassed by coercion (' . $label . '): status ' . $result['status'] . ' ' . $result['code']
			);
			$codes[ $label ] = $result['code'];
		}

		$accepted = $this->rest_run( 'wp-content-bridge/search-content', array( 'per_page' => '100' ) );
		$this->assert_true( 200 === $accepted['status'], 'The maximum per_page was rejected as a string: ' . $accepted['code'] );

		return $codes;
	}

	/**
	 * The comma-separated form of a list is equivalent to the array form.
	 *
	 * @param int $post_id Fixture post.
	 * @return array<string, mixed>
	 */
	private function verify_list_coercion_equivalence( int $post_id ): array {
		$base = array(
			'per_page' => 100,
			'order_by' => 'id',
			'order'    => 'asc',
			'statuses' => array( 'publish' ),
		);

		$array_multi   = $this->rest_run( 'wp-content-bridge/search-content', array_merge( $base, array( 'post_types' => array( 'post', 'page' ) ) ) );
		$string_multi  = $this->rest_run(
			'wp-content-bridge/search-content',
			array_merge(
				$base,
				array(
					'post_types' => 'post,page',
					'per_page'   => '100',
					'statuses'   => 'publish',
				)
			)
		);
		$array_single  = $this->rest_run( 'wp-content-bridge/search-content', array_merge( $base, array( 'post_types' => array( 'post' ) ) ) );
		$string_single = $this->rest_run(
			'wp-content-bridge/search-content',
			array_merge(
				$base,
				array(
					'post_types' => 'post',
					'per_page'   => '100',
					'statuses'   => 'publish',
				)
			)
		);

		foreach ( array( $array_multi, $string_multi, $array_single, $string_single ) as $index => $result ) {
			$this->assert_true( 200 === $result['status'], 'List coercion case ' . $index . ' failed: ' . $result['code'] );
		}

		$multi_fingerprint  = $this->fingerprint( $array_multi );
		$single_fingerprint = $this->fingerprint( $array_single );

		$this->assert_true(
			'' !== $multi_fingerprint && '[]' !== $multi_fingerprint,
			'The list-equivalence assertion is vacuous: the array form matched no content.'
		);
		$this->assert_true(
			$multi_fingerprint === $this->fingerprint( $string_multi ),
			'A comma-separated list did not resolve to the same result as the array form.'
		);
		$this->assert_true(
			$single_fingerprint === $this->fingerprint( $string_single ),
			'A single-value string did not resolve to the same result as a one-element array.'
		);
		$this->assert_true(
			in_array( $post_id, $this->ids( $array_single ), true ),
			'The fixture post was not returned by the array form, so the comparison proves nothing.'
		);

		return array(
			'multi_ids'  => count( $this->ids( $array_multi ) ),
			'single_ids' => count( $this->ids( $array_single ) ),
		);
	}

	/**
	 * A comma inside a string field is not split into a list.
	 *
	 * @param int $post_id Fixture post.
	 * @return array<string, mixed>
	 */
	private function verify_string_field_not_split( int $post_id ): array {
		$result = $this->rest_run(
			'wp-content-bridge/search-content',
			array(
				'query'      => self::COMMA_PHRASE,
				'post_types' => 'post',
				'statuses'   => 'publish',
				'per_page'   => '20',
			)
		);

		$this->assert_true(
			200 === $result['status'],
			'A string field containing a comma was rejected, which means coercion split it into an array: ' . $result['code']
		);
		$this->assert_true(
			in_array( $post_id, $this->ids( $result ), true ),
			'The comma-bearing search phrase did not match the fixture post, so the string did not survive intact.'
		);

		return array(
			'phrase'  => self::COMMA_PHRASE,
			'matched' => true,
		);
	}

	/**
	 * Boolean strings coerce to the expected native values.
	 *
	 * @param int $post_id Fixture post.
	 * @return array<string, int>
	 */
	private function verify_boolean_coercion( int $post_id ): array {
		$statuses = array();
		foreach ( array( 'true', 'false', '1', '0' ) as $value ) {
			$result = $this->rest_run(
				'wp-content-bridge/get-block-tree',
				array(
					'post_id'       => (string) $post_id,
					'include_attrs' => $value,
				)
			);
			$this->assert_true( 200 === $result['status'], 'Boolean coercion rejected "' . $value . '": ' . $result['code'] );
			$statuses[ $value ] = $result['status'];
		}

		return $statuses;
	}

	/**
	 * Domain policy still rejects a coerced but disallowed value.
	 *
	 * @return array<string, mixed>
	 */
	private function verify_policy_still_applies(): array {
		$result = $this->rest_run( 'wp-content-bridge/search-content', array( 'post_types' => 'definitely_not_a_type' ) );
		$this->assert_true(
			400 === $result['status'],
			'A post type outside the configured read policy answered ' . $result['status'] . ' instead of 400.'
		);

		return array(
			'status' => $result['status'],
			'code'   => $result['code'],
		);
	}

	/**
	 * A domain rejection answers its own HTTP status, not 500.
	 *
	 * Every `WP_Error` an ability returns goes through `AbilityError`, which
	 * carries the status core would otherwise default to 500. A 500 here means
	 * either a code with no mapping or a construction site that bypassed the
	 * factory, and a client would read an ordinary refusal as a server fault.
	 *
	 * @param int $post_id Fixture post.
	 * @return array<string, array<string, mixed>>
	 */
	private function verify_error_statuses( int $post_id ): array {
		$cases = array(
			'invalid_input'    => array( 400, 'wpcb_invalid_input', 'wp-content-bridge/search-content', array( 'post_types' => 'definitely_not_a_type' ) ),
			'not_found'        => array( 404, 'wpcb_content_unavailable', 'wp-content-bridge/get-content', array( 'post_id' => 99999999 ) ),
			'invalid_selector' => array( 400, 'wpcb_invalid_selector', 'wp-content-bridge/get-url-seo', array( 'url' => 'https://example.invalid/definitely-not-here' ) ),
		);

		$observed = array();
		foreach ( $cases as $label => $case ) {
			$result = $this->rest_run( $case[2], $case[3] );
			$this->assert_true(
				$case[0] === $result['status'],
				'A domain rejection (' . $label . ') answered ' . $result['status'] . ' instead of ' . $case[0] . '; code ' . $result['code']
			);
			$this->assert_true(
				$case[1] === $result['code'],
				'A domain rejection (' . $label . ') changed its public error code to ' . $result['code'] . '.'
			);
			$observed[ $label ] = array(
				'status' => $result['status'],
				'code'   => $result['code'],
			);
		}

		$ok = $this->rest_run( 'wp-content-bridge/get-content', array( 'post_id' => $post_id ) );
		$this->assert_true( 200 === $ok['status'], 'The success baseline broke while mapping error statuses: ' . $ok['code'] );

		return $observed;
	}

	/**
	 * Coercion does not authorize. A principal without the capability is refused.
	 *
	 * @param int $user_id Capability-less user.
	 * @param int $post_id Fixture post.
	 * @return array<string, mixed>
	 */
	private function verify_coercion_is_not_authorization( int $user_id, int $post_id ): array {
		$administrator = get_current_user_id();
		wp_set_current_user( $user_id );
		try {
			$result = $this->rest_run( 'wp-content-bridge/get-content', array( 'post_id' => (string) $post_id ) );
		} finally {
			wp_set_current_user( $administrator );
		}

		$this->assert_true(
			200 !== $result['status'],
			'A capability-less principal executed a read through the coercion path.'
		);

		return array(
			'status' => $result['status'],
			'code'   => $result['code'],
		);
	}

	/**
	 * Executes an ability over the REST run endpoint.
	 *
	 * @param string $ability Ability name.
	 * @param array  $input   Input as a request would carry it.
	 * @return array{status:int,code:string,data:mixed}
	 * @phpstan-param array<string, mixed> $input
	 */
	private function rest_run( string $ability, array $input ): array {
		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . $ability . '/run' );
		$request->set_query_params( array( 'input' => $input ) );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$code = '';
		if ( $response->is_error() && is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ) {
			$code = $data['code'];
		}

		return array(
			'status' => $response->get_status(),
			'code'   => $code,
			'data'   => $data,
		);
	}

	/**
	 * Extracts the returned post IDs from a search response.
	 *
	 * @param array $result Result of rest_run().
	 * @return list<int>
	 * @phpstan-param array{status:int,code:string,data:mixed} $result
	 */
	private function ids( array $result ): array {
		$data = $result['data'];
		if ( ! is_array( $data ) ) {
			return array();
		}
		$payload = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : $data;
		$items   = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();

		$ids = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
				$ids[] = (int) $item['id'];
			}
		}
		sort( $ids );

		return $ids;
	}

	/**
	 * Builds a comparable fingerprint of a search response.
	 *
	 * @param array $result Result of rest_run().
	 * @return string
	 * @phpstan-param array{status:int,code:string,data:mixed} $result
	 */
	private function fingerprint( array $result ): string {
		$encoded = wp_json_encode( $this->ids( $result ) );

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Creates the fixture post whose title carries a comma.
	 *
	 * @return int
	 */
	private function create_fixture_post(): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => self::COMMA_PHRASE . ' coercion fixture',
				'post_content' => '<!-- wp:paragraph --><p>Coercion fixture.</p><!-- /wp:paragraph -->',
			),
			true
		);

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			throw new RuntimeException( 'The fixture post could not be created.' );
		}

		return (int) $post_id;
	}

	/**
	 * Creates a user holding no WPCB capability.
	 *
	 * @return int
	 */
	private function create_denied_user(): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'wpcb-coercion-' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'wpcb-coercion-' . wp_generate_password( 8, false ) . '@example.invalid',
				'role'       => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'The capability-less fixture user could not be created.' );
		}

		return (int) $user_id;
	}

	/**
	 * Resolves an administrator to run as.
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

( new WPCB_Rest_Input_Coercion_Verification() )->run();
