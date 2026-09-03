<?php
/**
 * Redirect write ability adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Redirect\CreateRedirect;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderForbidden;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectRuleNotRepresentable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectSourceRejected;
use Throwable;
use WP_Error;

/**
 * Maps the named-provider redirect write to a WordPress Ability.
 *
 * Requires `wpcb_manage_redirects`, and the selected provider additionally
 * enforces its own backend authority — Redirection through its scoped
 * permission filters, Yoast through its native `wpseo_manage_redirects`,
 * which that adapter has to check itself because Yoast's manager checks
 * nothing (ADR 0026 amendment).
 */
final readonly class CreateRedirectAbilities {

	/**
	 * Creates the ability adapter.
	 *
	 * @param CreateRedirect $create Named-provider write use case.
	 */
	public function __construct( private CreateRedirect $create ) {}

	/**
	 * Registers the ability lifecycle hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Registers the public create-redirect contract.
	 *
	 * @return void
	 */
	public function register_ability(): void {
		wp_register_ability(
			CreateRedirect::ABILITY,
			array(
				'label'               => __( 'Create redirect', 'wp-content-bridge' ),
				'description'         => __( 'Creates one exact-match redirect in a named provider after cross-provider collision, loop, reserved-path and live-content checks.', 'wp-content-bridge' ),
				'category'            => AbilityCategory::SLUG,
				'input_schema'        => AbilitySchemas::create_redirect_input(),
				'output_schema'       => AbilitySchemas::create_redirect_output(),
				'permission_callback' => array( $this, 'can_create_redirects' ),
				'execute_callback'    => array( $this, 'execute' ),
				// Creating a redirect adds routing; it loses no content or
				// configuration the caller did not supply, so it is not
				// destructive (ADR 0028). It is not idempotent either: a
				// second identical call collides with the rule it created.
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
	public function can_create_redirects( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_manage_redirects' );
	}

	/**
	 * Executes one redirect write into the named provider.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		if ( ! $this->can_create_redirects() ) {
			return AbilityError::create( 'wpcb_forbidden', __( 'You are not permitted to create redirects.', 'wp-content-bridge' ) );
		}

		try {
			return $this->create->execute( self::normalize_input( $input ), get_current_user_id() );
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
			return AbilityError::create( 'wpcb_write_failed', __( 'The redirect could not be created.', 'wp-content-bridge' ) );
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
