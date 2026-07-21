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
if ( 0 !== (int) get_option( Installer::INTEGRATION_USER_OPTION, 0 ) ) {
	$failures[] = 'wpcb_integration_user_id is not zero by default';
}

global $wpdb;
$table  = Installer::audit_table_name();
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
if ( $table !== $exists ) {
	$failures[] = "audit table {$table} was not created";
}

$audit_log = new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog();
$audit_log->record(
	new \IsuDev\WPContentBridge\Application\Mutation\AuditEvent(
		1,
		'wp-content-bridge/create-draft',
		null,
		'post',
		array( 'title', 'content' ),
		null,
		'abcdef0123456789:2026-07-20 12:30:00',
		'success',
		null
	)
);

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; one-off read-back in a CLI verifier.
$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );
if ( null === $row ) {
	$failures[] = 'audit row was not written';
} else {
	if ( 'wp-content-bridge/create-draft' !== $row['ability'] ) {
		$failures[] = 'audit ability not persisted';
	}
	if ( array( 'title', 'content' ) !== json_decode( (string) $row['changed_fields'], true ) ) {
		$failures[] = 'audit changed_fields not persisted as name list';
	}
	// Redaction guard: no content/secret columns exist at all.
	if ( array_key_exists( 'content', $row ) || array_key_exists( 'secret', $row ) ) {
		$failures[] = 'audit table exposes a content/secret column';
	}
}

if ( array() === $failures ) {
	echo "PASS: writes foundation (caps, flags default-off, audit table)\n";
	exit( 0 );
}

echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
exit( 1 );
