<?php
/**
 * Repeatable runtime verification for invocation telemetry (ADR 0029).
 *
 * Proves the four properties the ADR trades on, against a live WordPress:
 *
 * 1. **Absent when off.** With the flag off, no listener is attached to
 *    `wp_ability_invoked` and executing an ability stores nothing.
 * 2. **A denial is finally visible.** A principal refused at
 *    `permission_callback` produces exactly one telemetry entry — the gap this
 *    feature exists to close, since that refusal leaves no trace anywhere else.
 * 3. **Telemetry is not the audit.** Read invocations add no `wpcb_audit` rows,
 *    so the mutation history is untouched by read traffic.
 * 4. **Bounded, and shapes only.** The ring buffer never exceeds its cap, and a
 *    stored entry has exactly the five declared fields — no input, no message,
 *    no payload.
 *
 * Execute with: wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/invocation-telemetry-verification.php";'
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail this CLI verifier fast.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics, not rendered HTML.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the audit table has no API and this verifier only counts its rows.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export renders booleans unambiguously in a CLI failure message.

use IsuDev\WPContentBridge\Adapter\Abilities\AbilityInvocationTelemetry;
use IsuDev\WPContentBridge\Application\Telemetry\InvocationAttempt;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressInvocationLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies the telemetry diagnostic mode end to end.
 */
final class WPCB_Invocation_Telemetry_Verification {

	/**
	 * Runs the verifier and prints machine-readable evidence.
	 *
	 * @return void
	 */
	public function run(): void {
		$flag_before   = get_option( Installer::INVOCATION_TELEMETRY_ENABLED_OPTION, null );
		$buffer_before = get_option( Installer::INVOCATION_TELEMETRY_OPTION, null );
		$denied_user   = 0;

		try {
			$denied_user = $this->create_denied_user();
			$evidence    = array(
				'absent_when_off' => $this->verify_absent_when_off(),
				'denial_visible'  => $this->verify_denial_is_recorded( $denied_user ),
				'success_marked'  => $this->verify_success_is_marked_completed(),
				'audit_untouched' => $this->verify_reads_add_no_audit_rows(),
				'bounded'         => $this->verify_ring_buffer_is_bounded(),
				'shapes_only'     => $this->verify_entries_carry_shapes_only(),
			);
		} finally {
			$this->restore_option( Installer::INVOCATION_TELEMETRY_ENABLED_OPTION, $flag_before );
			$this->restore_option( Installer::INVOCATION_TELEMETRY_OPTION, $buffer_before );
			if ( 0 !== $denied_user ) {
				wp_set_current_user( $this->administrator_id() );
				wp_delete_user( $denied_user );
			}
		}

		echo 'PASS: invocation-telemetry ', wp_json_encode( $evidence ), "\n";
	}

	/**
	 * With the flag off nothing observes ability execution.
	 *
	 * The composition root has already run in this process with the stored flag
	 * value, so a listener attached to `wp_ability_invoked` here would be real
	 * evidence that a disabled feature is still running.
	 *
	 * @return array<string, mixed>
	 */
	private function verify_absent_when_off(): array {
		$stored_flag = (bool) get_option( Installer::INVOCATION_TELEMETRY_ENABLED_OPTION, false );
		$attached    = false !== has_action( 'wp_ability_invoked' );

		$this->assert_true(
			$stored_flag === $attached,
			'Telemetry listener presence does not match the stored flag: flag=' . var_export( $stored_flag, true )
				. ' attached=' . var_export( $attached, true )
		);

		if ( ! $stored_flag ) {
			delete_option( Installer::INVOCATION_TELEMETRY_OPTION );
			wp_set_current_user( $this->administrator_id() );
			$this->execute( 'wp-content-bridge/get-diagnostics', null );

			$this->assert_true(
				array() === $this->entries(),
				'An ability invocation wrote telemetry while the feature was off.'
			);
		}

		return array(
			'stored_flag'      => $stored_flag,
			'listener_present' => $attached,
		);
	}

