<?php
/**
 * WordPress Abilities projection for block-pattern discovery.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Pattern\ListBlockPatterns;
use IsuDev\WPContentBridge\Application\Pattern\PatternAccessManager;
use IsuDev\WPContentBridge\Application\Pattern\PatternPayloadTooLarge;
use IsuDev\WPContentBridge\Application\Pattern\PatternUnavailable;
use IsuDev\WPContentBridge\Domain\Pattern\PatternQuery;
use Throwable;
use WP_Error;

/**
 * Registers the thin, read-only block-pattern ability.
 */
final readonly class PatternAbilities {

	/**
	 * Creates the projection.
	 *
	 * @param PatternAccessManager $access Pattern policy.
	 * @param ListBlockPatterns    $service Listing use case.
	 */
	public function __construct(
		private PatternAccessManager $access,
		private ListBlockPatterns $service,
	) {
	}

	/**
	 * Registers the canonical ability hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the public list contract.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'wp-content-bridge/list-block-patterns',
			array(
				'label'               => __( 'List block patterns', 'wp-content-bridge' ),
				'description'         => __( 'Lists bounded metadata and optional complete markup for block patterns registered on this WordPress site.', 'wp-content-bridge' ),
				'category'            => 'wp-content-bridge',
				'input_schema'        => AbilitySchemas::pattern_list_input(),
				'output_schema'       => AbilitySchemas::pattern_list_output(),
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
	 * Combines the dedicated plugin capability with native editor access.
	 *
	 * @param mixed $input Unused input.
	 * @return bool
	 */
	public function can_read( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_read_patterns' ) && $this->access->can_read();
	}

	/**
	 * Executes pattern listing through the application service.
	 *
	 * @param mixed $input Validated callback input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = array() ): array|WP_Error {
		if ( ! $this->can_read() ) {
			return new WP_Error( 'wpcb_forbidden', __( 'You are not allowed to read block patterns through WP Content Bridge.', 'wp-content-bridge' ) );
		}

		try {
			return $this->service->execute( PatternQuery::from_input( self::normalize_input( $input ) ) )->to_array();
		} catch ( PatternUnavailable ) {
			return new WP_Error( 'wpcb_pattern_unavailable', __( 'Block patterns are unavailable.', 'wp-content-bridge' ) );
		} catch ( PatternPayloadTooLarge ) {
			return new WP_Error( 'wpcb_pattern_content_too_large', __( 'Requested block-pattern content exceeds the response limit.', 'wp-content-bridge' ) );
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'wpcb_invalid_input', $exception->getMessage() );
		} catch ( Throwable ) {
			return new WP_Error( 'wpcb_internal_error', __( 'WP Content Bridge could not list block patterns.', 'wp-content-bridge' ) );
		}
	}

	/**
	 * Keeps string keys and normalizes REST scalar strings.
	 *
	 * @param mixed $input Callback input.
	 * @return array<string, mixed>
	 */
	private static function normalize_input( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $input as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}
		foreach ( array( 'page', 'per_page' ) as $key ) {
			if ( isset( $normalized[ $key ] ) && is_string( $normalized[ $key ] ) && ctype_digit( $normalized[ $key ] ) ) {
				$normalized[ $key ] = (int) $normalized[ $key ];
			}
		}
		if ( isset( $normalized['include_content'] ) && is_string( $normalized['include_content'] ) ) {
			if ( in_array( $normalized['include_content'], array( '1', 'true' ), true ) ) {
				$normalized['include_content'] = true;
			} elseif ( in_array( $normalized['include_content'], array( '0', 'false' ), true ) ) {
				$normalized['include_content'] = false;
			}
		}

		return $normalized;
	}
}
