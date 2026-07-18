<?php
/**
 * Bridge reader least-privilege user provisioning fixture.
 *
 * Provisions (and tears down) a WordPress user holding ONLY the `read` and
 * `wpcb_read_content` capabilities on a local WordPress install. This is the
 * identity the ChatGPT OAuth grant and the MCP smoke test map their
 * credential/grant to. No role, no admin, no edit capabilities.
 *
 * Modes are selected with the WPCB_BRIDGE_MODE environment variable
 * (setup|teardown). Never run against production. Local development only.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Integration;

const WPCB_BRIDGE_LOGIN = 'wpcb-bridge-reader';
const WPCB_BRIDGE_EMAIL = 'wpcb-bridge-reader@example.invalid';

/**
 * Locks a user down to exactly the `read` and `wpcb_read_content` capabilities.
 *
 * Clears any role (and the caps that come with it) before granting only the
 * two allowed capabilities, so repeated setup runs stay idempotent even if
 * the account previously drifted.
 *
 * @param \WP_User $user Target user.
 * @return void
 */
function wpcb_bridge_lock_capabilities( \WP_User $user ): void {
	$user->set_role( '' );
	$user->add_cap( 'read', true );
	$user->add_cap( 'wpcb_read_content', true );
}

/**
 * Creates (or reuses) the bridge-reader user and pins it to least privilege.
 *
 * @return void
 */
function wpcb_bridge_setup(): void {
	if ( ! function_exists( 'get_user_by' ) || ! function_exists( 'wp_insert_user' ) || ! class_exists( '\\WP_User' ) ) {
		echo wp_json_encode(
			array(
				'status'  => 'ERROR',
				'message' => 'WordPress user functions unavailable.',
			)
		), "\n";

		return;
	}

	$user = get_user_by( 'login', WPCB_BRIDGE_LOGIN );

	if ( ! ( $user instanceof \WP_User ) ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => WPCB_BRIDGE_LOGIN,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => WPCB_BRIDGE_EMAIL,
				'role'       => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			echo wp_json_encode(
				array(
					'status'  => 'ERROR',
					'message' => 'Failed to create bridge-reader user.',
				)
			), "\n";

			return;
		}

		$user = get_user_by( 'id', (int) $user_id );
	}

	if ( ! ( $user instanceof \WP_User ) ) {
		echo wp_json_encode(
			array(
				'status'  => 'ERROR',
				'message' => 'Bridge-reader user lookup failed after creation.',
			)
		), "\n";

		return;
	}

	wpcb_bridge_lock_capabilities( $user );

	echo wp_json_encode(
		array(
			'status'     => 'OK',
			'user_id'    => (int) $user->ID,
			'user_login' => $user->user_login,
		)
	), "\n";
}

/**
 * Deletes the bridge-reader user if present.
 *
 * @return void
 */
function wpcb_bridge_teardown(): void {
	if ( ! function_exists( 'get_user_by' ) || ! function_exists( 'wp_delete_user' ) || ! class_exists( '\\WP_User' ) ) {
		echo wp_json_encode(
			array(
				'status'  => 'ERROR',
				'message' => 'WordPress user functions unavailable.',
			)
		), "\n";

		return;
	}

	$user = get_user_by( 'login', WPCB_BRIDGE_LOGIN );

	if ( ! ( $user instanceof \WP_User ) ) {
		echo wp_json_encode( array( 'status' => 'NOOP' ) ), "\n";

		return;
	}

	$user_id = (int) $user->ID;

	wp_delete_user( $user_id );

	echo wp_json_encode(
		array(
			'status'  => 'DELETED',
			'user_id' => $user_id,
		)
	), "\n";
}

$wpcb_bridge_mode = getenv( 'WPCB_BRIDGE_MODE' );
if ( 'setup' === $wpcb_bridge_mode ) {
	wpcb_bridge_setup();
} elseif ( 'teardown' === $wpcb_bridge_mode ) {
	wpcb_bridge_teardown();
} else {
	echo wp_json_encode(
		array(
			'status'  => 'ERROR',
			'message' => 'Set WPCB_BRIDGE_MODE to setup or teardown.',
		)
	), "\n";
}
