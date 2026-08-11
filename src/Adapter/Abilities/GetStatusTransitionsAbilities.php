<?php
/**
 * Get-status-transitions ability adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Status\GetStatusTransitions;
use Throwable;
use WP_Error;

/**
 * Maps the read-only status-transition query to WordPress Abilities.
 *
 * Always registered, like the other reads in {@see ContentAbilities} — the
 * write half of this feature area, `transition-content-status`, lives in
 * {@see TransitionContentStatusAbilities} and is registered only while
 * `wpcb_writes_enabled` is on, so this class must never depend on anything
 * gated by that flag.
 */
final readonly class GetStatusTransitionsAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param GetStatusTransitions $get Read use case.
	 */
	public function __construct( private GetStatusTransitions $get ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public get-status-transitions contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			GetStatusTransitions::ABILITY,
			array(
				'label'               => __( 'Get status transitions', 'wp-content-bridge' ),
				'description'         => __( "Returns the configured status transitions available from one content object's current status, which additional publication gates the acting principal satisfies for each, and whether this site can actually run scheduled publication.", 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::get_status_transitions_input(),
				'output_schema'       => AbilitySchemas::get_status_transitions_output(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Checks the plugin-level read capability only.
	 *
	 * Per-object native and per-type policy checks happen inside the use
	 * case, matching {@see ContentAbilities::can_read()}: this callback
	 * stays object-blind, so failing it never distinguishes "no capability"
	 * from "unreadable object" — the same non-enumerating property the
	 * {@see ContentUnavailable} failure inside the use case exists for.
	 *
	 * @param mixed $input Unused ability input.
	 * @return bool
	 */
	public function can_read( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_read_content' );
	}

	/**
	 * Executes one get-status-transitions request.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_read() ) {
			return new WP_Error( 'wpcb_forbidden', __( 'You are not allowed to read content through WP Content Bridge.', 'wp-content-bridge' ) );
		}

		$post_id = isset( $input['post_id'] ) ? self::normalized_post_id( $input['post_id'] ) : 0;
		if ( 0 >= $post_id ) {
			return new WP_Error( 'wpcb_invalid_input', __( 'post_id must be a positive integer.', 'wp-content-bridge' ) );
		}

		try {
			return $this->get->execute( $post_id )->to_array();
		} catch ( ContentUnavailable ) {
			return new WP_Error( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		} catch ( Throwable ) {
			return new WP_Error( 'wpcb_internal_error', __( 'The status-transition query could not be completed.', 'wp-content-bridge' ) );
		}
	}

	/**
	 * Normalizes a REST-supplied scalar integer.
	 *
	 * @param mixed $value Candidate post ID.
	 * @return int
	 */
	private static function normalized_post_id( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return (int) $value;
		}

		return 0;
	}
}
