<?php
/**
 * Typed integration-access failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Access;

use RuntimeException;

/**
 * Carries a stable, admin-UI-safe failure code.
 */
final class IntegrationAccessProblem extends RuntimeException {

	/**
	 * Creates a typed access failure.
	 *
	 * @param string $error_code Stable error code.
	 */
	public function __construct( public readonly string $error_code ) {
		parent::__construct( 'Integration access failed: ' . $error_code );
	}
}
