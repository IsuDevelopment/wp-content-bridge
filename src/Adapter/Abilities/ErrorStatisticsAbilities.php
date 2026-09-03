<?php
/**
 * Site error-statistics ability adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Statistics\ErrorStatisticsUnreadable;
use IsuDev\WPContentBridge\Application\Statistics\GetNotFoundStatistics;
use Throwable;
use WP_Error;

/**
 * Maps the aggregate 404 read to a WordPress Ability (ADR 0030).
 *
 * Requires `wpcb_read_error_statistics`, which is deliberately **not**
 * `wpcb_manage_redirects` (ADR 0030 s5): Redirection's own permission model
 * already separates reading the 404 log from managing redirects, so honouring
 * the separation costs nothing and makes the useful grant expressible -
 * diagnose without authority to change routing.
 *
 * The provider's own permission model is enforced inside the adapter that
 * reads its table, because a direct read never reaches the provider's check.
 */
final readonly class ErrorStatisticsAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param GetNotFoundStatistics $statistics Aggregate 404 read use case.
	 */
	public function __construct( private GetNotFoundStatistics $statistics ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public get-404-statistics contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			GetNotFoundStatistics::ABILITY,
			array(
				'label'               => __( 'Get 404 statistics', 'wp-content-bridge' ),
				'description'         => __( 'Returns aggregated counts of the paths that returned 404 on this site, per statistics provider, with the retention window each count covers. Aggregate only: it reports the requested path and a hit count and never a visitor IP, user agent, referrer, or request data. A provider that collects nothing, a log that is switched off, and a permission denial are reported as distinct states, never as zero hits.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::not_found_statistics_input(),
				'output_schema'       => AbilitySchemas::not_found_statistics_output(),
				'permission_callback' => array( $this, 'can_read_statistics' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => AbilityMeta::read(),
			)
		);
	}

	/**
	 * Requires the dedicated statistics capability.
	 *
	 * @param mixed $input Candidate ability input.
	 * @return bool
	 */
	public function can_read_statistics( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_read_error_statistics' );
	}

	/**
	 * Executes one aggregate 404 read.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = array() ): array|WP_Error {
		if ( ! $this->can_read_statistics() ) {
			return AbilityError::create( 'wpcb_forbidden', __( 'You are not permitted to read site error statistics.', 'wp-content-bridge' ) );
		}

		try {
			return $this->statistics->execute(
				self::normalize_input( $input ),
				new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			);
		} catch ( InvalidArgumentException $error ) {
			return AbilityError::create( 'wpcb_invalid_input', $error->getMessage() );
		} catch ( ErrorStatisticsUnreadable ) {
			// Distinct from the four reported states on purpose: those are
			// answers, this is a backend that failed while answering, and a
			// caller must not read it as "nothing is being collected".
			return AbilityError::create( 'wpcb_statistics_unreadable', __( 'A statistics provider is present but its log could not be read.', 'wp-content-bridge' ) );
		} catch ( Throwable ) {
			return AbilityError::create( 'wpcb_internal_error', __( 'Site error statistics could not be read.', 'wp-content-bridge' ) );
		}
	}

	/**
	 * Keeps string keys from arbitrary callback input.
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

		return $normalized;
	}
}