	/**
	 * A permission-callback denial produces exactly one entry.
	 *
	 * @param int $user_id Capability-less user.
	 * @return array<string, mixed>
	 */
	private function verify_denial_is_recorded( int $user_id ): array {
		$log = $this->arrange_listener();

		wp_set_current_user( $user_id );
		$result = $this->execute( 'wp-content-bridge/get-diagnostics', null );
		wp_set_current_user( $this->administrator_id() );
		$log->flush();

		$this->assert_true( is_wp_error( $result ), 'The capability-less principal was not refused.' );

		$entries = $this->entries();
		$this->assert_true( 1 === count( $entries ), 'A denial produced ' . count( $entries ) . ' entries, expected exactly 1.' );
		$this->assert_true(
			InvocationAttempt::ATTEMPTED === ( $entries[0]['outcome'] ?? '' ),
			'A denial was not recorded as attempted: ' . (string) ( $entries[0]['outcome'] ?? 'missing' )
		);
		$this->assert_true(
			( $entries[0]['user_id'] ?? -1 ) === $user_id,
			'The denial was attributed to the wrong principal.'
		);

		$this->remove_listener();

		return array(
			'entries' => count( $entries ),
			'outcome' => $entries[0]['outcome'],
			'error'   => is_wp_error( $result ) ? $result->get_error_code() : null,
		);
	}

	/**
	 * A successful invocation is upgraded in place, not recorded twice.
	 *
	 * @return array<string, mixed>
	 */
	private function verify_success_is_marked_completed(): array {
		$log = $this->arrange_listener();

		wp_set_current_user( $this->administrator_id() );
		$result = $this->execute( 'wp-content-bridge/get-diagnostics', null );
		$log->flush();

		$this->assert_true( ! is_wp_error( $result ), 'The administrator read failed, so this proves nothing.' );

		$entries = $this->entries();
		$this->assert_true(
			1 === count( $entries ),
			'A successful call produced ' . count( $entries ) . ' entries; the attempt must be upgraded, not duplicated.'
		);
		$this->assert_true(
			InvocationAttempt::COMPLETED === ( $entries[0]['outcome'] ?? '' ),
			'A successful call was not marked completed: ' . (string) ( $entries[0]['outcome'] ?? 'missing' )
		);

		$this->remove_listener();

		return array(
			'entries' => count( $entries ),
			'outcome' => $entries[0]['outcome'],
			'channel' => $entries[0]['channel'],
		);
	}

	/**
	 * Read invocations never touch the mutation audit.
	 *
	 * @return array<string, mixed>
	 */
	private function verify_reads_add_no_audit_rows(): array {
		global $wpdb;

		$log   = $this->arrange_listener();
		$table = Installer::audit_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the installer, not from input.
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		wp_set_current_user( $this->administrator_id() );
		for ( $index = 0; $index < 3; $index++ ) {
			$this->execute( 'wp-content-bridge/get-diagnostics', null );
		}
		$log->flush();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the installer, not from input.
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$this->assert_true(
			$before === $after,
			'Read invocations added ' . ( $after - $before ) . ' rows to the mutation audit; the sinks must stay separate.'
		);
		$this->assert_true( count( $this->entries() ) >= 3, 'The telemetry sink did not record the reads it was supposed to.' );

		$this->remove_listener();

		return array(
			'audit_rows_before' => $before,
			'audit_rows_after'  => $after,
			'telemetry_entries' => count( $this->entries() ),
		);
	}

