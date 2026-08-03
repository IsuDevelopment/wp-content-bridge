<?php
/**
 * Custom Schema integration availability failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Raised when the optional Custom Schema provider cannot handle a request.
 */
final class CustomSchemaUnavailable extends RuntimeException {

	/**
	 * Returns the stable public error code.
	 */
	public function error_code(): string {
		return 'wpcb_custom_schema_unavailable';
	}
}
