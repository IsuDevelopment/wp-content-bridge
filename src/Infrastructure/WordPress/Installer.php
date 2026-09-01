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

	private const SCHEMA_VERSION = 11;
	private const VERSION_OPTION = 'wpcb_schema_version';

	public const WRITES_ENABLED_OPTION        = 'wpcb_writes_enabled';
	public const PUBLISH_ENABLED_OPTION       = 'wpcb_publish_enabled';
	public const MEDIA_READS_ENABLED_OPTION   = 'wpcb_media_reads_enabled';
	public const PATTERN_READS_ENABLED_OPTION = 'wpcb_pattern_reads_enabled';
	public const TRASH_ENABLED_OPTION         = 'wpcb_trash_enabled';
	public const INTEGRATION_USER_OPTION      = 'wpcb_integration_user_id';

	/**
	 * Non-autoloaded MCP projection flag (ADR 0025). Gates whether the plugin
	 * hands its registered abilities to an installed MCP Adapter.
	 *
	 * Unlike every other flag here, an **absent** row means enabled: a site
	 * that installed the Adapter did so in order to reach these abilities, and
	 * the plugin being unusable until someone finds a checkbox is the defect
	 * this ADR removes. `activate()` seeds the row so the settings screen has a
	 * value to render, and every read passes `true` as the default so an
	 * install that never re-activated still projects.
	 *
	 * Projection is not authorization: the flag only controls MCP discovery,
	 * never what a principal may execute.
	 */
	public const MCP_SERVER_ENABLED_OPTION = 'wpcb_mcp_server_enabled';

	/**
	 * Non-autoloaded llms.txt publication flag. Gates the three llms.txt
	 * write Abilities and, in a later slice, the virtual `/llms.txt` route;
	 * see ADR 0023. `get-llms-txt` deliberately does not depend on this flag.
	 */
	public const LLMS_ENABLED_OPTION = 'wpcb_llms_enabled';

	/**
	 * Non-autoloaded one-shot flag consumed by `LlmsTxtEndpoint` on the next
	 * `init`. Set here on activation/upgrade and by `LlmsTxtEndpoint` whenever
	 * `LLMS_ENABLED_OPTION` changes value, because a rewrite-rule flush is
	 * only correct once the current request's rule set already reflects the
	 * new flag value — never mid-request, when it might still reflect the
	 * old one. See `LlmsTxtEndpoint::maybe_flush_rewrite_rules()`.
	 */
	public const LLMS_FLUSH_NEEDED_OPTION = 'wpcb_llms_flush_needed';

	/**
	 * Non-autoloaded batching cursor for the debounced llms.txt regeneration
	 * cron job (`LlmsRegenerationRunner::CRON_HOOK`). Holds
	 * `['offset', 'started']`; cleared by `LlmsRegenerationRunner` when a run
	 * completes and by `LlmsRegenerationScheduler::handle_flag_change()` when
	 * publication is disabled mid-run. See ADR 0023 task 6.
	 */
	public const LLMS_REGEN_CURSOR_OPTION = 'wpcb_llms_regen_cursor';

	/**
	 * Non-autoloaded accumulator for entries a batched regeneration run has
	 * gathered so far. Never read by the public `/llms.txt` endpoint — only
	 * `WordPressLlmsArtifactStore::ARTIFACT_OPTION` is public — and replaced
	 * into it exactly once, atomically, when the run completes; see
	 * `LlmsRegenerationRunner`.
	 */
	public const LLMS_REGEN_STAGING_OPTION = 'wpcb_llms_regen_staging';

	/**
	 * Non-autoloaded marker recording that a content or SEO transition landed
	 * *while a batched run was already in progress*, and that the run therefore
	 * cannot be assumed to reflect it.
	 *
	 * A run self-reschedules onto its own cron hook between ticks, so during a
	 * multi-tick run `wp_next_scheduled()` is always truthy — which would make
	 * `LlmsRegenerationScheduler::maybe_enqueue()` treat every trigger as
	 * "already covered" and drop it. This flag is what keeps those triggers
	 * from being swallowed; `LlmsRegenerationRunner` consumes it when a run
	 * finalizes and enqueues a fresh one. See
	 * `LlmsRegenerationScheduler::maybe_enqueue()`.
	 */
	public const LLMS_REGEN_DIRTY_OPTION = 'wpcb_llms_regen_dirty';

	/**
	 * Retired dev-only proxy-base shim option; the plugin never read it.
	 * Removed here so upgrading and uninstalled sites shed the row.
	 */
	public const LEGACY_PUBLIC_BASE_URL_OPTION = 'wpcb_public_base_url';

	/**
	 * Non-autoloaded invocation-telemetry flag, off by default (ADR 0029).
	 *
	 * While off, the lifecycle listener is not registered at all, so nothing
	 * observes ability execution. Turning it on is a diagnostic mode: it pays a
	 * database write per request on the read path, which is why it is not the
	 * default for a plugin whose reads are the product.
	 */
	public const INVOCATION_TELEMETRY_ENABLED_OPTION = 'wpcb_invocation_telemetry_enabled';

	/**
	 * Non-autoloaded ring buffer of recent invocation attempts.
	 *
	 * Bounded by construction to `WordPressInvocationLog::MAX_ENTRIES`, so it
	 * needs no pruning and can never grow into or evict the mutation audit. It
	 * carries principal IDs, so it is never exposed through an Ability or REST
	 * and is removed on uninstall.
	 */
	public const INVOCATION_TELEMETRY_OPTION = 'wpcb_invocation_telemetry';

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
		add_option( self::LLMS_ENABLED_OPTION, false, '', false );
		add_option( self::MCP_SERVER_ENABLED_OPTION, true, '', false );
		add_option( self::INVOCATION_TELEMETRY_ENABLED_OPTION, false, '', false );
		add_option( self::INTEGRATION_USER_OPTION, 0, '', false );

		/*
		 * `WordPressStatusTransitionRepository::OPTION_NAME` is deliberately
		 * absent from the list above, and must stay absent. ADR 0024 requires
		 * the settings screen to tell "never configured" from "configured to
		 * nothing", and that repository distinguishes them by whether the
		 * option row exists at all. Seeding it here with `array()`, the way
		 * every other array-shaped option above is seeded, would make every
		 * fresh install look like an administrator who saved an empty matrix.
		 */
		delete_option( self::LEGACY_PUBLIC_BASE_URL_OPTION );
		self::create_audit_table();
		// Activation/upgrade always runs before this request's `init`, so the
		// rewrite rule this flag is about has not been (re)registered yet in
		// this request either; the flush must wait for the next one.
		update_option( self::LLMS_FLUSH_NEEDED_OPTION, true, false );
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
			'wpcb_manage_llms',
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
