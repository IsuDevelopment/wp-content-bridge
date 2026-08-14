<?php
/**
 * Redirect HTTP status allowlist.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Redirect;

/**
 * P0 (ADR 0026 s5) bounds redirect writes to the intersection of both
 * providers' documented codes that is unambiguous for editorial use.
 */
enum RedirectStatusCode: int {
	case PERMANENT = 301;
	case FOUND     = 302;
	case GONE      = 410;
}
