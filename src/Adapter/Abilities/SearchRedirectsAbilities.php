<?php
/**
 * Cross-provider redirect read ability adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Redirect\SearchRedirects;
use Throwable;
use WP_Error;

/**
 * Maps the cross-provider redirect read to a WordPress Ability.
 *
 * Registered by the redirect feature flag alone, independent of
 * `wpcb_writes_enabled`: knowing which engine holds a path is a diagnostic,
 * and withholding it while writes are off would leave an operator unable to
 * see the conflict that a later write will hit.
 */
final readonly class SearchRedirectsAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param SearchRedirects $search Cross-provider read use case.
	 */
	public function __construct( private SearchRedirects $search ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public search-redirects contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			SearchRedirects::ABILITY,
			array(
				'label'               => __( 'Search redirects', 'wp-content-bridge' ),
				'description'         => __( 'Reports which redirect providers hold a rule for one exact source path, across every active provider.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::search_redirects_input(),
				'output_schema'       => AbilitySchemas::search_redirects_output(),
				'permission_callback' => array( $this, 'can_read_redirects' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => AbilityMeta::read(),
			)
		);
	}

	/**
	 * Requires the dedicated redirect capability.
	 *
	 * @param mixed $input Candidate ability input.
	 * @return bool
	 */
	public function can_read_redirects( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_manage_redirects' );
	}

	/**
	 * Executes one cross-provider redirect read.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_read_redirects() ) {
			return AbilityError::create( 'wpcb_forbidden', __( 'You are not permitted to read redirects.', 'wp-content-bridge' ) );
		}

		try {
			return $this->search->execute( $input );
		} catch ( InvalidArgumentException $error ) {
			return AbilityError::create( 'wpcb_invalid_input', $error->getMessage() );
		} catch ( Throwable ) {
			return AbilityError::create( 'wpcb_internal_error', __( 'Redirects could not be read.', 'wp-content-bridge' ) );
		}
	}
}
