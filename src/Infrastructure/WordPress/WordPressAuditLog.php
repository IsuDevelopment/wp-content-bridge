<?php
/**
 * WordPress audit-log adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;

/**
 * Append-only, capped audit sink backed by a custom table plus an action hook.
 */
final class WordPressAuditLog implements AuditLog {

	/**
	 * Creates the audit log.
	 *
	 * @param int $max_rows Maximum retained rows before pruning oldest.
	 */
	public function __construct(
		private int $max_rows = 5000,
	) {
	}

	/**
	 * Records one mutation attempt and prunes the table.
	 *
	 * @param AuditEvent $event Pre-redacted event.
	 * @return void
	 */
	public function record( AuditEvent $event ): void {
		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var \wpdb $wpdb
		 */

		$table = Installer::audit_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated audit table, no core API.
		$wpdb->insert(
			$table,
			array(
				'created_gmt'       => gmdate( 'Y-m-d H:i:s' ),
				'user_id'           => $event->user_id,
				'ability'           => $event->ability,
				'object_id'         => $event->object_id,
				'object_type'       => $event->object_type,
				'changed_fields'    => (string) wp_json_encode( array_values( $event->changed_fields ) ),
				'expected_version'  => $event->expected_version,
				'resulting_version' => $event->resulting_version,
				'outcome'           => $event->outcome,
				'error_code'        => $event->error_code,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->prune( $table );

		/**
		 * Fires after a mutation attempt is audited.
		 *
		 * @param AuditEvent $event Pre-redacted audit event.
		 */
		do_action( 'wpcb_mutation', $event );
	}

	/**
	 * Removes rows beyond the retention cap.
	 *
	 * @param string $table Audit table name.
	 * @return void
	 */
	private function prune( string $table ): void {
		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- maintenance on a dedicated table; table name is internal.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $total <= $this->max_rows ) {
			return;
		}

		$excess = $total - $this->max_rows;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is internal; LIMIT is a bound integer.
		$wpdb->query( (string) $wpdb->prepare( 'DELETE FROM %i ORDER BY id ASC LIMIT %d', $table, $excess ) );
	}
}
