<?php
/**
 * Redirect candidate rejection.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use RuntimeException;

/**
 * Raised by {@see RedirectCandidateGuard} when a candidate fails a
 * provider-neutral editorial-safety invariant (ADR 0026 s5): reserved
 * prefix, live-content shadow, existing collision, or a chain/loop bound.
 */
final class RedirectSourceRejected extends RuntimeException {

	/**
	 * Returns the stable public error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_redirect_source_rejected';
	}
}
