<?php
/**
 * Registers and projects the block-level write abilities.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\BlockMismatch;
use IsuDev\WPContentBridge\Application\Mutation\BlockPathNotFound;
use IsuDev\WPContentBridge\Application\Mutation\InvalidBlockMarkup;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\PreviewBlockUpdate;
use IsuDev\WPContentBridge\Application\Mutation\UpdateBlock;
use Throwable;
use WP_Error;

/**
 * Adapter for update-block and its preview. Registered separately from
 * MutationAbilities, following the RestoreTrashedContentAbilities
 * precedent, so this file — not the shared adapter — carries the
 * path-addressed contract. Contains no policy — it maps input/output and
 * errors, and enforces capability gates in its permission callbacks.
 */
final readonly class BlockMutationAbilities {

	private const CATEGORY = 'wp-content-bridge';

	/**
	 * Creates the Abilities projection.
	 *
	 * @param UpdateBlock        $update  Update-block use case.
	 * @param PreviewBlockUpdate $preview Preview-update-block use case.
	 */
	public function __construct(
		private UpdateBlock $update,
		private PreviewBlockUpdate $preview,
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
	 * Registers the update-block and preview-update-block abilities.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		wp_register_ability(
			UpdateBlock::ABILITY,
			array(
				'label'               => __( 'Update block', 'wp-content-bridge' ),
				'description'         => __( 'Replaces exactly one block subtree, addressed by path, leaving every other block byte-identical. A matching version_token proves the document did not change but not that the path points at the intended block, so expected_block_name is asserted and the write fails closed with wpcb_block_mismatch when it differs. Empty block_markup deletes the subtree.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::update_block_input(),
				'output_schema'       => AbilitySchemas::update_block_output(),
				'permission_callback' => array( $this, 'can_update' ),
				'execute_callback'    => array( $this, 'execute_update' ),
				'meta'                => self::write_meta(),
			)
		);

		wp_register_ability(
			PreviewBlockUpdate::ABILITY,
			array(
				'label'               => __( 'Preview block update', 'wp-content-bridge' ),
				'description'         => __( 'Previews the whole post_content that update-block would store after resolving path, asserting expected_block_name, and validating block_markup, without writing.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::preview_update_block_input(),
				'output_schema'       => AbilitySchemas::preview_update_block_output(),
				'permission_callback' => array( $this, 'can_update' ),
				'execute_callback'    => array( $this, 'execute_preview' ),
				'meta'                => self::preview_meta(),
			)
		);
	}

	/**
	 * Checks capability to update the targeted post, mirroring
	 * MutationAbilities::can_update exactly.
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

		return 0 < $post_id && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Executes a block-subtree update.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_update( array $input ): array|WP_Error {
		if ( ! $this->can_update( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->update->execute( self::normalize_input( $input ), get_current_user_id() )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * Executes a block-subtree update preview. Never writes.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_preview( array $input ): array|WP_Error {
		if ( ! $this->can_update( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->preview->execute( self::normalize_input( $input ) )->to_array();
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
			|| $error instanceof BlockPathNotFound
			|| $error instanceof BlockMismatch
			|| $error instanceof InvalidBlockMarkup
			|| $error instanceof MutationForbidden
			|| $error instanceof MutationWriteFailed
		) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		}

		return self::internal_error();
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

		if ( isset( $input['path'] ) && is_array( $input['path'] ) ) {
			$input['path'] = array_map(
				static function ( mixed $index ): mixed {
					return is_string( $index ) && ctype_digit( $index ) ? (int) $index : $index;
				},
				$input['path']
			);
		}

		return $input;
	}

	/**
	 * Returns standard write annotations. Destructive because empty
	 * block_markup deletes the addressed subtree.
	 *
	 * @return array<string, mixed>
	 */
	private static function write_meta(): array {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);
	}

	/**
	 * Returns the shared annotations for the side-effect-free preview ability.
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
