<?php
/**
 * Runtime verification for post-scoped cache invalidation.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/cache-invalidation-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressPostCacheInvalidator;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI verifier output, not rendered HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier output, not filesystem access.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

$fixture_post_id = wp_insert_post(
	array(
		'post_title'  => 'WPCB cache invalidation fixture',
		'post_type'   => 'post',
		'post_status' => 'draft',
	),
	true
);
if ( is_wp_error( $fixture_post_id ) ) {
	fwrite( STDERR, "Could not create the cache fixture.\n" );
	exit( 1 );
}

$failures            = array();
$purged_post_ids     = array();
$completed           = array();
$failure_events      = array();
$litespeed_listener  = static function ( int $purged_post_id ) use ( &$purged_post_ids ): void {
	$purged_post_ids[] = $purged_post_id;
};
$completion_listener = static function ( int $completed_post_id, array $providers ) use ( &$completed ): void {
	$completed[] = array( $completed_post_id, $providers );
};
$failure_listener    = static function ( int $failed_post_id, Throwable $error ) use ( &$failure_events ): void {
	$failure_events[] = array( $failed_post_id, $error::class );
};

add_action( 'litespeed_purge_post', $litespeed_listener, PHP_INT_MAX );
add_action( 'wp_content_bridge_post_cache_invalidated', $completion_listener, 10, 2 );
add_action( 'wp_content_bridge_cache_invalidation_failed', $failure_listener, 10, 2 );

$invalidator = new WordPressPostCacheInvalidator();
$success     = new AuditEvent(
	1,
	'wp-content-bridge/update-seo',
	(int) $fixture_post_id,
	'post',
	array( 'seo_title' ),
	null,
	null,
	'success',
	null
);
$invalidator->on_mutation( $success );

if ( array( (int) $fixture_post_id ) !== $purged_post_ids ) {
	$failures[] = 'successful mutation did not dispatch the post-scoped LiteSpeed purge hook';
}
if (
	1 !== count( $completed )
	|| (int) $fixture_post_id !== $completed[0][0]
	// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Stable provider ID.
	|| ! in_array( 'wordpress', $completed[0][1], true )
	|| ! in_array( 'litespeed-cache', $completed[0][1], true )
) {
	$failures[] = 'successful mutation did not report the expected cache providers';
}

$denied = new AuditEvent(
	1,
	'wp-content-bridge/update-content',
	(int) $fixture_post_id,
	'post',
	array(),
	null,
	null,
	'denied',
	'wpcb_forbidden'
);
$invalidator->on_mutation( $denied );
if ( 1 !== count( $purged_post_ids ) || 1 !== count( $completed ) ) {
	$failures[] = 'failed mutation unexpectedly invalidated cache';
}

$throwing_listener = static function (): void {
	throw new RuntimeException( 'Synthetic cache adapter failure.' );
};
add_action( 'litespeed_purge_post', $throwing_listener, 1 );
$invalidator->on_mutation( $success );
remove_action( 'litespeed_purge_post', $throwing_listener, 1 );

if ( array( array( (int) $fixture_post_id, RuntimeException::class ) ) !== $failure_events ) {
	$failures[] = 'cache adapter failure was not contained and reported';
}

remove_action( 'litespeed_purge_post', $litespeed_listener, PHP_INT_MAX );
remove_action( 'wp_content_bridge_post_cache_invalidated', $completion_listener, 10 );
remove_action( 'wp_content_bridge_cache_invalidation_failed', $failure_listener, 10 );
wp_delete_post( (int) $fixture_post_id, true );

if ( array() !== $failures ) {
	echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}

echo "PASS: post-scoped cache invalidation\n";
exit( 0 );
