<?php
/**
 * WordPress listener that records ability invocation attempts.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use IsuDev\WPContentBridge\Application\Telemetry\InvocationAttempt;
use IsuDev\WPContentBridge\Application\Telemetry\InvocationLog;
use WP_Ability;

/**
 * Observes ability execution through the WordPress 7.1 lifecycle hooks.
 *
 * This closes a real gap: an ability refused at `permission_callback` used to
 * leave no trace anywhere, and reads are never audited. `wp_ability_invoked` is
 * the first core hook that fires for every invocation, before anything can
 * reject it.
 *
 * Core's `WP_Ability::execute()` decides what is observable here, and it is less
 * than it first appears:
 *
 * - `wp_ability_invoked` fires on every call, before normalization, validation
 *   and the permission check.
 * - `wp_after_execute_ability` fires **only on success**; every failure path
 *   returns a `WP_Error` before reaching it.
 *
 * So an entry is `completed` or it is `attempted`, and `attempted` means only
 * "did not complete" — a denial, invalid input and an internal error are
 * indistinguishable, because no hook fires on those paths. The naming says so
 * on purpose (ADR 0029).
 *
 * This class is registered only while the telemetry flag is on, so with the
 * flag off no hook runs at all rather than running and discarding.
 */
final readonly class AbilityInvocationTelemetry {

	/**
	 * Creates the listener.
	 *
	 * @param InvocationLog $log Bounded telemetry sink.
	 */
	public function __construct( private InvocationLog $log ) {
	}

	/**
	 * Registers the lifecycle hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ability_invoked', array( $this, 'on_invoked' ), 10, 3 );
		add_action( 'wp_after_execute_ability', array( $this, 'on_completed' ), 10, 4 );
	}

	/**
	 * Records an attempt.
	 *
	 * The second parameter is the raw ability input and is deliberately unused:
	 * it can carry the site's content, and `InvocationAttempt` has no field that
	 * could hold it.
	 *
	 * @param string $ability_name Ability name.
	 * @param mixed  $input        Raw ability input. Never recorded.
	 * @param mixed  $ability      Ability instance.
	 * @return void
	 */
	public function on_invoked( string $ability_name, mixed $input = null, mixed $ability = null ): void {
		unset( $input );

		if ( ! self::is_own_ability( $ability_name, $ability ) ) {
			return;
		}

		$this->log->record(
			new InvocationAttempt(
				$ability_name,
				get_current_user_id(),
				self::channel(),
				InvocationAttempt::ATTEMPTED,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Upgrades the matching attempt to completed.
	 *
	 * @param string $ability_name Ability name.
	 * @param mixed  $input        Ability input. Never recorded.
	 * @param mixed  $result       Ability result. Never recorded.
	 * @param mixed  $ability      Ability instance.
	 * @return void
	 */
	public function on_completed( string $ability_name, mixed $input = null, mixed $result = null, mixed $ability = null ): void {
		unset( $input, $result );

		if ( ! self::is_own_ability( $ability_name, $ability ) ) {
			return;
		}

		$this->log->complete( $ability_name );
	}

	/**
	 * Decides whether an invocation belongs to this plugin.
	 *
	 * Enabling a WPCB diagnostic must never start logging another plugin's
	 * invocations, so this checks the registered category and falls back to the
	 * name prefix only when the instance is unavailable.
	 *
	 * @param string $ability_name Ability name.
	 * @param mixed  $ability      Ability instance, when the hook supplied one.
	 * @return bool
	 */
	private static function is_own_ability( string $ability_name, mixed $ability ): bool {
		if ( $ability instanceof WP_Ability ) {
			return AbilityCategory::SLUG === $ability->get_category();
		}

		return str_starts_with( $ability_name, AbilityCategory::SLUG . '/' );
	}

	/**
	 * Names the channel the call arrived through.
	 *
	 * Coarse on purpose: enough to tell an agent client from an operator at a
	 * terminal, without recording anything about the request itself.
	 *
	 * @return string
	 */
	private static function channel(): string {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( is_admin() ) {
			return 'admin';
		}

		return 'other';
	}
}
