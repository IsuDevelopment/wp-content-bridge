<?php
/**
 * Repeatable Yoast configured-value and partial-result verifier.
 *
 * Execute with: wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/yoast-configured-runtime-verification.php";'
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only verification diagnostics.
// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertions intentionally fail this CLI verifier fast.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and removes one exact fixture around configured-value assertions.
 */
final class WPCB_Yoast_Configured_Runtime_Verification {

	/**
	 * Runs the verifier and prints machine-readable evidence.
	 *
	 * @return void
	 */
	public function run(): void {
		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( array() === $administrators || ! is_numeric( $administrators[0] ) ) {
			throw new RuntimeException( 'An administrator fixture is required.' );
		}
		wp_set_current_user( (int) $administrators[0] );

		$ability = wp_get_ability( 'wp-content-bridge/get-url-seo' );
		if ( ! $ability instanceof WP_Ability ) {
			throw new RuntimeException( 'The SEO ability is not registered.' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'WPCB Yoast configured runtime fixture',
				'post_content' => 'Disposable verification content.',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Could not create the configured-value fixture.' );
		}

		try {
			update_post_meta( $post_id, '_yoast_wpseo_title', 'Explicit %%title%%' );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', 'fixture keyphrase' );
			update_post_meta( $post_id, '_yoast_wpseo_focuskeywords', '[{"keyword":"additional fixture","score":87,"private":"must-not-leak"}]' );
			update_post_meta( $post_id, '_wpcb_fixture_secret', 'must-not-leak' );

			$result = $ability->execute( array( 'post_id' => $post_id ) );
			if ( is_wp_error( $result ) || ! is_array( $result ) ) {
				throw new RuntimeException( 'The configured-value fixture could not be read.' );
			}

			$this->assert_same( 'partial', $result['provenance']['completeness'] ?? null, 'Partial completeness drifted.' );
			$this->assert_same( 'explicit', $result['configured']['title']['state'] ?? null, 'Explicit title state drifted.' );
			$this->assert_same( 'Explicit %%title%%', $result['configured']['title']['value'] ?? null, 'Explicit title value drifted.' );
			$this->assert_same( 'inherited', $result['configured']['description']['state'] ?? null, 'Inherited description state drifted.' );
			$this->assert_same( null, $result['configured']['description']['value'] ?? null, 'Inherited description value drifted.' );
			$this->assert_same( array( 'fixture keyphrase', 'additional fixture' ), $result['configured']['focus_keyphrases']['value'] ?? null, 'Focus keyphrase normalization drifted.' );
			$this->assert_same( 'additional', $result['configured']['keyphrase_details']['value'][1]['role'] ?? null, 'Premium keyphrase role drifted.' );
			$this->assert_same( 87, $result['configured']['keyphrase_details']['value'][1]['score'] ?? null, 'Premium keyphrase score drifted.' );
			$this->assert_same( '28.0', $result['provenance']['provider']['module_versions']['premium'] ?? null, 'Premium module version drifted.' );
			$this->assert_same( '15.8', $result['provenance']['provider']['module_versions']['local'] ?? null, 'Local module version drifted.' );

			$encoded = wp_json_encode( $result );
			if ( ! is_string( $encoded ) || str_contains( $encoded, '_wpcb_fixture_secret' ) || str_contains( $encoded, 'must-not-leak' ) ) {
				throw new RuntimeException( 'Arbitrary post meta leaked through the SEO result.' );
			}

			echo wp_json_encode(
				array(
					'status'                => 'PASS',
					'provider'              => $result['provenance']['provider']['provider'] ?? null,
					'provider_version'      => $result['provenance']['provider']['version'] ?? null,
					'completeness'          => $result['provenance']['completeness'] ?? null,
					'explicit_title'        => true,
					'premium_keyphrases'    => true,
					'inherited_description' => true,
					'arbitrary_meta_leak'   => false,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			), PHP_EOL;
		} finally {
			wp_delete_post( $post_id, true );
		}
	}

	/**
	 * Requires strict equality.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 * @return void
	 */
	private function assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message );
		}
	}
}

( new WPCB_Yoast_Configured_Runtime_Verification() )->run();
