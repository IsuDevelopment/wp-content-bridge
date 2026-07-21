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

	private const SCHEMA_VERSION = 8;
	private const VERSION_OPTION = 'wpcb_schema_version';

	public const WRITES_ENABLED_OPTION        = 'wpcb_writes_enabled';
	public const PUBLISH_ENABLED_OPTION       = 'wpcb_publish_enabled';
	public const MEDIA_READS_ENABLED_OPTION   = 'wpcb_media_reads_enabled';
	public const PATTERN_READS_ENABLED_OPTION = 'wpcb_pattern_reads_enabled';
	public const TRASH_ENABLED_OPTION         = 'wpcb_trash_enabled';
	public const INTEGRATION_USER_OPTION      = 'wpcb_integration_user_id';

	private const AUDIT_TABLE = 'wpcb_audit';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::grant_administrator_capability();
		add_option( WordPressContentAccessSettingsRepository::OPTION_NAME, array(), '', false );
		add_option( self::WRITES_ENABLED_OPTION, false, '', false );
		add_option( self::PUBLISH_ENABLED_OPTION, false, '', false );
		add_option( self::MEDIA_READS_ENABLED_OPTION, false, '', false );
		add_option( self::PATTERN_READS_ENABLED_OPTION, false, '', false );
		add_option( self::TRASH_ENABLED_OPTION, false, '', false );
		add_option( self::INTEGRATION_USER_OPTION, 0, '', false );
		self::create_audit_table();
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Upgrades already-active development installations.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored_version  = get_option( self::VERSION_OPTION, 0 );
		$current_version = is_numeric( $stored_version ) ? (int) $stored_version : 0;

		if ( $current_version >= self::SCHEMA_VERSION ) {
			return;
		}

		self::activate();
	}

	/**
	 * Returns the fully-qualified audit table name.
	 *
	 * @return string
	 */
	public static function audit_table_name(): string {
		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var \wpdb $wpdb
		 */

		return $wpdb->prefix . self::AUDIT_TABLE;
	}

	/**
	 * Grants management and write capabilities to administrators.
	 *
	 * @return void
	 */
	private static function grant_administrator_capability(): void {
		$administrator = get_role( 'administrator' );

		if ( null === $administrator ) {
			return;
		}

		foreach ( array(
			'wpcb_manage_settings',
			'wpcb_read_content',
			'wpcb_read_media',
			'wpcb_read_patterns',
			'wpcb_edit_content',
			'wpcb_manage_seo',
			'wpcb_publish_content',
			'wpcb_delete_content',
		) as $capability ) {
			$administrator->add_cap( $capability );
		}
	}

	/**
	 * Creates the append-only mutation audit table.
	 *
	 * @return void
	 */
	private static function create_audit_table(): void {
		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var \wpdb $wpdb
		 */

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::audit_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_gmt DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			ability VARCHAR(191) NOT NULL,
			object_id BIGINT UNSIGNED NULL,
			object_type VARCHAR(64) NULL,
			changed_fields TEXT NOT NULL,
			expected_version VARCHAR(191) NULL,
			resulting_version VARCHAR(191) NULL,
			outcome VARCHAR(32) NOT NULL,
			error_code VARCHAR(64) NULL,
			PRIMARY KEY  (id),
			KEY created_gmt (created_gmt)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
