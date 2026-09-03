<?php
/**
 * Requested slug is not available.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * The slug is taken, so WordPress would have stored a different one.
 *
 * Distinct from a write failure: nothing went wrong, the caller simply cannot
 * have that URL. Reported rather than silently accepted, because WordPress
 * would otherwise append `-2` and return success for a URL nobody requested.
 */
final class PermalinkUnavailable extends RuntimeException {

	/**
	 * Returns the stable public error code.
	 */
	public function error_code(): string {
		return 'wpcb_permalink_unavailable';
	}
}
