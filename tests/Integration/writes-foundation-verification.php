<?php
/**
 * Runtime verification for the write-phase foundation.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/writes-foundation-verification.php";'
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output, not a filesystem operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- one-off schema check in a CLI verifier, not a request-path query.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off schema check; caching would be pointless here.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- exit code + STDOUT text for a CLI verifier, not rendered HTML.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

Installer::activate();

$failures = array();

$administrator = get_role( 'administrator' );
foreach ( array( 'wpcb_edit_content', 'wpcb_manage_seo', 'wpcb_publish_content' ) as $capability ) {
	if ( null === $administrator || ! $administrator->has_cap( $capability ) ) {
		$failures[] = "administrator missing {$capability}";
	}
}

if ( false !== (bool) get_option( Installer::WRITES_ENABLED_OPTION ) ) {
	$failures[] = 'wpcb_writes_enabled is not false by default';
}
if ( false !== (bool) get_option( Installer::PUBLISH_ENABLED_OPTION ) ) {
	$failures[] = 'wpcb_publish_enabled is not false by default';
}

global $wpdb;
$table  = Installer::audit_table_name();
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
if ( $table !== $exists ) {
	$failures[] = "audit table {$table} was not created";
}

if ( array() === $failures ) {
	echo "PASS: writes foundation (caps, flags default-off, audit table)\n";
	exit( 0 );
}

echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
exit( 1 );
