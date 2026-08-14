<?php
/**
 * Redirect provider availability failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use RuntimeException;

/**
 * Raised when no compatible redirect provider is active for a write.
 */
final class RedirectProviderUnavailable extends RuntimeException {

	/**
	 * Returns the stable public error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_redirect_provider_unavailable';
	}
}
