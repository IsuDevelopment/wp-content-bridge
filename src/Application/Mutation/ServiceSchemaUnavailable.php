<?php
/**
 * Service schema integration availability failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Raised when the optional Service schema provider cannot safely handle a write.
 */
final class ServiceSchemaUnavailable extends RuntimeException {

	/**
	 * Returns the stable public error code.
	 */
	public function error_code(): string {
		return 'wpcb_service_schema_unavailable';
	}
}
