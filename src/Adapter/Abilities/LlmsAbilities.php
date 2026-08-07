<?php
/**
 * Registers and projects the llms.txt Abilities.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Llms\GetLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\PreviewUpdateLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\RegenerateLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\UpdateLlmsTxt;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use Throwable;
use WP_Error;

/**
 * Thin Abilities API adapter for llms.txt configuration, preview,
 * publication, and regeneration.
 *
 * All four Abilities require the `wpcb_manage_llms` capability. Only the
 * three writes additionally require the `wpcb_llms_enabled` flag:
 * get-llms-txt is registered and callable regardless of the flag, so an
 * administrator can inspect configuration and ownership conflicts before
 * ever enabling publication. That asymmetry is why preview-update-llms-txt,
 * update-llms-txt, and regenerate-llms-txt are registered conditionally
 * here rather than the whole class being gated at composition time the way
 * this codebase gates its other optional feature areas.
 *
 * Registered separately from every other Abilities adapter, per the llms.txt
 * execution plan: this file carries the llms.txt contract and nothing else.
 * Contains no policy of its own beyond the capability and flag checks below
 * — it maps input/output and errors, and defers every domain decision to the
 * injected use cases.
 */
final readonly class LlmsAbilities {

	private const CATEGORY = 'wp-content-bridge';

	/**
	 * Creates the Abilities projection.
	 *
	 * @param GetLlmsTxt           $get        Get-llms-txt use case.
	 * @param PreviewUpdateLlmsTxt $preview    Preview-update-llms-txt use case.
	 * @param UpdateLlmsTxt        $update     Update-llms-txt use case.
	 * @param RegenerateLlmsTxt    $regenerate Regenerate-llms-txt use case.
	 */
	public function __construct(
		private GetLlmsTxt $get,
		private PreviewUpdateLlmsTxt $preview,
		private UpdateLlmsTxt $update,
		private RegenerateLlmsTxt $regenerate,
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
	 * Registers get-llms-txt unconditionally, and the three writes only
	 * while `wpcb_llms_enabled` is on — mirroring, for these Abilities, the
	 * ADR 0023 rule that a disabled feature exposes no additional surface.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		wp_register_ability(
			GetLlmsTxt::ABILITY,
			array(
				'label'               => __( 'Get llms.txt', 'wp-content-bridge' ),
				'description'         => __( 'Reads the current llms.txt configuration, artifact summary, and ownership-conflict state. Available without the publication flag so an administrator can inspect state before enabling anything.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::get_llms_txt_input(),
				'output_schema'       => AbilitySchemas::get_llms_txt_output(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_get' ),
				'meta'                => self::read_meta(),
			)
		);

		if ( ! self::publication_enabled() ) {
			return;
		}

		wp_register_ability(
			PreviewUpdateLlmsTxt::ABILITY,
			array(
				'label'               => __( 'Preview update llms.txt', 'wp-content-bridge' ),
				'description'         => __( 'Builds the whole prospective llms.txt document from live site content and returns current and prospective summaries plus a section-level diff, without writing anything.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::preview_update_llms_txt_input(),
				'output_schema'       => AbilitySchemas::preview_update_llms_txt_output(),
				'permission_callback' => array( $this, 'can_write' ),
				'execute_callback'    => array( $this, 'execute_preview' ),
				'meta'                => self::preview_meta(),
			)
		);

		wp_register_ability(
			UpdateLlmsTxt::ABILITY,
			array(
				'label'               => __( 'Update llms.txt', 'wp-content-bridge' ),
				'description'         => __( 'Validates a complete prospective llms.txt configuration, regenerates the document from live site content, and atomically replaces the stored configuration and snapshot.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::update_llms_txt_input(),
				'output_schema'       => AbilitySchemas::update_llms_txt_output(),
				'permission_callback' => array( $this, 'can_write' ),
				'execute_callback'    => array( $this, 'execute_update' ),
				'meta'                => self::write_meta(),
			)
		);

		wp_register_ability(
			RegenerateLlmsTxt::ABILITY,
			array(
				'label'               => __( 'Regenerate llms.txt', 'wp-content-bridge' ),
				'description'         => __( 'Rebuilds the stored llms.txt snapshot from the already-stored configuration and live site content. Accepts no input. Idempotent: leaves the stored snapshot untouched when the rebuilt document is unchanged.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::regenerate_llms_txt_input(),
				'output_schema'       => AbilitySchemas::regenerate_llms_txt_output(),
				'permission_callback' => array( $this, 'can_write' ),
				'execute_callback'    => array( $this, 'execute_regenerate' ),
				'meta'                => self::write_meta(),
			)
		);
	}

	/**
	 * Checks the dedicated management capability.
	 *
	 * @param mixed $input Unused input.
	 * @return bool
	 */
	public function can_read( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_manage_llms' );
	}

	/**
	 * Checks the management capability and the publication flag.
	 *
	 * @param mixed $input Unused input.
	 * @return bool
	 */
	public function can_write( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_manage_llms' ) && self::publication_enabled();
	}

	/**
	 * Executes a get-llms-txt read.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_get( array $input ): array|WP_Error {
		if ( ! $this->can_read( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->get->execute( $input )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * Executes an llms.txt update preview. Never writes.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_preview( array $input ): array|WP_Error {
		if ( ! $this->can_write( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->preview->execute( $input )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * Executes an llms.txt configuration update.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_update( array $input ): array|WP_Error {
		if ( ! $this->can_write( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->update->execute( $input, get_current_user_id() )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * Executes an llms.txt regeneration.
	 *
	 * @param mixed $input Ability input; must be empty.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_regenerate( mixed $input = array() ): array|WP_Error {
		if ( ! $this->can_write( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->regenerate->execute( self::normalize_regenerate_input( $input ), get_current_user_id() )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * Keeps only string keys, discarding anything else without guessing.
	 *
	 * @param mixed $input Callback input.
	 * @return array<string, mixed>
	 */
	private static function normalize_regenerate_input( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $input as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Reads the non-autoloaded publication flag.
	 *
	 * @return bool
	 */
	private static function publication_enabled(): bool {
		return (bool) get_option( Installer::LLMS_ENABLED_OPTION, false );
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
		if ( $error instanceof MutationConflict || $error instanceof MutationWriteFailed ) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		}

		return self::internal_error();
	}

	/**
	 * Returns standard write annotations.
	 *
	 * @return array<string, mixed>
	 */
	private static function write_meta(): array {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
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
	 * Returns standard read annotations.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_meta(): array {
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
		return new WP_Error( 'wpcb_forbidden', __( 'You are not permitted to perform this llms.txt operation.', 'wp-content-bridge' ) );
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
