<?php
/**
 * Ring-buffered invocation telemetry sink.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Telemetry\InvocationAttempt;
use IsuDev\WPContentBridge\Application\Telemetry\InvocationLog;

/**
 * Buffers attempts in memory and flushes them once per request.
 *
 * Two properties matter and both are structural rather than operational
 * (ADR 0029):
 *
 * - **Bounded by construction.** The stored option keeps the most recent
 *   `MAX_ENTRIES` entries and drops the rest. There is no pruning job, and
 *   nothing here can grow into the mutation audit's storage or evict it.
 * - **One write per request, not per invocation.** Entries accumulate in memory
 *   and are written on `shutdown`. This is what lets an entry be created before
 *   the permission check and upgraded to `completed` afterwards without paying
 *   two database writes for every successful call.
 *
 * A fatal error mid-request loses that request's buffer. That is acceptable for
 * a diagnostic and unacceptable for an audit, which is one more reason the two
 * sinks are separate classes with separate storage.
 */
final class WordPressInvocationLog implements InvocationLog {

	/**
	 * Most recent entries retained.
	 *
	 * A recent-activity window, not a history: under heavy traffic it covers
	 * seconds.
	 */
	public const MAX_ENTRIES = 200;

	/**
	 * Attempts buffered for this request.
	 *
	 * @var list<InvocationAttempt>
	 */
	private array $buffered = array();

	/**
	 * Whether the flush hook is registered.
	 *
	 * @var bool
	 */
	private bool $flush_scheduled = false;

	/**
	 * Buffers an attempt and ensures it will be flushed.
	 *
	 * @param InvocationAttempt $attempt Attempt to record.
	 * @return void
	 */
	public function record( InvocationAttempt $attempt ): void {
		$this->buffered[] = $attempt;

		if ( ! $this->flush_scheduled ) {
			add_action( 'shutdown', array( $this, 'flush' ), 20 );
			$this->flush_scheduled = true;
		}
	}

	/**
	 * Upgrades the most recent buffered attempt for an ability to completed.
	 *
	 * Searches from the end so nested or repeated invocations of the same
	 * ability in one request complete in reverse order of starting, which is the
	 * order they actually finish in.
	 *
	 * @param string $ability Ability name.
	 * @return void
	 */
	public function complete( string $ability ): void {
		$buffered = $this->buffered;
		for ( $index = count( $buffered ) - 1; $index >= 0; $index-- ) {
			$candidate = $buffered[ $index ];
			if ( $candidate->ability === $ability && InvocationAttempt::ATTEMPTED === $candidate->outcome ) {
				$buffered[ $index ] = $candidate->completed();
				$this->buffered     = array_values( $buffered );

				return;
			}
		}
	}

	/**
	 * Writes this request's buffer into the ring-buffered option.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( array() === $this->buffered ) {
			return;
		}

		$entries = array();
		foreach ( $this->buffered as $attempt ) {
			$entries[] = $attempt->to_array();
		}
		$this->buffered = array();

		$stored = get_option( Installer::INVOCATION_TELEMETRY_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_merge( array_values( $stored ), $entries );
		if ( count( $merged ) > self::MAX_ENTRIES ) {
			$merged = array_slice( $merged, -self::MAX_ENTRIES );
		}

		update_option( Installer::INVOCATION_TELEMETRY_OPTION, $merged, false );
	}
}
