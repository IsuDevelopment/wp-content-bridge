<?php
/**
 * Uninstall routine.
 *
 * WordPress loads this file when the plugin is deleted from wp-admin, and runs
 * it *instead of* any callback registered with `register_uninstall_hook()`.
 * It never runs on deactivation.
 *
 * The plugin is not loaded at this point and its autoloader is deliberately
 * not required: uninstall must succeed even on an install whose `vendor/`
 * directory is missing or partially removed, which is exactly the state a user
 * is often in when they reach for Delete. Every name below is therefore a
 * literal, kept in sync by hand with `Installer` and the classes noted.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Plugin-owned options. Mirrors the constants in
 * `Installer` and `WordPressContentAccessSettingsRepository::OPTION_NAME`.
 *
 * `wpcb_public_base_url` is a retired dev-only proxy-base shim option the
 * plugin itself never read; it is listed so stray installs shed the row.
 */
foreach ( array(
	'wpcb_schema_version',
	'wpcb_content_type_access',
	'wpcb_writes_enabled',
	'wpcb_publish_enabled',
	'wpcb_media_reads_enabled',
	'wpcb_pattern_reads_enabled',
	'wpcb_trash_enabled',
	'wpcb_integration_user_id',
	'wpcb_public_base_url',
) as $wpcb_option ) {
	delete_option( $wpcb_option );
}

/*
 * Dedicated capabilities, removed from every role that carries them.
 * `Installer::activate()` grants these to administrators, and the settings
 * screen can grant them to an integration user's role.
 */
$wpcb_capabilities = array(
	'wpcb_manage_settings',
	'wpcb_read_content',
	'wpcb_read_media',
	'wpcb_read_patterns',
	'wpcb_edit_content',
	'wpcb_manage_seo',
	'wpcb_publish_content',
	'wpcb_delete_content',
);

$wpcb_roles = wp_roles();

foreach ( array_keys( $wpcb_roles->roles ) as $wpcb_role_name ) {
	$wpcb_role = $wpcb_roles->get_role( $wpcb_role_name );

	if ( null === $wpcb_role ) {
		continue;
	}

	foreach ( $wpcb_capabilities as $wpcb_capability ) {
		$wpcb_role->remove_cap( $wpcb_capability );
	}
}

/*
 * Capabilities granted directly to a user rather than through a role — the
 * shape the documented `wpcb-bridge-reader` integration principal uses, which
 * carries no role at all. Selecting on the serialized capability meta keeps
 * this bounded to affected users instead of paging the whole user table.
 */
global $wpdb;

$wpcb_users = get_users(
	array(
		'fields'       => 'ID',
		'meta_key'     => $wpdb->get_blog_prefix() . 'capabilities', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall cleanup.
		'meta_value'   => 'wpcb_', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-time uninstall cleanup.
		'meta_compare' => 'LIKE',
	)
);

foreach ( $wpcb_users as $wpcb_user_id ) {
	$wpcb_user = get_userdata( (int) $wpcb_user_id );

	if ( ! $wpcb_user instanceof WP_User ) {
		continue;
	}

	foreach ( $wpcb_capabilities as $wpcb_capability ) {
		if ( isset( $wpcb_user->caps[ $wpcb_capability ] ) ) {
			$wpcb_user->remove_cap( $wpcb_capability );
		}
	}
}

/*
 * Transient caches. Prefixes come from
 * `WordPressTransientIdempotencyStore` and `WordPressRenderedSchemaReader`.
 * These expire on their own; they are cleared here so deletion leaves no
 * plugin rows behind in the options table on installs without a persistent
 * object cache.
 */
foreach ( array( 'wpcb_idem_', 'wpcb_seo_ld_' ) as $wpcb_prefix ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup; no cache to prime and no core API for prefix deletion.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . $wpcb_prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $wpcb_prefix ) . '%'
		)
	);
}

/*
 * The `{prefix}wpcb_audit` table is deliberately NOT dropped.
 *
 * It records who changed what through the bridge — field names only, never
 * values — as a rolling window of the most recent 5,000 mutation attempts
 * (`WordPressAuditLog::$max_rows`), pruned oldest-first on every write. Roadmap
 * Slice 7 (`docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md`) requires an accepted
 * ADR for audit retention and erasure before that store becomes a supported
 * read model, and states the ADR must say what happens to rows already
 * collected. Silently destroying that history on delete would pre-empt the
 * decision, so the table is left for an administrator to remove deliberately.
 *
 * This is documented in readme.txt so the behaviour is not a surprise.
 */
