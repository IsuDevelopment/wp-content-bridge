<?php
/**
 * Invalid content-state mutation failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Signals that a mutation is invalid for the target's current state.
 */
final class MutationInvalidState extends RuntimeException {

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_invalid_state';
	}
}
