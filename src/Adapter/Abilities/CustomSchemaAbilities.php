<?php
/**
 * Registers optional Custom Schema read, preview, and write Abilities.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\CustomSchemaInvalid;
use IsuDev\WPContentBridge\Application\Mutation\CustomSchemaUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\GetCustomSchema;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\PreviewCustomSchema;
use IsuDev\WPContentBridge\Application\Mutation\UpdateCustomSchema;
use Throwable;
use WP_Error;

/**
 * Thin Abilities API adapter for optional bounded Custom Schema configuration.
 */
final readonly class CustomSchemaAbilities {

	private const CATEGORY = AbilityCategory::SLUG;

	/**
	 * Creates the adapter.
	 *
	 * @param GetCustomSchema     $get     Read application use case.
	 * @param PreviewCustomSchema $preview Preview application use case.
	 * @param UpdateCustomSchema  $update  Write application use case.
	 */
	public function __construct(
		private GetCustomSchema $get,
		private PreviewCustomSchema $preview,
		private UpdateCustomSchema $update,
	) {}

	/**
	 * Registers the WordPress lifecycle hook.
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the public Ability contracts.
	 */
	public function register_abilities(): void {
		wp_register_ability(
			GetCustomSchema::ABILITY,
			array(
				'label'               => __( 'Get Custom Schema', 'wp-content-bridge' ),
				'description'         => __( 'Read saved Custom Schema JSON and bounded structural validation for an existing post.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::get_custom_schema_input(),
				'output_schema'       => AbilitySchemas::get_custom_schema_output(),
				'permission_callback' => array( $this, 'can_manage' ),
				'execute_callback'    => array( $this, 'execute_get' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			PreviewCustomSchema::ABILITY,
			array(
				'label'               => __( 'Preview Custom Schema update', 'wp-content-bridge' ),
				'description'         => __( 'Validate prospective Custom Schema JSON without writing it. Use get-url-seo after a write to inspect the complete resolved Yoast graph.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::preview_custom_schema_input(),
				'output_schema'       => AbilitySchemas::preview_custom_schema_output(),
				'permission_callback' => array( $this, 'can_manage' ),
				'execute_callback'    => array( $this, 'execute_preview' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			UpdateCustomSchema::ABILITY,
			array(
				'label'               => __( 'Update Custom Schema', 'wp-content-bridge' ),
				'description'         => __( 'Update bounded Custom Schema JSON through Schema Extended validation and return the effective saved configuration.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::update_custom_schema_input(),
				'output_schema'       => AbilitySchemas::update_custom_schema_output(),
				'permission_callback' => array( $this, 'can_manage' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Requires bridge SEO management and native post edit access.
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
	 * Executes a Custom Schema read.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_get( array $input ): array|WP_Error {
		if ( ! $this->can_manage( $input ) ) {
			return new WP_Error( 'wpcb_forbidden', __( 'You are not permitted to read this configuration.', 'wp-content-bridge' ) );
		}

		try {
			return $this->get->execute( $input )->to_array();
		} catch ( Throwable $error ) {
			return $this->map_error( $error );
		}
	}

	/**
	 * Executes a Custom Schema preview without writing.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_preview( array $input ): array|WP_Error {
		if ( ! $this->can_manage( $input ) ) {
			return new WP_Error( 'wpcb_forbidden', __( 'You are not permitted to preview this configuration.', 'wp-content-bridge' ) );
		}

		try {
			return $this->preview->execute( $input )->to_array();
		} catch ( Throwable $error ) {
			return $this->map_error( $error );
		}
	}

	/**
	 * Executes a Custom Schema update.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_manage( $input ) ) {
			return new WP_Error( 'wpcb_forbidden', __( 'You are not permitted to perform this write.', 'wp-content-bridge' ) );
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
		if ( $error instanceof CustomSchemaInvalid ) {
			return new WP_Error( $error->error_code(), $error->getMessage(), array( 'validation' => $error->validation() ) );
		}
		if ( $error instanceof InvalidArgumentException ) {
			return new WP_Error( 'wpcb_invalid_input', $error->getMessage() );
		}
		if ( $error instanceof ContentUnavailable ) {
			return new WP_Error( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		}
		if ( $error instanceof MutationConflict || $error instanceof MutationForbidden || $error instanceof MutationWriteFailed || $error instanceof CustomSchemaUnavailable ) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		}

		return new WP_Error( 'wpcb_internal_error', __( 'An unexpected error occurred.', 'wp-content-bridge' ) );
	}

	/**
	 * Returns the shared MCP annotations for side-effect-free operations.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_meta(): array {
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);
	}
}
