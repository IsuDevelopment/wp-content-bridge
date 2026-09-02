<?php
/**
 * Redirect failure classification for redacted audit storage.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use InvalidArgumentException;
use Throwable;

/**
 * One place that maps a redirect failure to an audit outcome and a stable
 * error code, shared by every redirect write.
 *
 * Kept out of the individual use cases so the three of them cannot drift into
 * classifying the same exception differently — a divergence that would only
 * ever be noticed by someone reading the audit table and trusting it.
 */
final class RedirectAuditOutcome {

	/**
	 * Classifies one failure.
	 *
	 * @param Throwable $error Failure.
	 * @return string One of success|invalid|denied|failure.
	 */
	public static function for_error( Throwable $error ): string {
		return match ( true ) {
			$error instanceof RedirectSourceRejected,
			$error instanceof InvalidArgumentException => 'invalid',
			$error instanceof RedirectProviderForbidden => 'denied',
			default => 'failure',
		};
	}

	/**
	 * Returns the stable error code for one failure.
	 *
	 * @param Throwable $error Failure.
	 * @return string
	 */
	public static function code_for( Throwable $error ): string {
		return match ( true ) {
			$error instanceof RedirectSourceRejected => 'wpcb_redirect_source_rejected',
			$error instanceof InvalidArgumentException => 'wpcb_invalid_input',
			$error instanceof RedirectProviderForbidden => 'wpcb_forbidden',
			$error instanceof RedirectRuleNotRepresentable => 'wpcb_redirect_rule_not_representable',
			$error instanceof RedirectProviderUnavailable => 'wpcb_redirect_provider_unavailable',
			default => 'wpcb_internal_error',
		};
	}
}
