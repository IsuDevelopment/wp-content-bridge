<?php
/**
 * Typed llms.txt ownership-adoption failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

use RuntimeException;

/**
 * Carries a bounded code suitable for an administrator redirect and audit.
 */
final class LlmsOwnershipAdoptionProblem extends RuntimeException {

	/**
	 * Creates a typed failure.
	 *
	 * @param string $error_code Stable, non-sensitive failure code.
	 * @param string $message    Human-readable failure description.
	 */
	public function __construct(
		public readonly string $error_code,
		string $message,
	) {
		parent::__construct( $message );
	}
}
