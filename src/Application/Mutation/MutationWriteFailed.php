<?php
/**
 * Raised when WordPress rejects a content write.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Infrastructure-level write failure surfaced to the adapter as wpcb_write_failed.
 */
final class MutationWriteFailed extends RuntimeException {

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_write_failed';
	}
}
