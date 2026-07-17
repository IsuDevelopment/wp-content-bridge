<?php
/**
 * Plugin installation and schema upgrades.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

/**
 * Applies idempotent installation changes.
 */
final class Installer {

	private const SCHEMA_VERSION = 3;
	private const VERSION_OPTION = 'wpcb_schema_version';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::grant_administrator_capability();
		add_option( WordPressContentAccessSettingsRepository::OPTION_NAME, array(), '', false );
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Upgrades already-active development installations.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored_version  = get_option( self::VERSION_OPTION, 0 );
		$current_version = is_int( $stored_version ) ? $stored_version : 0;

		if ( $current_version >= self::SCHEMA_VERSION ) {
			return;
		}

		self::activate();
	}

	/**
	 * Grants the settings capability to administrators.
	 *
	 * @return void
	 */
	private static function grant_administrator_capability(): void {
		$administrator = get_role( 'administrator' );

		if ( null !== $administrator ) {
			$administrator->add_cap( 'wpcb_manage_settings' );
			$administrator->add_cap( 'wpcb_read_content' );
		}
	}
}
