<?php
/**
 * Discarding audit sink for the featured-image verifier.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;

/**
 * Audit sink that discards, so a verifier run never appends to the site's
 * audit record.
 */
final class WPCB_Featured_Image_Discarding_Audit_Log implements AuditLog {

	/**
	 * Discards one event.
	 *
	 * @param AuditEvent $event Unused event.
	 * @return void
	 */
	public function record( AuditEvent $event ): void {
		unset( $event );
	}
}
