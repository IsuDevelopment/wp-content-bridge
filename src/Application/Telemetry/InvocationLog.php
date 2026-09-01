<?php
/**
 * Invocation telemetry port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Telemetry;

/**
 * Bounded sink for ability invocation attempts.
 *
 * Separate from `Application\Mutation\AuditLog` on purpose (ADR 0029): the audit
 * records what *happened* to content and is evidence; this records what was
 * *attempted* and is a diagnostic. They must never share a sink — the audit
 * table prunes, and read traffic would evict write history.
 */
interface InvocationLog {

	/**
	 * Records an attempt.
	 *
	 * @param InvocationAttempt $attempt Attempt to record.
	 * @return void
	 */
	public function record( InvocationAttempt $attempt ): void;

	/**
	 * Marks the most recent matching attempt in this request completed.
	 *
	 * Implementations that buffer within a request use this to upgrade an entry
	 * in place, so a successful call costs no extra write. An implementation
	 * with nothing matching to upgrade must do nothing rather than record a
	 * second entry.
	 *
	 * @param string $ability Ability name.
	 * @return void
	 */
	public function complete( string $ability ): void;
}
