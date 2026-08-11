<?php
/**
 * Minimal Abilities API and options stand-ins for the MCP projection tests.
 *
 * The unit suite boots Composer only, so the handful of WordPress functions the
 * projection touches are declared here. State lives in `$GLOBALS` so a test can
 * arrange a registry, a filter, and an option value per case. The `WP_Ability`
 * stand-in lives in `wp-ability-class-stub.php`.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-ability-class-stub.php';

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * Returns the arranged ability registry.
	 *
	 * @return array<int, WP_Ability>
	 */
	function wp_get_abilities(): array {
		$abilities = $GLOBALS['wpcb_test_abilities'] ?? array();

		return is_array( $abilities ) ? $abilities : array();
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Applies the arranged filter callback, if any.
	 *
	 * @param string $hook_name Filter name.
	 * @param mixed  $value     Filtered value.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, mixed $value ): mixed {
		$filters = $GLOBALS['wpcb_test_filters'] ?? array();

		if ( is_array( $filters ) && isset( $filters[ $hook_name ] ) && is_callable( $filters[ $hook_name ] ) ) {
			return $filters[ $hook_name ]( $value );
		}

		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Reads an arranged option value.
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Value returned when the row is absent.
	 * @return mixed
	 */
	function get_option( string $option, mixed $default_value = false ): mixed {
		$options = $GLOBALS['wpcb_test_options'] ?? array();

		if ( is_array( $options ) && array_key_exists( $option, $options ) ) {
			return $options[ $option ];
		}

		return $default_value;
	}
}

if ( ! function_exists( 'has_action' ) ) {
	/**
	 * Reports no hooks, since the unit suite has no hook system.
	 *
	 * @param string $hook_name Action name.
	 * @return bool
	 */
	function has_action( string $hook_name ): bool {
		unset( $hook_name );

		return false;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Returns a predictable REST URL.
	 *
	 * @param string $path REST path.
	 * @return string
	 */
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
