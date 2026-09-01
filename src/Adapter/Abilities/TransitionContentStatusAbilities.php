<?php
/**
 * Transition-content-status ability adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationInvalidState;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Status\TransitionContentStatus;
use Throwable;
use WP_Error;

/**
 * Maps the status-transition write to WordPress Abilities and native
 * authorization.
 *
 * Registered only while `wpcb_writes_enabled` is on (see `Plugin::boot()`):
 * per ADR 0024, the write must be unregistered while its gate is off, never
 * registered-and-refusing.
 */
final readonly class TransitionContentStatusAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param TransitionContentStatus $transition Write use case.
	 */
	public function __construct( private TransitionContentStatus $transition ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public transition-content-status contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			TransitionContentStatus::ABILITY,
			array(
				'label'               => __( 'Transition content status', 'wp-content-bridge' ),
				'description'         => __( 'Moves one content object to a status permitted by the configured per-type transition graph, optionally scheduling it, after concurrency and authorization checks. The response reports the status actually stored, never merely the one requested.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::transition_content_status_input(),
				'output_schema'       => AbilitySchemas::transition_content_status_output(),
				'permission_callback' => array( $this, 'can_transition' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => AbilityMeta::write( false, false ),
			)
		);
	}

	/**
	 * Combines the dedicated plugin capability with native object editing.
	 *
	 * A cheap, target-status-blind pre-filter, matching
	 * {@see MutationAbilities::can_update()}. The full, authoritative,
	 * strictly-ordered gate chain — including the additional publication
	 * checks for `publish`/`future` — lives entirely inside
	 * {@see TransitionContentStatus::execute()}, so this callback failing or
	 * passing never changes what the use case itself enforces.
	 *
	 * @param mixed $input Candidate ability input.
	 * @return bool
	 */
	public function can_transition( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_edit_content' ) ) {
			return false;
		}

		$raw_post_id = is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;

		return 0 < $post_id && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Executes one transition-content-status request.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_transition( $input ) ) {
			return AbilityError::create( 'wpcb_forbidden', __( 'You are not permitted to transition this content.', 'wp-content-bridge' ) );
		}

		try {
			return $this->transition->execute( self::normalize_input( $input ), get_current_user_id() )->to_array();
		} catch ( InvalidArgumentException $error ) {
			return AbilityError::create( 'wpcb_invalid_input', $error->getMessage() );
		} catch ( ContentUnavailable ) {
			return AbilityError::create( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		} catch ( MutationForbidden $error ) {
			return AbilityError::create( 'wpcb_forbidden', $error->getMessage() );
		} catch ( MutationConflict $error ) {
			return AbilityError::create( $error->error_code(), $error->getMessage() );
		} catch ( MutationInvalidState $error ) {
			return AbilityError::create( $error->error_code(), $error->getMessage() );
		} catch ( MutationWriteFailed $error ) {
			return AbilityError::create( 'wpcb_write_failed', $error->getMessage() );
		} catch ( Throwable ) {
			return AbilityError::create( 'wpcb_internal_error', __( 'The status transition could not be completed.', 'wp-content-bridge' ) );
		}
	}

	/**
	 * Normalizes REST scalar integers before domain validation.
	 *
	 * @param array<string, mixed> $input Callback input.
	 * @return array<string, mixed>
	 */
	private static function normalize_input( array $input ): array {
		if ( isset( $input['post_id'] ) && is_string( $input['post_id'] ) && ctype_digit( $input['post_id'] ) ) {
			$input['post_id'] = (int) $input['post_id'];
		}

		return $input;
	}
}
