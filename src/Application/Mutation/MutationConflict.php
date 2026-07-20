<?php
/**
 * Stale-write conflict failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Thrown when the supplied version token no longer matches the object.
 */
final class MutationConflict extends RuntimeException {

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_conflict';
	}
}
