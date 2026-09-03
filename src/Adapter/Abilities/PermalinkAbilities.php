<?php
/**
 * Permalink write ability adapter.
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
use IsuDev\WPContentBridge\Application\Mutation\PermalinkUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\UpdatePermalink;
use Throwable;
use WP_Error;

/**
 * Maps the permalink use case to WordPress Abilities and native authorization.
 */
final readonly class PermalinkAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param UpdatePermalink $update Permalink write use case.
	 */
	public function __construct( private UpdatePermalink $update ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public update-permalink contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			UpdatePermalink::ABILITY,
			array(
				'label'               => __( 'Change permalink', 'wp-content-bridge' ),
				'description'         => __( "Changes one post's slug and returns both the previous and the new URL, so a redirect can be created from the old one. A slug already in use is refused rather than silently uniquified. It cannot change the site's permalink structure.", 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::update_permalink_input(),
				'output_schema'       => AbilitySchemas::update_permalink_output(),
				'permission_callback' => array( $this, 'can_update' ),
				'execute_callback'    => array( $this, 'execute' ),

				/*
				 * Destructive: the old URL stops resolving to this object from
				 * any route WordPress does not cover with `_wp_old_slug`, and
				 * inbound links to it break. Nothing in the request restores the
				 * previous slug, which is why the response returns it.
				 */
				'meta'                => AbilityMeta::write( true, false ),
			)
		);
	}

	/**
	 * Requires bridge content editing plus native edit access to the target.
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
	 * Executes the slug change.
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
		if (
			$error instanceof PermalinkUnavailable
			|| $error instanceof MutationConflict
			|| $error instanceof MutationForbidden
			|| $error instanceof MutationWriteFailed
		) {
			return AbilityError::create( $error->error_code(), $error->getMessage() );
		}

		return AbilityError::create( 'wpcb_internal_error', __( 'An unexpected error occurred.', 'wp-content-bridge' ) );
	}
}
