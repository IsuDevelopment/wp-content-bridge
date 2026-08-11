<?php
/**
 * Registers and projects the write abilities.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\CreateDraft;
use IsuDev\WPContentBridge\Application\Mutation\InvalidBlockMarkup;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\PreviewContentUpdate;
use IsuDev\WPContentBridge\Application\Mutation\PreviewSeoUpdate;
use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use IsuDev\WPContentBridge\Application\Mutation\SeoImageUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\UpdateContent;
use IsuDev\WPContentBridge\Application\Mutation\UpdateSeo;
use Throwable;
use WP_Error;

/**
 * Adapter for create-draft, update-content, update-seo, and their previews.
 * Contains no policy — it maps input/output and errors, and enforces
 * capability gates in its permission callbacks.
 */
final readonly class MutationAbilities {

	private const CATEGORY = AbilityCategory::SLUG;

	/**
	 * Creates the Abilities projection.
	 *
	 * @param CreateDraft          $create          Create-draft use case.
	 * @param UpdateContent        $update          Update-content use case.
	 * @param UpdateSeo            $update_seo      Update-SEO use case.
	 * @param PreviewContentUpdate $preview_content Preview-update-content use case.
	 * @param PreviewSeoUpdate     $preview_seo     Preview-update-seo use case.
	 */
	public function __construct(
		private CreateDraft $create,
		private UpdateContent $update,
		private UpdateSeo $update_seo,
		private PreviewContentUpdate $preview_content,
		private PreviewSeoUpdate $preview_seo,
	) {
	}

	/**
	 * Registers WordPress lifecycle hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the write abilities.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		wp_register_ability(
			CreateDraft::ABILITY,
			array(
				'label'               => __( 'Create draft', 'wp-content-bridge' ),
				'description'         => __( 'Create a new draft post, page, or custom post type.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::create_draft_input(),
				'output_schema'       => AbilitySchemas::create_draft_output(),
				'permission_callback' => array( $this, 'can_create' ),
				'execute_callback'    => array( $this, 'execute_create' ),
				'meta'                => self::write_meta( false ),
			)
		);

		wp_register_ability(
			UpdateContent::ABILITY,
			array(
				'label'               => __( 'Update content', 'wp-content-bridge' ),
				'description'         => __( 'Update the title, content, excerpt, or taxonomies of an existing post.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::update_content_input(),
				'output_schema'       => AbilitySchemas::update_content_output(),
				'permission_callback' => array( $this, 'can_update' ),
				'execute_callback'    => array( $this, 'execute_update' ),
				'meta'                => self::write_meta( true ),
			)
		);

		wp_register_ability(
			UpdateSeo::ABILITY,
			array(
				'label'               => __( 'Update SEO', 'wp-content-bridge' ),
				'description'         => __( 'Write the Yoast Free core SEO field allowlist for an existing post.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::update_seo_input(),
				'output_schema'       => AbilitySchemas::update_seo_output(),
				'permission_callback' => array( $this, 'can_update_seo' ),
				'execute_callback'    => array( $this, 'execute_update_seo' ),
				'meta'                => self::write_meta( true ),
			)
		);

		wp_register_ability(
			PreviewContentUpdate::ABILITY,
			array(
				'label'               => __( 'Preview content update', 'wp-content-bridge' ),
				'description'         => __( 'Preview the title, content, excerpt, and taxonomy changes an update would produce, without writing.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::preview_content_input(),
				'output_schema'       => AbilitySchemas::preview_content_output(),
				'permission_callback' => array( $this, 'can_update' ),
				'execute_callback'    => array( $this, 'execute_preview_content' ),
				'meta'                => self::preview_meta(),
			)
		);

		wp_register_ability(
			PreviewSeoUpdate::ABILITY,
			array(
				'label'               => __( 'Preview SEO update', 'wp-content-bridge' ),
				'description'         => __( 'Preview the normalized SEO field values an update would produce, without writing.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::preview_seo_input(),
				'output_schema'       => AbilitySchemas::preview_seo_output(),
				'permission_callback' => array( $this, 'can_update_seo' ),
				'execute_callback'    => array( $this, 'execute_preview_seo' ),
				'meta'                => self::preview_meta(),
			)
		);
	}

	/**
	 * Checks capability to create the requested post type.
	 *
	 * @param mixed $input Candidate ability input.
	 * @return bool
	 */
	public function can_create( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_edit_content' ) ) {
			return false;
		}

		$post_type = is_array( $input ) && is_string( $input['post_type'] ?? null ) ? $input['post_type'] : '';
		$object    = get_post_type_object( $post_type );
		if ( null === $object ) {
			return false;
		}

		$capability = $object->cap->create_posts ?? $object->cap->edit_posts ?? 'edit_posts';

		return current_user_can( is_string( $capability ) ? $capability : 'edit_posts' );
	}

	/**
	 * Checks capability to update the targeted post.
	 *
	 * @param mixed $input Candidate ability input.
	 * @return bool
	 */
	public function can_update( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_edit_content' ) ) {
			return false;
		}

		$raw_post_id = is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;
		if ( 0 >= $post_id ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Checks capability to write SEO on the targeted post.
	 *
	 * @param mixed $input Candidate ability input.
	 * @return bool
	 */
	public function can_update_seo( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_manage_seo' ) ) {
			return false;
		}

		$raw_post_id = is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;
		if ( 0 >= $post_id ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Executes draft creation.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_create( array $input ): array|WP_Error {
		if ( ! $this->can_create( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->create->execute( $input, get_current_user_id() )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * Executes a content update.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_update( array $input ): array|WP_Error {
		if ( ! $this->can_update( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->update->execute( $input, get_current_user_id() )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * Executes an SEO update.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_update_seo( array $input ): array|WP_Error {
		if ( ! $this->can_update_seo( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->update_seo->execute( $input, get_current_user_id() )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * Executes a content-update preview. Never writes.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_preview_content( array $input ): array|WP_Error {
		if ( ! $this->can_update( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->preview_content->execute( $input )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * Executes an SEO-update preview. Never writes.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_preview_seo( array $input ): array|WP_Error {
		if ( ! $this->can_update_seo( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->preview_seo->execute( $input )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * Maps a thrown failure to a stable WP_Error.
	 *
	 * @param Throwable $error The failure that ended the attempt.
	 * @return WP_Error
	 */
	private function to_error( Throwable $error ): WP_Error {
		if ( $error instanceof InvalidArgumentException ) {
			return new WP_Error( 'wpcb_invalid_input', $error->getMessage() );
		}
		if ( $error instanceof ContentUnavailable ) {
			return new WP_Error( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		}
		if ( $error instanceof MutationConflict
			|| $error instanceof InvalidBlockMarkup
			|| $error instanceof MutationForbidden
			|| $error instanceof MutationWriteFailed
			|| $error instanceof SeoFieldUnsupported
			|| $error instanceof SeoImageUnavailable
		) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		}

		return self::internal_error();
	}

	/**
	 * Returns standard write annotations.
	 *
	 * @param bool $destructive Whether the write can destroy existing content.
	 * @return array<string, mixed>
	 */
	private static function write_meta( bool $destructive ): array {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => $destructive,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);
	}

	/**
	 * Returns the shared annotations for side-effect-free preview Abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function preview_meta(): array {
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

	/**
	 * Creates a stable forbidden result.
	 *
	 * @return WP_Error
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error( 'wpcb_forbidden', __( 'You are not permitted to perform this write.', 'wp-content-bridge' ) );
	}

	/**
	 * Returns an opaque error without leaking internal details.
	 *
	 * @return WP_Error
	 */
	private static function internal_error(): WP_Error {
		return new WP_Error( 'wpcb_internal_error', __( 'An unexpected error occurred.', 'wp-content-bridge' ) );
	}
}
