<?php
/**
 * Remote image import ability adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Media\CreateMedia;
use IsuDev\WPContentBridge\Application\Media\MediaUnavailable;
use IsuDev\WPContentBridge\Application\Media\MediaUploadFailed;
use Throwable;
use WP_Error;

/**
 * Maps the media import use case to WordPress Abilities and native authorization.
 */
final readonly class CreateMediaAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param CreateMedia $create Media import use case.
	 */
	public function __construct( private CreateMedia $create ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public create-media contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			CreateMedia::ABILITY,
			array(
				'label'               => __( 'Import an image', 'wp-content-bridge' ),
				'description'         => __( 'Fetches one image from a remote URL and stores it in the media library. The stored file type is decided by the downloaded bytes, not by the URL or its extension, and only JPEG, PNG, GIF, WebP, and AVIF are accepted. It does not attach the image to any post: use update-featured-image for that. An idempotency_key is required so a retried call returns the same attachment instead of importing a second copy.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::create_media_input(),
				'output_schema'       => AbilitySchemas::create_media_output(),
				'permission_callback' => array( $this, 'can_create' ),
				'execute_callback'    => array( $this, 'execute' ),

				/*
				 * Not destructive: creating an attachment cannot lose content
				 * the client did not supply (ADR 0028). Not idempotent: the
				 * operation itself would create a second attachment on replay,
				 * and the required key - not the annotation - is what makes a
				 * retry safe. Annotating it idempotent would tell a client that
				 * retrying blind is fine, which is what the key prevents.
				 */
				'meta'                => AbilityMeta::write( false, false ),
			)
		);
	}

	/**
	 * Requires the dedicated upload capability plus native upload rights.
	 *
	 * The plugin capability is separate from `wpcb_edit_content` on purpose: a
	 * principal that may edit text is not thereby a principal that may put
	 * files on the server.
	 *
	 * @param mixed $input Unused candidate Ability input.
	 * @return bool
	 */
	public function can_create( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_upload_media' ) && current_user_can( 'upload_files' );
	}

	/**
	 * Executes the import.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_create( $input ) ) {
			return AbilityError::create( 'wpcb_forbidden', __( 'You are not permitted to import media.', 'wp-content-bridge' ) );
		}

		try {
			return $this->create->execute( $input, get_current_user_id() )->to_array();
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
		if ( $error instanceof MediaUploadFailed ) {
			/*
			 * One public message for every refusal stage. Telling a caller
			 * whether a host resolved, answered, or answered with the wrong
			 * bytes is the reconnaissance an SSRF attempt is after.
			 */
			return AbilityError::create( $error->error_code(), __( 'The image could not be imported from that URL.', 'wp-content-bridge' ) );
		}

		return AbilityError::create( 'wpcb_internal_error', __( 'An unexpected error occurred.', 'wp-content-bridge' ) );
	}
}
