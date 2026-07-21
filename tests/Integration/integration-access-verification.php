<?php
/**
 * Runtime verification for managed integration-user capabilities.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/integration-access-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Application\Access\IntegrationAccessManager;
use IsuDev\WPContentBridge\Domain\Access\IntegrationCapability;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressIntegrationAccessRepository;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI verifier output, not rendered HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier output, not filesystem access.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}

$original_managed_user = get_option( Installer::INTEGRATION_USER_OPTION, 0 );
$created_user_ids      = array();
$failures              = array();

try {
	update_option( Installer::INTEGRATION_USER_OPTION, 0, false );

	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	foreach ( array( 'first', 'second' ) as $position ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => "wpcb-access-{$position}-{$suffix}",
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => "wpcb-access-{$position}-{$suffix}@example.invalid",
				'role'       => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'Could not create the integration-access fixture user.' );
		}

		$created_user_ids[] = (int) $user_id;
	}

	$first  = get_user_by( 'id', $created_user_ids[0] );
	$second = get_user_by( 'id', $created_user_ids[1] );
	if ( ! $first instanceof WP_User || ! $second instanceof WP_User ) {
		throw new RuntimeException( 'Could not resolve the integration-access fixture users.' );
	}

	$first->add_cap( 'read_private_pages' );

	$manager = new IntegrationAccessManager( new WordPressIntegrationAccessRepository() );
	$manager->update(
		$first->ID,
		array(
			IntegrationCapability::READ_CONTENT->value,
			IntegrationCapability::READ_MEDIA->value,
			IntegrationCapability::READ_PATTERNS->value,
			IntegrationCapability::EDIT_CONTENT->value,
			IntegrationCapability::DELETE_CONTENT->value,
		)
	);

	if ( ! user_can( $first, IntegrationCapability::READ_CONTENT->value )
		|| ! user_can( $first, IntegrationCapability::READ_MEDIA->value )
		|| ! user_can( $first, IntegrationCapability::READ_PATTERNS->value )
		|| ! user_can( $first, IntegrationCapability::EDIT_CONTENT->value )
		|| ! user_can( $first, IntegrationCapability::DELETE_CONTENT->value )
	) {
		$failures[] = 'first managed user did not receive the selected capabilities';
	}

	$manager->update( $second->ID, array( IntegrationCapability::READ_CONTENT->value ) );
	$first = get_user_by( 'id', $first->ID );
	if ( ! $first instanceof WP_User ) {
		throw new RuntimeException( 'First fixture user disappeared during verification.' );
	}

	if ( user_can( $first, IntegrationCapability::READ_CONTENT->value )
		|| user_can( $first, IntegrationCapability::READ_MEDIA->value )
		|| user_can( $first, IntegrationCapability::READ_PATTERNS->value )
		|| user_can( $first, IntegrationCapability::EDIT_CONTENT->value )
		|| user_can( $first, IntegrationCapability::DELETE_CONTENT->value )
	) {
		$failures[] = 'previously managed user retained WPCB capabilities';
	}
	if ( ! user_can( $first, 'read_private_pages' ) ) {
		$failures[] = 'unrelated user capability was removed';
	}
	if ( (int) get_option( Installer::INTEGRATION_USER_OPTION, 0 ) !== $second->ID ) {
		$failures[] = 'managed integration-user option was not updated';
	}
} finally {
	update_option( Installer::INTEGRATION_USER_OPTION, $original_managed_user, false );
	foreach ( $created_user_ids as $user_id ) {
		wp_delete_user( $user_id );
	}
}

if ( array() !== $failures ) {
	echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}

echo "PASS: managed integration-user capabilities\n";
exit( 0 );
