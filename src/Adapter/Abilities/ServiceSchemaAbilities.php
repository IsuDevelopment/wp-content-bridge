<?php
/**
 * Registers and projects the optional Service schema write Ability.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\ServiceSchemaUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\UpdateServiceSchema;
use Throwable;
use WP_Error;

/**
 * Thin Abilities API adapter for the optional structured Service write.
 */
final readonly class ServiceSchemaAbilities {

	private const CATEGORY = 'wp-content-bridge';

	/**
	 * Creates the adapter.
	 *
	 * @param UpdateServiceSchema $update Application use case.
	 */
	public function __construct( private UpdateServiceSchema $update ) {}

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
			UpdateServiceSchema::ABILITY,
			array(
				'label'               => __( 'Update Service schema', 'wp-content-bridge' ),
				'description'         => __( 'Configure a structured Service entity, service areas, brands, and OfferCatalog for an existing post.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::update_service_schema_input(),
				'output_schema'       => AbilitySchemas::update_service_schema_output(),
				'permission_callback' => array( $this, 'can_update' ),
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
	 * Requires both the bridge SEO capability and native post edit access.
	 *
	 * @param mixed $input Candidate Ability input.
	 */
	public function can_update( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_manage_seo' ) ) {
			return false;
		}

		$raw_post_id = is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;

		return 0 < $post_id && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Executes a Service schema update.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_update( $input ) ) {
			return new WP_Error( 'wpcb_forbidden', __( 'You are not permitted to perform this write.', 'wp-content-bridge' ) );
		}

		try {
			return $this->update->execute( $input, get_current_user_id() )->to_array();
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'wpcb_invalid_input', $error->getMessage() );
		} catch ( ContentUnavailable $error ) {
			unset( $error );

			return new WP_Error( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		} catch ( MutationConflict | MutationForbidden | MutationWriteFailed | ServiceSchemaUnavailable $error ) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		} catch ( Throwable $error ) {
			unset( $error );

			return new WP_Error( 'wpcb_internal_error', __( 'An unexpected error occurred.', 'wp-content-bridge' ) );
		}
	}
}
