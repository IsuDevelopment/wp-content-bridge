<?php
/**
 * Update redirect ability adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Redirect\UpdateRedirect;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderForbidden;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectRuleNotRepresentable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectSourceRejected;
use Throwable;
use WP_Error;

/**
 * Maps the update_redirect use case to a WordPress Ability.
 *
 * Requires `wpcb_manage_redirects`, and the named provider additionally
 * enforces its own backend authority.
 */
final readonly class UpdateRedirectAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param UpdateRedirect $use_case Redirect write use case.
	 */
	public function __construct( private UpdateRedirect $use_case ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			UpdateRedirect::ABILITY,
			array(
				'label'               => __( 'Update redirect', 'wp-content-bridge' ),
				'description'         => __( 'Changes the target or HTTP status of one existing redirect in a named provider, after a cross-provider loop check.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::update_redirect_input(),
				'output_schema'       => AbilitySchemas::update_redirect_output(),
				'permission_callback' => array( $this, 'can_manage_redirects' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => AbilityMeta::write( false, false ),
			)
		);
	}

	/**
	 * Requires the dedicated redirect capability.
	 *
	 * @param mixed $input Candidate ability input.
	 * @return bool
	 */
	public function can_manage_redirects( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_manage_redirects' );
	}

	/**
	 * Executes one redirect write.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_manage_redirects() ) {
			return AbilityError::create( 'wpcb_forbidden', __( 'You are not permitted to change redirects.', 'wp-content-bridge' ) );
		}

		try {
			return $this->use_case->execute( self::normalize_input( $input ), get_current_user_id() );
		} catch ( InvalidArgumentException $error ) {
			return AbilityError::create( 'wpcb_invalid_input', $error->getMessage() );
		} catch ( RedirectSourceRejected $error ) {
			return AbilityError::create( 'wpcb_redirect_source_rejected', $error->getMessage() );
		} catch ( RedirectRuleNotRepresentable $error ) {
			return AbilityError::create( 'wpcb_redirect_rule_not_representable', $error->getMessage() );
		} catch ( RedirectProviderForbidden $error ) {
			return AbilityError::create( 'wpcb_forbidden', $error->getMessage() );
		} catch ( RedirectProviderUnavailable $error ) {
			return AbilityError::create( 'wpcb_redirect_provider_unavailable', $error->getMessage() );
		} catch ( Throwable ) {
			return AbilityError::create( 'wpcb_write_failed', __( 'The redirect could not be changed.', 'wp-content-bridge' ) );
		}
	}

	/**
	 * Normalizes REST scalar integers before domain validation.
	 *
	 * @param array<string, mixed> $input Callback input.
	 * @return array<string, mixed>
	 */
	private static function normalize_input( array $input ): array {
		if ( isset( $input['status'] ) && is_string( $input['status'] ) && ctype_digit( $input['status'] ) ) {
			$input['status'] = (int) $input['status'];
		}

		return $input;
	}
}
