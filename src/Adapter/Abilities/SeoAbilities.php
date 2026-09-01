<?php
/**
 * WordPress Abilities projection for normalized SEO reads.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Seo\GetSeo;
use IsuDev\WPContentBridge\Application\Seo\SameSiteSeoTargetFactory;
use Throwable;
use WP_Error;

/**
 * Keeps WordPress registration and error mapping outside the SEO use case.
 */
final readonly class SeoAbilities {

	private const CATEGORY = AbilityCategory::SLUG;

	/**
	 * Creates the SEO ability adapter.
	 *
	 * @param GetSeo                   $get            Shared SEO read use case.
	 * @param SameSiteSeoTargetFactory $target_factory Same-origin selector validation.
	 */
	public function __construct(
		private GetSeo $get,
		private SameSiteSeoTargetFactory $target_factory,
	) {
	}

	/**
	 * Registers WordPress lifecycle hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the semantic SEO read contract.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'wp-content-bridge/get-url-seo',
			array(
				'label'               => __( 'Get URL SEO', 'wp-content-bridge' ),
				'description'         => __( 'Returns normalized configured and effective SEO metadata for one readable WordPress object or same-site URL.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::seo_input(),
				'output_schema'       => AbilitySchemas::seo_output(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_get' ),
				'meta'                => AbilityMeta::read(),
			)
		);
	}

	/**
	 * Checks the plugin-level read capability.
	 *
	 * @param mixed $input Unused validated input.
	 * @return bool
	 */
	public function can_read( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_read_content' );
	}

	/**
	 * Executes one normalized SEO lookup.
	 *
	 * @param mixed $input Validated selector input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_get( mixed $input = array() ): array|WP_Error {
		if ( ! $this->can_read() ) {
			return AbilityError::create( 'wpcb_forbidden', __( 'You are not allowed to read SEO data through WP Content Bridge.', 'wp-content-bridge' ) );
		}

		try {
			$normalized = self::normalize_input( $input );

			return $this->get->execute( $this->target_factory->from_input( $normalized ) )->to_array();
		} catch ( InvalidArgumentException $exception ) {
			return AbilityError::create( 'wpcb_invalid_selector', $exception->getMessage() );
		} catch ( ContentUnavailable ) {
			return AbilityError::create( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		} catch ( Throwable ) {
			return AbilityError::create( 'wpcb_seo_data_unavailable', __( 'SEO data could not be retrieved from the active provider.', 'wp-content-bridge' ) );
		}
	}

	/**
	 * Keeps only string keys from callback input.
	 *
	 * @param mixed $input Callback input.
	 * @return array<string, mixed>
	 */
	private static function normalize_input( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $input as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}
}
