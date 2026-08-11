<?php
/**
 * Restore-trashed-content ability adapter.
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
use IsuDev\WPContentBridge\Application\Mutation\RestoreTrashedContent;
use IsuDev\WPContentBridge\Application\Mutation\TrashUnavailable;
use Throwable;
use WP_Error;

/**
 * Maps the restore use case to WordPress Abilities and native authorization.
 * The mirror image of TrashAbilities — same registration gate, same
 * capability shape, no preview.
 */
final readonly class RestoreTrashedContentAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param RestoreTrashedContent $restore Restore-trashed-content use case.
	 */
	public function __construct( private RestoreTrashedContent $restore ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public restore-trashed-content contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			RestoreTrashedContent::ABILITY,
			array(
				'label'               => __( 'Restore trashed content', 'wp-content-bridge' ),
				'description'         => __( 'Restores one trashed content object to its safe pre-trash status after concurrency and authorization checks.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::restore_trashed_content_input(),
				'output_schema'       => AbilitySchemas::restore_trashed_content_output(),
				'permission_callback' => array( $this, 'can_restore' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Combines the dedicated plugin capability with native object deletion.
	 *
	 * @param mixed $input Candidate ability input.
	 * @return bool
	 */
	public function can_restore( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_delete_content' ) ) {
			return false;
		}

		$raw_post_id = is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;

		return 0 < $post_id && current_user_can( 'delete_post', $post_id );
	}

	/**
	 * Executes one restore-trashed-content request.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_restore( $input ) ) {
			return new WP_Error( 'wpcb_forbidden', __( 'You are not permitted to restore this content.', 'wp-content-bridge' ) );
		}

		try {
			return $this->restore->execute( self::normalize_input( $input ), get_current_user_id() )->to_array();
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'wpcb_invalid_input', $error->getMessage() );
		} catch ( ContentUnavailable ) {
			return new WP_Error( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		} catch ( MutationForbidden ) {
			return new WP_Error( 'wpcb_forbidden', __( 'Restoring trashed content is not permitted for this type.', 'wp-content-bridge' ) );
		} catch ( MutationConflict $error ) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		} catch ( MutationInvalidState $error ) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		} catch ( TrashUnavailable $error ) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		} catch ( MutationWriteFailed $error ) {
			return new WP_Error( 'wpcb_write_failed', $error->getMessage() );
		} catch ( Throwable ) {
			return new WP_Error( 'wpcb_internal_error', __( 'The content could not be restored.', 'wp-content-bridge' ) );
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
