<?php
/**
 * Audit log port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Append-only mutation audit sink.
 */
interface AuditLog {

	/**
	 * Records one mutation attempt (success or failure).
	 *
	 * @param AuditEvent $event Pre-redacted event.
	 * @return void
	 */
	public function record( AuditEvent $event ): void;
}
