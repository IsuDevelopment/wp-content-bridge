<?php
/**
 * Registers and projects optional Service schema read, preview, and write Abilities.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\GetServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\ServiceSchemaUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\PreviewServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\UpdateServiceSchema;
use Throwable;
use WP_Error;

/**
 * Thin Abilities API adapter for optional structured Service configuration.
 */
final readonly class ServiceSchemaAbilities {

	private const CATEGORY = AbilityCategory::SLUG;

	/**
	 * Creates the adapter.
	 *
	 * @param GetServiceSchema     $get     Read application use case.
	 * @param PreviewServiceSchema $preview Preview application use case.
	 * @param UpdateServiceSchema  $update  Write application use case.
	 */
	public function __construct(
		private GetServiceSchema $get,
		private PreviewServiceSchema $preview,
		private UpdateServiceSchema $update,
	) {}

	/**
	 * Registers the WordPress lifecycle hook.
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the public Ability contract.
	 */
	public function register_abilities(): void {
		wp_register_ability(
			GetServiceSchema::ABILITY,
			array(
				'label'               => __( 'Get Service schema', 'wp-content-bridge' ),
				'description'         => __( 'Read the saved structured Service, service-area, brand, and OfferCatalog configuration for an existing post.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::get_service_schema_input(),
				'output_schema'       => AbilitySchemas::get_service_schema_output(),
				'permission_callback' => array( $this, 'can_manage' ),
				'execute_callback'    => array( $this, 'execute_get' ),
				'meta'                => AbilityMeta::read(),
			)
		);

		wp_register_ability(
			PreviewServiceSchema::ABILITY,
			array(
				'label'               => __( 'Preview Service schema update', 'wp-content-bridge' ),
				'description'         => __( 'Preview the sanitized Service schema configuration that an update would produce, without writing metadata.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::preview_service_schema_input(),
				'output_schema'       => AbilitySchemas::preview_service_schema_output(),
				'permission_callback' => array( $this, 'can_manage' ),
				'execute_callback'    => array( $this, 'execute_preview' ),
				'meta'                => AbilityMeta::read(),
			)
		);

		wp_register_ability(
			UpdateServiceSchema::ABILITY,
			array(
				'label'               => __( 'Update Service schema', 'wp-content-bridge' ),
				'description'         => __( 'Configure a structured Service entity, service areas, brands, and OfferCatalog for an existing post.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::update_service_schema_input(),
				'output_schema'       => AbilitySchemas::update_service_schema_output(),
				'permission_callback' => array( $this, 'can_manage' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => AbilityMeta::write( true, false ),
			)
		);
	}

	/**
	 * Requires both the bridge SEO capability and native post edit access.
	 *
	 * @param mixed $input Candidate Ability input.
	 */
	public function can_manage( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_manage_seo' ) ) {
			return false;
		}

		$raw_post_id = is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;

		return 0 < $post_id && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Executes a Service schema read.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_get( array $input ): array|WP_Error {
		if ( ! $this->can_manage( $input ) ) {
			return AbilityError::create( 'wpcb_forbidden', __( 'You are not permitted to read this configuration.', 'wp-content-bridge' ) );
		}

		try {
			return $this->get->execute( $input )->to_array();
		} catch ( Throwable $error ) {
			return $this->map_error( $error );
		}
	}

	/**
	 * Executes a Service schema preview without writing.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_preview( array $input ): array|WP_Error {
		if ( ! $this->can_manage( $input ) ) {
			return AbilityError::create( 'wpcb_forbidden', __( 'You are not permitted to preview this configuration.', 'wp-content-bridge' ) );
		}

		try {
			return $this->preview->execute( $input )->to_array();
		} catch ( Throwable $error ) {
			return $this->map_error( $error );
		}
	}

	/**
	 * Executes a Service schema update.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_manage( $input ) ) {
			return AbilityError::create( 'wpcb_forbidden', __( 'You are not permitted to perform this write.', 'wp-content-bridge' ) );
		}

		try {
			return $this->update->execute( $input, get_current_user_id() )->to_array();
		} catch ( Throwable $error ) {
			return $this->map_error( $error );
		}
	}

	/**
	 * Maps application failures to the stable WordPress adapter vocabulary.
	 *
	 * @param Throwable $error Application or domain failure.
	 */
	private function map_error( Throwable $error ): WP_Error {
		if ( $error instanceof InvalidArgumentException ) {
			return AbilityError::create( 'wpcb_invalid_input', $error->getMessage() );
		}
		if ( $error instanceof ContentUnavailable ) {
			unset( $error );

			return AbilityError::create( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		}
		if ( $error instanceof MutationConflict || $error instanceof MutationForbidden || $error instanceof MutationWriteFailed || $error instanceof ServiceSchemaUnavailable ) {
			return AbilityError::create( $error->error_code(), $error->getMessage() );
		}

		unset( $error );

		return AbilityError::create( 'wpcb_internal_error', __( 'An unexpected error occurred.', 'wp-content-bridge' ) );
	}
}