	/**
	 * The ring buffer discards the oldest entries instead of growing.
	 *
	 * Written straight through the sink rather than by invoking an ability
	 * thousands of times: the property under test is the bound, not the hook.
	 *
	 * @return array<string, mixed>
	 */
	private function verify_ring_buffer_is_bounded(): array {
		delete_option( Installer::INVOCATION_TELEMETRY_OPTION );
		$log   = new WordPressInvocationLog();
		$total = WordPressInvocationLog::MAX_ENTRIES + 25;

		for ( $index = 0; $index < $total; $index++ ) {
			$log->record(
				new InvocationAttempt(
					'wp-content-bridge/get-diagnostics',
					1,
					'cli',
					InvocationAttempt::ATTEMPTED,
					gmdate( 'Y-m-d H:i:s' )
				)
			);
		}
		$log->flush();

		$entries = $this->entries();
		$this->assert_true(
			WordPressInvocationLog::MAX_ENTRIES === count( $entries ),
			'The ring buffer holds ' . count( $entries ) . ' entries, expected ' . WordPressInvocationLog::MAX_ENTRIES . '.'
		);

		return array(
			'written' => $total,
			'kept'    => count( $entries ),
		);
	}

	/**
	 * A stored entry has exactly the five declared fields.
	 *
	 * @return array<string, mixed>
	 */
	private function verify_entries_carry_shapes_only(): array {
		$entries = $this->entries();
		$this->assert_true( array() !== $entries, 'There are no entries to inspect.' );

		foreach ( $entries as $entry ) {
			$this->assert_true(
				array( 'ability', 'user_id', 'channel', 'outcome', 'occurred_at' ) === array_keys( $entry ),
				'A telemetry entry carries unexpected fields: ' . implode( ', ', array_keys( $entry ) )
			);
		}

		return array(
			'inspected' => count( $entries ),
			'fields'    => array_keys( $entries[0] ),
		);
	}

	/**
	 * Attaches a listener over a fresh sink and clears the stored buffer.
	 *
	 * @return WordPressInvocationLog
	 */
	private function arrange_listener(): WordPressInvocationLog {
		$this->remove_listener();
		delete_option( Installer::INVOCATION_TELEMETRY_OPTION );

		$log = new WordPressInvocationLog();
		( new AbilityInvocationTelemetry( $log ) )->register_hooks();

		return $log;
	}

	/**
	 * Detaches every telemetry listener.
	 *
	 * @return void
	 */
	private function remove_listener(): void {
		remove_all_actions( 'wp_ability_invoked' );
		remove_all_actions( 'wp_after_execute_ability' );
	}

	/**
	 * Executes one ability.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input Ability input.
	 * @return mixed
	 */
	private function execute( string $name, mixed $input ): mixed {
		$ability = wp_get_ability( $name );
		if ( ! $ability instanceof WP_Ability ) {
			throw new RuntimeException( $name . ' is not registered.' );
		}

		return $ability->execute( $input );
	}

	/**
	 * Reads the stored entries.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function entries(): array {
		$stored = get_option( Installer::INVOCATION_TELEMETRY_OPTION, array() );

		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * Restores an option to its pre-run value.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value observed before the run, or null when absent.
	 * @return void
	 */
	private function restore_option( string $option, mixed $value ): void {
		if ( null === $value ) {
			delete_option( $option );

			return;
		}

		update_option( $option, $value, false );
	}

	/**
	 * Creates a user holding no WPCB capability.
	 *
	 * @return int
	 */
	private function create_denied_user(): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'wpcb-telemetry-' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'wpcb-telemetry-' . wp_generate_password( 8, false ) . '@example.invalid',
				'role'       => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'The capability-less fixture user could not be created.' );
		}

		return (int) $user_id;
	}

	/**
	 * Resolves an administrator to run as.
	 *
	 * @return int
	 */
	private function administrator_id(): int {
		$ids = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( array() === $ids || ! is_numeric( $ids[0] ) ) {
			throw new RuntimeException( 'An administrator is required.' );
		}

		return (int) $ids[0];
	}

	/**
	 * Throws on a failed verification assertion.
	 *
	 * @param bool   $condition Assertion condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
}

( new WPCB_Invocation_Telemetry_Verification() )->run();
