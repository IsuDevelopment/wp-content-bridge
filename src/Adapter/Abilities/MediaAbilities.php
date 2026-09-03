<?php
/**
 * WordPress Abilities projection for media reads.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Media\GetMediaById;
use IsuDev\WPContentBridge\Application\Media\MediaUnavailable;
use IsuDev\WPContentBridge\Application\Media\SearchMedia;
use IsuDev\WPContentBridge\Domain\Media\MediaQuery;
use Throwable;
use WP_Error;

/**
 * Registers thin, read-only media callbacks.
 */
final readonly class MediaAbilities {

	/**
	 * Creates the media projection.
	 *
	 * @param SearchMedia  $search    Search use case.
	 * @param GetMediaById $get_by_id Detail use case.
	 */
	public function __construct(
		private SearchMedia $search,
		private GetMediaById $get_by_id,
	) {
	}

	/**
	 * Registers the media abilities on the canonical hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers stable public contracts.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'wp-content-bridge/get-media',
			array(
				'label'               => __( 'Search media', 'wp-content-bridge' ),
				'description'         => __( 'Searches the authorized WordPress media library by exact ID, exact same-site URL, exact filename, or bounded text query.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::media_search_input(),
				'output_schema'       => AbilitySchemas::media_search_output(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_search' ),
				'meta'                => AbilityMeta::read(),
			)
		);

		wp_register_ability(
			'wp-content-bridge/get-media-by-id',
			array(
				'label'               => __( 'Get media by ID', 'wp-content-bridge' ),
				'description'         => __( 'Returns one authorized WordPress attachment with stable identity and normalized metadata.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::media_by_id_input(),
				'output_schema'       => AbilitySchemas::media_by_id_output(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_get_by_id' ),
				'meta'                => AbilityMeta::read(),
			)
		);
	}

	/**
	 * Checks the dedicated plugin capability.
	 *
	 * @param mixed $input Unused input.
	 * @return bool
	 */
	public function can_read( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_read_media' );
	}

	/**
	 * Executes bounded media search.
	 *
	 * @param mixed $input Validated callback input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_search( mixed $input = array() ): array|WP_Error {
		if ( ! $this->can_read() ) {
			return self::forbidden();
		}

		try {
			return $this->search->execute( MediaQuery::from_input( self::normalize_input( $input ) ) )->to_array();
		} catch ( MediaUnavailable ) {
			return self::unavailable();
		} catch ( InvalidArgumentException $exception ) {
			return AbilityError::create( 'wpcb_invalid_input', $exception->getMessage() );
		} catch ( Throwable ) {
			return self::internal_error();
		}
	}

	/**
	 * Executes deterministic media retrieval.
	 *
	 * @param mixed $input Validated callback input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_get_by_id( mixed $input = array() ): array|WP_Error {
		if ( ! $this->can_read() ) {
			return self::forbidden();
		}

		try {
			$normalized = self::normalize_input( $input );
			$id         = $normalized['id'] ?? 0;
			if ( ! is_int( $id ) || 1 > $id ) {
				return AbilityError::create( 'wpcb_invalid_input', 'id must be a positive integer.' );
			}

			return array_merge(
				array( 'schema_version' => '1.0' ),
				$this->get_by_id->execute( $id )->to_array(),
				array(
					'provenance' => array(
						'source'    => 'wordpress',
						'untrusted' => true,
					),
				)
			);
		} catch ( MediaUnavailable ) {
			return self::unavailable();
		} catch ( InvalidArgumentException $exception ) {
			return AbilityError::create( 'wpcb_invalid_input', $exception->getMessage() );
		} catch ( Throwable ) {
			return self::internal_error();
		}
	}

	/**
	 * Keeps string keys and normalizes REST integer strings.
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
		foreach ( array( 'id', 'page', 'per_page' ) as $key ) {
			if ( isset( $normalized[ $key ] ) && is_string( $normalized[ $key ] ) && ctype_digit( $normalized[ $key ] ) ) {
				$normalized[ $key ] = (int) $normalized[ $key ];
			}
		}

		return $normalized;
	}


	/**
	 * Returns a stable capability denial.
	 *
	 * @return WP_Error
	 */
	private static function forbidden(): WP_Error {
		return AbilityError::create( 'wpcb_forbidden', __( 'You are not allowed to read media through WP Content Bridge.', 'wp-content-bridge' ) );
	}

	/**
	 * Returns a stable non-enumerating unavailable result.
	 *
	 * @return WP_Error
	 */
	private static function unavailable(): WP_Error {
		return AbilityError::create( 'wpcb_media_unavailable', __( 'Media is unavailable.', 'wp-content-bridge' ) );
	}

	/**
	 * Returns a stable unexpected-failure result.
	 *
	 * @return WP_Error
	 */
	private static function internal_error(): WP_Error {
		return AbilityError::create( 'wpcb_internal_error', __( 'WP Content Bridge could not complete the media request.', 'wp-content-bridge' ) );
	}
}
