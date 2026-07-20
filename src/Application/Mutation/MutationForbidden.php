<?php
/**
 * Raised when per-post-type write policy denies a mutation.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Policy-level denial (distinct from capability denial, which the adapter's
 * permission callback handles before the use case runs).
 */
final class MutationForbidden extends RuntimeException {

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_forbidden';
	}
}
