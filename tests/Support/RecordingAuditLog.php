<?php
/**
 * Recording audit log test double.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Support;

use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;

/**
 * Keeps every recorded event in memory so a test can assert both the outcome
 * and that only field names, never values, were stored.
 */
final class RecordingAuditLog implements AuditLog {

	/**
	 * Recorded events, in order.
	 *
	 * @var list<AuditEvent>
	 */
	public array $events = array();

	/**
	 * Records one event.
	 *
	 * @param AuditEvent $event Pre-redacted event.
	 * @return void
	 */
	public function record( AuditEvent $event ): void {
		$this->events[] = $event;
	}
}
