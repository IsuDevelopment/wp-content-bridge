<?php
/**
 * Attachment-metadata write ability adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Media\MediaUnavailable;
use IsuDev\WPContentBridge\Application\Media\UpdateMedia;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use Throwable;
use WP_Error;

/**
 * Maps the attachment-metadata use case to WordPress Abilities.
 */
final readonly class UpdateMediaAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param UpdateMedia $update Attachment-metadata write use case.
	 */
	public function __construct( private UpdateMedia $update ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public update-media contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			UpdateMedia::ABILITY,
			array(
				'label'               => __( 'Update media metadata', 'wp-content-bridge' ),
				'description'         => __( 'Edits the descriptive fields of one existing attachment: title, alt_text, caption, description. At least one is required. It cannot change the stored file, its MIME type, or its filename, and it never uploads anything.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::update_media_input(),
				'output_schema'       => AbilitySchemas::update_media_output(),
				'permission_callback' => array( $this, 'can_update' ),
				'execute_callback'    => array( $this, 'execute' ),

				/*
				 * Destructive: each present field replaces what was there, and
				 * the previous text is not recoverable from the request
				 * (attachments carry no revisions). Not idempotent in the
				 * annotation sense, because the version token moves, so an
				 * identical replay is refused as a conflict.
				 */
				'meta'                => AbilityMeta::write( true, false ),
			)
		);
	}

	/**
	 * Requires bridge content editing plus native edit access to the attachment.
	 *
	 * @param mixed $input Candidate Ability input.
	 * @return bool
	 */
	public function can_update( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_edit_content' ) ) {
			return false;
		}

		$raw_id        = is_array( $input ) ? ( $input['attachment_id'] ?? 0 ) : 0;
		$attachment_id = is_numeric( $raw_id ) ? (int) $raw_id : 0;

		return 0 < $attachment_id && current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Executes the edit.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_update( $input ) ) {
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
	 * @return WP_Error
	 */
	private function map_error( Throwable $error ): WP_Error {
		if ( $error instanceof InvalidArgumentException ) {
			return AbilityError::create( 'wpcb_invalid_input', $error->getMessage() );
		}
		if ( $error instanceof MediaUnavailable ) {
			return AbilityError::create( 'wpcb_media_unavailable', __( 'Media is unavailable.', 'wp-content-bridge' ) );
		}
		if ( $error instanceof MutationConflict || $error instanceof MutationWriteFailed ) {
			return AbilityError::create( $error->error_code(), $error->getMessage() );
		}

		return AbilityError::create( 'wpcb_internal_error', __( 'An unexpected error occurred.', 'wp-content-bridge' ) );
	}
}
