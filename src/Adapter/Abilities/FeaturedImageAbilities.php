<?php
/**
 * Featured-image write ability adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Media\MediaUnavailable;
use IsuDev\WPContentBridge\Application\Media\UpdateFeaturedImage;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use Throwable;
use WP_Error;

/**
 * Maps the featured-image use case to WordPress Abilities and native authorization.
 */
final readonly class FeaturedImageAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param UpdateFeaturedImage $update Featured-image write use case.
	 */
	public function __construct( private UpdateFeaturedImage $update ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public update-featured-image contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			UpdateFeaturedImage::ABILITY,
			array(
				'label'               => __( 'Set featured image', 'wp-content-bridge' ),
				'description'         => __( 'Assigns an existing image attachment as one post\'s featured image, or removes the current one when attachment_id is null. It never uploads or imports a file: the attachment must already exist and be readable by the caller.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::update_featured_image_input(),
				'output_schema'       => AbilitySchemas::update_featured_image_output(),
				'permission_callback' => array( $this, 'can_update' ),
				'execute_callback'    => array( $this, 'execute' ),

				/*
				 * Destructive: assigning replaces an existing featured image and
				 * a null removes one, and in both cases the previous assignment
				 * is not recoverable from the request. Idempotent: replaying the
				 * same input reaches the same state, though the version token
				 * moves, so a replay is refused as a conflict rather than
				 * silently repeated.
				 */
				'meta'                => AbilityMeta::write( true, true ),
			)
		);
	}

	/**
	 * Requires bridge content editing plus native edit access to the target.
	 *
	 * The attachment's own readability is checked inside the use case, not
	 * here: refusing at this boundary would answer before the version token is
	 * tested, which would let a caller probe attachment existence without
	 * holding a current token.
	 *
	 * @param mixed $input Candidate Ability input.
	 * @return bool
	 */
	public function can_update( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_edit_content' ) ) {
			return false;
		}

		$raw_post_id = is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;

		return 0 < $post_id && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Executes the write.
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
		if ( $error instanceof ContentUnavailable ) {
			return AbilityError::create( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		}
		if ( $error instanceof MediaUnavailable ) {
			return AbilityError::create( 'wpcb_media_unavailable', __( 'Media is unavailable.', 'wp-content-bridge' ) );
		}
		if ( $error instanceof MutationConflict || $error instanceof MutationForbidden || $error instanceof MutationWriteFailed ) {
			return AbilityError::create( $error->error_code(), $error->getMessage() );
		}

		return AbilityError::create( 'wpcb_internal_error', __( 'An unexpected error occurred.', 'wp-content-bridge' ) );
	}
}
